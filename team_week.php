<?php
// team_week.php — 📊 活動ログ一覧（Leader以上）
// ・team_id=NULL の daily_check も「自チーム扱い」で集計（応急対応）
// ・タイムスタンプ列を自動推定（created_at / indate / submitted_at / timestamp / ts / createdAt）
// ・?debug=1 で安全デバッグ表示
ini_set('display_errors','1'); error_reporting(E_ALL);

require_once __DIR__ . '/funcs.php';
team_required();
if (!role_at_least('leader')) { http_response_code(403); exit('このページにアクセスする権限がありません（Leader 以上）'); }

$me  = current_user();
$pdo = db_conn();

/* ========= 表示チームID（所属チームのみ許可） ========= */
$defaultTeamId = (int)$me['team_id'];
$reqTeamId = isset($_GET['team_id']) ? (int)$_GET['team_id'] : $defaultTeamId;
$chk = $pdo->prepare("SELECT 1 FROM team_members WHERE user_id=:uid AND team_id=:tid LIMIT 1");
$chk->execute([':uid'=>(int)$me['id'], ':tid'=>$reqTeamId]);
$teamId = $chk->fetchColumn() ? $reqTeamId : $defaultTeamId;

/* ========= 所属チーム一覧（切替リンク用） ========= */
$teamsStmt = $pdo->prepare("
  SELECT t.id, COALESCE(NULLIF(t.name,''), CONCAT('Team ',t.id)) AS name
  FROM team_members tm
  JOIN teams t ON t.id = tm.team_id
  WHERE tm.user_id = :uid
  ORDER BY name ASC
");
$teamsStmt->execute([':uid'=>(int)$me['id']]);
$myTeams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

/* ========= 期間設定 ========= */
$period = $_GET['period'] ?? '7';   // 'all' | '7' | '30'
$rangeText = '直近7日';
$since = null; // 'Y-m-d H:i:s'
if     ($period==='30'){ $since = date('Y-m-d 00:00:00', strtotime('-30 day')); $rangeText='直近30日'; }
elseif ($period==='7') { $since = date('Y-m-d 00:00:00', strtotime('-7 day'));  $rangeText='直近7日'; }
else { $period='all'; }

/* ========= タイムスタンプ列を自動推定 ========= */
function detect_ts_col(PDO $pdo): string {
  try { $cols = $pdo->query("SHOW COLUMNS FROM daily_check")->fetchAll(PDO::FETCH_COLUMN); }
  catch(Throwable $e){ return 'created_at'; }
  foreach (['created_at','indate','submitted_at','timestamp','ts','createdAt'] as $c) {
    if (in_array($c, $cols, true)) return $c;
  }
  return 'created_at';
}
$tsCol = detect_ts_col($pdo);

/* ========= NULL team_id も「自チーム扱い」で集計する where 句（単発用） ========= */
$teamWhere = "(team_id = :tid OR team_id IS NULL)";

/* ========= DEBUG（?debug=1 で表示） ========= */
if (isset($_GET['debug'])) {
  echo '<pre style="background:#fff;border:1px dashed #bbb;padding:10px;border-radius:8px;margin:8px 0">';
  echo "=== DEBUG ===\n";
  echo "team_id: {$teamId}\n";
  echo "period : {$period}  since=" . ($since ?? 'ALL') . "\n";
  echo "tsCol  : {$tsCol}\n";
  try {
    $rows = $pdo->query("SELECT team_id, COUNT(*) n, MIN($tsCol) min_at, MAX($tsCol) max_at FROM daily_check GROUP BY team_id ORDER BY team_id")->fetchAll(PDO::FETCH_ASSOC);
    echo "\n全期間 チーム別件数:\n";
    if ($rows) foreach ($rows as $r) echo " team ".($r['team_id']??'NULL')." : {$r['n']}件 [{$r['min_at']}..{$r['max_at']}]\n";
    else echo "(0件)\n";
  } catch(Throwable $e){ echo "DBG err: ".$e->getMessage()."\n"; }
  try{
    $st=$pdo->prepare("SELECT id,user_id,team_id,mood,workload,trust,$tsCol ts FROM daily_check WHERE $teamWhere ORDER BY ts DESC LIMIT 10");
    $st->execute([':tid'=>$teamId]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    echo "\n表示中(=選択 + NULL) 最新10件:\n";
    if($rows){ foreach($rows as $r){ echo "{$r['ts']} user:{$r['user_id']} mood:{$r['mood']} work:{$r['workload']} trust:{$r['trust']}\n"; } }
    else echo "(0件)\n";
  }catch(Throwable $e){ echo "DBG latest err: ".$e->getMessage()."\n"; }
  echo "=== /DEBUG ===\n</pre>";
}

/* ========= メンバー一覧 ========= */
$sqlMembers = "
  SELECT u.id AS user_id, COALESCE(NULLIF(u.display_name,''), u.email) AS name
  FROM team_members tm
  JOIN users u ON u.id = tm.user_id
  WHERE tm.team_id = :tid
  ORDER BY name ASC
";
$stM = $pdo->prepare($sqlMembers);
$stM->execute([':tid'=>$teamId]);
$members = $stM->fetchAll(PDO::FETCH_ASSOC);

/* ========= Checkin件数（期間内 / NULL含む） ========= */
$sqlCnt = "SELECT user_id, COUNT(*) AS n_check FROM daily_check WHERE $teamWhere";
$params = [':tid'=>$teamId];
if ($since){ $sqlCnt .= " AND $tsCol >= :since"; $params[':since']=$since; }
$sqlCnt .= " GROUP BY user_id";
$stCnt = $pdo->prepare($sqlCnt);
$stCnt->execute($params);
$cntRows = $stCnt->fetchAll(PDO::FETCH_KEY_PAIR);

/* ========= 最新スコア（直近1件 / NULL含む） ========= */
/* ここが修正ポイント：サブクエリと外側で :tid を分離（:tid1 / :tid2） */
$teamWhere1 = "(team_id = :tid1 OR team_id IS NULL)";
$teamWhere2 = "(team_id = :tid2 OR team_id IS NULL)";
$sqlLatest = "
  SELECT dc.user_id, dc.mood, dc.workload, dc.trust, dc.$tsCol AS created_at
  FROM daily_check dc
  JOIN (
    SELECT user_id, MAX($tsCol) AS last_at
    FROM daily_check
    WHERE $teamWhere1
    GROUP BY user_id
  ) x ON x.user_id = dc.user_id AND x.last_at = dc.$tsCol
  WHERE $teamWhere2
";
$stL = $pdo->prepare($sqlLatest);
$stL->execute([':tid1'=>$teamId, ':tid2'=>$teamId]);
$latestRows = [];
while ($r = $stL->fetch(PDO::FETCH_ASSOC)) { $latestRows[(int)$r['user_id']] = $r; }

/* ========= SOS / 感謝（受取）件数（通常どおり team_id 指定のみ） ========= */
$hasSOS    = false; $hasThanks = false;
try { $hasSOS    = (bool)$pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sos_logs'")->fetchColumn(); } catch(Throwable $e){}
try { $hasThanks = (bool)$pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='thanks_logs'")->fetchColumn(); } catch(Throwable $e){}

$sosCnt = [];
if ($hasSOS) {
  $sql = "SELECT user_id, COUNT(*) AS n FROM sos_logs WHERE team_id=:tid";
  $p=[ ':tid'=>$teamId ];
  if ($since){ $sql.=" AND created_at >= :since"; $p[':since']=$since; }
  $sql .= " GROUP BY user_id";
  $st=$pdo->prepare($sql); $st->execute($p);
  $sosCnt = $st->fetchAll(PDO::FETCH_KEY_PAIR);
}

$thanksRecvCnt = [];
if ($hasThanks) {
  $dirCol=false; try{ $dirCol=(bool)$pdo->query("SHOW COLUMNS FROM thanks_logs LIKE 'direction'")->fetch(); }catch(Throwable $e){}
  $condDir = $dirCol ? " AND (direction='recv' OR direction='received')" : "";
  $sql = "SELECT user_id, COUNT(*) AS n FROM thanks_logs WHERE team_id=:tid $condDir";
  $p=[ ':tid'=>$teamId ];
  if ($since){ $sql.=" AND created_at >= :since"; $p[':since']=$since; }
  $sql .= " GROUP BY user_id";
  $st=$pdo->prepare($sql); $st->execute($p);
  $thanksRecvCnt = $st->fetchAll(PDO::FETCH_KEY_PAIR);
}

/* ========= 合計 ========= */
$totalCheckins = array_sum($cntRows ?: []);
$totalSOS      = array_sum($sosCnt ?: []);
$totalThanks   = array_sum($thanksRecvCnt ?: []);

/* ========= ビュー ========= */
include __DIR__ . '/header.php';
?>
<main style="max-width:1080px;margin:0 auto;padding:20px;">
  <h1 style="margin:0 0 6px;">📊 活動ログ一覧</h1>

  <!-- 表示チーム切替 -->
  <p style="margin:6px 0 8px;color:#64748b;">
    表示チーム：
    <?php foreach ($myTeams as $i=>$t): ?>
      <a href="?team_id=<?= (int)$t['id'] ?>&period=<?= h($period) ?>"
         <?= ((int)$t['id']===$teamId)?'style="font-weight:bold;text-decoration:underline"':''; ?>>
        <?= h($t['name']) ?>
      </a><?= $i<count($myTeams)-1?' / ':'' ?>
    <?php endforeach; ?>
  </p>

  <p style="color:#555;margin:0 0 16px;">
    チーム (<strong>ID <?= h((string)$teamId) ?></strong>) の活動ログを表示します。
    <span style="margin-left:8px;color:#64748b;">期間：
      <a href="?period=all&team_id=<?= (int)$teamId ?>"<?= $period==='all'?' style="font-weight:bold;text-decoration:underline"':''; ?>>全期間</a> /
      <a href="?period=7&team_id=<?= (int)$teamId ?>"  <?= $period==='7'  ?' style="font-weight:bold;text-decoration:underline"':''; ?>>直近7日</a> /
      <a href="?period=30&team_id=<?= (int)$teamId ?>" <?= $period==='30' ?' style="font-weight:bold;text-decoration:underline"':''; ?>>直近30日</a>
    </span>
    <span style="margin-left:12px;"><a href="team_trends.php?days=7&team_id=<?= (int)$teamId ?>">→ チーム推移グラフ</a></span>
  </p>

  <style>
    :root { --gray-200:#e5e7eb; --muted:#6b7280; }
    .cards { display:grid; gap:12px; grid-template-columns: repeat(3, 1fr); margin: 0 0 12px; }
    .card  { border:1px solid var(--gray-200); border-radius:12px; background:#fff; padding:14px; }
    .big   { font-size:24px; line-height:1.1; }
    .muted { color:var(--muted); font-size:12px; }
    .tbl   { width:100%; border-collapse: collapse; background:#fff; border:1px solid var(--gray-200); border-radius:12px; overflow:hidden; }
    .tbl th, .tbl td { padding:10px 12px; border-bottom:1px solid #eef2f7; text-align:left; }
    .tbl th { background:#f8fafc; white-space:nowrap; }
    .badge { display:inline-block; padding:2px 8px; border:1px solid #e2e8f0; border-radius:999px; background:#f1f5f9; font-size:12px; color:#0f172a;}
    .empty { border:1px dashed #cbd5e1; color:#475569; background:#f8fafc; padding:18px; border-radius:12px; margin-top:10px;}
    @media (max-width: 860px){ .cards{ grid-template-columns: 1fr; } .tbl th:nth-child(4), .tbl td:nth-child(4){ display:none; } }
  </style>

  <section class="cards">
    <div class="card"><div class="muted">メンバー数</div><div class="big"><?= count($members) ?></div><div class="muted"><?= h($rangeText) ?> 対象</div></div>
    <div class="card"><div class="muted">Checkin 件数（<?= h($rangeText) ?>）</div><div class="big"><?= (int)$totalCheckins ?></div></div>
    <div class="card">
      <div class="muted"><?= $hasSOS?'SOS ':'' ?><?= ($hasSOS&&$hasThanks)?' / ':'' ?><?= $hasThanks?'感謝（受取） ':'' ?>件数（<?= h($rangeText) ?>）</div>
      <div class="big"><?= (int)$totalSOS ?><?= ($hasSOS&&$hasThanks)?' / ':'' ?><?= $hasThanks?(int)$totalThanks:'' ?></div>
    </div>
  </section>

  <?php if (empty($members)): ?>
    <div class="empty">このチームにメンバーがいません。</div>
  <?php else: ?>
    <table class="tbl">
      <thead>
        <tr>
          <th>メンバー</th>
          <th>Checkin件数<br><span class="muted"><?= h($rangeText) ?></span></th>
          <th>最新 ご機嫌/負荷/信頼</th>
          <th>最終記録</th>
          <?php if ($hasSOS): ?><th>SOS件数<br><span class="muted"><?= h($rangeText) ?></span></th><?php endif; ?>
          <?php if ($hasThanks): ?><th>感謝（受取）件数<br><span class="muted"><?= h($rangeText) ?></span></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($members as $m):
        $uid = (int)$m['user_id'];
        $nCheck = (int)($cntRows[$uid] ?? 0);
        $latest = $latestRows[$uid] ?? null;
        $latestStr = $latest ? sprintf('%s / %s / %s',
                      is_null($latest['mood'])?'—':round((float)$latest['mood'],1),
                      is_null($latest['workload'])?'—':round((float)$latest['workload'],1),
                      is_null($latest['trust'])?'—':round((float)$latest['trust'],1)) : '—';
        $lastAt = $latest ? date('Y-m-d H:i', strtotime($latest['created_at'])) : '—';
        $nSOS    = $hasSOS    ? (int)($sosCnt[$uid] ?? 0) : null;
        $nThanks = $hasThanks ? (int)($thanksRecvCnt[$uid] ?? 0) : null;
      ?>
        <tr>
          <td><span class="badge"><?= h($m['name']) ?></span></td>
          <td><?= $nCheck ?></td>
          <td><?= h($latestStr) ?></td>
          <td><?= h($lastAt) ?></td>
          <?php if ($hasSOS): ?><td><?= $nSOS ?></td><?php endif; ?>
          <?php if ($hasThanks): ?><td><?= $nThanks ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <p class="muted" style="margin-top:14px;">
    推移を詳しく見る場合は <a href="team_trends.php?days=7&team_id=<?= (int)$teamId ?>">チーム推移グラフ</a> を参照。
  </p>
</main>
<?php include __DIR__ . '/footer.php'; ?>
