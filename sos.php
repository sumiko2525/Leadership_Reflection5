<?php
/**
 * sos.php（修正版）
 * Quick SOS：状況入力 → /api/ai_sos.php → 3アクション提案 → DB保存
 * - ダミーAI廃止、内部APIをcURLで呼び出し
 * - フォールバック時は「理由」も表示
 * - team_id列の有無を自動判定して保存
 */

require_once __DIR__ . '/header.php';   // funcs.php 等の共通読み込み前提
// login_required(); // 必要なら有効化

// ----------------- 画面用変数 -----------------
$errors = [];
$input_text = '';
$suggestions = [];
$status_badge = '';   // 'ok' or 'fallback'
$fail_reason  = '';   // フォールバック理由の表示

// ----------------- ユーザー情報 -----------------
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$me = function_exists('current_user') ? current_user() : [
  'id'      => ($_SESSION['user_id'] ?? null),
  'team_id' => ($_SESSION['team_id'] ?? null),
];

/**
 * 内部API呼び出し
 * - 未ログインでも -1 を送ってAI側の必須チェックを回避
 * - 失敗時はフォールバック固定3行＋reasonを返す
 */
function call_ai_sos(int $teamId, int $userId, string $text): array {
  // ★あなたの配置に合わせて修正（サブディレクトリ名など）
  $endpoint = '/Leadership_Reflection5/api/ai_sos.php';

  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
  $url = $scheme . $_SERVER['HTTP_HOST'] . $endpoint;

  if ($teamId === 0) $teamId = -1;
  if ($userId === 0) $userId = -1;

  $payload = json_encode([
    'team_id' => $teamId,
    'user_id' => $userId,
    'channel' => 'web',
    'text'    => $text,
  ], JSON_UNESCAPED_UNICODE);

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 7,
  ]);
  $resp = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($resp === false || $http < 200 || $http >= 300) {
    error_log("ai_sos call failed: http=$http err=$err");
    return [
      'status'  => 'fallback',
      'reason'  => "AI接続エラー（HTTP:$http）",
      'summary' => '',
      'actions' => [
        '作業を3ブロックに分け、所要時間を見積もる',
        '関係者へ優先順位と締切の再確認を依頼する',
        '本日30分の休息と回復策をカレンダーで確保する',
      ],
    ];
  }

  $j = json_decode($resp, true);
  if (!is_array($j) || empty($j['actions'])) {
    error_log("ai_sos empty actions: ".$resp);
    return [
      'status'  => 'fallback',
      'reason'  => 'AI応答の解析に失敗',
      'summary' => '',
      'actions' => [
        'タスクを可視化し最優先1つに集中する',
        '関係者へ状況共有と支援依頼を行う',
        '短い休息と次アクションの再定義をする',
      ],
    ];
  }
  return $j; // {status, reason, summary, actions[3], meta{...}}
}

