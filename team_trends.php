<?php
// team_trends.php — チーム平均＋各メンバーの7日推移（手順I: RBAC＆team_id対応済み）
require_once __DIR__ . '/funcs.php';
team_required();
if (!role_at_least('leader')) {
  http_response_code(403);
  exit('このページにアクセスする権限がありません（Leader 以上）');
}
$me  = current_user();
$pdo = db_conn();

// ===== 直近7日（日付配列を先に用意） =====
$days = [];
for ($i = 6; $i >= 0; $i--) {
  $d = date('Y-m-d', strtotime("-{$i} day"));
  $days[] = $d;
}

// ===== チーム平均（1本の折れ線×3系列） =====
$sqlTeam = "
  SELECT DATE(created_at) d,
         AVG(mood)      AS avg_mood,
         AVG(workload)  AS avg_workload,
         AVG(trust)     AS avg_trust
  FROM daily_check
  WHERE team_id = :tid
    AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
  GROUP BY DATE(created_at)
  ORDER BY d ASC
";
$stmtTeam = $pdo->prepare($sqlTeam);
$stmtTeam->execute([':tid' => $me['team_id']]);
$teamRows = $stmtTeam->fetchAll();

$teamSeries = [
  'labels'   => array_map(fn($d) => date('n/j', strtotime($d)), $days),
  'mood'     => array_fill(0, count($days), null),
  'workload' => array_fill(0, count($days), null),
  'trust'    => array_fill(0, count($days), null),
];
$dayIndex = array_flip($days);
foreach ($teamRows as $r) {
  $d = $r['d'];
  if (isset($dayIndex[$d])) {
    $i = $dayIndex[$d];
    $teamSeries['mood'][$i]     = round((float)$r['avg_mood'], 2);
    $teamSeries['workload'][$i] = round((float)$r['avg_workload'], 2);
    $teamSeries['trust'][$i]    = round((float)$r['avg_trust'], 2);
  }
}

// ===== メンバー一覧（この順でカードを並べる） =====
$sqlMembers = "
  SELECT u.id AS user_id,
         COALESCE(NULLIF(u.display_name, ''), u.email) AS name
  FROM team_members tm
  JOIN users u ON u.id = tm.user_id
  WHERE tm.team_id = :tid
  ORDER BY name ASC
";
$stmtM = $pdo->prepare($sqlMembers);
$stmtM->execute([':tid' => $me['team_id']]);
$members = $stmtM->fetchAll();

// ===== 各メンバーの7日推移（1クエリでまとめて取得→PHP側で整形） =====
$sqlSeries = "
  SELECT dc.user_id,
         DATE(dc.created_at) AS d,
         AVG(dc.mood)      AS avg_mood,
         AVG(dc.workload)  AS avg_workload,
         AVG(dc.trust)     AS avg_trust
  FROM daily_check dc
  WHERE dc.team_id = :tid
    AND dc.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
  GROUP BY dc.user_id, DATE(dc.created_at)
  ORDER BY dc.user_id, d
";
$stmtS = $pdo->prepare($sqlSeries);
$stmtS->execute([':tid' => $me['team_id']]);
$rows = $stmtS->fetchAll();

// 構造：user_id => ['name'=>..., 'mood'=>[7], 'workload'=>[7], 'trust'=>[7]]
$userSeries = [];
foreach ($members as $m) {
  $uid = (int)$m['user_id'];
  $userSeries[$uid] = [
    'name'     => (string)$m['name'],
    'mood'     => array_fill(0, count($days), null),
    'workload' => array_fill(0, count($days), null),
    'trust'    => array_fill(0, count($days), null),
  ];
}
foreach ($rows as $r) {
  $uid = (int)$r['user_id'];
  $d   = $r['d'];
  if (!isset($userSeries[$uid]) || !isset($dayIndex[$d])) continue;
  $i = $dayIndex[$d];
  $userSeries[$uid]['mood'][$i]     = round((float)$r['avg_mood'], 2);
  $userSeries[$uid]['workload'][$i] = round((float)$r['avg_workload'], 2);
  $userSeries[$uid]['trust'][$i]    = round((float)$r['avg_trust'], 2);
}

// JSONへ
$labels = $teamSeries['labels'];
$teamPayload = [
  'labels'   => $labels,
  'mood'     => $teamSeries['mood'],
  'workload' => $teamSeries['workload'],
  'trust'    => $teamSeries['trust'],
];
$userPayload = [];
foreach ($userSeries as $uid => $s) {
  $userPayload[] = [
    'user_id'  => $uid,
    'name'     => $s['name'],
    'mood'     => $s['mood'],
    'workload' => $s['workload'],
    'trust'    => $s['trust'],
  ];
}

