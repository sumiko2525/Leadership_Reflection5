<?php
// daily_stats.php（手順I適用・★注釈付き 完成版 / 2025-10-06）
require_once __DIR__ . '/funcs.php';
team_required();               // ★追加
$me  = current_user();         // ★追加
$pdo = db_conn();

// 直近7日（チーム平均と件数）
$sql = "
  SELECT DATE(created_at) d,
         AVG(mood)      AS avg_mood,
         AVG(workload)  AS avg_workload,
         AVG(trust)     AS avg_trust,
         COUNT(*)       AS cnt
  FROM daily_check
  WHERE team_id = :tid        -- ★追加：team_id条件
    AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
  GROUP BY DATE(created_at)
  ORDER BY d ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':tid' => $me['team_id']]);
$rows = $stmt->fetchAll();

include __DIR__ . '/header.php';
?>
<main style="max-width:900px;margin:0 auto;padding:22px;">
  <h1 style="margin:0 0 10px;">📅 直近7日の詳細（チーム平均）</h1>
  <p style="color:#555;margin:0 0 14px;">各日はチーム内の入力の平均値（複数回ある場合は平均化）です。</p>

  <div class="card" style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff;">
    <table style="width:100%;border-collapse:collapse;">
      <thead>
        <tr>
          <th style="text-align:left;padding:6px;border-bottom:1px solid #f3f4f6;">日付</th>
          <th>平均 ご機嫌</th><th>平均 負荷</th><th>平均 信頼</th><th>件数</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td style="padding:6px;border-bottom:1px solid #f9fafb;"><?= h($r['d']) ?></td>
            <td><?= h(number_format((float)$r['avg_mood'], 2)) ?></td>
            <td><?= h(number_format((float)$r['avg_workload'], 2)) ?></td>
            <td><?= h(number_format((float)$r['avg_trust'], 2)) ?></td>
            <td><?= h((string)$r['cnt']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>
<?php include __DIR__ . '/footer.php'; ?>
