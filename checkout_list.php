<?php
declare(strict_types=1);
require_once __DIR__ . '/funcs.php';
team_required();                    // ★ 追加：未ログイン/未所属は入れない
$me  = current_user();
$pdo = db_conn();

// ★ 変更：team_idで絞る + 発言者名も取得
$sql = "
  SELECT
    cl.id,
    cl.g1, cl.g2, cl.g3,
    cl.self_compassion,
    cl.meditated_min,
    cl.created_at,
    COALESCE(NULLIF(u.display_name,''), u.email) AS author
  FROM checkout_log cl
  JOIN users u ON u.id = cl.user_id
  WHERE cl.team_id = :tid                      -- ★ チーム境界ガード
  ORDER BY cl.created_at DESC
  LIMIT 50
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':tid' => $me['team_id']]);
$rows = $stmt->fetchAll();

include __DIR__ . '/header.php';
?>
<main style="max-width:900px;margin:0 auto;padding:24px;">
  <h1 style="margin:0 0 10px;">🙏 チェックアウト履歴（最新50件）</h1>

  <style>
    .tbl{ width:100%; border-collapse:collapse;}
    .tbl th, .tbl td{ padding:8px; border-bottom:1px solid #e5e7eb; vertical-align:top; }
    .muted{ color:#64748b; font-size:12px; }
  </style>

  <table class="tbl">
    <thead>
      <tr>
        <th style="white-space:nowrap;">日時</th>
        <th>感謝</th>
        <th>セルフ</th>
        <th style="text-align:center;white-space:nowrap;">瞑想(分)</th>
        <th style="white-space:nowrap;">発言者</th> <!-- ★ 追加 -->
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td style="white-space:nowrap;"><?= h($r['created_at']) ?></td>
          <td>
            <?= h($r['g1']) ?>
            <?php if (!empty($r['g2'])): ?><div class="muted">/ <?= h($r['g2']) ?></div><?php endif; ?>
            <?php if (!empty($r['g3'])): ?><div class="muted">/ <?= h($r['g3']) ?></div><?php endif; ?>
          </td>
          <td><?= h($r['self_compassion'] ?? '') ?></td>
          <td style="text-align:center;"><?= h((string)$r['meditated_min']) ?></td>
          <td><?= h($r['author']) ?></td> <!-- ★ 追加 -->
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</main>
<?php include __DIR__ . '/footer.php'; ?>
