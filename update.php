<?php
session_start();            // セッション開始（ログイン状態を確認）
require_once('funcs.php');  // 共通関数ファイルを読み込む
loginCheck();               // ログインしていない場合は強制終了

require_once('db_connect.php'); // DB接続（$pdo）

// POSTデータの取得とバリデーション
$id = isset($_POST['id']) ? intval($_POST['id']) : null;
$log_date = $_POST['log_date'] ?? '';
$title = $_POST['title'] ?? '';
$reflection = $_POST['reflection'] ?? '';
$energy_level = $_POST['energy_level'] ?? '';
$trust_level = $_POST['trust_level'] ?? '';
$learning = $_POST['learning'] ?? '';
$next_action = $_POST['next_action'] ?? '';
$emotion = $_POST['emotion'] ?? '';

// 必須チェック
if (!$id || !$log_date || !$title || !$reflection || $energy_level === '' || $trust_level === '') {
    exit('必須項目が入力されていません');
}

// ログインユーザーIDを取得して、本人の記録だけを更新対象にする
$user_id = $_SESSION['user_id'];

// SQL文：対象IDかつ自分の記録のみ更新
$sql = "UPDATE leadership_note
        SET log_date = ?, title = ?, reflection = ?, energy_level = ?, trust_level = ?, learning = ?, next_action = ?, emotion = ?, updated_at = NOW()
        WHERE id = ? AND user_id = ? AND deleted = 0";

$stmt = $pdo->prepare($sql);

// 実行
$stmt->execute([
    $log_date,
    $title,
    $reflection,
    $energy_level,
    $trust_level,
    $learning,
    $next_action,
    $emotion,
    $id,
    $user_id
]);

// 一覧ページにリダイレクト
header('Location: view.php');
exit;
