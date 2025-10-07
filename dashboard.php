<?php
// dashboard.php
require_once __DIR__ . '/funcs.php';

/* ===== 直近7日の集計データを用意 ===== */
$days = [];
for ($i = 6; $i >= 0; $i--) {
  $d = date('Y-m-d', strtotime("-{$i} day"));
  $days[$d] = ['mood' => null, 'workload' => null, 'trust' => null, 'count' => 0];
}

try {
  $pdo = db_conn();

  // 直近7日の日別平均
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

  // KPI用：最新の1件 & 直近7日の平均
  $latest = $pdo->query("SELECT mood, workload, trust, DATE(created_at) d FROM daily_check ORDER BY created_at DESC LIMIT 1")->fetch() ?: null;

  $avg7 = $pdo->query("
    SELECT AVG(mood) AS am, AVG(workload) AS aw, AVG(trust) AS atv
    FROM daily_check
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
  ")->fetch();

} catch (Throwable $e) {
  error_log('[DASHBOARD] '.$e->getMessage());
  $rows = [];
  $latest = null;
  $avg7 = ['am'=>null,'aw'=>null,'atv'=>null];
}

/* ===== Chart用配列 ===== */
$labels   = [];
$mood     = [];
$workload = [];
$trust    = [];

foreach ($days as $ymd => $vals) {
  $labels[]   = date('n/j', strtotime($ymd));
  $mood[]     = is_null($vals['mood'])     ? null : (float)$vals['mood'];
  $workload[] = is_null($vals['workload']) ? null : (float)$vals['workload'];
  $trust[]    = is_null($vals['trust'])    ? null : (float)$vals['trust'];
}

$hasData = (bool)array_filter($mood, fn($v)=>$v!==null) ||
           (bool)array_filter($workload, fn($v)=>$v!==null) ||
           (bool)array_filter($trust, fn($v)=>$v!==null);
?>
<?php include __DIR__ . '/header.php'; ?>
<main style="max-width:980px;margin:0 auto;padding:24px;">

  <h1 style="margin:0 0 8px;">📌 ダッシュボード</h1>
  <p style="color:#555;margin:0 0 18px;">直近の状態をひと目で。チェックインやSOS履歴へもここから。</p>

  <style>
    :root { --teal:#14b8a6; --teal-600:#0d9488; --lav:#7c3aed; --gray-100:#f3f4f6; --gray-300:#d1d5db; }
    .grid { display:grid; gap:14px; grid-template-columns: 1.2fr 1fr; }
    @media (max-width: 860px){ .grid { grid-template-columns: 1fr; } }
    .card { border:1px solid var(--gray-300); border-radius:12px; background:#fff; padding:14px; }
    .kpis { display:grid; grid-template-columns: repeat(3,1fr); gap:10px; }
    .kpi { border:1px solid var(--gray-300); border-radius:10px; padding:10px; text-align:center; }
    .kpi h4 { margin:0 0 6px; font-size:13px; color:#555; }
    .kpi .v { font-size:22px; font-weight:800; }
    .links { display:flex; flex-wrap:wrap; gap:10px; }
    .btn { border:1.8px solid var(--gray-300); border-radius:10px; padding:8px 14px;
           text-decoration:none; background:#fff; color:var(--lav); font-weight:600;
           transition:.15s; }
    .btn:hover { border-color:var(--teal); color:var(--teal); transform:translateY(1px); }
  </style>

  <div class="grid">
    <!-- 左：7日ミニグラフ -->
    <section class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <h3 style="margin:0;">📊 直近7日トレンド</h3>
        <a class="btn" href="daily_stats.php">詳細を見る</a>
      </div>
      <?php if ($hasData): ?>
        <div style="height:260px;">
          <canvas id="miniTrend"></canvas>
        </div>
      <?php else: ?>
        <div style="padding:12px;color:#666;">まだデータがありません。<a href="daily.php">まずはチェックイン</a>しましょう。</div>
      <?php endif; ?>
    </section>

    <!-- 右：KPI & クイックリンク -->
    <section class="card">
      <h3 style="margin:0 0 8px;">🔎 サマリー</h3>
      <div class="kpis" style="margin-bottom:10px;">
        <div class="kpi">
          <h4>最新 ご機嫌度</h4>
          <div class="v"><?= $latest ? h((string)$latest['mood']) : '—' ?></div>
        </div>
        <div class="kpi">
          <h4>最新 仕事の負荷</h4>
          <div class="v"><?= $latest ? h((string)$latest['workload']) : '—' ?></div>
        </div>
        <div class="kpi">
          <h4>最新 チーム内信頼</h4>
          <div class="v"><?= $latest ? h((string)$latest['trust']) : '—' ?></div>
        </div>
      </div>

      <div class="kpis" style="margin-bottom:12px;">
        <div class="kpi">
          <h4>7日平均 ご機嫌</h4>
          <div class="v"><?= $avg7 && $avg7['am'] ? h(number_format((float)$avg7['am'],1)) : '—' ?></div>
        </div>
        <div class="kpi">
          <h4>7日平均 負荷</h4>
          <div class="v"><?= $avg7 && $avg7['aw'] ? h(number_format((float)$avg7['aw'],1)) : '—' ?></div>
        </div>
        <div class="kpi">
          <h4>7日平均 信頼</h4>
          <div class="v"><?= $avg7 && $avg7['atv'] ? h(number_format((float)$avg7['atv'],1)) : '—' ?></div>
        </div>
      </div>

      <div class="links">
        <a href="daily.php" class="btn">＋ 今日のチェックイン</a>
        <a href="daily_stats.php" class="btn">📈 7日詳細</a>
        <a href="history.php" class="btn">🧭 SOS履歴</a>
      </div>
    </section>
  </div>

  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <?php if ($hasData): ?>
  <script>
    const labels   = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
    const mood     = <?= json_encode($mood) ?>;
    const workload = <?= json_encode($workload) ?>;
    const trust    = <?= json_encode($trust) ?>;

    const ctx = document.getElementById('miniTrend').getContext('2d');
    new Chart(ctx, {
      type: 'line',
      data: {
        labels,
        datasets: [
          { label:'ご機嫌度',    data:mood,     borderColor:'#14b8a6', backgroundColor:'rgba(20,184,166,.12)', tension:.3, spanGaps:false, pointRadius:3, pointHoverRadius:5 },
          { label:'仕事の負荷',  data:workload, borderColor:'#0ea5e9', backgroundColor:'rgba(14,165,233,.10)', tension:.3, spanGaps:false, pointRadius:3, pointHoverRadius:5 },
          { label:'チーム内信頼',data:trust,    borderColor:'#f59e0b', backgroundColor:'rgba(245,158,11,.10)', tension:.3, spanGaps:false, pointRadius:3, pointHoverRadius:5 },
        ]
      },
      options: {
        responsive:true,
        maintainAspectRatio:false,
        scales:{ y:{ min:1, max:5, ticks:{ stepSize:1 } } },
        plugins:{ legend:{ display:true, position:'bottom' } }
      }
    });
  </script>
  <?php endif; ?>
</main>
<?php include __DIR__ . '/footer.php'; ?>
