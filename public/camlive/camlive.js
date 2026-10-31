// The camera page the VPS opens for a Camera Live link: it answers the phone's WebRTC call (through /api/golive.php,
// like Join Live) and shows his camera full screen with his voice. Before he joins: "LIVE STARTING SOON".
const QS = new URLSearchParams(location.search);
const stream = QS.get('stream'), sig = QS.get('sig');
const ICE = [{ urls: ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302'] }];
const $ = (id) => document.getElementById(id);
let pc = null, sess = '';
const api = (body) => fetch('/api/golive.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ stream: Number(stream), sig, sess, ...body }) }).catch(() => {});
const show = (on) => { $('v').classList.toggle('on', on); $('wait').style.display = on ? 'none' : 'flex'; fit(); };
// same shape as the live (phone held the right way): fill the screen. Other shape: show all of it, with a soft blurred copy behind
// 📐 his turn / straighten / mirror (from his phone): the video box is turned; 90° turns swap its width and height
let view = { r: 0, q: 0, m: false };
const fit = () => {
  const v = $('v'), on = v.classList.contains('on') && v.videoWidth > 0, odd = view.q % 2 === 1, W = innerWidth, H = innerHeight;
  const bw = odd ? H : W, bh = odd ? W : H, a = v.videoWidth / v.videoHeight, ba = bw / bh, same = on && Math.abs(a - ba) / ba < 0.2;
  const rad = Math.abs(view.r) * Math.PI / 180, s = Math.cos(rad) + Math.sin(rad) * Math.max(ba, 1 / ba);
  for (const el of [v, $('bg')]) {
    el.style.width = bw + 'px'; el.style.height = bh + 'px'; el.style.left = (W - bw) / 2 + 'px'; el.style.top = (H - bh) / 2 + 'px'; el.style.right = el.style.bottom = 'auto';
    el.style.transform = `rotate(${view.q * 90 + view.r}deg) scale(${(s * (el === v ? 1 : 1.15)).toFixed(3)}) scaleX(${view.m ? -1 : 1})`;
  }
  v.classList.toggle('fit', on && !same); $('bg').classList.toggle('on', on && !same);
};
addEventListener('resize', fit);
$('v').addEventListener('resize', fit); $('v').addEventListener('loadedmetadata', fit);
const close = () => { if (pc) { try { pc.close(); } catch (e) { /* closed */ } } pc = null; $('v').srcObject = null; $('bg').srcObject = null; $('a').srcObject = null; show(false); };

const answer = async (offer) => {
  close();
  pc = new RTCPeerConnection({ iceServers: ICE });
  pc.ontrack = (e) => {
    if (e.track.kind === 'video') { const ms = new MediaStream([e.track]); $('v').srcObject = ms; $('bg').srcObject = ms; $('v').play().catch(() => {}); $('bg').play().catch(() => {}); return; }
    $('a').srcObject = new MediaStream([e.track]); $('a').volume = 1; $('a').play().catch(() => {});
  };
  pc.onconnectionstatechange = () => {
    const s = pc ? pc.connectionState : 'closed';
    api({ state: s });
    if (s === 'connected') show(true);
    if (s === 'failed' || s === 'closed') close();
  };
  await pc.setRemoteDescription({ type: 'offer', sdp: offer });
  await pc.setLocalDescription(await pc.createAnswer());
  await new Promise((res) => {
    if (pc.iceGatheringState === 'complete') return res();
    const t = setTimeout(res, 3000);
    pc.onicegatheringstatechange = () => { if (pc.iceGatheringState === 'complete') { clearTimeout(t); res(); } };
  });
  await api({ answer: pc.localDescription.sdp });
};
const check = async () => {
  if (!stream || !sig) return;
  try {
    const r = await fetch(`/api/golive.php?stream=${encodeURIComponent(stream)}&sig=${encodeURIComponent(sig)}`, { cache: 'no-store' });
    if (!r.ok) return;
    const g = await r.json();
    if (g.name) $('who').textContent = g.name;
    if (g.view && JSON.stringify(g.view) !== JSON.stringify(view)) { view = { r: Number(g.view.r) || 0, q: Number(g.view.q) || 0, m: !!g.view.m }; fit(); }
    if (!g.on) { if (sess) { sess = ''; close(); } return; }
    if (g.sess !== sess && g.offer) { sess = g.sess; await answer(g.offer); }
  } catch (e) { /* again soon */ }
};
check(); setInterval(check, 1500);
