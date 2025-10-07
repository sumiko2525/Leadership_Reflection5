<?php
// daily.php（手順I適用・★注釈付き 完成版 / 2025-10-06）
require_once __DIR__ . '/funcs.php';
team_required();                 // ★追加：未ログイン/未所属は login.php へ
$me  = current_user();           // ★追加：セッションから team_id / user_id / role を取得
$pdo = db_conn();

$err = '';
$ok  = false;

// POST処理：チェックイン登録
if ($_SERVER['REQUEST_METHOD'] === 'POST') {           // ★追加：POSTハンドリング
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {  // ★追加：CSRF検証
        http_response_code(400);
        exit('不正なリクエストです。');
    }
    $mood     = isset($_POST['mood']) ? (int)$_POST['mood'] : null;
    $workload = isset($_POST['workload']) ? (int)$_POST['workload'] : null;
    $trust    = isset($_POST['trust']) ? (int)$_POST['trust'] : null;

    if (!$mood || !$workload || !$trust) {
        $err = 'ご機嫌・負荷・信頼を選択してください。';
    } else {
        // ★重要：team_id と user_id は POST ではなくセッションから埋める
        $sql = 'INSERT INTO daily_check (team_id, user_id, mood, workload, trust, created_at)
                VALUES (:tid, :uid, :m, :w, :t, NOW())';    // ★変更：team_id / user_id を必須化
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tid' => $me['team_id'],
            ':uid' => $me['id'],
            ':m'   => $mood,
            ':w'   => $workload,
            ':t'   => $trust,
        ]);
        $ok = true;
        // PRGパターン（F5の多重送信防止）
        header('Location: daily.php?ok=1');
        exit;
    }
}

// 直近の自分の記録（14件）
$sqlList = 'SELECT mood, workload, trust, created_at
            FROM daily_check
            WHERE team_id=:tid AND user_id=:uid       -- ★追加：team_id / user_id で絞る
            ORDER BY created_at DESC
            LIMIT 14';
$stmtL = $pdo->prepare($sqlList);
$stmtL->execute([':tid' => $me['team_id'], ':uid' => $me['id']]);
$mine = $stmtL->fetchAll();

include __DIR__ . '/header.php';
?>
<main style="max-width:780px;margin:0 auto;padding:22px;">
  <h1 style="margin:0 0 8px;">＋ 今日のチェックイン</h1>
  <p style="color:#555;margin:0 0 14px;">ご機嫌・負荷・信頼を1〜5で入力します（1=低/良くない, 5=高/良い）。</p>

  <?php if ($err): ?>
    <div style="color:#b91c1c;background:#fee2e2;border:1px solid #fecaca;padding:10px;border-radius:8px;margin-bottom:12px;">
      <?= h($err) ?>
    </div>
  <?php endif; ?>
  <?php if (isset($_GET['ok'])): ?>
    <div style="color:#065f46;background:#d1fae5;border:1px solid #a7f3d0;padding:10px;border-radius:8px;margin-bottom:12px;">
      1件登録しました。
    </div>
  <?php endif; ?>

  <form method="post" class="card" style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff;margin-bottom:18px;">
    <?= csrf_field() /* ★追加：CSRFトークンをフォームへ埋め込む */ ?>
    <div style="display:grid;gap:12px;grid-template-columns:repeat(3,1fr);">
      <label>ご機嫌度
        <select name="mood" required>
          <option value="">選択</option>
          <?php for($i=1;$i<=5;$i++): ?>
            <option value="<?=$i?>"><?=$i?></option>
          <?php endfor; ?>
        </select>
      </label>
      <label>仕事の負荷
        <select name="workload" required>
          <option value="">選択</option>
          <?php for($i=1;$i<=5;$i++): ?>
            <option value="<?=$i?>"><?=$i?></option>
          <?php endfor; ?>
        </select>
      </label>
      <label>チーム内信頼
        <select name="trust" required>
          <option value="">選択</option>
          <?php for($i=1;$i<=5;$i++): ?>
            <option value="<?=$i?>"><?=$i?></option>
          <?php endfor; ?>
        </select>
      </label>
    </div>
    <div style="margin-top:12px;">
      <button type="submit" style="padding:8px 14px;border:1px solid #d1d5db;border-radius:10px;background:#fff;">記録する</button>
    </div>
  </form>

  <h2 style="margin:0 0 10px;">あなたの直近記録（14件）</h2>
  <div class="card" style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff;">
    <table style="width:100%;border-collapse:collapse;">
      <thead><tr><th style="text-align:left;padding:6px;border-bottom:1px solid #f3f4f6;">日時</th><th>ご機嫌</th><th>負荷</th><th>信頼</th></tr></thead>
      <tbody>
      <?php foreach($mine as $r): ?>
        <tr>
          <td style="padding:6px;border-bottom:1px solid #f9fafb;"><?= h($r['created_at']) ?></td>
          <td><?= h((string)$r['mood']) ?></td>
          <td><?= h((string)$r['workload']) ?></td>
          <td><?= h((string)$r['trust']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</main>
<?php include __DIR__ . '/footer.php'; ?>