// ----------------- POST処理 -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF
  if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    $errors[] = 'セキュリティトークンが無効です。もう一度やり直してください。';
  }

  // 入力
  $input_text = trim((string)($_POST['sos_text'] ?? ''));
  if ($input_text === '') {
    $errors[] = '状況を1行で書いてください。';
  } elseif (mb_strlen($input_text, 'UTF-8') > 1000) {
    $errors[] = '長すぎます。1000文字以内で要点をまとめてください。';
  }

  if (!$errors) {
    // AIへ
    $teamId = (int)($me['team_id'] ?? -1);
    $userId = (int)($me['id'] ?? -1);
    $ai = call_ai_sos($teamId, $userId, $input_text);
    $status_badge = $ai['status'] ?? 'ok';
    $fail_reason  = $ai['reason'] ?? '';
    $suggestions  = array_values(array_slice($ai['actions'] ?? [], 0, 3));

    // 保存
    try {
      $pdo = db_conn();
      $hasTeam = false;
      try { $pdo->query("SELECT team_id FROM sos_requests LIMIT 0"); $hasTeam = true; } catch(Throwable $e){}

      if ($hasTeam) {
        $sql = "INSERT INTO sos_requests (user_id, team_id, content, suggestion1, suggestion2, suggestion3, created_at)
                VALUES (:uid, :tid, :content, :s1, :s2, :s3, NOW())";
      } else {
        $sql = "INSERT INTO sos_requests (user_id, content, suggestion1, suggestion2, suggestion3, created_at)
                VALUES (:uid, :content, :s1, :s2, :s3, NOW())";
      }

      $stmt = $pdo->prepare($sql);
      $uid = (int)($me['id'] ?? 0);
      if ($uid <= 0) $stmt->bindValue(':uid', null, PDO::PARAM_NULL);
      else           $stmt->bindValue(':uid', $uid, PDO::PARAM_INT);
      if ($hasTeam) {
        $tid = (int)($me['team_id'] ?? 0);
        if ($tid <= 0) $stmt->bindValue(':tid', null, PDO::PARAM_NULL);
        else           $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
      }
      $stmt->bindValue(':content', $input_text, PDO::PARAM_STR);
      $stmt->bindValue(':s1', $suggestions[0] ?? '', PDO::PARAM_STR);
      $stmt->bindValue(':s2', $suggestions[1] ?? '', PDO::PARAM_STR);
      $stmt->bindValue(':s3', $suggestions[2] ?? '', PDO::PARAM_STR);
      $stmt->execute();
    } catch (Throwable $e) {
      error_log('sos_save error: '.$e->getMessage());
    }
  }
}
?>
<style>
  .sos-container{max-width:720px;margin:40px auto;padding:0 16px;text-align:center;}
  .sos-container form,.sos-container textarea{ text-align:left; }
  .panel{background:#fff;border:1px solid #d6ebe8;border-radius:14px;padding:16px 18px;margin-bottom:16px;}
  .title{font-size:20px;font-weight:800;color:#145b57;margin-bottom:8px;}
  .desc{color:#466;font-size:14px;margin-bottom:10px;}
  .field{display:flex;flex-direction:column;gap:8px;margin:10px 0;}
  textarea{width:100%;min-height:110px;padding:12px;border:1px solid #cfe7e3;border-radius:10px;font-size:15px;}
  .btn{display:inline-block;padding:10px 14px;border-radius:10px;background:#1e6f6a;color:#fff;font-weight:700;border:none;cursor:pointer;}
  .btn:hover{filter:brightness(1.05);}
  .muted{color:#5b7775;font-size:12px;}
  .suggest{background:#f7fbfb;border:1px dashed #bfe1dc;border-radius:12px;padding:12px;margin-top:8px;text-align:left;}
  .err{background:#fff1f1;border:1px solid #f4caca;color:#9b2c2c;padding:10px 12px;border-radius:10px;margin-bottom:12px;white-space:pre-line;}
  .badge{margin-left:8px;padding:2px 6px;border-radius:6px;font-size:12px;}
  .badge-ok{background:#e6f6ef;color:#1e6f6a;}
  .badge-fb{background:#ffe8e8;color:#9b2c2c;}
</style>

<main class="sos-container">
  <section class="panel">
    <div class="title">🚨 Quick SOS</div>
    <div class="desc">30秒で状況を入力すると、AIコーチが次の3アクションを提案します。</div>

    <?php if ($errors): ?>
      <div class="err"><?= h(implode("\n", $errors)) ?></div>
    <?php endif; ?>

    <form method="post" action="">
      <?= csrf_field(); ?>
      <div class="field">
        <label for="sos_text"><b>状況を1〜3行で：</b></label>
        <textarea id="sos_text" name="sos_text" placeholder="例）締切が明日。Aさんの作業が遅れ、クレーム対応も重なって優先順位を見失っている。"><?= h($input_text) ?></textarea>
        <div class="muted">ヒント：事実／影響／望む状態</div>
      </div>
      <button class="btn" type="submit">提案を受け取る</button>
    </form>
  </section>

  <?php if ($suggestions): ?>
    <section class="panel">
      <div class="title">
        次の3アクション（AIコーチ）
        <?php if ($status_badge !== 'ok'): ?>
          <span class="badge badge-fb">フォールバック</span>
          <?php if (!empty($fail_reason)): ?>
            <div class="muted" style="margin-top:6px;">理由: <?= h($fail_reason) ?></div>
          <?php endif; ?>
        <?php else: ?>
          <span class="badge badge-ok">AI生成</span>
        <?php endif; ?>
      </div>

      <div class="suggest">
        <ol style="margin:6px 0 0 18px; padding:0;">
          <li style="margin:6px 0;"><?= h($suggestions[0]) ?></li>
          <li style="margin:6px 0;"><?= h($suggestions[1]) ?></li>
          <li style="margin:6px 0;"><?= h($suggestions[2]) ?></li>
        </ol>
      </div>

      <div style="margin-top:10px; text-align:center;">
        <button class="btn" type="button" onclick="copySuggestions()">提案をコピー</button>
        <span class="muted">Slackやメールに貼り付けて使えます。</span>
      </div>
    </section>

    <script>
      function copySuggestions(){
        const text = Array.from(document.querySelectorAll('.suggest li'))
          .map(li=>`- ${li.textContent}`).join('\n');
        navigator.clipboard.writeText(text).then(()=>alert('提案をコピーしました'));
      }
    </script>
  <?php endif; ?>
</main>

<?php require_once __DIR__ . '/footer.php'; ?>
