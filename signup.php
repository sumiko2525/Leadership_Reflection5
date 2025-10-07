<?php
declare(strict_types=1);

// === 一時デバッグ（動いたら消してOK）=========================
ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);
// ============================================================

// funcs.php を場所ゆらぎ対応でロード
$__found = false;
foreach ([
  __DIR__ . '/funcs.php',
  __DIR__ . '/lib/funcs.php',
  dirname(__DIR__) . '/funcs.php',
  dirname(__DIR__) . '/lib/funcs.php',
] as $__p) {
  if (is_file($__p)) { require_once $__p; $__found = true; break; }
}
if (!$__found) { http_response_code(500); exit('funcs.php not found'); }

// 二重防止つきでセッション開始（funcs.php 側でも開始している想定）
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// フレッシュサインアップ：ログイン済み + ?new=1 なら一度ログアウトしてフォームへ
if (!empty($_SESSION['user_id']) && isset($_GET['new'])) {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
  }
  session_destroy();
  session_start();
}

// 誤タップ保護：ログイン済みで new=1 が無ければダッシュボードへ
if (!empty($_SESSION['user_id']) && !isset($_GET['new'])) {
  redirect('dashboard.php');
}

$pdo = db_conn();
session_regenerate_safe();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    $err = '不正なリクエストです。フォームを再読み込みしてください。';
  } else {
    // 「メール（またはユーザーID）」として扱う—@が無くても可
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    $name  = trim((string)($_POST['display_name'] ?? ''));
    $team  = trim((string)($_POST['team_name'] ?? ''));

    if ($email === '' || $pass === '' || $team === '') {
      $err = '「メール（またはユーザーID）」「パスワード」「チーム名」は必須です。';
    } elseif (mb_strlen($pass) < 6) {
      $err = 'パスワードは6文字以上でお願いします。';
    } else {
      try {
        $pdo->beginTransaction();

        // 1) users
        $stmt = $pdo->prepare('INSERT INTO users (email, pass_hash, display_name) VALUES (:e, :p, :n)');
        $stmt->execute([
          ':e' => $email,
          ':p' => password_hash($pass, PASSWORD_DEFAULT),
          ':n' => ($name !== '' ? $name : null),
        ]);
        $user_id = (int)$pdo->lastInsertId();

        // 2) teams + 招待コード発行（衝突時は再生成）
        $invite = null; $team_id = null;
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
          $invite = '';
          for ($i=0; $i<10; $i++) { $invite .= $chars[random_int(0, strlen($chars)-1)]; }
          $stmt = $pdo->prepare('INSERT INTO teams (name, invite_code) VALUES (:n, :c)');
          try {
            $stmt->execute([':n' => $team, ':c' => $invite]);
            $team_id = (int)$pdo->lastInsertId();
            break;
          } catch (Throwable $e) {
            // 1062 duplicate → 招待コード作り直し
            if (strpos($e->getMessage(), '1062') === false) { throw $e; }
          }
        } while (true);

        // 3) team_members（adminとして参加）
        $stmt = $pdo->prepare('INSERT INTO team_members (team_id, user_id, role) VALUES (:t, :u, :r)');
        $stmt->execute([':t' => $team_id, ':u' => $user_id, ':r' => 'admin']);

        $pdo->commit();

        // 4) セッション確定 → ダッシュボードへ
        session_set_identity($user_id, $team_id, 'admin');
        redirect('dashboard.php');
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (strpos($e->getMessage(), '1062') !== false) {
          $err = 'そのメール（またはユーザーID）は既に登録されています。ログインをお試しください。';
        } else {
          error_log('[SIGNUP] '.$e->getMessage());
          $err = '登録に失敗しました。時間をおいて再度お試しください。';
        }
      }
    }
  }
}
$csrf = csrf_token();
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>サインアップ</title>
<style>
  :root{
    --bg:#ecfdf5; --card:#ffffff; --text:#0f172a; --muted:#64748b;
    --brand:#0f766e; --brand-600:#0d9488; --line:#e2e8f0; --input:#eef2ff;
  }
  *{box-sizing:border-box} html,body{height:100%}
  body{
    margin:0; background:var(--bg); color:var(--text);
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Hiragino Sans", "Noto Sans JP", "Yu Gothic", sans-serif;
    display:flex; align-items:center; justify-content:center; padding:24px;
  }
  .card{
    width:min(600px, 94vw);
    background:var(--card); border:1px solid var(--line); border-radius:18px;
    box-shadow: 0 10px 30px rgba(15,23,42,.08);
    padding:28px;
  }
  h1{margin:4px 0 12px; font-size:28px; text-align:center; color:var(--brand);}
  p.sub{margin:0 0 18px; text-align:center; color:var(--muted)}
  .grid{display:grid; grid-template-columns:1fr 1fr; gap:12px}
  @media (max-width:760px){ .grid{grid-template-columns:1fr} }
  .field{margin:0 0 12px}
  .label{display:block; font-size:14px; color:var(--muted); margin:0 0 6px}
  .input{
    width:100%; padding:12px 14px; border-radius:10px;
    border:1.5px solid var(--line); background:var(--input); outline:none; font-size:16px;
  }
  .input:focus{border-color:var(--brand-600); background:#fff; box-shadow:0 0 0 3px rgba(13,148,136,.12)}
  .btn{
    width:100%; padding:12px 14px; border:none; border-radius:10px;
    font-weight:700; font-size:16px; letter-spacing:.05em;
    background:var(--brand); color:#fff; cursor:pointer;
    box-shadow:0 6px 18px rgba(13,148,136,.24);
    transition:transform .06s ease, background .15s ease;
  }
  .btn:hover{background:var(--brand-600); transform:translateY(1px)}
  .err{
    margin:0 0 14px; padding:10px 12px; border-radius:10px;
    color:#b91c1c; background:#fee2e2; border:1px solid #fecaca;
  }
  .hint{margin:14px 0 0; text-align:center; color:var(--muted); font-size:14px}
  .hint a{color:var(--brand-600); text-decoration:none; border-bottom:1px dashed rgba(13,148,136,.4)}
  .hint a:hover{border-bottom-color:transparent}
</style>
</head>
<body>
  <form class="card" method="post" autocomplete="on" novalidate>
    <h1>サインアップ</h1>
    <p class="sub">新規チームを作成して、あなたが管理者（admin）としてはじめます。</p>

    <?php if ($err): ?><div class="err"><?=h($err)?></div><?php endif; ?>
    <input type="hidden" name="csrf_token" value="<?=h($csrf)?>">

    <div class="field">
      <label class="label" for="email">メール（またはユーザーID）</label>
      <input class="input" id="email" name="email" type="text"
             placeholder="name@example.com もしくは member1" required>
    </div>

    <div class="grid">
      <div class="field">
        <label class="label" for="password">パスワード（6文字以上）</label>
        <input class="input" id="password" name="password" type="password" placeholder="••••••••" required>
      </div>
      <div class="field">
        <label class="label" for="display_name">表示名（任意）</label>
        <input class="input" id="display_name" name="display_name" type="text" placeholder="例：山田 太郎">
      </div>
    </div>

    <div class="field">
      <label class="label" for="team_name">チーム名</label>
      <input class="input" id="team_name" name="team_name" type="text" placeholder="例：プロジェクトAチーム" required>
    </div>

    <div class="field" style="margin-top:6px">
      <button class="btn" type="submit">作成してはじめる</button>
    </div>

    <p class="hint">アカウントをお持ちですか？ <a href="login.php">ログイン</a></p>
  </form>
</body>
</html>
