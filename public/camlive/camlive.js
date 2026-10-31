// The camera page the VPS opens for a Camera Live link: it answers the phone's WebRTC call (through /api/golive.php,
// like Join Live) and shows his camera full screen with his voice. Before he joins: "LIVE STARTING SOON".
const QS = new URLSearchParams(location.search);
const stream = QS.get('stream'), sig = QS.get('sig');
const ICE = [{ urls: ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302'] }];
const $ = (id) => document.getElementById(id);
let pc = null, sess = '';
const api = (body) => fetch('/api/golive.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ stream: Number(stream), sig, sess, ...body }) }).catch(() => {});
const show = (on) => { $('v').classList.toggle('on', on); $('wait').style.display = on ? 'none' : 'flex'; };
const close = () => { if (pc) { try { pc.close(); } catch (e) { /* closed */ } } pc = null; $('v').srcObject = null; $('a').srcObject = null; show(false); };

const answer = async (offer) => {
  close();
  pc = new RTCPeerConnection({ iceServers: ICE });
  pc.ontrack = (e) => {
    if (e.track.kind === 'video') { $('v').srcObject = new MediaStream([e.track]); $('v').play().catch(() => {}); return; }
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
    if (!g.on) { if (sess) { sess = ''; close(); } return; }
    if (g.sess !== sess && g.offer) { sess = g.sess; await answer(g.offer); }
  } catch (e) { /* again soon */ }
};
check(); setInterval(check, 1500);
