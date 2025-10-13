<?php
// api/_test_openai.php  ←テスト後に削除してOK
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../lib/funcs.php';   // db_conn等がなくてもOK。app_configだけ使う想定。

$cfg = app_config();
$key = $cfg['OPENAI_API_KEY'] ?? '';
if ($key === '') { http_response_code(500); exit("OPENAI_API_KEY 未設定\n"); }

// --- テスト用プロンプト（3行返すことを期待）
$system = "あなたは短時間の行動提案コーチ。日本語で60字以内の短文を3行だけ返す。番号・絵文字・前置き禁止。";
$user   = "会議が連続しタスク遅延が心配。今すぐできる具体アクションを3つ。";

// --- API呼び出し
$payload = json_encode([
  'model' => 'gpt-4o-mini',           // まずは小さめで
  'messages' => [
    ['role'=>'system','content'=>$system],
    ['role'=>'user','content'=>$user],
  ],
  'temperature' => 0.3,
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => [
    'Content-Type: application/json',
    'Authorization: Bearer '.$key,
  ],
  CURLOPT_POSTFIELDS => $payload,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => (int)($cfg['AI_TIMEOUT_SEC'] ?? 5),
]);
$resp = curl_exec($ch);
$errno = curl_errno($ch);
$err   = curl_error($ch);
$code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resp === false) {
  http_response_code(502);
  exit("cURLエラー($errno): $err\n");
}
if ($code < 200 || $code >= 300) {
  http_response_code($code ?: 500);
  exit("HTTP $code: $resp\n");
}

$j = json_decode($resp, true);
$content = trim($j['choices'][0]['message']['content'] ?? '');
$lines = preg_split('/\R/u', $content);
$lines = array_values(array_filter(array_map('trim', $lines)));

// 3行だけ抜く（足りなければそのまま表示）
$lines = array_slice($lines, 0, 3);

// 表示（プレーンテキスト）
echo "=== OpenAI応答（期待：3行） ===\n";
foreach ($lines as $i => $line) {
  echo ($i+1).": ".$line."\n";
}
echo "\n--- Raw length: ".strlen($content)." bytes\n";
