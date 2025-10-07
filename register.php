<?php
session_start();
require_once('funcs.php');

// 管理者のみが使えるページにするためのチェック
loginCheck();  // ログイン済みか確認
kanriCheck();  // 管理者か確認（kanri_flg === 1 か）

$error = '';

// POST送信されたときだけ処理を実行
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 入力データの取得
    $name = $_POST['name'] ?? '';
    $lid = $_POST['lid'] ?? '';
    $lpw = $_POST['lpw'] ?? '';
    $kanri_flg = $_POST['kanri_flg'] ?? 0;
    $life_flg = 0;

    // 入力バリデーション
    if ($name === '' || $lid === '' || $lpw === '') {
        $error = 'すべての項目を入力してください。';
    } else {
        // パスワードのハッシュ化
        $hashed_pw = password_hash($lpw, PASSWORD_DEFAULT);

        try {
            $pdo = db_conn();

            // 同じログインIDがすでに使われていないか確認
            $check_stmt = $pdo->prepare('SELECT COUNT(*) FROM lr_user WHERE lid = :lid');
            $check_stmt->bindValue(':lid', $lid, PDO::PARAM_STR);
            $check_stmt->execute();
            $count = $check_stmt->fetchColumn();

            if ($count > 0) {
                $error = 'このログインIDはすでに使われています。';
            } else {
                // 新しいユーザーの登録処理
                $stmt = $pdo->prepare('
                    INSERT INTO lr_user (name, lid, lpw, kanri_flg, life_flg)
                    VALUES (:name, :lid, :lpw, :kanri_flg, :life_flg)
                ');
                $stmt->bindValue(':name', $name);
                $stmt->bindValue(':lid', $lid);
                $stmt->bindValue(':lpw', $hashed_pw);
                $stmt->bindValue(':kanri_flg', $kanri_flg, PDO::PARAM_INT);
                $stmt->bindValue(':life_flg', $life_flg, PDO::PARAM_INT);
                $stmt->execute();

                // 登録後に一覧ページへ移動
                header('Location: view.php');
                exit();
            }
        } catch (PDOException $e) {
            $error = 'DBエラー: ' . htmlspecialchars($e->getMessage());
        }
    }
}
?>
