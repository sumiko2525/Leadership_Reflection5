<?php
declare(strict_types=1);
require_once __DIR__ . '/funcs.php';
team_required();
include __DIR__ . '/header.php';
?>
<main style="max-width:720px;margin:0 auto;padding:24px;text-align:center;">
  <h1 style="margin:0 0 10px;">お疲れさまでした！</h1>
  <p style="color:#555;margin:0 0 18px;">感謝と振り返りを保存しました。</p>
  <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
    <a href="thanks.php" class="btn" style="background:#0f766e;color:#fff;padding:10px 16px;border-radius:10px;text-decoration:none;">みんなのThanksを見る</a>
    <a href="dashboard.php" class="btn" style="background:#334155;color:#fff;padding:10px 16px;border-radius:10px;text-decoration:none;">ダッシュボードへ</a>
  </div>
</main>
<?php include __DIR__ . '/footer.php'; ?>
