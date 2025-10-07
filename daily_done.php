<?php require_once __DIR__ . '/funcs.php'; ?>
<?php include __DIR__ . '/header.php'; ?>
<main style="max-width:720px;margin:0 auto;padding:40px 20px;text-align:center;">

  <h1 style="font-size:2rem;margin-bottom:8px;">✅ チェックイン完了</h1>
  <p style="color:#444;margin-bottom:32px;">
    今日の「ご機嫌度・仕事の負荷・チーム内信頼」を記録しました。
  </p>

  <style>
    :root {
      --teal: #14b8a6;
      --lavender: #7c3aed;
      --gray-200: #e5e7eb;
      --gray-300: #d1d5db;
    }

    .btnset {
      display:flex;
      justify-content:center;
      flex-wrap:wrap;
      gap:12px;
    }

    .btn {
      border: 1.8px solid var(--gray-300);
      border-radius: 12px;
      padding: 10px 22px;
      font-size: 15px;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.15s ease;
      background: #fff;
      color: var(--lavender);
      box-shadow: 0 2px 6px rgba(0,0,0,0.05);
    }

    .btn:hover {
      border-color: var(--teal);
      color: var(--teal);
      transform: translateY(1px);
      box-shadow: 0 4px 10px rgba(20,184,166,0.15);
    }

    .btn:active {
      transform: translateY(2px) scale(0.98);
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
  </style>

  <div class="btnset">
    <a href="daily.php" class="btn">記録に戻る</a>
    <a href="history.php" class="btn">SOS履歴を見る</a>
    <a href="daily_stats.php" class="btn">📊 直近7日を表示</a>
  </div>

</main>
<?php include __DIR__ . '/footer.php'; ?>
