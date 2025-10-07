<?php
declare(strict_types=1);
require_once __DIR__ . '/funcs.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify($_POST['csrf_token'] ?? null)) {
  // 不正 or 直接アクセス → トップへ
  redirect('login.php');
}

// セッション完全破棄
$_SESSION = [];
if (ini_get('session.use_cookies')) {
  $p = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

// 戻る
redirect('login.php');
