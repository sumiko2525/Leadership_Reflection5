<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors','1'); 
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/php_error.log');

/* ===== bootstrap（共通関数探索 or 最小定義） ===== */
$ROOT = dirname(__DIR__);
foreach ([$ROOT.'/lib/funcs.php',$ROOT.'/includes/funcs.php',$ROOT.'/common/funcs.php'] as $f) {
  if (is_file($f)) { require_once $f; break; }
}
if (!function_exists('app_config')) {
  $env = $ROOT.'/.env.php';
  if (!is_file($env)) {
    http_response_code(500);
    echo json_encode(['status'=>'error','reason'=>'.env.php not found'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  function app_config(): array { return require dirname(__DIR__).'/.env.php'; }
}
if (!function_exists('db_conn')) {
  function db_conn(): PDO {
    $c = app_config();
    $pdo = new PDO(
      sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',$c['DB_HOST'],$c['DB_NAME']),
      $c['DB_USER'],$c['DB_PASS'],
      [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
    return $pdo;
  }
}

/* ===== 入力 ===== */
if (isset($_GET['debug'])) {
  // https://.../ai_sos.php?debug=1 で単体テスト
  $in = ['team_id'=>-1,'user_id'=>-1,'channel'=>'web','text'=>'テスト：Aさんが体調不良で出社できません。'];
} else {
  $raw = file_get_contents('php://input');
  $in = json_decode($raw ?? '[]', true) ?: [];
}
$team_id = (int)($in['team_id'] ?? -1);
$user_id = (int)($in['user_id'] ?? -1);
$channel = (string)($in['channel'] ?? 'web');
$text    = trim((string)($in['text'] ?? ''));
if ($text === '') {
  http_response_code(400);
  echo json_encode(['status'=>'error','reason'=>'empty text'], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ===== OpenAI 呼び出し ===== */
$cfg = app_config();
$key = $cfg['OPENAI_API_KEY'] ?? '';
if (!$key) {
  echo json_encode([
    'status'=>'fallback',
    'reason'=>'OPENAI_API_KEY missing',
    'actions'=>[
      'タスクを整理して優先順位を見直す',
      '関係者へ現状共有と支援依頼を行う',
      '短い休憩を取り次の一歩を決める',
    ]
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

$model = 'gpt-4o-mini';
$prompt = "次の状況を読み、今すぐ取れる具体的な行動を日本語で短く3つだけ提案。前置きや見出しは禁止。\n\n状況:\n{$text}\n\n出力は3行のみ。";
$timeout = (int)($cfg['AI_TIMEOUT_SEC'] ?? 6);

$t0 = microtime(true);
$status='ok'; $reason=''; $actions=[];

try {
  // === OpenAI Responses API ===
  $url = 'https://api.openai.com/v1/responses';
  $body = [
    'model' => $model,
    'input' => $prompt,
    'max_output_tokens' => 200,
    'temperature' => 0.6,
  ];

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => $timeout,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
      'Authorization: Bearer '.$key,
      'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
  ]);
  $resp = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $cerr = curl_error($ch);
  curl_close($ch);

  // --- デバッグログ出力（常時） ---
  $debugPath = '/home/leadership/tmp/ai_sos.debug.log';
  @file_put_contents(
    $debugPath,
    date('c')
    . " http=$http err=" . ($cerr ?: 'none')
    . "\nreq=" . json_encode($body, JSON_UNESCAPED_UNICODE)
    . "\nresp=" . (is_string($resp) ? mb_substr($resp,0,4000) : '[non-string]')
    . "\n\n",
    FILE_APPEND
  );

  if ($resp === false || $http < 200 || $http >= 300) {
    throw new Exception("http=$http curl=".($cerr ?: 'none'));
  }

  // === 応答解析（堅牢） ===
  $j = json_decode($resp, true);

  // 1) テキストを最大限回収（Responses APIの表現ゆらぎを吸収）
  $buf = '';

  // A: output[].content[].text / {type:"output_text", text:"..."}
  if (isset($j['output']) && is_array($j['output'])) {
    foreach ($j['output'] as $o) {
      if (!empty($o['content']) && is_array($o['content'])) {
        foreach ($o['content'] as $c) {
          if (isset($c['text'])) { $buf .= (string)$c['text'] . "\n"; }
          elseif (($c['type'] ?? '') === 'output_text' && isset($c['text'])) {
            $buf .= (string)$c['text'] . "\n";
          }
        }
      }
    }
  }
  // B: output_text
  if ($buf === '' && isset($j['output_text'])) {
    $buf = (string)$j['output_text'];
  }
  // C: Chat Completions 互換（保険）
  if ($buf === '' && isset($j['choices'][0]['message']['content'])) {
    $buf = (string)$j['choices'][0]['message']['content'];
  }

  if ($buf === '') {
    throw new Exception('empty AI output');
  }

  // 2) JSON優先抽出（```json 包みでもOK）
  $content = trim($buf);
  if (preg_match('/\{.*\}/s', $content, $m)) {
    $jsonText = $m[0];
  } else {
    $jsonText = $content;
  }

  $out = json_decode($jsonText, true);
  if (is_array($out) && isset($out['actions']) && is_array($out['actions'])) {
    $actions = array_values(array_filter(array_map('trim', $out['actions'])));
  }

  // 3) JSONで取れなければ、箇条書きテキストから3件抽出
  if (count($actions) < 3) {
    // 行頭の番号・記号（1. / 1) / ・ - • など）を除去
    $content = preg_replace('/^\s*(?:\d+[\.．)]|[・\-•●◦])\s*/mu', '', $content);
    // 行または句点で分割
    $parts = preg_split('/\R|。/u', $content);
    $parts = array_values(array_filter(array_map('trim', $parts), fn($s)=>$s!==''));
    // 重複除去し先頭3つ
    $actions = array_slice(array_unique($parts), 0, 3);
  }

  if (count($actions) !== 3) {
    throw new Exception('parse: insufficient actions');
  }

} catch (Throwable $e) {
  $status='fallback'; 
  $reason=$e->getMessage();
  error_log("ai_sos fallback: ".$reason);
  $actions = [
    '作業を3ブロックに分け、所要時間を見積もる',
    '関係者へ優先順位と締切の再確認を依頼する',
    '本日30分の休息と回復策をカレンダーで確保する',
  ];
}

/* ===== ログ保存（prompt_hash & NULLバインド対応） ===== */
$latency_ms = (int)round((microtime(true)-$t0)*1000);
try {
  $pdo = db_conn();

  if ($pdo->query("SHOW TABLES LIKE 'ai_calls'")->rowCount() > 0) {
    $hasPrompt = $pdo->query("SHOW COLUMNS FROM ai_calls LIKE 'prompt_hash'")->rowCount() > 0;
    $hasAb     = $pdo->query("SHOW COLUMNS FROM ai_calls LIKE 'ab_bucket'")->rowCount() > 0;

    $prompt_hash = substr(hash('sha256', json_encode([
      'model'=>$model,'text'=>$text,'sys'=>'sos_v1'
    ], JSON_UNESCAPED_UNICODE)), 0, 64);

    $cols = "team_id,user_id,channel,model";
    $vals = ":tid,:uid,:ch,:m";
    if ($hasPrompt) { $cols .= ",prompt_hash"; $vals .= ",:ph"; }
    $cols .= ",input_anon,output_json";
    $vals .= ",:in,:out";
    if ($hasAb) { $cols .= ",ab_bucket"; $vals .= ",'A'"; }
    $cols .= ",latency_ms,cost_usd,status,created_at";
    $vals .= ",:lat,0,:st,NOW()";

    $sql = "INSERT INTO ai_calls ($cols) VALUES ($vals)";
    $stmt = $pdo->prepare($sql);

    // 0以下は NULL として保存（UNSIGNED対策）
    $tidVal = ($team_id > 0) ? $team_id : null;
    $uidVal = ($user_id > 0) ? $user_id : null;

    if (is_null($tidVal)) $stmt->bindValue(':tid', null, PDO::PARAM_NULL);
    else                  $stmt->bindValue(':tid', $tidVal, PDO::PARAM_INT);

    if (is_null($uidVal)) $stmt->bindValue(':uid', null, PDO::PARAM_NULL);
    else                  $stmt->bindValue(':uid', $uidVal, PDO::PARAM_INT);

    $stmt->bindValue(':ch',  $channel, PDO::PARAM_STR);
    $stmt->bindValue(':m',   $model,   PDO::PARAM_STR);
    if ($hasPrompt) $stmt->bindValue(':ph',  $prompt_hash, PDO::PARAM_STR);
    $stmt->bindValue(':in',  $text,    PDO::PARAM_STR);
    $stmt->bindValue(':out', json_encode(['actions'=>$actions,'reason'=>$reason], JSON_UNESCAPED_UNICODE), PDO::PARAM_STR);
    $stmt->bindValue(':lat', $latency_ms, PDO::PARAM_INT);
    $stmt->bindValue(':st',  $status, PDO::PARAM_STR);

    $stmt->execute();
  }
} catch (Throwable $e) {
  error_log('ai_calls insert error: '.$e->getMessage());
}

/* ===== レスポンス ===== */
echo json_encode([
  'status'=>$status,
  'reason'=>$reason,
  'actions'=>$actions,
  'meta'=>[
    'model'=>$model,
    'latency_ms'=>$latency_ms
  ]
], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
