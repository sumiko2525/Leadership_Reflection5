<?php
// セッション開始（ログイン情報を利用するため）
session_start();

// 共通関数ファイルの読み込み（db接続やログインチェック関数が入っている）
require_once('funcs.php');

// ログインしているかチェック（未ログインなら "LOGIN ERROR" と表示して止まる）
loginCheck();

// ログイン中のユーザーIDを取得（login_act.phpで$_SESSION['user_id']として保存してある前提）
$user_id = $_SESSION['user_id'];

// DB接続（funcs.php の db_conn 関数を使う）
$pdo = db_conn();


// POSTデータを受け取る
$log_date     = $_POST['log_date'];
$title        = $_POST['title'];
$reflection   = $_POST['reflection'];
$energy_level = $_POST['energy_level'];
$trust_level  = $_POST['trust_level'];
$learning     = $_POST['learning'];
$next_action  = $_POST['next_action'];
$emotion      = $_POST['emotion'];


// SQL準備（deletedは初期値0、作成・更新日時は現在時刻、user_idを追加）
$sql = "INSERT INTO leadership_note (
    user_id, log_date, title, reflection, energy_level, trust_level,
    learning, next_action, emotion, deleted, created_at, updated_at
) VALUES (
    :user_id, :log_date, :title, :reflection, :energy_level, :trust_level,
    :learning, :next_action, :emotion, 0, NOW(), NOW()
)";

// SQL実行の準備
$stmt = $pdo->prepare($sql);

// 各プレースホルダに値をバインド（安全な値として扱われる）
$stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);               // ログインユーザーID
$stmt->bindValue(':log_date', $log_date);                             // 記録日
$stmt->bindValue(':title', $title);                                   // タイトル
$stmt->bindValue(':reflection', $reflection);                         // ふりかえり
$stmt->bindValue(':energy_level', $energy_level, PDO::PARAM_INT);     // 活力
$stmt->bindValue(':trust_level', $trust_level, PDO::PARAM_INT);       // 信頼
$stmt->bindValue(':learning', $learning);                             // 学び
$stmt->bindValue(':next_action', $next_action);                       // 次の行動
$stmt->bindValue(':emotion', $emotion);                               // 感情

// SQL実行
$stmt->execute();

// 登録後に表示ページなどへリダイレクト
header("Location: index.php?done=1");
exit;