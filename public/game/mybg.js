// 🖼 My background for the Shorts games (Cup Kick, Action Kick): the owner picks stadium pictures from his phone,
// and the game shows the chosen one behind the 3D field, players, balls and cup (Place → 🖼 My background).
// The pictures stay on this phone (IndexedDB). Best: tall 9:16 pictures with NO players, ball, cup or text on them.
import * as THREE from 'three';

const css = `
.mbg{margin-top:8px;padding:10px 12px;border:1px solid #38bdf899;border-radius:12px;background:linear-gradient(180deg,#0c2233,#0b1626)}
.mbg[hidden]{display:none}
.mbg p{font-size:12px;color:#a9c8dc;margin:4px 0 0;line-height:1.4}
.mbg .list{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-top:8px}
.mbg .it{position:relative;aspect-ratio:9/16;border-radius:8px;overflow:hidden;border:2px solid transparent;background:#0003 center/cover;cursor:pointer}
.mbg .it.on{border-color:#38bdf8;box-shadow:0 0 0 2px #38bdf855}
.mbg .it b{position:absolute;top:2px;right:2px;width:20px;height:20px;border-radius:50%;background:#000a;color:#fff;font-size:12px;line-height:20px;text-align:center}
.mbg .add{display:flex;align-items:center;justify-content:center;font-size:26px;color:#9fd8f5;border:2px dashed #38bdf877;background:none}
.mbg label.s{display:block;font-size:12px;color:#cfe6f5;margin-top:8px}
.mbg label.s input{width:100%}
.mbg .rnd{display:flex;gap:8px;align-items:center;margin-top:8px;font-size:13px;color:#fff}
`;
const DB = 'fft_bg', ST = 'pics';
const db = () => new Promise((res, rej) => { const q = indexedDB.open(DB, 1); q.onupgradeneeded = () => q.result.createObjectStore(ST, { keyPath: 'id' }); q.onsuccess = () => res(q.result); q.onerror = () => rej(q.error); });
const tx = async (mode, fn) => { const d = await db(); return new Promise((res, rej) => { const t = d.transaction(ST, mode), s = t.objectStore(ST), r = fn(s); t.oncomplete = () => res(r && r.result); t.onerror = () => rej(t.error); }); };
const store = (k, v) => { try { if (v === undefined) return JSON.parse(localStorage.getItem(k) || 'null'); localStorage.setItem(k, JSON.stringify(v)); } catch (e) { return null; } };

