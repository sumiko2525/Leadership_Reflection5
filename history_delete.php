<?php
// history_delete.php
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/funcs.php';

// CSRFチェック（funcs.phpに check_csrf_token() が無い場合は csrf_field() の検証処理を自作してください）
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: history.php');
  exit;
}

if (!isset($_POST['id']) || !ctype_digit($_POST['id'])) {
  header('Location: history.php');
  exit;
}

$id = (int)$_POST['id'];

try {
  $pdo = db_conn();
  $stmt = $pdo->prepare("UPDATE sos_requests SET is_deleted = 1 WHERE id = :id");
  $stmt->bindValue(':id', $id, PDO::PARAM_INT);
  $stmt->execute();
} catch (PDOException $e) {
  // 失敗しても履歴に戻す（必要ならメッセージ表示など）
}

header('Location: history.php');
exit;
