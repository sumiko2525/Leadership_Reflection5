<?php
/**
 * /sos_save.php
 * JSON: { text, suggestion1, suggestion2, suggestion3 }
 * セッションの $me から team_id, user_id を取得して sos_requests に保存
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors','1');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/api/php_error.log');

require_once __DIR__ . '/lib/funcs.php';
session_start();
$me = current_user();            // あなたの共通関数に合わせて取得
if (empty($me['id']) || empty($me['team_id'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'msg'=>'unauthorized']); exit;
}

// CSRF（必要に応じてON）
/*
if (!csrf_verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
  http_response_code(403); echo json_encode(['ok'=>false,'msg'=>'csrf']); exit;
}
*/

$raw = file_get_contents('php://input');
$req = json_decode($raw ?? '[]', true);
$text = trim((string)($req['text'] ?? ''));
$s1   = trim((string)($req['suggestion1'] ?? ''));
$s2   = trim((string)($req['suggestion2'] ?? ''));
$s3   = trim((string)($req['suggestion3'] ?? ''));

if ($text === '') { http_response_code(400); echo json_encode(['ok'=>false,'msg'=>'empty']); exit; }

try {
  $pdo = db_conn();
  $st = $pdo->prepare(
    'INSERT INTO sos_requests (user_id, team_id, content, suggestion1, suggestion2, suggestion3, created_at)
     VALUES (:uid,:tid,:c,:s1,:s2,:s3,NOW())'
  );
  $st->execute([
    ':uid'=>$me['id'],
    ':tid'=>$me['team_id'],
    ':c'  =>$text,
    ':s1' =>$s1, ':s2'=>$s2, ':s3'=>$s3,
  ]);
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>'db error']); // 具体エラーはログへ
  error_log('sos_save error: '.$e->getMessage());
}

