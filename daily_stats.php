<?php
// daily_stats.php : 直近7日の日別平均（テーブル＋折れ線）
require_once __DIR__ . '/funcs.php';

// 直近7日（今日を含む）の日付配列を作っておく（キーは 'Y-m-d'）
$days = [];
for ($i = 6; $i >= 0; $i--) {
  $d = date('Y-m-d', strtotime("-{$i} day"));
  $days[$d] = ['mood' => null, 'workload' => null, 'trust' => null, 'count' => 0];
}

// DBから日別平均を取得（created_at基準）
try {
  $pdo = db_conn();
  $sql = "
    SELECT DATE(created_at) d,
           AVG(mood)      AS avg_mood,
           AVG(workload)  AS avg_workload,
           AVG(trust)     AS avg_trust,
           COUNT(*)       AS cnt
    FROM daily_check
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(created_at)
    ORDER BY d ASC
  ";
  $rows = $pdo->query($sql)->fetchAll();
  foreach ($rows as $r) {
    $d = $r['d'];
    if (isset($days[$d])) {
      $days[$d] = [
        'mood'     => round((float)$r['avg_mood'], 2),
        'workload' => round((float)$r['avg_workload'], 2),
        'trust'    => round((float)$r['avg_trust'], 2),
        'count'    => (int)$r['cnt'],
      ];
    }
  }
} catch (Throwable $e) {
  error_log('[DAILY_STATS] ' . $e->getMessage());
  exit('統計の取得に失敗しました。時間をおいて再度お試しください。');
}

// Chart.js に渡す配列を用意
$labels   = [];
$mood     = [];
$workload = [];
$trust    = [];
$counts   = [];

foreach ($days as $ymd => $vals) {
  $labels[]   = date('n/j', strtotime($ymd));           // 表示は M/D
  $mood[]     = is_null($vals['mood'])     ? null : (float)$vals['mood'];
  $workload[] = is_null($vals['workload']) ? null : (float)$vals['workload'];
  $trust[]    = is_null($vals['trust'])    ? null : (float)$vals['trust'];
  $counts[]   = (int)$vals['count'];
}

// 7日間すべて null の場合のガード
$allEmpty = array_reduce([$mood,$workload,$trust], fn($c,$a)=>$c && count(array_filter($a, fn($v)=>$v!==null))===0, true);
?>
<?php include __DIR__ . '/header.php'; ?>
<main style="max-width:920px;margin:0 auto;padding:24px;">
  <h1 style="margin:0 0 10px;">📊 直近7日のチェックイン</h1>
  <p style="color:#555;margin:0 0 16px;">日別平均（同日に複数記録がある場合は平均）。データが無い日はグレーのギャップになります。</p>

  <style>
    :root { --teal:#14b8a6; --teal-600:#0d9488; --gray-100:#f3f4f6; --gray-300:#d1d5db; }
    .card{border:1px solid var(--gray-300);border-radius:12px;padding:16px;background:#fff;}
    table{width:100%;border-collapse:collapse;}
    th,td{padding:8px 10px;border-bottom:1px solid var(--gray-100);text-align:center;}
    th{text-align:center;background:#f9fafb;}
    .pill{display:inline-block;min-width:28px;padding:2px 8px;border-radius:999px;border:1px solid var(--gray-300);}
  </style>

  <!-- テーブル -->
  <section class="card" style="margin-bottom:16px;">
    <table>
      <thead>
        <tr>
          <th>日付</th>
          <th>😊 ご機嫌度</th>
          <th>💼 仕事の負荷</th>
          <th>🤝 チーム内信頼</th>
          <th>件数</th>
        </tr>
      </thead>
      <tbody>
      <?php $i=0; foreach ($days as $ymd => $vals): $i++; ?>
        <tr>
          <td><?= h(date('Y-m-d', strtotime($ymd))) ?></td>
          <td><?= is_null($vals['mood'])     ? '<span class="pill">—</span>' : h(number_format($vals['mood'],1)) ?></td>
          <td><?= is_null($vals['workload']) ? '<span class="pill">—</span>' : h(number_format($vals['workload'],1)) ?></td>
          <td><?= is_null($vals['trust'])    ? '<span class="pill">—</span>' : h(number_format($vals['trust'],1)) ?></td>
          <td><?= h((string)$vals['count']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <!-- グラフ -->
  <section class="card">
    <?php if ($allEmpty): ?>
      最近7日に記録がありません。<a href="daily.php">まずはチェックイン</a>してみましょう。
    <?php else: ?>
      <canvas id="trend" height="280"></canvas>
    <?php endif; ?>
  </section>

  <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
    <a href="daily.php" class="pill" style="text-decoration:none;color:#111;">＋ 今日も記録する</a>
    <a href="history.php" class="pill" style="text-decoration:none;color:#111;">SOS履歴へ</a>
  </div>

  <!-- Chart.js CDN -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script>
    <?php if (!$allEmpty): ?>
    const labels   = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
    const mood     = <?= json_encode($mood) ?>;
    const workload = <?= json_encode($workload) ?>;
    const trust    = <?= json_encode($trust) ?>;

    const ctx = document.getElementById('trend').getContext('2d');
    new Chart(ctx, {
      type: 'line',
      data: {
        labels,
        datasets: [
          { label: 'ご機嫌度',    data: mood,     borderColor: '#14b8a6', backgroundColor: 'rgba(20,184,166,.15)', tension: .3, spanGaps: false, pointRadius: 4, pointHoverRadius: 6 },
          { label: '仕事の負荷',  data: workload, borderColor: '#0ea5e9', backgroundColor: 'rgba(14,165,233,.12)', tension: .3, spanGaps: false, pointRadius: 4, pointHoverRadius: 6 },
          { label: 'チーム内信頼',data: trust,    borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,.12)', tension: .3, spanGaps: false, pointRadius: 4, pointHoverRadius: 6 },
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: { min: 1, max: 5, ticks: { stepSize: 1 } }
        },
        plugins: {
          legend: { position: 'bottom' },
          tooltip: {
            callbacks: { label:(ctx)=> `${ctx.dataset.label}: ${ctx.parsed.y ?? '—'}` }
          }
        }
      }
    });
    <?php endif; ?>
  </script>
</main>
<?php include __DIR__ . '/footer.php'; ?>
