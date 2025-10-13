<?php
// /Leadership_Reflection5/api/miibo_hook.php
declare(strict_types=1);

/**
 * MIIBO → PHP → OpenAI 連携（寄り添うAIコーチ版 / 2025-10-12）
 * Quick SOSロジックを利用し、やさしい語り口で3行提案を返す
 */

header('Content-Type: text/plain; charset=UTF-8');
mb_internal_encoding('UTF-8');
$start = microtime(true);

// 1) 環境変数読み込み
$ROOT = dirname(__DIR__, 1);
$env  = @include $ROOT . '/.env.php';
$apiKey = $env['OPENAI_API_KEY'] ?? null;
if (!$apiKey) {
  http_response_code(500);
  echo "OPENAI_API_KEY missing";
  exit;
}

// 2) 入力受け取り（JSON or POST/GET）
$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);
$text = '';
foreach (['text','message','utterance','query','body'] as $k) {
  if (!empty($in[$k]) && is_string($in[$k])) { $text = trim($in[$k]); break; }
}
if ($text === '') {
  $text = trim((string)($_POST['text'] ?? $_GET['text'] ?? ''));
}
if ($text === '') {
  echo "質問テキストが見当たりません。例: {\"text\":\"今日のタスクが回らない\"}";
  exit;
}

// 3) OpenAI API 呼び出し（寄り添い口調 + です／ます調）
$prompt = "あなたは寄り添うAIコーチです。次の状況を読み、相手の気持ちに共感しながら、励ます口調で、今すぐ取れる具体的な行動を日本語のです／ます調で短く3つだけ提案してください。
前置きや見出しは禁止。出力は3行のみ。
状況:\n{$text}";

$payload = [
  'model' => 'gpt-4o-mini',
  'input' => $prompt,
  'max_output_tokens' => 200,
  'temperature' => 0.8,
];

$ch = curl_init('https://api.openai.com/v1/responses');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST           => true,
  CURLOPT_HTTPHEADER     => [
    'Authorization: Bearer ' . $apiKey,
    'Content-Type: application/json'
  ],
  CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
  CURLOPT_TIMEOUT        => 20,
]);
$resp = curl_exec($ch);
$errno = curl_errno($ch);
$http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 4) 応答整形
$actions = [];
if ($errno === 0 && $http >= 200 && $http < 300 && $resp) {
  $data = json_decode($resp, true);
  $text_out = '';
  if (isset($data['output'][0]['content'][0]['text'])) {
    $text_out = (string)$data['output'][0]['content'][0]['text'];
  } elseif (isset($data['output_text'])) {
    $text_out = (string)$data['output_text'];
  } else {
    $text_out = (string)$resp;
  }

  $text_out = preg_replace('/^\s*(\d+\.|[-・])\s*/mu', '', $text_out);
  $lines = array_values(array_filter(array_map('trim', preg_split('/\R|。/u', $text_out))));
  $lines = array_unique($lines);
  $actions = array_slice($lines, 0, 3);
}

// 5) フォールバック（優しい語り口）
if (count($actions) < 3) {
  $actions = [
    'まずは深呼吸をして、心を落ち着けてみましょう。',
    'やるべきことを3つだけ書き出して、優先順位をつけましょう。',
    '誰かに少し話してみることで、気持ちが整理されます。'
  ];
}

// 6) 出力整形（装飾つき）
$title  = "📌 SOSに対するご提案です";
$footer = "いかがでしょうか？ 応援しています！";
$badge  = "🤖 AIコーチより";

$lines = [];
foreach ($actions as $i => $a) {
  $num = ['１','２','３'][$i] ?? (string)($i+1);
  $lines[] = "{$num}. {$a}";
}

// 改行区切りで返す
echo $title . "\n"
   . implode("\n", $lines) . "\n"
   . $footer . "\n"
   . $badge;
