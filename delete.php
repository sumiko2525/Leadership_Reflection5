<?php
// セッションを開始（ログイン状態の確認のため）
session_start();

// 共通関数を読み込み（loginCheck, db_conn などが使えるようになる）
require_once('funcs.php');

// ログインしているか確認（してなければエラーで止まる）
loginCheck();

// DB接続
$pdo = db_conn();

// フォームから送られてきた削除対象のIDリストが存在するか確認
if (isset($_POST['delete_ids']) && is_array($_POST['delete_ids'])) {
    $delete_ids = $_POST['delete_ids']; // 配列で受け取る（例： [3, 5, 7]）

    // 受け取ったIDの数だけ「?」を準備（例：?,?,?）
    $placeholders = implode(',', array_fill(0, count($delete_ids), '?'));

    // 論理削除用のSQL（deleted = 1 にする）
    $sql = "UPDATE leadership_note SET deleted = 1 WHERE id IN ($placeholders)";

    // SQLを準備して実行
    $stmt = $pdo->prepare($sql);
    $stmt->execute($delete_ids);
}

// 削除後は一覧ページ（view.php）へリダイレクト
header("Location: view.php");
exit;
