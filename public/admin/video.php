<?php
// Video Maker: a game video goes in, a finished Short comes out (made in the browser — the VPS keeps streaming).
require __DIR__ . '/../_boot.php';
$admin = require_login();
page_start('Video Maker', 'video', $admin);
?>
<style>
@font-face { font-family: "Luckiest Guy"; src: url("/actionkick/fonts/LuckiestGuy-Regular.ttf") format("truetype"); font-display: block; }
.vm { display: grid; grid-template-columns: 1fr; gap: 14px; max-width: 1100px; }
@media (min-width: 900px) { .vm { grid-template-columns: 1fr 1fr; } .vm .out-col { grid-row: 1 / span 3; grid-column: 2; } }
.vm .box { background: var(--panel); border: 1px solid var(--line); border-radius: 14px; padding: 14px; }
.vm h3 { font-size: 15px; margin: 0 0 10px; }
.vm .drop { display: block; border: 2px dashed #2a4a9a; border-radius: 12px; padding: 16px; text-align: center; color: #b9c6dc; font-size: 14px; cursor: pointer; }
.vm .drop b { display: block; font-size: 28px; }
.vm .drop input { display: none; }
.vm .fi { margin-top: 8px; font-size: 13px; }
.vm .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.vm canvas#vm-prev { width: 100%; max-width: 360px; aspect-ratio: 4 / 5; border-radius: 12px; border: 2px solid #2a4a9a; display: block; margin: 0 auto; background: #000; }
.vm video { width: 100%; max-width: 360px; aspect-ratio: 4 / 5; border-radius: 12px; border: 2px solid #ffd23a; display: block; margin: 10px auto 0; background: #000; }
.vm .bar { height: 10px; border-radius: 9px; background: #1b2350; overflow: hidden; margin: 10px 0 6px; }
.vm .bar i { display: block; height: 100%; width: 0; background: linear-gradient(90deg, #ff2d55, #ff9f1a); transition: width .2s; }
.vm .tpl { display: inline-block; padding: 8px 12px; border-radius: 10px; background: var(--blue); color: #fff; font-size: 13px; font-weight: 700; }
</style>
<div class="page-head"><div><h1>🎬 Video Maker</h1><p class="muted">Upload a game video on one side — the edited Short comes out on the other. It is made on this phone / PC, so the VPS keeps streaming.</p></div></div>
<div class="vm">
  <div class="box">
    <h3>① Video</h3>
    <label class="drop"><b>🎥</b>Tap to choose the game video<input type="file" id="vm-video-in" accept="video/*"></label>
    <div class="fi" id="vm-video-f"></div>
    <label class="field" style="margin-top:10px"><span>Start the video at (seconds)</span><input id="start" type="number" step="0.05" min="0" value="0.75"></label>
  </div>
  <div class="box">
    <h3>② Audio (your own music)</h3>
    <label class="drop"><b>🎵</b>Tap to choose an audio file (mp3, m4a…)<input type="file" id="vm-audio-in" accept="audio/*"></label>
    <div class="fi" id="vm-audio-f"></div>
    <button type="button" class="btn btn-ghost btn-sm" id="vm-audio-x" hidden style="margin-top:6px">✕ No audio</button>
    <div class="row2" style="margin-top:10px">
      <label class="field"><span>Start the audio at (seconds)</span><input id="aStart" type="number" step="0.1" min="0" value="0"></label>
      <label class="field"><span>Volume (%)</span><input id="vol" type="number" step="5" min="0" max="200" value="100"></label>
    </div>
    <p class="muted small">The game's own sound is not used (like in your template). Without an audio file the video is silent.</p>
  </div>
  <div class="box">
    <h3>③ Template</h3>
    <span class="tpl">⚡ Moment Before Win</span>
    <label class="field" style="margin-top:10px"><span>Top text</span><input id="t1" maxlength="40" value="MOMENT BEFORE WIN"></label>
    <label class="field"><span>Big question at the end</span><input id="t2" maxlength="30" value="WIN YOUR TEAM???"></label>
    <label class="field"><span>Small text at the end (empty = none)</span><input id="t3" maxlength="40" value="*WOULD YOU DRINK THIS?*"></label>
    <details style="margin-top:8px"><summary class="ph-link" style="cursor:pointer">Pictures of the template (optional)</summary>
      <label class="field" style="margin-top:8px"><span>Banner picture (Picsart_25-11-25…png)</span><input type="file" id="vm-img1" accept="image/*"></label>
      <label class="field"><span>End picture (Picsart_26-01-25…png)</span><input type="file" id="vm-img2" accept="image/*"></label>
    </details>
  </div>
  <div class="box out-col">
    <h3>④ Ready video</h3>
    <canvas id="vm-prev" width="432" height="540"></canvas>
    <div hidden><div class="bar"><i id="vm-bar"></i></div></div>
    <p class="small" id="vm-msg" style="margin:8px 0">The preview shows the design. Choose a video, then press Make.</p>
    <button type="button" class="btn btn-green" id="vm-go" style="width:100%">🎬 Make the video</button>
    <div id="vm-out" hidden>
      <video id="vm-video" controls playsinline loop></video>
      <div style="display:flex;gap:8px;margin-top:10px"><button type="button" class="btn btn-blue" id="vm-save" style="flex:1">💾 Save</button><button type="button" class="btn btn-ghost" id="vm-share" style="flex:1">📤 Share</button></div>
    </div>
  </div>
</div>
<script type="module" src="/assets/videomaker.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/videomaker.js') ?>"></script>
<?php page_end();
