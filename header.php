<?php
// header.php — 固定ヘッダー / 濃ティールグラデ / 役割バッジ / 中央ナビ（管理者・リーダーは追加メニュー）/ 右端ハンバーガー
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/funcs.php';

$me = current_user();
$isLoggedIn = !empty($me['id']);
$role = $me['role'] ?? 'guest';
$teamName = '';
$displayName = '';

if ($isLoggedIn) {
  try {
    $pdo = db_conn();
    // チーム名
    if (!empty($me['team_id'])) {
      $st = $pdo->prepare('SELECT name FROM teams WHERE id=:tid');
      $st->execute([':tid' => $me['team_id']]);
      $teamName = (string)($st->fetchColumn() ?: '');
    }
    // 表示名（display_name優先 → email → User#id）
    $su = $pdo->prepare('SELECT COALESCE(NULLIF(display_name,""), email) FROM users WHERE id=:id');
    $su->execute([':id' => $me['id']]);
    $displayName = (string)($su->fetchColumn() ?: ('User#'.$me['id']));
  } catch (Throwable $e) { /* 表示優先 */ }
}

// 現在ページのアクティブ表示用（下線＋太字）
$here = basename($_SERVER['SCRIPT_NAME'] ?? '');
function nav_active(string $file): string {
  global $here;
  return $here === $file ? 'style="text-decoration:underline;font-weight:bold;"' : '';
}
?>
<style>
  :root{
    --hdh: 76px;             /* ヘッダー全体高 */
    --hd-top: 48px;          /* 上段（ブランド＋状態） */
    --hd-nav: 28px;          /* 下段（ナビ） */
    --text:#f0fdf4;
    --muted:#cceae4;
    --line:rgba(255,255,255,.18);
    --brand:#0f766e; --brand-600:#0d9488;
  }
  body{ padding-top: var(--hdh); }

  .app-hd{
    position: fixed; inset: 0 0 auto 0; height: var(--hdh); z-index: 1000;
    background: linear-gradient(135deg, #0b5f58 0%, #0d9488 60%, #14b8a6 100%);
    color: var(--text); border-bottom: 1px solid var(--line);
  }
  .hd-wrap{
    max-width: 1100px; margin: 0 auto; height: 100%;
    display: grid; grid-template-rows: var(--hd-top) var(--hd-nav);
    padding: 0 16px;
  }

  .hd-top{ display:flex; align-items:center; gap:12px; }
  .brand{ font-weight: 800; letter-spacing: .02em; font-size: 1.12rem; }
  .brand a{ color:#fff; text-decoration:none; }

  .right{ margin-left:auto; display:flex; align-items:center; gap:10px; }
  .badge{
    display:inline-block; padding:2px 8px; border-radius:999px;
    border:1px solid rgba(255,255,255,.35); color:#fff; font-size:12px; white-space:nowrap;
  }
  .btn-out{
    padding:6px 10px; border-radius:10px; border:1px solid var(--line);
    background: rgba(255,255,255,.06); color:#fff; cursor:pointer;
  }
  .btn-out:hover{ background: rgba(255,255,255,.12); }

  .menu-btn{
    margin-left:6px;
    display:inline-flex; align-items:center; justify-content:center;
    width:42px; height:42px; border-radius:10px; border:1px solid var(--line);
    background: rgba(255,255,255,.06); color:#fff; cursor:pointer;
  }
  .menu-btn:hover{ background: rgba(255,255,255,.12); }
  .menu-btn span{ display:block; width:18px; height:2px; background:#fff;
                  box-shadow:0 6px 0 #fff, 0 -6px 0 #fff; }

  .quicknav{
    display:flex; align-items:center; justify-content:center; gap:16px;
    border-top: 1px solid var(--line);
  }
  .quicknav a{
    color:#ffffff; text-decoration:none; font-size:.92rem; padding:4px 8px; border-radius:8px;
    border:1px solid transparent;
  }
  .quicknav a:hover{ background: rgba(255,255,255,.09); border-color: var(--line); }

  .menu{
    position: fixed; top: var(--hdh); right: 12px; width: min(92vw, 320px);
    background: #ffffff; color: #0f172a;
    border: 1px solid #e5e7eb; border-radius: 14px;
    box-shadow: 0 14px 40px rgba(2,6,23,.28);
    display: none;
  }
  .menu.open{ display:block; }
  .menu ul{ list-style:none; margin:8px; padding:6px; }
  .menu a{
    display:block; padding:10px 12px; border-radius:10px; text-decoration:none;
    color:#0f172a; border:1px solid transparent;
  }
  .menu a:hover{ background:#f1f5f9; border-color:#e2e8f0; }
  .menu .sect{ padding:8px 12px; color:#64748b; font-size:12px; }

  @media (max-width: 860px){ .right .badge, .right form { display:none; } }
</style>

<header class="app-hd" role="banner">
  <div class="hd-wrap">
    <!-- 上段：ブランド＋状態 -->
    <div class="hd-top">
      <div class="brand"><a href="dashboard.php">Micro Team Coach</a></div>

      <div class="right" aria-label="現在の状態とメニュー">
        <?php if ($isLoggedIn): ?>
          <span class="badge"><?= h($displayName) ?></span>
          <?php if ($teamName || !empty($me['team_id'])): ?>
            <span class="badge"><?= h($teamName ?: ('Team #'.$me['team_id'])) ?></span>
          <?php endif; ?>
          <span class="badge">
            <?= ($role==='admin' ? '管理者' : ($role==='leader' ? 'リーダー' : 'メンバー')) ?>
          </span>
          <form method="post" action="logout.php" style="margin:0">
            <?= csrf_field() ?>
            <button class="btn-out" type="submit">ログアウト</button>
          </form>
        <?php else: ?>
          <a href="login.php" class="badge" style="text-decoration:none;">ログイン</a>
          <a href="signup.php?new=1" class="badge" style="text-decoration:none;">サインアップ</a>
        <?php endif; ?>

        <!-- 右端ハンバーガー -->
        <button class="menu-btn" id="menuBtn" aria-controls="appMenu" aria-expanded="false" aria-label="メニューを開く">
          <span aria-hidden="true"></span>
        </button>
      </div>
    </div>

    <!-- 下段：中央ナビ（管理者・リーダーで項目追加） -->
    <nav class="quicknav" aria-label="グローバルナビゲーション">
      <a href="dashboard.php" <?=nav_active('dashboard.php')?>>ダッシュボード</a>
      <a href="daily.php" <?=nav_active('daily.php')?>>Checkin</a>
      <a href="checkout.php" <?=nav_active('checkout.php')?>>Checkout</a>
      <a href="sos.php" <?=nav_active('sos.php')?>>Quick SOS</a>
      <a href="thanks.php" <?=nav_active('thanks.php')?>>感謝ログ</a>
      <a href="history.php" <?=nav_active('history.php')?>>SOS履歴</a>
      <?php if (in_array($role, ['admin','leader'])): ?>
        <a href="team_week.php" <?=nav_active('team_week.php')?>>活動ログ一覧</a>
        <a href="team_trends.php" <?=nav_active('team_trends.php')?>>チーム推移グラフ</a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<!-- ドロップダウンメニュー（ハンバーガー：スマホ/補助導線） -->
<nav id="appMenu" class="menu" role="navigation" aria-label="メインメニュー">
  <div class="sect">メニュー</div>
  <ul>
    <li><a href="dashboard.php" <?=nav_active('dashboard.php')?>>ダッシュボード</a></li>
    <li><a href="daily.php" <?=nav_active('daily.php')?>>Checkin</a></li>
    <li><a href="checkout.php" <?=nav_active('checkout.php')?>>Checkout</a></li>
    <li><a href="sos.php" <?=nav_active('sos.php')?>>Quick SOS</a></li>
    <li><a href="thanks.php" <?=nav_active('thanks.php')?>>感謝ログ</a></li>
    <li><a href="history.php" <?=nav_active('history.php')?>>SOS履歴</a></li>

    <?php if (in_array($role, ['admin','leader'])): ?>
      <li class="sect">チーム分析</li>
      <li><a href="team_week.php" <?=nav_active('team_week.php')?>>活動ログ一覧</a></li>
      <li><a href="team_trends.php" <?=nav_active('team_trends.php')?>>チーム推移グラフ</a></li>
    <?php endif; ?>
  </ul>

  <?php if ($isLoggedIn): ?>
    <div class="sect">現在のユーザー</div>
    <ul>
      <li><a href="javascript:void(0)"><?= h($displayName) ?></a></li>
      <li><a href="javascript:void(0)"><?= h($teamName ?: ('Team #'.$me['team_id'])) ?> / <?= ($role==='admin'?'管理者':($role==='leader'?'リーダー':'メンバー')) ?></a></li>
      <li>
        <form method="post" action="logout.php" style="margin:6px 12px 12px">
          <?= csrf_field() ?>
          <button class="btn-out" type="submit" style="width:100%; color:#0f172a">ログアウト</button>
        </form>
      </li>
    </ul>
  <?php else: ?>
    <ul>
      <li><a href="login.php">ログイン</a></li>
      <li><a href="signup.php?new=1">サインアップ</a></li>
    </ul>
  <?php endif; ?>
</nav>

<script>
  (function(){
    const btn  = document.getElementById('menuBtn');
    const menu = document.getElementById('appMenu');
    const toggle = () => {
      const opened = menu.classList.toggle('open');
      btn.setAttribute('aria-expanded', opened ? 'true':'false');
    };
    btn.addEventListener('click', toggle);
    document.addEventListener('click', (e)=>{
      if (!menu.classList.contains('open')) return;
      const within = menu.contains(e.target) || btn.contains(e.target);
      if (!within) { menu.classList.remove('open'); btn.setAttribute('aria-expanded','false'); }
    });
    document.addEventListener('keydown', (e)=>{
      if (e.key === 'Escape'){ menu.classList.remove('open'); btn.setAttribute('aria-expanded','false'); }
    });
  })();
</script>
