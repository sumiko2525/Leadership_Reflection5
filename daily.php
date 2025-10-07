<?php
require_once __DIR__ . '/funcs.php';
?>
<?php include __DIR__ . '/header.php'; ?>
<main style="max-width:720px;margin:0 auto;padding:24px;">

  <h1 style="text-align:center;margin:0 0 6px;">🫖1分チェックイン</h1>
  <p style="text-align:center;color:#555;margin:0 0 24px;">今日の状態を3項目だけ、直感で選んでください。</p>

  <style>
    /* ===== ティールテーマ & ボタンチップ ===== */
    :root {
      --teal: #14b8a6;        /* Teal-500 */
      --teal-600: #0d9488;    /* 濃いめ */
      --teal-50: #f0fdfa;     /* 薄い背景 */
      --gray-200: #e5e7eb;
      --gray-400: #9ca3af;
      --ring: rgba(20,184,166,.35);
    }
    .row { display:flex; gap:10px; justify-content:space-between; }
    .chip {
      border-radius: 9999px;
      width: 52px; height: 52px; line-height: 52px;
      border: 2px solid var(--gray-200);
      background: #fff;
      text-align: center;
      font-weight: 700;
      cursor: pointer;
      user-select: none;
      transition: transform .06s ease, box-shadow .1s ease, background .12s, border-color .12s, color .12s;
      box-shadow: 0 1px 0 rgba(0,0,0,.04);
    }
    /* ホバー：うっすらティール */
    .chip:hover { background: var(--teal-50); border-color: var(--teal); }
    /* キーボードフォーカス */
    .chip:focus-within {
      outline: 0;
      box-shadow: 0 0 0 4px var(--ring);
    }
    /* 選択時：塗りつぶし＋白文字＋軽い押し込み（ぷにっ） */
    input[type="radio"]:checked + .chip {
      background: var(--teal);
      border-color: var(--teal-600);
      color: #fff;
      box-shadow: inset 0 2px 4px rgba(0,0,0,.18), 0 2px 6px rgba(20,184,166,.25);
      transform: translateY(1px) scale(0.98);
    }
    /* 押下中の触感 */
    .chip:active { transform: translateY(1px) scale(0.98); }

    /* ラベルと行見出し */
    .field { margin-bottom: 26px; }
    .field h3 { margin: 0 0 8px; display:flex; align-items:center; gap:8px; }
    .hint { font-size:12px; color:#777; margin-top:6px; }

    /* テキストエリア */
    .note {
      width:100%; border-radius:10px; border:1.5px solid var(--gray-200);
      padding:10px; transition: border-color .12s, box-shadow .12s; resize: vertical;
    }
    .note:focus {
      outline:0; border-color: var(--teal);
      box-shadow: 0 0 0 4px var(--ring);
    }

    /* 送信ボタン */
    .btn {
      background: var(--teal); color:#fff; border:none; border-radius:10px;
      padding:10px 18px; font-size:16px; cursor:pointer;
      box-shadow: 0 2px 8px rgba(20,184,166,.35);
      transition: transform .06s ease, background .12s;
    }
    .btn:hover { background: var(--teal-600); }
    .btn:active { transform: translateY(1px); }
    /* アクセシビリティ：ラジオは隠すが読み上げOK */
    .visuallyhidden {
      position:absolute !important; clip:rect(1px,1px,1px,1px);
      width:1px; height:1px; overflow:hidden; white-space:nowrap;
    }
  </style>

  <form action="daily_save.php" method="post" style="margin-top:10px;">
    <?= csrf_field() ?>

    <!-- ご機嫌度 -->
    <section class="field" role="radiogroup" aria-labelledby="lbl-mood">
      <h3 id="lbl-mood">😊 ご機嫌度 <span class="hint">（1=とても低い / 5=とても高い）</span></h3>
      <div class="row">
        <?php for ($i=1; $i<=5; $i++): ?>
          <label>
            <input class="visuallyhidden" type="radio" name="mood" value="<?= $i ?>" required>
            <div class="chip" tabindex="0" aria-label="ご機嫌度 <?= $i ?>"><?= $i ?></div>
          </label>
        <?php endfor; ?>
      </div>
    </section>

    <!-- 仕事の負荷 -->
    <section class="field" role="radiogroup" aria-labelledby="lbl-workload">
      <h3 id="lbl-workload">💼 仕事の負荷 <span class="hint">（1=とても軽い / 5=とても重い）</span></h3>
      <div class="row">
        <?php for ($i=1; $i<=5; $i++): ?>
          <label>
            <input class="visuallyhidden" type="radio" name="workload" value="<?= $i ?>" required>
            <div class="chip" tabindex="0" aria-label="仕事の負荷 <?= $i ?>"><?= $i ?></div>
          </label>
        <?php endfor; ?>
      </div>
    </section>

    <!-- チーム内信頼 -->
    <section class="field" role="radiogroup" aria-labelledby="lbl-trust">
      <h3 id="lbl-trust">🤝 チーム内信頼 <span class="hint">（1=とても低い / 5=とても高い）</span></h3>
      <div class="row">
        <?php for ($i=1; $i<=5; $i++): ?>
          <label>
            <input class="visuallyhidden" type="radio" name="trust" value="<?= $i ?>" required>
            <div class="chip" tabindex="0" aria-label="チーム内信頼 <?= $i ?>"><?= $i ?></div>
          </label>
        <?php endfor; ?>
      </div>
    </section>

    <!-- 一言メモ -->
    <section class="field">
      <h3>📝 一言メモ（任意）</h3>
      <textarea name="note" rows="3" class="note" placeholder="今日のひとこと…"></textarea>
    </section>

    <div style="text-align:center;">
      <button type="submit" class="btn">送信</button>
    </div>
  </form>

  <script>
    // クリック/Enter/Spaceでも気持ちよく選べるよう軽い支援（アクセシビリティ＋触感）
    document.querySelectorAll('.chip').forEach(chip => {
      chip.addEventListener('click', () => {
        const input = chip.previousElementSibling; // 直前のradio
        input.checked = true;
        input.dispatchEvent(new Event('change', {bubbles:true}));
      });
      chip.addEventListener('keydown', e => {
        if (e.key === ' ' || e.key === 'Enter') {
          e.preventDefault();
          chip.click();
        }
      });
    });
  </script>

</main>
<?php include __DIR__ . '/footer.php'; ?>
