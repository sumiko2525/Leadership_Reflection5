<?php
// history.php（検索修正版：ユニークなプレースホルダ & 空検索のUX改善）
require_once __DIR__ . '/funcs.php';

// 0) 検索クエリ取得（スペース区切りでAND検索）
$q_raw = trim((string)($_GET['q'] ?? ''));
$keywords = [];
if ($q_raw !== '') {
  // 全角スペースを半角に寄せてから分割
  $q_norm = str_replace('　', ' ', $q_raw);
  $q_norm = preg_replace('/\s+/u', ' ', $q_norm);
  foreach (explode(' ', $q_norm) as $w) {
    if ($w !== '') $keywords[] = $w;
  }
}

// LIKEのワイルドカードをエスケープ（% と _ を文字として扱う）
function like_escape($s) {
  $s = str_replace('\\', '\\\\', $s);
  $s = str_replace('%',  '\%', $s);
  $s = str_replace('_',  '\_', $s);
  return $s;
}

// 1) ページング設定
$perPage = 10;
$page = isset($_GET['page']) && ctype_digit($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $perPage;

// 2) WHERE句を構築（キーワードAND／各出現でユニーク名）
$where = "is_deleted = 0";
$params = [];

if (!empty($keywords)) {
  $andParts = [];
  foreach ($keywords as $i => $kw) {
    // 4つのカラムそれぞれにユニークなプレースホルダを割り当て
    $p1 = ":kw{$i}_c";
    $p2 = ":kw{$i}_s1";
    $p3 = ":kw{$i}_s2";
    $p4 = ":kw{$i}_s3";
    $andParts[] = "(content LIKE {$p1} ESCAPE '\\\\' OR suggestion1 LIKE {$p2} ESCAPE '\\\\' OR suggestion2 LIKE {$p3} ESCAPE '\\\\' OR suggestion3 LIKE {$p4} ESCAPE '\\\\')";
    $val = '%' . like_escape($kw) . '%';
    $params[$p1] = $val;
    $params[$p2] = $val;
    $params[$p3] = $val;
    $params[$p4] = $val;
  }
  $where .= ' AND ' . implode(' AND ', $andParts);
}

// 3) 総件数（フィルタ後）
try {
  $pdo = db_conn();
  $sqlCount = "SELECT COUNT(*) FROM sos_requests WHERE {$where}";
  $stmtCount = $pdo->prepare($sqlCount);
  foreach ($params as $k => $v) $stmtCount->bindValue($k, $v, PDO::PARAM_STR);
  $stmtCount->execute();
  $total = (int)$stmtCount->fetchColumn();
} catch (PDOException $e) {
  exit('DB接続/集計エラー: ' . h($e->getMessage()));
}

// 4) データ取得（新しい順）
try {
  $sql = "
    SELECT id, content, suggestion1, suggestion2, suggestion3, created_at
    FROM sos_requests
    WHERE {$where}
    ORDER BY created_at DESC, id DESC
    LIMIT :limit OFFSET :offset
  ";
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k => $v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
  $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
  $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  exit('データ取得エラー: ' . h($e->getMessage()));
}

// 5) ページング計算
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

// 6) クエリを維持したページングURL生成
function page_link($p, $q_raw) {
  $qs = ['page' => $p];
  if ($q_raw !== '') $qs['q'] = $q_raw;
  return '?' . http_build_query($qs);
}

// 7) 日付表示ヘルパ
function dt($str) {
  $t = strtotime($str);
  return $t ? date('Y-m-d H:i', $t) : h($str);
}
?>
<?php include __DIR__ . '/header.php'; ?>
<main class="container" style="max-width:960px;margin:0 auto;padding:20px;">

  <h1 style="margin:0 0 12px;">🧭 SOS履歴（History）</h1>

  <!-- 検索フォーム -->
  <form method="get" action="history.php" style="display:flex;gap:8px;align-items:center;margin:6px 0 8px;">
    <input
      type="text"
      name="q"
      value="<?= h($q_raw) ?>"
      placeholder="例）締切 共有　（スペースで複数AND）"
      style="flex:1;border:1px solid #ddd;border-radius:10px;padding:8px 10px;"
    >
    <button type="submit" style="border:1px solid #ddd;border-radius:10px;padding:8px 14px;background:#fff;cursor:pointer;">検索</button>
    <?php if ($q_raw !== ''): ?>
      <a href="history.php" style="border:1px solid #eee;border-radius:10px;padding:8px 14px;text-decoration:none;color:#333;background:#fafafa;">クリア</a>
    <?php endif; ?>
  </form>

  <!-- 検索ヒント（空検索時のみ表示） -->
  <?php if ($q_raw === ''): ?>
    <div style="font-size:12px;color:#777;margin-bottom:12px;">
      ヒント：キーワードをスペース区切りで入力すると <b>AND検索</b> できます（例：<code>締切 共有</code>）。
      よく使うフィルタ：
      <a href="?q=締切">締切</a> /
      <a href="?q=共有">共有</a> /
      <a href="?q=報告">報告</a>
    </div>
  <?php endif; ?>

  <p style="color:#555;margin:0 0 16px;">
    <?= $q_raw === '' ? '全 ' . h((string)$total) . ' 件' : '該当 ' . h((string)$total) . ' 件（' . h(implode(' AND ', $keywords)) . '）' ?>　
    / 1ページ <?= h((string)$perPage) ?> 件
  </p>

  <?php if (empty($rows)): ?>
    <div class="card" style="padding:16px;border:1px solid #eee;border-radius:12px;background:#fafafa;">
      該当する履歴がありません。<br>
      <a href="history.php">👉 すべて表示に戻る</a>
    </div>
  <?php else: ?>
    <div class="grid" style="display:grid;grid-template-columns:1fr;gap:16px;">
      <?php foreach ($rows as $r): ?>
        <article class="card" style="border:1px solid #eee;border-radius:12px;padding:16px;">
          <div style="font-size:12px;color:#666;margin-bottom:8px;">
            📅 <?= dt($r['created_at']) ?>　
            <span style="color:#aaa;">#<?= h($r['id']) ?></span>
          </div>

          <div style="font-weight:bold;margin-bottom:8px;">
            <?= nl2br(h($r['content'])) ?>
          </div>

          <ul style="margin:0 0 8px 18px;padding:0;">
            <?php if (!empty($r['suggestion1'])): ?>
              <li>提案①：<?= nl2br(h($r['suggestion1'])) ?></li>
            <?php endif; ?>
            <?php if (!empty($r['suggestion2'])): ?>
              <li>提案②：<?= nl2br(h($r['suggestion2'])) ?></li>
            <?php endif; ?>
            <?php if (!empty($r['suggestion3'])): ?>
              <li>提案③：<?= nl2br(h($r['suggestion3'])) ?></li>
            <?php endif; ?>
          </ul>

          <form action="history_delete.php" method="post" onsubmit="return confirm('このカードを非表示にします。よろしいですか？');" style="margin-top:8px;">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= h($r['id']) ?>">
            <button type="submit" style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:6px 10px;cursor:pointer;">
              🗑 非表示（論理削除）
            </button>
          </form>
        </article>
      <?php endforeach; ?>
    </div>

    <!-- ページネーション -->
    <nav aria-label="pagination" style="margin-top:20px;display:flex;gap:8px;flex-wrap:wrap;">
      <?php
        $prev = max(1, $page - 1);
        $next = min($totalPages, $page + 1);

        if ($page > 1) {
          echo '<a href="' . h(page_link($prev, $q_raw)) . '" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;text-decoration:none;">← 前へ</a>';
        } else {
          echo '<span style="padding:6px 10px;border:1px solid #eee;border-radius:8px;color:#aaa;">← 前へ</span>';
        }

        $start = max(1, $page - 3);
        $end   = min($totalPages, $page + 3);
        for ($i = $start; $i <= $end; $i++) {
          if ($i == $page) {
            echo '<span style="padding:6px 10px;border:1px solid #333;border-radius:8px;background:#333;color:#fff;">' . h($i) . '</span>';
          } else {
            echo '<a href="' . h(page_link($i, $q_raw)) . '" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;text-decoration:none;">' . h($i) . '</a>';
          }
        }

        if ($page < $totalPages) {
          echo '<a href="' . h(page_link($next, $q_raw)) . '" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;text-decoration:none;">次へ →</a>';
        } else {
          echo '<span style="padding:6px 10px;border:1px solid #eee;border-radius:8px;color:#aaa;">次へ →</span>';
        }
      ?>
    </nav>
  <?php endif; ?>

</main>
<?php include __DIR__ . '/footer.php'; ?>
