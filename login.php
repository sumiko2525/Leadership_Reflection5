<?php
declare(strict_types=1);
require_once __DIR__ . '/funcs.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$pdo = db_conn();
$err = '';

/** 同一オリジン判定（任意の保険） */
function is_same_origin(): bool {
  $ref = $_SERVER['HTTP_REFERER'] ?? '';
  $host = $_SERVER['HTTP_HOST'] ?? '';
  return $ref !== '' && stripos($ref, $host) !== false;
}

/** CSRF検証（セッション or ダブルサブミットCookieのどちらかを許可） */
function csrf_ok_for_login(): bool {
  $posted = $_POST['csrf_token'] ?? null;
  if ($posted && csrf_verify($posted)) return true; // 通常ルート
  // フォールバック：同一オリジン & Cookie一致ならOK
  $cookie = $_COOKIE['csrfl'] ?? '';
  return $posted && $cookie && hash_equals((string)$cookie, (string)$posted) && is_same_origin();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!csrf_ok_for_login()) {
      throw new RuntimeException('CSRF');
    }
    $idn  = trim((string)($_POST['identifier'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    if ($idn === '' || $pass === '') throw new RuntimeException('EMPTY');

    // HY093対策：プレースホルダは別名に
    $st = $pdo->prepare(
      'SELECT id, pass_hash FROM users
       WHERE email=:idn_email OR display_name=:idn_name LIMIT 1'
    );
    $st->execute([':idn_email'=>$idn, ':idn_name'=>$idn]);
    $u = $st->fetch();
    if (!$u || !password_verify($pass, $u['pass_hash'])) throw new RuntimeException('CRED');

    // 所属を一件取得（列依存なし）
    $m = $pdo->prepare('SELECT team_id, role FROM team_members WHERE user_id=:uid LIMIT 1');
    $m->execute([':uid'=>(int)$u['id']]);
    $tm = $m->fetch();
    if (!$tm) throw new RuntimeException('NO_TEAM');

    session_set_identity((int)$u['id'], (int)$tm['team_id'], (string)$tm['role']);
    redirect('dashboard.php');

  } catch (RuntimeException $ex) {
    $map = [
      'CSRF'  => 'セッションの有効期限が切れました。ページを再読み込みしてください。',
      'EMPTY' => 'ユーザーID（またはメール）とパスワードを入力してください。',
      'CRED'  => 'ユーザーID（またはメール）またはパスワードが違います。',
      'NO_TEAM' => 'チームに未所属です。管理者に連絡してください。',
    ];
    $err = $map[$ex->getMessage()] ?? 'ログインに失敗しました。時間をおいて再度お試しください。';
    if ($ex->getMessage() !== 'CRED') { error_log('[LOGIN ERROR] '.$ex->getMessage()); }
  } catch (Throwable $e) {
    error_log('[LOGIN FATAL] '.$e->getMessage());
    $err = 'ログインに失敗しました。時間をおいて再度お試しください。';
  }
}

// 表示用トークンの発行と Cookie 設定（SameSite=Laxで同一サイトPOSTのみ許可）
$csrf = csrf_token();
setcookie('csrfl', $csrf, [
  'expires'  => time()+3600,
  'path'     => '/',
  'secure'   => !empty($_SERVER['HTTPS']),
  'httponly' => false,     // double-submitはJS不要なのでfalseでもOK
  'samesite' => 'Lax',
]);
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ログイン</title>
<style>
  :root{ --bg:#ecfdf5; --card:#fff; --text:#0f172a; --muted:#64748b; --brand:#0f766e; --brand-600:#0d9488; --line:#e2e8f0; --input:#eef2ff; }
  *{box-sizing:border-box} html,body{height:100%}
  body{ margin:0; background:var(--bg); color:var(--text);
        font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Hiragino Sans","Noto Sans JP","Yu Gothic",sans-serif;
        display:flex; align-items:center; justify-content:center; padding:24px; }
  .card{ width:min(520px,92vw); background:var(--card); border:1px solid var(--line); border-radius:18px; box-shadow:0 10px 30px rgba(15,23,42,.08); padding:28px; }
  h1{ margin:4px 0 18px; font-size:28px; text-align:center; color:var(--brand); }
  .field{ margin:0 0 14px } .label{ display:block; font-size:14px; color:var(--muted); margin:0 0 6px }
  .input{ width:100%; padding:12px 14px; border-radius:10px; border:1.5px solid var(--line); background:var(--input); outline:none; font-size:16px; }
  .input:focus{ border-color:var(--brand-600); background:#fff; box-shadow:0 0 0 3px rgba(13,148,136,.12) }
  .btn{ width:100%; padding:12px 14px; border:none; border-radius:10px; font-weight:700; font-size:16px; letter-spacing:.05em; background:var(--brand); color:#fff; cursor:pointer; box-shadow:0 6px 18px rgba(13,148,136,.24); }
  .btn:hover{ background:var(--brand-600) }
  .err{ margin:0 0 14px; padding:10px 12px; border-radius:10px; color:#b91c1c; background:#fee2e2; border:1px solid #fecaca }
  .hint{ margin:14px 0 0; text-align:center; color:var(--muted); font-size:14px }
  .hint a{ color:var(--brand-600); text-decoration:none; border-bottom:1px dashed rgba(13,148,136,.4) }
</style>
</head>
<body>
  <form class="card" method="post" autocomplete="off" novalidate>
    <h1>ログイン</h1>
    <?php if ($err): ?><div class="err"><?=h($err)?></div><?php endif; ?>
    <input type="hidden" name="csrf_token" value="<?=h($csrf)?>">
    <div class="field">
      <label class="label" for="identifier">ユーザーID（またはメール）</label>
      <input class="input" id="identifier" name="identifier" type="text" placeholder="admin1 もしくは name@example.com" required autofocus>
    </div>
    <div class="field">
      <label class="label" for="password">パスワード</label>
      <input class="input" id="password" name="password" type="password" placeholder="••••••••" required>
    </div>
    <button class="btn" type="submit">ログイン</button>
    <p class="hint"><a href="index.php">トップページに戻る</a> ／ <a href="signup.php?new=1">はじめての方（サインアップ）</a></p>
  </form>
</body>
</html>
