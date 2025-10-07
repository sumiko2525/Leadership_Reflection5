<?php
// daily_save.php
require_once __DIR__ . '/funcs.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: daily.php'); exit;
}

// CSRF
if (!csrf_verify($_POST['csrf_token'] ?? null)) {
  exit('不正なアクセスです（CSRF）。');
}

// 1〜5の整数かチェック
$mood     = isset($_POST['mood']) ? (int)$_POST['mood'] : 0;
$workload = isset($_POST['workload']) ? (int)$_POST['workload'] : 0;
$trust    = isset($_POST['trust']) ? (int)$_POST['trust'] : 0;
$note     = trim((string)($_POST['note'] ?? ''));

foreach (['mood'=>$mood, 'workload'=>$workload, 'trust'=>$trust] as $k => $v) {
  if ($v < 1 || $v > 5) {
    exit("入力エラー：{$k} は 1〜5 を選択してください。");
  }
}

try {
  $pdo = db_conn();
  $sql = "INSERT INTO daily_check (user_id, mood, workload, trust, note, ai_comment, created_at)
          VALUES (:uid, :mood, :workload, :trust, :note, :ai, NOW())";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':uid'      => $_SESSION['user_id'] ?? null, // 未ログインならNULLでOK
    ':mood'     => $mood,
    ':workload' => $workload,
    ':trust'    => $trust,
    ':note'     => $note,
    ':ai'       => '', // 後でAIコメントを付ける拡張余地
  ]);
} catch (Throwable $e) {
  error_log('[DAILY SAVE ERROR] '.$e->getMessage());
  exit('保存に失敗しました。時間をおいて再度お試しください。');
}

// 完了：完了画面へ（または daily.php に戻す）
header('Location: daily_done.php'); // 直帰したい場合は 'daily.php' にしてもOK
exit;
