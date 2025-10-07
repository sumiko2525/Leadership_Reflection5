<?php
// team_week.php — リーダー/管理者向け：チーム7日間のSOS・感謝グラフ（さくら環境向け・決定版）
declare(strict_types=1);
session_start();

/* ▼ 画面に出ない環境があるため、ログファイルに詳細を書き出す */
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php_error.log');
// 開発中のみ表示ONに：ini_set('display_errors','1'); error_reporting(E_ALL);

require_once __DIR__ . '/funcs.php'; // あなたの環境は同階層に funcs.php

login_required();
date_default_timezone_set('Asia/Tokyo');

$me   = current_user();
$role = $me['role'] ?? 'member';
if (!in_array($role, ['admin','leader'], true)) {
  http_response_code(403);
  exit('権限がありません。');
}

/* DB接続（あなたの環境は db_conn()） */
try { $pdo = db_conn(); }
catch (Throwable $e) {
  error_log('DB接続失敗: '.$e->getMessage());
  http_response_code(500); exit('DB接続エラー');
}

/* チーム決定（admin は ?team_id= で切替可能） */
$teamId = (int)($me['team_id'] ?? 0);
if ($role === 'admin' && isset($_GET['team_id'])) $teamId = (int)$_GET['team_id'];
if ($teamId <= 0) exit('チーム未設定');

/* チーム名取得 */
$teamName = '';
try {
  $st = $pdo->prepare('SELECT name FROM teams WHERE id = :tid');
  $st->execute([':tid'=>$teamId]);
  $teamName = (string)($st->fetchColumn() ?: '');
} catch (Throwable $e) {
  error_log('チーム名取得失敗: '.$e->getMessage());
}

/* ===== メンバー一覧（スキーマ自動判定＋表示名式の自動選択） ===== */
function tableExists(PDO $pdo, string $t): bool {
  try { $pdo->query("SELECT 1 FROM `{$t}` LIMIT 1"); return true; }
  catch(Throwable $e){ return false; }
}
function columnExists(PDO $pdo, string $table, string $col): bool {
  try { $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'"); return (bool)$stmt->fetch(); }
  catch(Throwable $e){ return false; }
}
/* 表示名の式を決める（別名 a はテーブルエイリアス） */
function makeNameExpr(PDO $pdo, string $table, string $a = 'u'): string {
  $cands = ['display_name','name','user_name','nickname','email'];
  $parts = [];
  foreach ($cands as $c) {
    if (columnExists($pdo, $table, $c)) {
      if (in_array($c, ['display_name','name','user_name','nickname'], true)) {
        $parts[] = "NULLIF({$a}.{$c},\"\")";
      } else {
        $parts[] = "{$a}.{$c}";
      }
    }
  }
  if (!$parts) return "CONCAT('User#', {$a}.id)";
  return 'COALESCE(' . implode(',', $parts) . ", CONCAT('User#', {$a}.id))";
}

$members = [];

/* 1) team_members + users 連携（最有力） */
if (tableExists($pdo,'team_members') && tableExists($pdo,'users')
    && columnExists($pdo,'team_members','team_id')
    && columnExists($pdo,'team_members','user_id')
    && columnExists($pdo,'users','id')) {

  $nameExpr = makeNameExpr($pdo, 'users', 'u');
  $sql = "
    SELECT u.id, {$nameExpr} AS name
    FROM team_members tm
    JOIN users u ON u.id = tm.user_id
    WHERE tm.team_id = :tid
    ORDER BY name ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':tid'=>$teamId]);
  $members = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* 2) users 単表（users.team_id がある） */
} elseif (tableExists($pdo,'users') && columnExists($pdo,'users','team_id')) {
  $nameExpr = makeNameExpr($pdo, 'users', 'u');
  $sql = "
    SELECT u.id, {$nameExpr} AS name
    FROM users u
    WHERE u.team_id = :tid
    ORDER BY name ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':tid'=>$teamId]);
  $members = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* 3) lr_user 単表（lr_user.team_id がある） */
} elseif (tableExists($pdo,'lr_user') && columnExists($pdo,'lr_user','team_id')) {
  $nameExpr = makeNameExpr($pdo, 'lr_user', 'u');
  $sql = "
    SELECT u.id, {$nameExpr} AS name
    FROM lr_user u
    WHERE u.team_id = :tid
    ORDER BY name ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':tid'=>$teamId]);
  $members = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

} else {
  error_log('メンバー取得スキーマ不一致: users/team_members/lr_user の列構成に適合せず');
  http_response_code(500);
  exit('メンバー取得エラー（スキーマ不一致）');
}
/* ===== メンバー一覧ここまで ===== */

/* 直近7日（今日含む） */
$labels = []; $indexOfDate = [];
for ($i=6; $i>=0; $i--) {
  $d = new DateTime("today -$i day");
  $key = $d->format('Y-m-d');
  $labels[] = $d->format('n/j');
  $indexOfDate[$key] = count($labels)-1;
}
$startDate = (new DateTime('today -6 day'))->format('Y-m-d 00:00:00');
$endDate   = (new DateTime('today'))->format('Y-m-d 23:59:59');

