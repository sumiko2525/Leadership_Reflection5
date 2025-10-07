<?php
session_start(); // セッション開始
require_once('funcs.php'); // 共通関数読み込み

// POSTデータ取得
$lid = $_POST['lid'];
$lpw = $_POST['lpw'];

// DB接続
$pdo = db_conn();

// 入力されたユーザーIDが存在するか確認
$sql = 'SELECT * FROM lr_user WHERE lid = :lid AND life_flg = 0';
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':lid', $lid, PDO::PARAM_STR);
$stmt->execute();
$val = $stmt->fetch(); // 1件取得

// ユーザーが存在し、パスワードが一致するか確認
if ($val && password_verify($lpw, $val['lpw'])) {
    // ✅ ログイン成功
    session_regenerate_id(true); // セッションIDを再生成（セキュリティ対策）

    // ✅ セッション変数にユーザー情報を保存
    $_SESSION['chk_ssid']   = session_id();
    $_SESSION['user_id']    = $val['id'];
    $_SESSION['user_name']  = $val['name'];
    $_SESSION['kanri_flg']  = $val['kanri_flg'];

    // ✅ 管理者かどうかで遷移先を分岐
    if ($_SESSION['kanri_flg'] == 1) {
        header('Location: view.php');   // 管理者 → 全体の一覧ページ
    } else {
        header('Location: mypage.php'); // 一般ユーザー → 自分専用ページ
    }
    exit();

} else {
    // ❌ ログイン失敗
    echo 'ログインに失敗しました';
    echo '<br><a href="login.php">ログイン画面に戻る</a>';
}
