// 🏭 Auto Shorts (Admin → Auto Shorts): the VPS opens a Shorts game with ?auto=JOB&asig=… in a hidden Chrome.
// The page then plays one random round by itself, records it (⚡ Perfect), writes titles / description / thumbnail
// and sends everything to the panel (public/api/autoshorts.php). Nothing here runs on a normal visit.
import { makeTitles, makeDesc, makeThumb } from '/game/shortsmeta.js?v=2';

const Q = new URLSearchParams(location.search);
export const AUTO = /^v[a-f0-9]{10}$/.test(Q.get('auto') || '') ? { id: Q.get('auto'), sig: Q.get('asig') || '', lv: Number(Q.get('lv')) || 4,
  q: [720, 1080, 1440, 2160].includes(Number(Q.get('q'))) ? Number(Q.get('q')) : 1080, fps: Number(Q.get('f')) === 60 ? 60 : 30, loop: Q.get('loop') === '1' } : null;

const api = (what, body, type) => fetch(`/api/autoshorts.php?do=${what}&job=${AUTO.id}&sig=${encodeURIComponent(AUTO.sig)}${what === 'chunk' ? '' : ''}`, {
  method: 'POST', body, headers: type ? { 'Content-Type': type } : {} });
const fail = (msg) => { try { api('fail', JSON.stringify({ msg: String(msg).slice(0, 200) }), 'application/json'); } catch (e) { /* ignore */ } };

let SETUP = null;   // 🎛 the look of this video (Admin → Auto Shorts → Video look)

/** Before the game sets up its menu: Auto record on, ⚡ Perfect, the size and frame rate of the job — and the
 *  video's look, written where the game itself keeps its menu choices, so the game starts with exactly them. */
export function autoPrep(game) {
  if (!AUTO) return;
  try {
    localStorage.setItem('fft_rec_' + game, JSON.stringify({ on: true, q: AUTO.q, fps: AUTO.fps, mode: 'perfect' }));
    localStorage.removeItem('fft_bgm_' + game);   // (the phone's own sounds are not on the VPS)
    localStorage.setItem('fft_mvg_' + game, JSON.stringify({ on: false }));
  } catch (e) { /* ignore */ }
  try {   // asked once, before the game reads its settings (the voice-over may take a few seconds to make)
    const x = new XMLHttpRequest();
    x.open('POST', `/api/autoshorts.php?do=setup&job=${AUTO.id}&sig=${encodeURIComponent(AUTO.sig)}`, false);
    x.send('{}');
    const s = x.status === 200 ? JSON.parse(x.responseText) : null;
    if (s && s.ls) { SETUP = s; Object.entries(s.ls).forEach(([k, v]) => localStorage.setItem(k, typeof v === 'string' ? v : JSON.stringify(v))); }
  } catch (e) { /* the game's normal look */ }
  addEventListener('error', (e) => fail('page error: ' + (e.message || e)));
  addEventListener('unhandledrejection', (e) => fail('page error: ' + (e.reason && e.reason.message || e.reason)));
}

/** When the menu is ready: a random line-up, the job's level and loop, then START. */
export function autoStart(click) {
  if (!AUTO) return;
  const $ = (id) => document.getElementById(id);
  setTimeout(() => {
    try {
      const d = (SETUP && SETUP.dom) || {};
      if ($('snd')) $('snd').checked = d.snd !== false;
      if ($('mess')) { $('mess').value = String(AUTO.lv); $('mess').dispatchEvent(new Event('input')); }
      if ($('loop')) $('loop').checked = AUTO.loop;
      if ($('np')) { $('np').value = String((SETUP && SETUP.np) || 6); $('np').dispatchEvent(new Event('change')); }
      ['t1', 't2', 't3'].forEach((k) => { if ($(k) && typeof d[k] === 'string') $(k).value = d[k]; });
      if ($('shuffle')) $('shuffle').click();
      setTimeout(() => (click || (() => $('go').click()))(), 800);
    } catch (e) { fail(e.message); }
  }, SETUP && (SETUP.music || (SETUP.ls && Object.keys(SETUP.ls).some((k) => k.startsWith('fft_bgm_')))) ? 6000 : 4000);   // time to load the voice / the song
  let last = -1;
  autoStart.progress = (p) => { const v = Math.round(p * 100); if (v >= last + 5) { last = v; api('progress&p=' + v).catch(() => {}); } };
}

/** The video is made: send it (in 2 MB pieces), then the words and the thumbnail. */
export async function autoSend(blob, snap, info) {
  if (!AUTO) return;
  try {
    const step = 2 * 1048576;
    for (let i = 0, k = 0; i < blob.size; i += step, k++) {
      let ok = false;
      for (let t = 0; t < 4 && !ok; t++) {
        const r = await fetch(`/api/autoshorts.php?do=chunk&i=${k}&job=${AUTO.id}&sig=${encodeURIComponent(AUTO.sig)}`, { method: 'POST', body: blob.slice(i, i + step) }).catch(() => null);
        ok = !!(r && r.ok);
        if (!ok) await new Promise((res) => setTimeout(res, 3000));
      }
      if (!ok) throw new Error('could not send the video');
    }
    let thumb = '';
    if (snap && snap.c) thumb = makeThumb(snap, info).toDataURL('image/jpeg', 0.9);
    const r = await api('finish', JSON.stringify({ titles: makeTitles(info, 6), desc: makeDesc(info), thumb, info }), 'application/json');
    if (!r.ok) throw new Error('the panel did not take the video');
    document.title = 'DONE';
  } catch (e) { fail(e.message); }
}
