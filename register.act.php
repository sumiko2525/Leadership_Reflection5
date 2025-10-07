<?php
// セッション開始・関数読み込み
session_start();
require_once('funcs.php');

// ログインチェック（未ログインなら終了）
loginCheck();

// 管理者かどうかチェック（kanri_flgが1以外なら終了）
if ($_SESSION['kanri_flg'] != 1) {
    exit('管理者のみ登録できます');
}

// POSTデータの受け取りとバリデーション
$name       = $_POST['name'] ?? '';
$lid        = $_POST['lid'] ?? '';
$lpw        = $_POST['lpw'] ?? '';
$kanri_flg  = isset($_POST['kanri_flg']) ? intval($_POST['kanri_flg']) : 0;
$life_flg   = isset($_POST['life_flg']) ? intval($_POST['life_flg']) : 0;

// 必須項目チェック
if ($name === '' || $lid === '' || $lpw === '') {
    exit('入力項目が不足しています');
}

// パスワードをハッシュ化
$hashed_lpw = password_hash($lpw, PASSWORD_DEFAULT);

// DB接続
$pdo = db_conn();

// SQL準備
$sql = "INSERT INTO lr_user(name, lid, lpw, kanri_flg, life_flg)
        VALUES(:name, :lid, :lpw, :kanri_flg, :life_flg)";
$stmt = $pdo->prepare($sql);

// 値をバインド
$stmt->bindValue(':name', $name, PDO::PARAM_STR);
$stmt->bindValue(':lid', $lid, PDO::PARAM_STR);
$stmt->bindValue(':lpw', $hashed_lpw, PDO::PARAM_STR);
$stmt->bindValue(':kanri_flg', $kanri_flg, PDO::PARAM_INT);
$stmt->bindValue(':life_flg', $life_flg, PDO::PARAM_INT);

// SQL実行
$status = $stmt->execute();

// 実行結果で分岐
if ($status === false) {
    $error = $stmt->errorInfo();
    exit('登録エラー: ' . $error[2]);
} else {
    header('Location: view.php'); // 一覧ページなどに戻す
    exit();
}