include __DIR__ . '/header.php';
?>
<main style="max-width:1080px;margin:0 auto;padding:20px;">
  <h1 style="margin:0 0 10px;">📈 チーム7日トレンド</h1>
  <p style="color:#555;margin:0 0 18px;">チーム全体の平均と、各メンバーの7日推移を表示します（Leader 以上）。</p>

  <style>
    :root { --teal:#14b8a6; --blue:#0ea5e9; --amber:#f59e0b; --gray-200:#e5e7eb; }
    .card { border:1px solid var(--gray-200); border-radius:12px; background:#fff; padding:14px; margin-bottom:14px; }
    .grid { display:grid; gap:12px; grid-template-columns: repeat(2, 1fr); }
    @media (max-width: 860px){ .grid { grid-template-columns: 1fr; } }
    .title { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; }
    .muted { color:#6b7280; font-size:12px; }
    .mini { height:220px; }
    .search { display:flex; gap:8px; margin: 8px 0 14px; }
    input[type="search"] { width: 320px; max-width: 100%; padding:8px 10px; border:1px solid var(--gray-200); border-radius:8px; }
  </style>

  <!-- チーム平均 -->
  <section class="card">
    <div class="title">
      <h3 style="margin:0;">チーム平均（直近7日）</h3>
      <div class="muted">Team ID: <?=h($me['team_id'])?></div>
    </div>
    <div style="height:280px;">
      <canvas id="teamChart"></canvas>
    </div>
  </section>

  <!-- フィルタ -->
  <div class="search">
    <input id="q" type="search" placeholder="メンバー名で絞り込み…">
    <div class="muted">表示は7日間。値は各日の平均（複数回入力があれば平均化）。</div>
  </div>

  <!-- メンバーごとのミニチャート -->
  <section id="cards" class="grid"></section>

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script>
    // PHP->JS受け渡し
    const labels   = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
    const teamData = <?= json_encode($teamPayload) ?>;
    const users    = <?= json_encode($userPayload, JSON_UNESCAPED_UNICODE) ?>;

    // チーム平均の大きいチャート
    const teamCtx = document.getElementById('teamChart').getContext('2d');
    const teamChart = new Chart(teamCtx, {
      type: 'line',
      data: {
        labels,
        datasets: [
          { label:'ご機嫌度',    data: teamData.mood,     borderColor:'#14b8a6', backgroundColor:'rgba(20,184,166,.12)', tension:.3, spanGaps:false, pointRadius:3 },
          { label:'仕事の負荷',  data: teamData.workload, borderColor:'#0ea5e9', backgroundColor:'rgba(14,165,233,.10)', tension:.3, spanGaps:false, pointRadius:3 },
          { label:'チーム内信頼',data: teamData.trust,    borderColor:'#f59e0b', backgroundColor:'rgba(245,158,11,.10)', tension:.3, spanGaps:false, pointRadius:3 },
        ]
      },
      options: {
        responsive:true,
        maintainAspectRatio:false,
        scales:{ y:{ min:1, max:5, ticks:{ stepSize:1 } } },
        plugins:{ legend:{ display:true, position:'bottom' } }
      }
    });

    // メンバーカードの生成
    const cards = document.getElementById('cards');
    function makeCard(user) {
      const id = 'chart_'+user.user_id;
      const el = document.createElement('div');
      el.className = 'card';
      el.dataset.name = (user.name || '').toLowerCase();
      el.innerHTML = `
        <div class="title"><strong>${user.name ?? 'No name'}</strong>
          <span class="muted">7日推移</span></div>
        <div class="mini"><canvas id="${id}"></canvas></div>
      `;
      cards.appendChild(el);
      const ctx = document.getElementById(id).getContext('2d');
      new Chart(ctx, {
        type: 'line',
        data: {
          labels,
          datasets: [
            { label:'ご機嫌度',    data:user.mood,     borderColor:'#14b8a6', backgroundColor:'rgba(20,184,166,.12)', tension:.3, spanGaps:false, pointRadius:0, pointHoverRadius:4 },
            { label:'仕事の負荷',  data:user.workload, borderColor:'#0ea5e9', backgroundColor:'rgba(14,165,233,.10)', tension:.3, spanGaps:false, pointRadius:0, pointHoverRadius:4 },
            { label:'チーム内信頼',data:user.trust,    borderColor:'#f59e0b', backgroundColor:'rgba(245,158,11,.10)', tension:.3, spanGaps:false, pointRadius:0, pointHoverRadius:4 },
          ]
        },
        options: {
          responsive:true,
          maintainAspectRatio:false,
          scales:{ y:{ min:1, max:5, ticks:{ stepSize:1 } } },
          plugins:{ legend:{ display:false } }
        }
      });
    }

    // 初期描画
    users.forEach(makeCard);

    // 名前フィルタ
    const q = document.getElementById('q');
    q.addEventListener('input', () => {
      const needle = q.value.trim().toLowerCase();
      [...cards.children].forEach(card => {
        const hit = !needle || card.dataset.name.includes(needle);
        card.style.display = hit ? '' : 'none';
      });
    });
  </script>
</main>
<?php include __DIR__ . '/footer.php'; ?>
