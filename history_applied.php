<?php
// history.php（手順I適用・★注釈付き 完成版 / 2025-10-06）
require_once __DIR__ . '/funcs.php';
team_required();               // ★追加
$me  = current_user();         // ★追加
$pdo = db_conn();

// フィルタ（期間・キーワード）
$from = $_GET['from'] ?? '';
$to   = $_GET['to']   ?? '';
$q    = trim((string)($_GET['q'] ?? ''));

// 検索SQL（team_idは必須条件）
$wheres = ['team_id = :tid'];                  // ★追加：ベース条件
$params = [':tid' => $me['team_id']];

if ($from !== '') { $wheres[] = 'DATE(created_at) >= :from'; $params[':from'] = $from; }
if ($to   !== '') { $wheres[] = 'DATE(created_at) <= :to';   $params[':to']   = $to; }
if ($q    !== '') { $wheres[] = 'message LIKE :q';           $params[':q']    = '%'.$q.'%'; }

$sql = 'SELECT id, message, user_id, created_at
        FROM sos_requests
        WHERE ' . implode(' AND ', $wheres) . '
        ORDER BY created_at DESC
        LIMIT 200';                               // 適宜ページング
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$list = $stmt->fetchAll();

include __DIR__ . '/header.php';
?>
<main style="max-width:900px;margin:0 auto;padding:22px;">
  <h1 style="margin:0 0 10px;">🧭 SOS履歴</h1>
  <p style="color:#555;margin:0 0 14px;">チーム内のSOSを検索できます（キーワード・期間）。</p>

  <form method="get" class="card" style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff;margin-bottom:18px;">
    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:end;">
      <label>開始日<br><input type="date" name="from" value="<?= h($from) ?>"></label>
      <label>終了日<br><input type="date" name="to" value="<?= h($to) ?>"></label>
      <label>キーワード<br><input type="text" name="q" value="<?= h($q) ?>"></label>
      <button type="submit" style="padding:8px 14px;border:1px solid #d1d5db;border-radius:10px;background:#fff;">検索</button>
    </div>
  </form>

  <div class="card" style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff;">
    <table style="width:100%;border-collapse:collapse;">
      <thead><tr>
        <th style="text-align:left;padding:6px;border-bottom:1px solid #f3f4f6;">日時</th>
        <th>本文</th>
      </tr></thead>
      <tbody>
        <?php foreach($list as $r): ?>
          <tr>
            <td style="padding:6px;border-bottom:1px solid #f9fafb;"><?= h($r['created_at']) ?></td>
            <td><?= h($r['message']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>
<?php include __DIR__ . '/footer.php'; ?>