/** o = { game, after (element), scene, stage, onChange() } */
export function setupMyBg(o) {
  const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
  const K = 'fft_mbg_' + o.game, S = Object.assign({ pick: '', zoom: 1, pos: -0.15, rnd: false }, store(K) || {});
  const save = () => store(K, S);
  const box = document.createElement('div'); box.className = 'mbg'; box.hidden = true;
  box.innerHTML = `<b style="color:#fff">🖼 My background</b>
    <p>Add stadium pictures from your phone. Best: tall (9:16) pictures with no players, ball, cup or text.</p>
    <div class="list"></div><input type="file" accept="image/*" multiple hidden>
    <label class="s">Zoom <input type="range" data-k="zoom" min="0.8" max="2.2" step="0.05"></label>
    <label class="s">Move up / down <input type="range" data-k="pos" min="-0.6" max="0.6" step="0.01"></label>
    <label class="rnd"><input type="checkbox"> 🎲 A different picture at every START</label>`;
  o.after.insertAdjacentElement('afterend', box);
  const list = box.querySelector('.list'), file = box.querySelector('input[type=file]'), rnd = box.querySelector('.rnd input');
  let pics = [], tex = null, texId = '', active = false;
  const urls = new Map();
  const url = (p) => { if (!urls.has(p.id)) urls.set(p.id, URL.createObjectURL(p.blob)); return urls.get(p.id); };

  const draw = () => {
    list.innerHTML = '';
    for (const p of pics) {
      const d = document.createElement('div'); d.className = 'it' + (p.id === S.pick ? ' on' : ''); d.style.backgroundImage = `url(${url(p)})`;
      d.innerHTML = '<b title="Remove">✕</b>';
      d.onclick = (e) => {
        if (e.target.tagName === 'B') { tx('readwrite', (s) => s.delete(p.id)).then(load); return; }
        S.pick = p.id; save(); draw(); use();
      };
      list.appendChild(d);
    }
    const a = document.createElement('div'); a.className = 'it add'; a.textContent = '+'; a.onclick = () => file.click(); list.appendChild(a);
  };
  const load = async () => {
    try { pics = (await tx('readonly', (s) => s.getAll())) || []; } catch (e) { pics = []; }
    pics.sort((a, b) => a.t - b.t);
    if (!pics.some((p) => p.id === S.pick)) S.pick = pics.length ? pics[0].id : '';
    draw(); use();
  };
  file.onchange = async () => {
    for (const f of file.files) {
      // made smaller first (max 2160 px tall), so the phone stays fast
      const bmp = await createImageBitmap(f), k = Math.min(1, 2160 / bmp.height), c = document.createElement('canvas');
      c.width = Math.round(bmp.width * k); c.height = Math.round(bmp.height * k); c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
      const blob = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.9)), id = 'b' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
      try { await tx('readwrite', (s) => s.put({ id, blob, t: Date.now() })); S.pick = id; } catch (e) { /* storage full */ }
    }
    file.value = ''; save(); load();
  };
  box.querySelectorAll('input[type=range]').forEach((r) => { r.value = S[r.dataset.k]; r.oninput = () => { S[r.dataset.k] = Number(r.value); save(); fit(); }; });
  rnd.checked = S.rnd; rnd.onchange = () => { S.rnd = rnd.checked; save(); };

  // the picture fills the screen like "cover"; zoom and up/down move it so its stands sit just above the field
  const fit = () => {
    if (!tex || !tex.image) return;
    const a = o.stage.clientWidth / o.stage.clientHeight, ia = tex.image.width / tex.image.height;
    let rx = Math.min(1, a / ia), ry = Math.min(1, ia / a);
    rx /= S.zoom; ry /= S.zoom;
    // (the bottom of the picture may go below the screen edge: the 3D field covers that part anyway)
    tex.repeat.set(rx, ry); tex.offset.set((1 - rx) / 2, S.pos);
    if (active) o.scene.background = tex;
  };
  const use = () => {
    const p = pics.find((x) => x.id === S.pick);
    if (!p) { if (tex) { tex.dispose(); tex = null; texId = ''; } o.onChange && o.onChange(); return; }
    if (p.id === texId && tex) { fit(); return; }
    const img = new Image();
    img.onload = () => {
      if (tex) tex.dispose();
      tex = new THREE.Texture(img); tex.colorSpace = THREE.SRGBColorSpace; tex.needsUpdate = true; texId = p.id;
      // the colour of the far end of the field (so the fog there matches the picture)
      const c = document.createElement('canvas'); c.width = 8; c.height = 8; const g = c.getContext('2d'); g.drawImage(img, 0, img.height * 0.55, img.width, img.height * 0.15, 0, 0, 8, 8);
      const d = g.getImageData(0, 0, 8, 8).data; let rr = 0, gg = 0, bb = 0; for (let i = 0; i < d.length; i += 4) { rr += d[i]; gg += d[i + 1]; bb += d[i + 2]; }
      tex.userData.fog = `rgb(${rr / 64 | 0},${gg / 64 | 0},${bb / 64 | 0})`;
      fit(); o.onChange && o.onChange();
    };
    img.src = url(p);
  };
  load();
  window.addEventListener('resize', () => setTimeout(fit, 50));
  return {
    show(on) { box.hidden = !on; },
    /** called by the game's applyTheme for "My background": put the picture behind the scene */
    apply(on) {
      active = on;
      if (on && tex) { fit(); o.scene.background = tex; if (o.scene.fog && tex.userData.fog) o.scene.fog.color.set(tex.userData.fog); }
      return on && !!tex;
    },
    next() { if (!S.rnd || pics.length < 2) return; let p; do { p = pics[(Math.random() * pics.length) | 0]; } while (p.id === S.pick); S.pick = p.id; save(); draw(); use(); },
    refit: fit,
  };
}
