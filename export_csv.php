<?php
session_start();
require_once('funcs.php');
loginCheck(); // ログインしているか確認

$pdo = db_conn();

// 管理者なら全データ、一般ユーザーなら自分のデータだけ
if ($_SESSION['kanri_flg'] == 1) {
    $sql = 'SELECT * FROM leadership_note WHERE deleted = 0 ORDER BY log_date DESC';
    $stmt = $pdo->prepare($sql);
} else {
    $sql = 'SELECT * FROM leadership_note WHERE deleted = 0 AND user_id = :user_id ORDER BY log_date DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
}
$stmt->execute();
$notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// CSVのヘッダー行
$csv = "日付,タイトル,ふりかえり,活力,信頼,学び,次の行動,気持ち\n";

// データ行を追加
foreach ($notes as $note) {
    $csv .= "{$note['log_date']},{$note['title']},{$note['reflection']},{$note['energy_level']},{$note['trust_level']},{$note['learning']},{$note['next_action']},{$note['emotion']}\n";
}

// CSVを出力
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="leadership_notes.csv"');
echo mb_convert_encoding($csv, 'SJIS-win', 'UTF-8'); // Excelで文字化けしないように変換
exit;