/* データ構造（ゼロ埋め） */
$series = [];
$memberIds = [];
foreach ($members as $m) {
  $uid = (int)$m['id'];
  $memberIds[] = $uid;
  $series[$uid] = ['name'=>$m['name'], 'sos'=>array_fill(0,7,0), 'grat'=>array_fill(0,7,0)];
}

/* 集計：あなたのDBに合わせたテーブル
   - SOS        : sos_requests
   - Gratitude  : checkout_log（team_id が無い想定なので user_id で絞り込み）
*/
if (!empty($memberIds)) {
  $in = implode(',', array_fill(0, count($memberIds), '?'));

  // SOS（team_id あり）
  try {
    $sql = "SELECT user_id, DATE(created_at) d, COUNT(*) c
            FROM sos_requests
            WHERE team_id = ? AND created_at BETWEEN ? AND ?
              AND user_id IN ($in)
            GROUP BY user_id, DATE(created_at)";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$teamId, $startDate, $endDate], $memberIds));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $uid = (int)$r['user_id']; $dk = $r['d'];
      if (isset($series[$uid]) && isset($indexOfDate[$dk])) $series[$uid]['sos'][$indexOfDate[$dk]] = (int)$r['c'];
    }
  } catch (Throwable $e) { error_log('SOS集計失敗: '.$e->getMessage()); }

  // 感謝（checkout_log：team_id なし前提）
  try {
    $sql = "SELECT user_id, DATE(created_at) d, COUNT(*) c
            FROM checkout_log
            WHERE created_at BETWEEN ? AND ?
              AND user_id IN ($in)
            GROUP BY user_id, DATE(created_at)";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$startDate, $endDate], $memberIds));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $uid = (int)$r['user_id']; $dk = $r['d'];
      if (isset($series[$uid]) && isset($indexOfDate[$dk])) $series[$uid]['grat'][$indexOfDate[$dk]] = (int)$r['c'];
    }
  } catch (Throwable $e) { error_log('感謝集計失敗: '.$e->getMessage()); }
}

/* ▼ ヘッダーの位置を自動判定（直下 or templates/） */
$headerPath = __DIR__ . '/header.php';
if (!file_exists($headerPath)) {
  $alt = __DIR__ . '/templates/header.php';
  if (file_exists($alt)) $headerPath = $alt;
}
if (file_exists($headerPath)) {
  include $headerPath;
} else {
  echo '<!doctype html><meta charset="utf-8"><title>Micro Team Coach</title>';
}
?>
<main class="container" style="max-width:1100px;margin:24px auto;padding:0 12px;">
  <h1 style="margin:.3em 0 0.2em;">チーム7日間一覧</h1>
  <div style="opacity:.8;margin-bottom:10px;">
    チーム：<?= htmlspecialchars($teamName ?? '', ENT_QUOTES, 'UTF-8') ?>
    （<?= htmlspecialchars((new DateTime($startDate))->format('n/j'), ENT_QUOTES, 'UTF-8') ?>〜<?= htmlspecialchars((new DateTime($endDate))->format('n/j'), ENT_QUOTES, 'UTF-8') ?>）
    <?php if ($role === 'admin'): ?>
      <form method="get" style="display:inline-block;margin-left:12px;">
        <label>team_id:
          <input type="number" name="team_id" value="<?= (int)$teamId ?>" style="width:100px">
        </label>
        <button type="submit">切替</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if (empty($members)): ?>
    <p>このチームにメンバーがいません。</p>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;">
      <?php foreach ($members as $m):
        $uid = (int)$m['id'];
        $name = $m['name'];
        $sosData = $series[$uid]['sos'];
        $gratData = $series[$uid]['grat'];
        $canvasId = 'cv_' . $uid;
      ?>
      <section style="background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:12px;box-shadow:0 1px 2px rgba(0,0,0,.04);">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
          <div style="font-weight:700;"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></div>
          <div style="font-size:.85rem;opacity:.7;">直近7日</div>
        </div>
        <div style="position:relative;height:160px;">
          <canvas id="<?= $canvasId ?>"
            data-labels='<?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>'
            data-sos='<?= json_encode($sosData) ?>'
            data-grat='<?= json_encode($gratData) ?>'></canvas>
        </div>
      </section>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>

<footer style="margin:40px 0 24px;text-align:center;color:#666;">
  Leadership Reflection® ver 0.9.0 © リーダーシップ開発研究所
</footer>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
document.querySelectorAll('canvas[id^="cv_"]').forEach(cv => {
  const labels = JSON.parse(cv.dataset.labels || '[]');
  const sos = JSON.parse(cv.dataset.sos || '[]');
  const grat = JSON.parse(cv.dataset.grat || '[]');

  new Chart(cv.getContext('2d'), {
    type: 'line',
    data: {
      labels,
      datasets: [
        { label: 'SOS',  data: sos,  borderColor: '#00695c', backgroundColor: 'rgba(0,105,92,.12)',  borderWidth: 2, tension: .3, pointRadius: 2 },
        { label: '感謝', data: grat, borderColor: '#26a69a', backgroundColor: 'rgba(38,166,154,.12)', borderWidth: 2, tension: .3, pointRadius: 2 }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: { x: { grid: { display:false } }, y: { beginAtZero:true, ticks: { stepSize: 1 } } },
      plugins: { legend: { display:false }, tooltip: { mode:'index', intersect:false } }
    }
  });
});
</script>
