<?php
// team_trends.php — 📈 チーム推移グラフ（Leader以上・team切替/days切替・NULL team_id 応急対応・デバッグ付）
ini_set('display_errors','1'); error_reporting(E_ALL);

require_once __DIR__ . '/funcs.php';
team_required();
if (!role_at_least('leader')) {
  http_response_code(403);
  exit('このページにアクセスする権限がありません（Leader 以上）');
}

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

/* ========= 日数ウィンドウ ========= */
$windowDays = max(1, (int)($_GET['days'] ?? 7));
$since = date('Y-m-d 00:00:00', strtotime('-'.$windowDays.' day'));

/* ========= タイムスタンプ列の自動推定 ========= */
function detect_ts_col(PDO $pdo): string {
  try { $cols = $pdo->query("SHOW COLUMNS FROM daily_check")->fetchAll(PDO::FETCH_COLUMN); }
  catch(Throwable $e){ return 'created_at'; }
  foreach (['created_at','indate','submitted_at','timestamp','ts','createdAt'] as $c) {
    if (in_array($c,$cols,true)) return $c;
  }
  return 'created_at';
}
$tsCol = detect_ts_col($pdo);

/* ========= NULL team_id も「自チーム扱い」で集計する where 句 ========= */
$teamWhere = "(team_id = :tid OR team_id IS NULL)";

