<?php
declare(strict_types=1);
require_once __DIR__ . '/funcs.php';
team_required();                      // ★ 追加：未ログイン/未所属は入れない
$me = current_user();
include __DIR__ . '/header.php';
?>
<main style="max-width:760px;margin:0 auto;padding:24px;">
  <h1 style="margin:0 0 8px;">🌙 5分チェックアウト</h1>
  <p style="color:#555;margin:0 0 18px;">今日に感謝を3つ。自分をねぎらい、短い瞑想で締めくくろう。</p>

  <style>
    :root{ --teal:#14b8a6; --teal-600:#0d9488; --gray-300:#d1d5db; --ring: rgba(20,184,166,.35); }
    .card{border:1px solid var(--gray-300);border-radius:12px;padding:16px;background:#fff;margin-bottom:14px;}
    .input{width:100%;border:1.5px solid #e5e7eb;border-radius:10px;padding:10px;font-size:15px;}
    .input:focus{outline:0;border-color:var(--teal);box-shadow:0 0 0 4px var(--ring);}
    .btn{background:var(--teal);color:#fff;border:none;border-radius:10px;padding:10px 18px;cursor:pointer;}
    .btn:hover{background:var(--teal-600);}
    .row{display:grid;grid-template-columns:24px 1fr;gap:10px;align-items:center;margin-bottom:10px;}
    .pill{display:inline-block;border:1px solid #e5e7eb;border-radius:999px;padding:4px 10px;font-size:12px;margin-right:6px;cursor:pointer;}
    .pill:hover{background:#f8fafc}
  </style>

  <form action="checkout_save.php" method="post" autocomplete="off">
    <?= csrf_field() ?>

    <section class="card">
      <h3 style="margin:0 0 10px;">🙏 感謝（3つまで）</h3>
      <div class="row"><span>1</span><input class="input" type="text" name="g1" maxlength="255" placeholder="例：同僚Aがレビューを手伝ってくれた（必須）" required></div>
      <div class="row"><span>2</span><input class="input" type="text" name="g2" maxlength="255" placeholder="例：会議が予定どおり終わった（任意）"></div>
      <div class="row"><span>3</span><input class="input" type="text" name="g3" maxlength="255" placeholder="例：暖かいコーヒーでリフレッシュ（任意）"></div>
      <div style="margin-top:6px;font-size:12px;color:#666;">
        ヒント：
        <span class="pill" onclick="setQuick('g1','助けてくれた人に感謝')">助けてくれた人</span>
        <span class="pill" onclick="setQuick('g2','進んだ小さな一歩に感謝')">小さな前進</span>
        <span class="pill" onclick="setQuick('g3','健康と時間に感謝')">健康と時間</span>
      </div>
    </section>

    <section class="card">
      <h3 style="margin:0 0 10px;">💚 自分をねぎらう一言</h3>
      <input class="input" type="text" name="self" maxlength="255" placeholder="例：今日もよくやった。失敗も学びに変えられた。">
    </section>

    <section class="card">
      <h3 style="margin:0 6px 8px 0;display:flex;align-items:center;gap:10px;">
        🧘‍♀️ 慈悲の瞑想（1〜5分）
        <small style="font-weight:normal;color:#666;">「私が安らかでありますように。私の大切な人が安らかでありますように。関わるすべての人が安らかでありますように。」</small>
      </h3>
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;">
        <label>分：</label>
        <select class="input" name="minutes" style="max-width:120px;">
          <?php for($i=0;$i<=5;$i++): ?>
            <option value="<?= $i ?>"><?= $i ?></option>
          <?php endfor; ?>
        </select>
        <button type="button" class="btn" id="startTimer">⏱ 開始</button>
        <span id="left" style="margin-left:8px;color:#0d9488;font-weight:700;"></span>
      </div>
      <progress id="prog" value="0" max="1" style="width:100%;height:10px;border-radius:8px;"></progress>
    </section>

    <div style="text-align:center;margin-top:18px;">
      <button type="submit" class="btn">保存して終了</button>
    </div>
  </form>

  <script>
    function setQuick(id, text){ const el=document.querySelector(`[name="${id}"]`); if(el && !el.value) el.value=text; }
    const btn = document.getElementById('startTimer');
    const left= document.getElementById('left');
    const prog= document.getElementById('prog');
    let timer=null;
    btn?.addEventListener('click', ()=>{
      const mins = parseInt(document.querySelector('[name="minutes"]').value||'0',10);
      if(!mins){ left.textContent='0分（タイマーなし）'; prog.value=0; return; }
      clearInterval(timer);
      const total = mins*60; let t=total;
      timer = setInterval(()=>{
        t--; if(t<=0){ clearInterval(timer); left.textContent='お疲れさま！'; prog.value=1; return; }
        left.textContent = `残り ${Math.floor(t/60)}:${String(t%60).padStart(2,'0')}`;
        prog.value = (total - t)/total;
      },1000);
    });
  </script>
</main>
<?php include __DIR__ . '/footer.php'; ?>
