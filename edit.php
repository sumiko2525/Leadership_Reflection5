<?php
// セッション開始（ログイン状態を管理）
session_start();

// 共通関数の読み込み（loginCheck や db_conn を使うため）
require_once('funcs.php');

// ログインしているかチェック（未ログインなら「LOGIN ERROR」で終了）
loginCheck();

// DB接続（funcs.phpのdb_conn()を使用）
$pdo = db_conn();

// 編集対象のIDが指定されていなければエラー表示
if (!isset($_GET['id'])) {
    exit('IDが指定されていません');
}

// GETで受け取ったIDを整数化して変数に代入
$id = intval($_GET['id']);

// ログインユーザーのID（自分のデータだけ編集させるために使用）
$user_id = $_SESSION['user_id'];

// 該当IDのデータを取得（ログインユーザー自身の記録で、削除されていないものに限定）
$sql = "SELECT * FROM leadership_note WHERE id = ? AND user_id = ? AND deleted = 0";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id, $user_id]);
$note = $stmt->fetch(PDO::FETCH_ASSOC);

// データが存在しない場合（他人の記録 or 削除済など）はエラー表示
if (!$note) {
    exit('データが見つかりません');
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>Leadership Note 編集</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        /* デザイン部分（略） */
        body {
            font-family: "Hiragino Kaku Gothic ProN", sans-serif;
            background-color: #f0f9f8;
            padding: 2rem;
            color: #333;
        }
        .container {
            max-width: 600px;
            margin: auto;
            background: #fff;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 4px 10px rgba(0, 150, 136, 0.2);
        }
        h1 {
            text-align: center;
            color: #00796b;
            font-size: 1.8rem;
        }
        form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        label {
            font-weight: bold;
        }
        input[type="text"],
        input[type="date"],
        input[type="number"],
        textarea {
            width: 100%;
            padding: 10px;
            font-size: 1rem;
            border: 1px solid #ccc;
            border-radius: 6px;
        }
        textarea {
            resize: vertical;
        }
        button {
            background-color: #009688;
            color: white;
            padding: 12px;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            cursor: pointer;
        }
        button:hover {
            background-color: #00796b;
        }
    </style>
</head>
<body>
  <div class="container">
    <h1>記録を編集する</h1>
    <form action="update.php" method="POST">
      <input type="hidden" name="id" value="<?= htmlspecialchars($note['id']) ?>">

      <label>日付:
        <input type="date" name="log_date" value="<?= htmlspecialchars($note['log_date']) ?>" required>
      </label>

      <label>タイトル:
        <input type="text" name="title" value="<?= htmlspecialchars($note['title']) ?>" required>
      </label>

      <label>ふりかえり内容:
        <textarea name="reflection" rows="4" required><?= htmlspecialchars($note['reflection']) ?></textarea>
      </label>

      <label>🔥活力レベル（0〜10）:
        <input type="number" name="energy_level" min="0" max="10" value="<?= htmlspecialchars($note['energy_level']) ?>" required>
      </label>

      <label>🌱信頼レベル（0〜10）:
        <input type="number" name="trust_level" min="0" max="10" value="<?= htmlspecialchars($note['trust_level']) ?>" required>
      </label>

      <label>学び（任意）:
        <textarea name="learning" rows="3"><?= htmlspecialchars($note['learning']) ?></textarea>
      </label>

      <label>次の行動（任意）:
        <textarea name="next_action" rows="3"><?= htmlspecialchars($note['next_action']) ?></textarea>
      </label>

      <label>気持ち（任意）:
        <input type="text" name="emotion" value="<?= htmlspecialchars($note['emotion']) ?>">
      </label>

      <button type="submit">更新する</button>
    </form>
  </div>
</body>
</html>
