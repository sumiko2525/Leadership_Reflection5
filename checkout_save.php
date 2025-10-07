<?php
declare(strict_types=1);
require_once __DIR__ . '/funcs.php';
team_required();                         // ★ 未ログイン/未所属を遮断
$me = current_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('checkout.php'); }
if (!csrf_verify($_POST['csrf_token'] ?? null)) { exit('不正なアクセス（CSRF）'); }

$g1 = trim((string)($_POST['g1'] ?? ''));
$g2 = trim((string)($_POST['g2'] ?? ''));
$g3 = trim((string)($_POST['g3'] ?? ''));
$self = trim((string)($_POST['self'] ?? ''));
$minutes = max(0, min(60, (int)($_POST['minutes'] ?? 0))); // 0〜60に丸め

if ($g1 === '') { exit('感謝1は必須です'); }

try {
  $pdo = db_conn();
  // ★ team_id はセッションから（POSTでは受け取らない）
  $sql = "INSERT INTO checkout_log
            (team_id, user_id, g1, g2, g3, self_compassion, meditated_min, created_at)
          VALUES
            (:tid, :uid, :g1, :g2, :g3, :self, :min, NOW())";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':tid'  => $me['team_id'],
    ':uid'  => $me['id'],
    ':g1'   => $g1,
    ':g2'   => ($g2 !== '' ? $g2 : null),
    ':g3'   => ($g3 !== '' ? $g3 : null),
    ':self' => ($self !== '' ? $self : null),
    ':min'  => $minutes,
  ]);
} catch (Throwable $e) {
  error_log('[CHECKOUT_SAVE] '.$e->getMessage());
  exit('保存に失敗しました。');
}

redirect('checkout_done.php');           // 完了画面へ（下に最小版あり）
