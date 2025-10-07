<?php
// sos.php（手順I適用・★注釈付き 完成版 / 2025-10-06）
require_once __DIR__ . '/funcs.php';
team_required();                 // ★追加
$me  = current_user();           // ★追加
$pdo = db_conn();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {           // ★追加：POSTハンドリング
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('不正なリクエストです。');
    }
    // 削除（Leader以上）
    if (isset($_POST['delete_id'])) {
        if (!role_at_least('leader')) { http_response_code(403); exit('権限がありません'); }
        $id = (int)$_POST['delete_id'];
        $sql = 'DELETE FROM sos_requests WHERE id=:id AND team_id=:tid'; // ★追加：二重鍵
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id'=>$id, ':tid'=>$me['team_id']]);
        header('Location: sos.php'); exit;
    }
    // 追加
    $msg = trim((string)($_POST['message'] ?? ''));
    if ($msg === '') {
        $err = 'メッセージを入力してください。';
    } else {
        $sql = 'INSERT INTO sos_requests (team_id, user_id, message, created_at)
                VALUES (:tid, :uid, :msg, NOW())';              // ★変更：team_id / user_id を必須化
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tid' => $me['team_id'],                           // ★重要：セッションから埋める
            ':uid' => $me['id'],
            ':msg' => $msg,
        ]);
        header('Location: sos.php'); exit;
    }
}

// 一覧（チーム内の最新30件）
$sqlList = 'SELECT id, message, user_id, created_at
            FROM sos_requests
            WHERE team_id=:tid                                   -- ★追加
            ORDER BY created_at DESC
            LIMIT 30';
$stmt = $pdo->prepare($sqlList);
$stmt->execute([':tid' => $me['team_id']]);
$list = $stmt->fetchAll();

include __DIR__ . '/header.php';
?>
<main style="max-width:840px;margin:0 auto;padding:22px;">
  <h1 style="margin:0 0 10px;">🆘 Quick SOS</h1>
  <p style="color:#555;margin:0 0 14px;">困りごと・助けてほしいことを気軽に共有。チーム内だけに表示されます。</p>

  <?php if ($err): ?>
    <div style="color:#b91c1c;background:#fee2e2;border:1px solid #fecaca;padding:10px;border-radius:8px;margin-bottom:12px;">
      <?= h($err) ?>
    </div>
  <?php endif; ?>

  <form method="post" class="card" style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff;margin-bottom:18px;">
    <?= csrf_field() /* ★追加：CSRF対策 */ ?>
    <div style="display:flex;gap:8px;align-items:center;">
      <input type="text" name="message" placeholder="例：顧客Aの件、資料レビューお願いできますか？"
             style="flex:1;padding:8px 10px;border:1px solid #d1d5db;border-radius:10px;" required>
      <button type="submit" style="padding:8px 14px;border:1px solid #d1d5db;border-radius:10px;background:#fff;">投稿</button>
    </div>
  </form>

  <div class="card" style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff;">
    <table style="width:100%;border-collapse:collapse;">
      <thead><tr>
        <th style="text-align:left;padding:6px;border-bottom:1px solid #f3f4f6;">日時</th>
        <th>本文</th>
        <th style="width:110px;">操作</th>
      </tr></thead>
      <tbody>
      <?php foreach ($list as $r): ?>
        <tr>
          <td style="padding:6px;border-bottom:1px solid #f9fafb;"><?= h($r['created_at']) ?></td>
          <td><?= h($r['message']) ?></td>
          <td>
            <?php if (role_at_least('leader')): // ★追加：Leader以上のみ削除可 ?>
              <form method="post" onsubmit="return confirm('削除しますか？');" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="delete_id" value="<?= (int)$r['id'] ?>">
                <button type="submit" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:8px;background:#fff;">削除</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>
<?php include __DIR__ . '/footer.php'; ?>