/* ========= デバッグ（?debug=1 で表示） ========= */
if (isset($_GET['debug'])) {
  echo '<pre style="background:#fff;border:1px dashed #bbb;padding:10px;border-radius:8px;margin:8px 0">';
  echo "=== DEBUG ===\n";
  echo "team_id   : {$teamId}\n";
  echo "windowDays: {$windowDays}  since={$since}\n";
  echo "tsCol     : {$tsCol}\n";
  try{
    $rows=$pdo->query("SELECT team_id,COUNT(*) n,MIN($tsCol) min_at,MAX($tsCol) max_at FROM daily_check GROUP BY team_id")->fetchAll(PDO::FETCH_ASSOC);
    echo "\n全期間 チーム別件数:\n";
    if ($rows) foreach($rows as $r){ echo " team ".($r['team_id']??'NULL')." : {$r['n']}件 [{$r['min_at']}..{$r['max_at']}]\n"; }
    else echo "(0件)\n";
  }catch(Throwable $e){ echo "DBG err: ".$e->getMessage()."\n"; }
  try{
    $st=$pdo->prepare("SELECT user_id,mood,workload,trust,$tsCol ts FROM daily_check WHERE $teamWhere ORDER BY ts DESC LIMIT 10");
    $st->execute([':tid'=>$teamId]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    echo "\n表示中(=選択 + NULL) 最新10件:\n";
    if($rows){ foreach($rows as $r){ echo "{$r['ts']} u:{$r['user_id']} M:{$r['mood']} W:{$r['workload']} T:{$r['trust']}\n"; } }
    else echo "(0件)\n";
  }catch(Throwable $e){ echo "DBG latest err: ".$e->getMessage()."\n"; }
  echo "=== /DEBUG ===\n</pre>";
}

/* ========= ラベル（日付配列） ========= */
$days=[]; for($i=$windowDays-1;$i>=0;$i--){ $days[] = date('Y-m-d', strtotime("-{$i} day")); }
$labelsHuman = array_map(fn($d)=>date('n/j', strtotime($d)), $days);
$dayIndex = array_flip($days);

/* ========= チーム平均（NULL含む） ========= */
$sqlTeam = "
  SELECT DATE($tsCol) AS d,
         AVG(mood) AS avg_mood,
         AVG(workload) AS avg_workload,
         AVG(trust) AS avg_trust
  FROM daily_check
  WHERE $teamWhere
    AND $tsCol >= :since
  GROUP BY DATE($tsCol)
  ORDER BY d ASC
";
$st = $pdo->prepare($sqlTeam);
$st->execute([':tid'=>$teamId, ':since'=>$since]);
$teamRows = $st->fetchAll(PDO::FETCH_ASSOC);

$teamSeries = [
  'labels'=>$labelsHuman,
  'mood'=>array_fill(0,count($days),null),
  'workload'=>array_fill(0,count($days),null),
  'trust'=>array_fill(0,count($days),null),
];
foreach ($teamRows as $r) {
  if (!isset($dayIndex[$r['d']])) continue;
  $i=$dayIndex[$r['d']];
  $teamSeries['mood'][$i]     = round((float)$r['avg_mood'],2);
  $teamSeries['workload'][$i] = round((float)$r['avg_workload'],2);
  $teamSeries['trust'][$i]    = round((float)$r['avg_trust'],2);
}

/* ========= 要約カード（NULL含む） ========= */
$latestDay = !empty($teamRows) ? end($teamRows) : null;
$summary = [
  'latest'=>[
    'mood'     => $latestDay ? round((float)$latestDay['avg_mood'],2) : null,
    'workload' => $latestDay ? round((float)$latestDay['avg_workload'],2) : null,
    'trust'    => $latestDay ? round((float)$latestDay['avg_trust'],2) : null,
    'date'     => $latestDay ? date('n/j', strtotime($latestDay['d'])) : null,
  ],
  'avg'=>['mood'=>null,'workload'=>null,'trust'=>null],
];
$st=$pdo->prepare("
  SELECT AVG(mood) a_mood, AVG(workload) a_work, AVG(trust) a_trust
  FROM daily_check
  WHERE $teamWhere AND $tsCol >= :since
");
$st->execute([':tid'=>$teamId, ':since'=>$since]);
$avg=$st->fetch(PDO::FETCH_ASSOC) ?: [];
$summary['avg']['mood']     = isset($avg['a_mood']) ? round((float)$avg['a_mood'],2) : null;
$summary['avg']['workload'] = isset($avg['a_work']) ? round((float)$avg['a_work'],2) : null;
$summary['avg']['trust']    = isset($avg['a_trust']) ? round((float)$avg['a_trust'],2) : null;

/* ========= メンバー一覧（所属チームのみ。ここは NULL を含めない） ========= */
$st=$pdo->prepare("
  SELECT u.id AS user_id, COALESCE(NULLIF(u.display_name,''), u.email) AS name
  FROM team_members tm
  JOIN users u ON u.id = tm.user_id
  WHERE tm.team_id = :tid
  ORDER BY name ASC
");
$st->execute([':tid'=>$teamId]);
$members=$st->fetchAll(PDO::FETCH_ASSOC);

/* ========= 各メンバー推移（daily_check は NULL含む） ========= */
$st=$pdo->prepare("
  SELECT dc.user_id, DATE(dc.$tsCol) AS d,
         AVG(dc.mood) AS avg_mood,
         AVG(dc.workload) AS avg_workload,
         AVG(dc.trust) AS avg_trust
  FROM daily_check dc
  WHERE ($teamWhere) AND dc.$tsCol >= :since
  GROUP BY dc.user_id, DATE(dc.$tsCol)
  ORDER BY dc.user_id, d
");
$st->execute([':tid'=>$teamId, ':since'=>$since]);
$rows=$st->fetchAll(PDO::FETCH_ASSOC);

$userSeries=[];
foreach ($members as $m){
  $uid=(int)$m['user_id'];
  $userSeries[$uid]=[
    'name'=>$m['name'],
    'mood'=>array_fill(0,count($days),null),
    'workload'=>array_fill(0,count($days),null),
    'trust'=>array_fill(0,count($days),null),
  ];
}
foreach($rows as $r){
  $uid=(int)$r['user_id']; $d=$r['d'];
  if(!isset($userSeries[$uid]) || !isset($dayIndex[$d])) continue;
  $i=$dayIndex[$d];
  $userSeries[$uid]['mood'][$i]     = round((float)$r['avg_mood'],2);
  $userSeries[$uid]['workload'][$i] = round((float)$r['avg_workload'],2);
  $userSeries[$uid]['trust'][$i]    = round((float)$r['avg_trust'],2);
}

$teamPayload=['labels'=>$labelsHuman,'mood'=>$teamSeries['mood'],'workload'=>$teamSeries['workload'],'trust'=>$teamSeries['trust']];
$userPayload=[];
foreach($userSeries as $uid=>$s){ $userPayload[]=['user_id'=>$uid,'name'=>$s['name'],'mood'=>$s['mood'],'workload'=>$s['workload'],'trust'=>$s['trust']]; }

/* ========= ビュー ========= */
include __DIR__ . '/header.php';
?>
<main style="max-width:1080px;margin:0 auto;padding:20px;">
  <h1 style="margin:0 0 6px;">📈 チーム推移グラフ</h1>

  <!-- 表示チーム切替 -->
  <p style="margin:6px 0 8px;color:#64748b;">
    表示チーム：
    <?php foreach ($myTeams as $i=>$t): ?>
      <a href="?team_id=<?= (int)$t['id'] ?>&days=<?= (int)$windowDays ?>"
         <?= ((int)$t['id']===$teamId)?'style="font-weight:bold;text-decoration:underline"':''; ?>>
        <?= h($t['name']) ?>
      </a><?= $i<count($myTeams)-1?' / ':'' ?>
    <?php endforeach; ?>
  </p>

  <p style="color:#555;margin:0 0 18px;">
    チーム全体と各メンバーの<?= h((string)$windowDays) ?>日推移。
    <span style="margin-left:8px;color:#64748b;">期間：
      <a href="?days=7&team_id=<?= (int)$teamId ?>"  <?= $windowDays===7  ?'style="font-weight:bold;text-decoration:underline"':''; ?>>7日</a> /
      <a href="?days=30&team_id=<?= (int)$teamId ?>" <?= $windowDays===30 ?'style="font-weight:bold;text-decoration:underline"':''; ?>>30日</a>
    </span>
    <span style="margin-left:12px;"><a href="team_week.php?period=7&team_id=<?= (int)$teamId ?>">→ 活動ログ一覧</a></span>
  </p>

  <style>
    :root { --teal:#14b8a6; --blue:#0ea5e9; --amber:#f59e0b; --gray-200:#e5e7eb; }
    .cards{display:grid;gap:12px;grid-template-columns:repeat(3,1fr);margin:0 0 14px;}
    .card{border:1px solid var(--gray-200);border-radius:12px;background:#fff;padding:14px;}
    .big{font-size:26px;line-height:1.1;}
    .muted{color:#6b7280;font-size:12px;}
    .grid{display:grid;gap:12px;grid-template-columns:repeat(2,1fr);}
    @media (max-width:860px){.grid{grid-template-columns:1fr}.cards{grid-template-columns:1fr}}
    .title{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;}
    .mini{height:220px;}
    .search{display:flex;gap:8px;margin:8px 0 14px;}
    input[type="search"]{width:320px;max-width:100%;padding:8px 10px;border:1px solid var(--gray-200);border-radius:8px;}
    .empty{border:1px dashed #cbd5e1;color:#475569;background:#f8fafc;padding:18px;border-radius:12px;}
  </style>

  <?php if (empty($teamRows) && empty($rows)): ?>
    <div class="empty">直近<?= h((string)$windowDays) ?>日にデータがありません。Checkin を記録すると推移が表示されます。</div>
  <?php endif; ?>

  <section class="cards">
    <div class="card">
      <div class="muted">最新日（<?= h($summary['latest']['date'] ?? '—') ?>）</div>
      <div class="big">ご機嫌 <?= $summary['latest']['mood'] ?? '—' ?></div>
      <div class="muted">負荷 <?= $summary['latest']['workload'] ?? '—' ?> / 信頼 <?= $summary['latest']['trust'] ?? '—' ?></div>
    </div>
    <div class="card">
      <div class="muted">期間平均（直近<?= h((string)$windowDays) ?>日）</div>
      <div class="big">ご機嫌 <?= $summary['avg']['mood'] ?? '—' ?></div>
      <div class="muted">負荷 <?= $summary['avg']['workload'] ?? '—' ?> / 信頼 <?= $summary['avg']['trust'] ?? '—' ?></div>
    </div>
    <div class="card">
      <div class="muted">Team</div><div class="big">ID <?= h((string)$teamId) ?></div>
      <div class="muted">表示期間：<?= h((string)$windowDays) ?>日</div>
    </div>
  </section>

  <section class="card">
    <div class="title"><h3 style="margin:0;">チーム平均の推移</h3><div class="muted">Y軸 1–5</div></div>
    <div style="height:280px;"><canvas id="teamChart"></canvas></div>
  </section>

  <div class="search">
    <input id="q" type="search" placeholder="メンバー名で絞り込み…">
    <div class="muted">値は各日の平均（複数回入力があれば平均化）。</div>
  </div>

  <section id="cards" class="grid"></section>

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script>
    const labels = <?= json_encode($teamPayload['labels'], JSON_UNESCAPED_UNICODE) ?>;
    const teamData = <?= json_encode($teamPayload, JSON_UNESCAPED_UNICODE) ?>;
    const users = <?= json_encode($userPayload, JSON_UNESCAPED_UNICODE) ?>;

    new Chart(document.getElementById('teamChart').getContext('2d'), {
      type:'line',
      data:{ labels,
        datasets:[
          {label:'ご機嫌度',data:teamData.mood,borderColor:'#14b8a6',backgroundColor:'rgba(20,184,166,.12)',tension:.3,spanGaps:false,pointRadius:3},
          {label:'仕事の負荷',data:teamData.workload,borderColor:'#0ea5e9',backgroundColor:'rgba(14,165,233,.10)',tension:.3,spanGaps:false,pointRadius:3},
          {label:'チーム内信頼',data:teamData.trust,borderColor:'#f59e0b',backgroundColor:'rgba(245,158,11,.10)',tension:.3,spanGaps:false,pointRadius:3},
        ]},
      options:{responsive:true,maintainAspectRatio:false,scales:{y:{min:1,max:5,ticks:{stepSize:1}}},plugins:{legend:{display:true,position:'bottom'}}}
    });

    const cards = document.getElementById('cards');
    function makeCard(user){
      const id='chart_'+user.user_id;
      const el=document.createElement('div');
      el.className='card'; el.dataset.name=(user.name||'').toLowerCase();
      el.innerHTML=`<div class="title"><strong>${user.name??'No name'}</strong><span class="muted"><?= h((string)$windowDays) ?>日推移</span></div><div class="mini"><canvas id="${id}"></canvas></div>`;
      cards.appendChild(el);
      new Chart(document.getElementById(id).getContext('2d'), {
        type:'line',
        data:{ labels,
          datasets:[
            {label:'ご機嫌度',data:user.mood,borderColor:'#14b8a6',backgroundColor:'rgba(20,184,166,.12)',tension:.3,spanGaps:false,pointRadius:0,pointHoverRadius:4},
            {label:'仕事の負荷',data:user.workload,borderColor:'#0ea5e9',backgroundColor:'rgba(14,165,233,.10)',tension:.3,spanGaps:false,pointRadius:0,pointHoverRadius:4},
            {label:'チーム内信頼',data:user.trust,borderColor:'#f59e0b',backgroundColor:'rgba(245,158,11,.10)',tension:.3,spanGaps:false,pointRadius:0,pointHoverRadius:4},
          ]},
        options:{responsive:true,maintainAspectRatio:false,scales:{y:{min:1,max:5,ticks:{stepSize:1}}},plugins:{legend:{display:false}}}
      });
    }
    users.forEach(makeCard);

    const q=document.getElementById('q');
    q.addEventListener('input',()=>{const needle=q.value.trim().toLowerCase();[...cards.children].forEach(card=>{card.style.display=(!needle||card.dataset.name.includes(needle))?'':'none';});});
  </script>
</main>
<?php include __DIR__ . '/footer.php'; ?>
