<?php
require __DIR__ . '/../_boot.php';
$admin = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (($_POST['do'] ?? '') === 'mv_cfg') {
        $key = trim((string) ($_POST['key'] ?? ''));
        $voice = trim((string) ($_POST['voice'] ?? ''));
        if ($key !== '' && !preg_match('/^[A-Za-z0-9_\-]{20,200}$/', $key)) { flash('That ElevenLabs key looks wrong. Copy it again.', 'error'); redirect('myvoice.php?tab=settings'); }
        if ($voice !== '' && !preg_match('/^[A-Za-z0-9]{10,40}$/', $voice)) { flash('That Voice ID looks wrong. Copy it again from ElevenLabs → Voices → your voice → ID.', 'error'); redirect('myvoice.php?tab=settings'); }
        if ($key !== '') setting_set('mv_key', $key);
        if (isset($_POST['forget_key'])) setting_set('mv_key', '');
        $model = (string) ($_POST['model'] ?? '');
        mv_save_cfg([
            'voice'        => $voice !== '' ? $voice : mv_cfg()['voice'],
            'model'        => isset(MV_MODELS[$model]) ? $model : 'eleven_multilingual_v2',
            'custom_model' => preg_replace('/[^a-z0-9_.\-]/', '', strtolower(trim((string) ($_POST['custom_model'] ?? '')))) ?? '',
            'stability'    => max(0, min(100, (int) ($_POST['stability'] ?? 50))),
            'similarity'   => max(0, min(100, (int) ($_POST['similarity'] ?? 80))),
        ]);
        log_activity('My Voice settings saved', 'ok');
        flash(mv_ready() ? 'Saved. Open 🎙 Make and try it.' : 'Saved. Add both the key and the Voice ID.');
    }
    redirect('myvoice.php');
}

