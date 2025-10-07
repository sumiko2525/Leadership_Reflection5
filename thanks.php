<?php
// thanks.php（チームの感謝履歴を表示 / g1,g2,g3 を統合）
// ★要件：team_idで必ず絞る / 発言者名を表示 / 新しい順
declare(strict_types=1);

require_once __DIR__ . '/funcs.php';
team_required();                 // ★未ログイン/未所属を遮断
$me  = current_user();
$pdo = db_conn();

// ★g1/g2/g3 を「縦持ち」にして1本のmessage列に統合 → 外側で team_id を絞る
$sql = "
  SELECT X.created_at,
         X.message,
         COALESCE(NULLIF(u.display_name,''), u.email) AS author
  FROM (
    SELECT team_id, user_id, created_at, g1 AS message
      FROM checkout_log
      WHERE g1 IS NOT NULL AND g1 <> ''
    UNION ALL
    SELECT team_id, user_id, created_at, g2
      FROM checkout_log
      WHERE g2 IS NOT NULL AND g2 <> ''
    UNION ALL
    SELECT team_id, user_id, created_at, g3
      FROM checkout_log
      WHERE g3 IS NOT NULL AND g3 <> ''
  ) AS X
  JOIN users u ON u.id = X.user_id
  WHERE X.team_id = :tid                -- ★チーム境界ガード
  ORDER BY X.created_at DESC
  LIMIT 200
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':tid' => $me['team_id']]);
$rows = $stmt->fetchAll();

include __DIR__ . '/header.php';
?>
<main style="max-width:960px;margin:0 auto;padding:22px;">
  <h1 style="margin:0 0 10px;">Thanks（みんなの感謝）</h1>
  <p style="color:#555;margin:0 0 14px;">チェックアウトで投稿された感謝（g1/g2/g3）を最新順に表示します。</p>

  <style>
    .thanks-grid{ display:grid; gap:12px; grid-template-columns:1fr; }
    .t-card{
      border:1px solid #e5e7eb; border-radius:12px; background:#fff; padding:12px;
      box-shadow: 0 2px 8px rgba(2,6,23,.04);
    }
    .t-meta{ color:#64748b; font-size:12px; margin-bottom:6px; display:flex; gap:8px; flex-wrap:wrap; }
    .t-msg{ font-size:16px; line-height:1.6; white-space:pre-wrap; word-break:break-word; }
    .empty{ border:1px dashed #cbd5e1; border-radius:12px; padding:14px; color:#64748b; background:#f8fafc; }
  </style>

  <?php if (!$rows): ?>
    <div class="empty">まだ感謝の投稿がありません。チェックアウトで「感謝」を記録するとここに表示されます。</div>
  <?php else: ?>
    <section class="thanks-grid" aria-label="感謝の履歴">
      <?php foreach ($rows as $r): ?>
        <article class="t-card">
          <div class="t-meta">
            <span><?= h($r['author']) ?></span>
            <span>｜</span>
            <time datetime="<?= h($r['created_at']) ?>"><?= h($r['created_at']) ?></time>
          </div>
          <div class="t-msg"><?= h($r['message']) ?></div>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</main>
<?php include __DIR__ . '/footer.php'; ?>
