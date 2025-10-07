<?php
/**
 * sos.php
 * Quick SOS：30秒で状況入力 → AIコーチが3アクション提案 → DB保存
 */

require_once __DIR__ . '/header.php';          // 共通ヘッダー
require_once __DIR__ . '/lib/ai_client.php';   // ダミーAI
// login_required(); // ログイン必須にするなら有効化

$errors = [];
$suggestions = [];
$input_text = "";

// フォーム送信時の処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRFトークン検証
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'セキュリティトークンが無効です。もう一度やり直してください。';
    }

    // 入力取得・検証
    $input_text = trim((string)($_POST['sos_text'] ?? ''));
    if ($input_text === '') {
        $errors[] = '状況を1行で書いてください。';
    } elseif (mb_strlen($input_text, 'UTF-8') > 1000) {
        $errors[] = '長すぎます。1000文字以内で要点をまとめてください。';
    }

    if (!$errors) {
        // ダミーAIで3提案を作る
        $suggestions = ai_suggest_actions($input_text);

        // DB保存
        $pdo = db_conn();
        sql_try(function() use ($pdo, $input_text, $suggestions) {
            $sql = "INSERT INTO sos_requests (user_id, content, suggestion1, suggestion2, suggestion3, created_at)
                    VALUES (:uid, :content, :s1, :s2, :s3, NOW())";
            $stmt = $pdo->prepare($sql);
            $uid  = $_SESSION['user_id'] ?? null;

            if ($uid === null) {
                $stmt->bindValue(':uid', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':uid', (int)$uid, PDO::PARAM_INT);
            }
            $stmt->bindValue(':content', $input_text, PDO::PARAM_STR);
            $stmt->bindValue(':s1', $suggestions[0] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':s2', $suggestions[1] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':s3', $suggestions[2] ?? '', PDO::PARAM_STR);
            $stmt->execute();
        });
    }
}
?>

<style>
  /* ======== SOSページ全体の中央寄せ ======== */
  .sos-container{
    max-width: 720px;      /* 横幅を絞って中央に */
    margin: 40px auto;     /* 上下余白 + 中央寄せ */
    padding: 0 16px;       /* モバイル左右余白 */
    text-align: center;    /* 見出し・説明は中央 */
  }
  .sos-container form,
  .sos-container textarea{ text-align: left; } /* 入力操作は左寄せの方が使いやすい */

  /* ======== コンポーネント ======== */
  .panel { background:#ffffff; border:1px solid #d6ebe8; border-radius:14px; padding:16px 18px; margin-bottom:16px; }
  .title { font-size:20px; font-weight:800; color:#145b57; margin-bottom:8px; }
  .desc  { color:#466; font-size:14px; margin-bottom:10px; }
  .field { display:flex; flex-direction:column; gap:8px; margin:10px 0; }
  textarea{ width:100%; min-height:110px; padding:12px; border:1px solid #cfe7e3; border-radius:10px; font-size:15px; }
  .btn   { display:inline-block; padding:10px 14px; border-radius:10px; background:#1e6f6a; color:#fff; font-weight:700; border:none; cursor:pointer; }
  .btn:hover{ filter:brightness(1.05); }
  .muted { color:#5b7775; font-size:12px; }
  .suggest { background:#f7fbfb; border:1px dashed #bfe1dc; border-radius:12px; padding:12px; margin-top:8px; text-align:left; }
  .err { background:#fff1f1; border:1px solid #f4caca; color:#9b2c2c; padding:10px 12px; border-radius:10px; margin-bottom:12px; white-space:pre-line; }
</style>

<main class="sos-container">
  <section class="panel">
    <div class="title">🚨 Quick SOS</div>
    <div class="desc">今すぐ整えたいときに。30秒で状況を入力すると、AIコーチが次の3アクションを提案します。</div>

    <?php if ($errors): ?>
      <div class="err"><?= h(implode("\n", $errors)) ?></div>
    <?php endif; ?>

    <form method="post" action="">
      <?= csrf_field(); ?>
      <div class="field">
        <label for="sos_text"><b>状況を1〜3行で：</b></label>
        <textarea id="sos_text" name="sos_text" placeholder="例）締切が明日。Aさんの作業が遅れ、クレーム対応も重なって優先順位を見失っている。"><?= h($input_text) ?></textarea>
        <div class="muted">ヒント：事実（何が起きた）／影響（困りごと）／望む状態（どうしたい）</div>
      </div>
      <button class="btn" type="submit">提案を受け取る</button>
    </form>
  </section>

  <?php if ($suggestions): ?>
    <section class="panel">
      <div class="title">次の3アクション（AIコーチ）</div>
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
