<?php
// header.php — 固定ヘッダー / 濃ティールグラデ / 右端ハンバーガー / 状態表示
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/funcs.php';

$me = current_user();
$isLoggedIn = !empty($me['id']);
$teamName = ''; $displayName = '';

if ($isLoggedIn) {
  try {
    $pdo = db_conn();
    $st = $pdo->prepare('SELECT name FROM teams WHERE id=:tid');
    $st->execute([':tid' => $me['team_id']]);
    $teamName = (string)($st->fetchColumn() ?: '');
    $su = $pdo->prepare('SELECT COALESCE(NULLIF(display_name,""), email) FROM users WHERE id=:id');
    $su->execute([':id' => $me['id']]);
    $displayName = (string)($su->fetchColumn() ?: ('User#'.$me['id']));
  } catch (Throwable $e) { /* 表示優先 */ }
}
?>
<style>
  :root{
    --hdh: 64px;
    --text:#f0fdf4;
    --muted:#cceae4;
    --line:rgba(255,255,255,.18);
    --brand:#0f766e; --brand-600:#0d9488;
  }
  /* 本文が隠れないように */
  body{ padding-top: var(--hdh); }

  .app-hd{
    position: fixed; inset: 0 0 auto 0; height: var(--hdh); z-index: 1000;
    background: linear-gradient(135deg, #0b5f58 0%, #0d9488 60%, #14b8a6 100%);
    color: var(--text); border-bottom: 1px solid var(--line);
  }
  .hd-wrap{
    max-width: 1100px; margin: 0 auto; height: 100%;
    display: flex; align-items: center; gap: 12px;
    padding: 0 16px;
  }
  .brand{ font-weight: 800; letter-spacing: .02em; }
  .brand a{ color:#fff; text-decoration:none; }

  /* 右側ブロック：ユーザー情報 + 右端ハンバーガー */
  .right{ margin-left:auto; display:flex; align-items:center; gap:10px; }
  .badge{
    display:inline-block; padding:2px 8px; border-radius:999px;
    border:1px solid rgba(255,255,255,.35); color:#fff; font-size:12px;
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

  /* メニュー本体（右上ドロップ） */
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

  /* 狭い幅では右の情報をメニュー内に移す想定で隠す */
  @media (max-width: 860px){ .right .badge, .right form { display:none; } }
</style>

<header class="app-hd" role="banner">
  <div class="hd-wrap">
    <!-- 左上：ブランド名 -->
    <div class="brand"><a href="dashboard.php">Micro Team Coach</a></div>

    <!-- 右端：ユーザー状態 → 最後にハンバーガー -->
    <div class="right" aria-label="現在の状態とメニュー">
      <?php if ($isLoggedIn): ?>
        <span class="badge"><?= h($displayName) ?></span>
        <span class="badge"><?= h($teamName ?: ('Team #'.$me['team_id'])) ?></span>
        <span class="badge"><?= h($me['role']) ?></span>
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
</header>

<!-- ドロップダウンメニュー -->
<nav id="appMenu" class="menu" role="navigation" aria-label="メインメニュー">
  <div class="sect">メニュー</div>
  <ul>
    <li><a href="dashboard.php">Dashboard</a></li>
    <li><a href="daily.php">Checkin</a></li>
    <li><a href="checkout.php">Checkout</a></li>
    <li><a href="sos.php">QuickSOS</a></li>
    <li><a href="thanks.php">Thanks history</a></li>
    <li><a href="history.php">SOS history</a></li>
  </ul>

  <?php if ($isLoggedIn): ?>
    <div class="sect">現在のユーザー</div>
    <ul>
      <li><a href="javascript:void(0)"><?= h($displayName) ?></a></li>
      <li><a href="javascript:void(0)"><?= h($teamName ?: ('Team #'.$me['team_id'])) ?> / <?= h($me['role']) ?></a></li>
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