$tab = tab_now('myvoice');
$c = mv_cfg();
page_start('My Voice', 'myvoice', $admin);
?>
<style>
.mv { display: flex; flex-direction: column; gap: 12px; max-width: 760px; }
.mv .box { background: var(--panel); border: 1px solid var(--line); border-radius: 14px; padding: 14px; font-family: 'Noto Sans Bengali', Inter, sans-serif; }
.mv h3 { font-size: 15px; margin: 0 0 8px; }
.mv textarea { width: 100%; min-height: 90px; border-radius: 12px; background: #0b1527; border: 1px solid var(--line); color: #fff; padding: 12px; font: 15px 'Noto Sans Bengali', Inter, sans-serif; resize: vertical; }
.mv .row { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; } .mv .row .btn { flex: 1; }
.mv audio { width: 100%; margin-top: 10px; }
.mv .meta { font-size: 12px; color: #8fa3c4; margin-top: 6px; }
.mv .hist { display: flex; flex-direction: column; gap: 10px; }
.mv .hi { padding: 10px; border-radius: 12px; background: #0f1f38; border: 1px solid var(--line); }
.mv .hi .bn { font-size: 12.5px; color: #9fb0d6; margin-top: 2px; }
</style>
<div class="page-head"><div><h1>🗣 My Voice</h1><p class="muted">Type in Bangla (or any language) — it comes out in the language you choose, in YOUR voice. Download it, or play it on your live.</p></div></div>
<?= tab_bar('myvoice') ?>

<?php if ($tab === 'make'): ?>
<div class="mv" id="mv" data-csrf="<?= e(csrf_token()) ?>">
  <?php if (!mv_ready()): ?><div class="flash flash-error">Not ready yet: <a href="myvoice.php?tab=settings">open ⚙️ Settings</a> and add your ElevenLabs key and Voice ID.</div><?php endif; ?>
  <div class="box">
    <h3>① বাংলায় বা ইংরেজিতে লিখুন</h3>
    <textarea id="mv-text" maxlength="1000" placeholder="যেমন: সবাইকে স্বাগতম! চ্যাটে তোমার দেশের নাম লেখো!"></textarea>
    <label class="field" style="margin-top:10px"><span>কোন ভাষায় বানাবে (Make it in)</span><select id="mv-lang"><?php foreach (MV_LANGS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
    <div class="row"><button type="button" class="btn btn-blue" id="mv-make">✨ Make my voice</button></div>
    <p class="meta" id="mv-msg"></p>
  </div>
  <div class="box" id="mv-res" hidden>
    <h3>② আপনার কণ্ঠে (<span id="mv-lname">English</span>)</h3>
    <textarea id="mv-en" maxlength="1000" style="min-height:60px"></textarea>
    <p class="meta">Not right? Fix the words above and press “Make again” (it uses credits again).</p>
    <audio id="mv-audio" controls></audio>
    <div class="row">
      <a class="btn btn-ghost" id="mv-dl" download>💾 Download</a>
      <button type="button" class="btn btn-green" id="mv-live">📡 Play on live</button>
      <button type="button" class="btn btn-ghost" id="mv-again">↻ Make again</button>
    </div>
    <p class="meta" id="mv-info"></p>
  </div>
</div>
<?php elseif ($tab === 'history'): ?>
<div class="mv" id="mv" data-csrf="<?= e(csrf_token()) ?>">
  <?php $h = mv_history(); if (!$h): ?><div class="box"><p class="muted">Nothing made yet.</p></div><?php endif; ?>
  <div class="hist">
  <?php foreach ($h as $x): ?>
    <div class="hi">
      <div><?= e($x['en']) ?></div>
      <?php if ($x['text'] !== $x['en']): ?><div class="bn"><?= e($x['text']) ?></div><?php endif; ?>
      <audio controls preload="none" src="<?= e($x['url']) ?>"></audio>
      <div class="row"><a class="btn btn-ghost btn-sm" href="<?= e($x['url']) ?>" download="my-voice-<?= e(date('Ymd-His', (int) $x['ts'])) ?>.mp3">💾 Download</a>
        <button type="button" class="btn btn-green btn-sm" data-live="<?= e($x['id']) ?>">📡 Play on live</button></div>
      <div class="meta"><?= e(date('d M H:i', (int) $x['ts'])) ?> · <?= e(MV_LANGS[$x['lang'] ?? 'en'] ?? 'English') ?> · <?= (int) $x['chars'] ?> letters</div>
    </div>
  <?php endforeach; ?>
  </div>
</div>
<?php else: ?>
<section class="panel">
  <div class="ph"><h2>⚙️ Settings</h2><?= mv_ready() ? '<span class="tag tag-green">Ready</span>' : '<span class="tag tag-orange">Not ready</span>' ?></div>
  <ol class="steps">
    <li>ElevenLabs → <strong>Developers → API Keys</strong> → your key (needs <strong>Text to Speech: Access</strong>).</li>
    <li>ElevenLabs → <strong>Voices</strong> → your voice (Tasin) → copy its <strong>ID</strong>.</li>
    <li>Paste both below → <strong>Save</strong>. Only here — never in a chat.</li>
  </ol>
  <form method="post" class="form" style="margin-top:12px">
    <?= csrf_field() ?><input type="hidden" name="do" value="mv_cfg">
    <div class="row2">
      <label class="field"><span>ElevenLabs API key</span><input name="key" type="password" autocomplete="off" spellcheck="false" placeholder="<?= mv_key() !== '' ? 'Saved — leave empty to keep it' : 'Paste the key' ?>"></label>
      <label class="field"><span>Voice ID</span><input name="voice" autocomplete="off" spellcheck="false" value="<?= e($c['voice']) ?>" placeholder="e.g. 21m00Tcm4TlvDq8ikWAM"></label>
      <label class="field"><span>Model</span><select name="model"><?php foreach (MV_MODELS as $k => $l): ?><option value="<?= e($k) ?>" <?= $c['model'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Other model name (optional, e.g. a newer one from ElevenLabs)</span><input name="custom_model" value="<?= e($c['custom_model']) ?>" placeholder="leave empty to use the one above"></label>
      <label class="field"><span>Stability: <b id="st-v"><?= (int) $c['stability'] ?></b> (left = more emotion, right = steadier)</span><input type="range" name="stability" min="0" max="100" value="<?= (int) $c['stability'] ?>" oninput="document.getElementById('st-v').textContent=this.value"></label>
      <label class="field"><span>Similarity: <b id="si-v"><?= (int) $c['similarity'] ?></b> (right = closer to your voice)</span><input type="range" name="similarity" min="0" max="100" value="<?= (int) $c['similarity'] ?>" oninput="document.getElementById('si-v').textContent=this.value"></label>
    </div>
    <?php if (mv_key() !== ''): ?><label class="check"><input type="checkbox" name="forget_key"> Delete the saved key</label><?php endif; ?>
    <div class="form-actions"><button class="btn btn-blue" type="submit">Save</button></div>
  </form>
  <p class="muted small" style="margin-top:10px">Bangla is turned into English with the OpenAI key saved in YouTube Manager → 🤖 AI Replies (a tiny cost). Each English letter uses about 1 ElevenLabs credit (Flash: about half). The same line made again costs nothing — it is kept.</p>
</section>
<?php endif; ?>
<script>
(() => {
  const root = document.getElementById('mv'); if (!root) return;
  const api = async (body) => { try { const r = await fetch('myvoice_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': root.dataset.csrf }, body: JSON.stringify(body) }); return await r.json(); } catch (e) { return { error: 'No answer — check the internet and try again.' }; } };
  const live = async (id, btn) => { btn.disabled = true; const j = await api({ do: 'live', id }); btn.textContent = j.ok ? '✓ Playing on the live' : '⚠️ ' + (j.error || 'Not done'); setTimeout(() => { btn.disabled = false; btn.textContent = '📡 Play on live'; }, 4000); };
  document.querySelectorAll('[data-live]').forEach((b) => b.addEventListener('click', () => live(b.dataset.live, b)));
  const make = document.getElementById('mv-make'); if (!make) return;
  const msg = document.getElementById('mv-msg'), res = document.getElementById('mv-res'), en = document.getElementById('mv-en');
  const lang = document.getElementById('mv-lang');
  try { const l = localStorage.getItem('fft_mv_lang'); if (l && [...lang.options].some((o) => o.value === l)) lang.value = l; } catch (e) { /* ignore */ }
  lang.addEventListener('change', () => { try { localStorage.setItem('fft_mv_lang', lang.value); } catch (e) { /* ignore */ } });
  const lname = () => lang.options[lang.selectedIndex].textContent;
  let cur = null;
  const run = async (body, btn) => {
    btn.disabled = true; msg.textContent = '⏳ Making your voice… (5–15 s)';
    const j = await api(body);
    btn.disabled = false;
    if (j.error) { msg.textContent = '⚠️ ' + j.error; if (j.en) { en.value = j.en; res.hidden = false; } return; }
    cur = j.item; msg.textContent = ''; document.getElementById('mv-lname').textContent = lname();
    res.hidden = false; en.value = cur.en;
    const a = document.getElementById('mv-audio'); a.src = cur.url; a.play().catch(() => {});
    const dl = document.getElementById('mv-dl'); dl.href = cur.url; dl.download = 'my-voice-' + new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '') + '.mp3';
    document.getElementById('mv-info').textContent = `${cur.chars} letters · about ${cur.chars} credits (less with Flash)`;
    res.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };
  make.addEventListener('click', () => { const t = document.getElementById('mv-text').value.trim(); if (!t) { msg.textContent = 'Write something first.'; return; } run({ do: 'make', text: t, lang: lang.value }, make); });
  document.getElementById('mv-again').addEventListener('click', (e) => run({ do: 'make', text: document.getElementById('mv-text').value.trim(), en: en.value.trim(), lang: lang.value }, e.target));
  document.getElementById('mv-live').addEventListener('click', (e) => { if (cur) live(cur.id, e.target); });
})();
</script>
<?php page_end();
