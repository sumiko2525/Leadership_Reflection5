<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

ini_set('display_errors','1');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/php_error.log');

// 1) .env.php を直読み（v5の1つ上階層にある想定）
$envPath = __DIR__ . '/../.env.php';
if (!file_exists($envPath)) { http_response_code(500); exit(".env.php が見つかりません: $envPath\n"); }
$cfg = require $envPath;
$key = $cfg['OPENAI_API_KEY'] ?? '';
if ($key === '') { http_response_code(500); exit("OPENAI_API_KEY 未設定\n"); }

// 2) テスト用プロンプト
$system = "日本語で60字以内の短文を3行だけ。番号・絵文字・前置きは禁止。";
$user   = "会議が連続してタスク遅延が不安。今すぐできる具体アクションを3つ。";

// 3) 呼び出し
$payload = json_encode([
  'model' => 'gpt-4o-mini',
  'messages' => [
    ['role'=>'system','content'=>$system],
    ['role'=>'user','content'=>$user],
  ],
  'temperature' => 0.3,
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
  CURLOPT_POST=>true,
  CURLOPT_HTTPHEADER=>[
    'Content-Type: application/json',
    'Authorization: Bearer '.$key,
  ],
  CURLOPT_POSTFIELDS=>$payload,
  CURLOPT_RETURNTRANSFER=>true,
  CURLOPT_TIMEOUT=>7,
]);
$resp = curl_exec($ch);
$errno = curl_errno($ch);
$err   = curl_error($ch);
$code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resp === false) { http_response_code(502); exit("cURL error($errno): $err\n"); }
if ($code < 200 || $code >= 300) { http_response_code($code ?: 500); exit("HTTP $code\n$resp\n"); }

$j = json_decode($resp,true);
$content = trim($j['choices'][0]['message']['content'] ?? '');
$lines = array_values(array_filter(array_map('trim', preg_split('/\R/u',$content))));
$lines = array_slice($lines, 0, 3);

echo "=== OpenAI応答（期待：3行） ===\n";
foreach ($lines as $i=>$line) echo ($i+1).": $line\n";
