// 🏟 Big stadium for the Shorts games (Cup Kick, Action Kick): a round bowl of stands full of people around the field,
// a lit roof ring, a big screen, a row of country flags, and three skies — day with clouds, sunset, night with stars
// and floodlights. Made the first time it is chosen in the menu (Place → 🏟 Big stadium …).
import * as THREE from 'three';

const rng = (s) => () => { s |= 0; s = s + 0x6D2B79F5 | 0; let t = Math.imul(s ^ s >>> 15, 1 | s); t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; };

/** o = { scene, tex(w, h, draw), flags() → [country codes], title() → text on the big screen } */
export function makeBigStadium(o) {
  const r = rng(2026), root = new THREE.Group(); o.scene.add(root);
  const CZ = -6, R0 = 18.5, A0 = Math.PI * 1.08, A1 = Math.PI * 1.92;   // the bowl: a part of a circle behind the players
  const at = (rad, a, y) => new THREE.Vector3(Math.cos(a) * rad, y, CZ + Math.sin(a) * rad);
  const lam = (c, e) => new THREE.MeshLambertMaterial({ color: c, side: THREE.DoubleSide, ...(e ? { emissive: e } : {}) });

  // stands: two decks of steps, a glowing ribbon between them
  const standMat = lam('#6b7280'), ribbonMat = new THREE.MeshBasicMaterial({ color: '#ff3d7f' });
  const rows = [];
  for (let k = 0; k < 26; k++) {
    const upper = k >= 13, rad = R0 + k * 0.95 + (upper ? 1.6 : 0), y = 0.5 + k * 0.62 + (upper ? 1.4 : 0);
    rows.push([rad, y]);
    const seg = new THREE.Mesh(new THREE.CylinderGeometry(rad + 0.95, rad + 0.95, 0.62, 96, 1, true, Math.PI / 2 - A1, A1 - A0), standMat);
    seg.position.set(0, y - 0.31, CZ); root.add(seg);
    const top = new THREE.Mesh(new THREE.RingGeometry(rad, rad + 0.95, 96, 1, A0, A1 - A0), standMat);
    top.rotation.x = Math.PI / 2; top.position.set(0, y, CZ); root.add(top);
  }
  const ribbon = new THREE.Mesh(new THREE.CylinderGeometry(R0 + 13 * 0.95 + 0.8, R0 + 13 * 0.95 + 0.8, 0.9, 96, 1, true, Math.PI / 2 - A1, A1 - A0), ribbonMat);
  ribbon.position.set(0, 0.5 + 13 * 0.62 + 0.6, CZ); root.add(ribbon);

  // the crowd: thousands of little people in many shirt colours
  const N = 7200, crowd = new THREE.InstancedMesh(new THREE.BoxGeometry(0.32, 0.46, 0.28), new THREE.MeshLambertMaterial(), N);
  const m4 = new THREE.Matrix4(), q = new THREE.Quaternion(), s1 = new THREE.Vector3(1, 1, 1), col = new THREE.Color();
  const SHIRTS = ['#f5c518', '#2563eb', '#e11d48', '#f8fafc', '#16a34a', '#f97316', '#7c3aed', '#0ea5e9', '#111827', '#ec4899'];
  let n = 0;
  for (const [rad, y] of rows) {
    const per = Math.floor((A1 - A0) * (rad + 0.45) / 0.4);
    for (let i = 0; i < per && n < N; i++) {
      if (r() < 0.07) continue;
      const a = A0 + (i + 0.5 + (r() - 0.5) * 0.3) / per * (A1 - A0), p = at(rad + 0.45, a, y + 0.23);
      q.setFromAxisAngle(new THREE.Vector3(0, 1, 0), -a - Math.PI / 2);
      m4.compose(p, q, s1); crowd.setMatrixAt(n, m4); crowd.setColorAt(n, col.set(SHIRTS[(r() * SHIRTS.length) | 0])); n++;
    }
  }
  crowd.count = n; root.add(crowd);

  // roof ring with a row of lights under its edge
  const roofR = R0 + 27 * 0.95 + 1.6, roofY = 0.5 + 26 * 0.62 + 2.6;
  const roof = new THREE.Mesh(new THREE.RingGeometry(roofR - 6, roofR + 1, 96, 1, A0 - 0.08, A1 - A0 + 0.16), lam('#d7dbe2'));
  roof.rotation.x = Math.PI / 2; roof.position.set(0, roofY, CZ); root.add(roof);
  const roofEdge = new THREE.Mesh(new THREE.CylinderGeometry(roofR - 6, roofR - 6, 0.7, 96, 1, true, Math.PI / 2 - A1 - 0.08, A1 - A0 + 0.16), lam('#aeb4be'));
  roofEdge.position.set(0, roofY - 0.35, CZ); root.add(roofEdge);
  const bulbs = new THREE.InstancedMesh(new THREE.BoxGeometry(0.7, 0.18, 0.3), new THREE.MeshBasicMaterial({ color: '#fff8dc' }), 90);
  for (let i = 0; i < 90; i++) { const a = A0 - 0.06 + i / 89 * (A1 - A0 + 0.12); m4.compose(at(roofR - 6.1, a, roofY - 0.8), q.setFromAxisAngle(new THREE.Vector3(0, 1, 0), -a - Math.PI / 2), s1); bulbs.setMatrixAt(i, m4); }
  root.add(bulbs);

  // floodlight towers (lit at night)
  const flood = new THREE.Group(); root.add(flood);
  const lightMat = new THREE.MeshBasicMaterial({ color: '#eaf4ff' });
  for (const a of [Math.PI * 1.4, Math.PI * 1.6]) {   // on the roof, in the corners of the picture
    const p = at(roofR - 3, a, roofY + 1.6), panel = new THREE.Group(); panel.position.copy(p); panel.lookAt(0, 0, 0);
    for (let i = 0; i < 4; i++) for (let j = 0; j < 3; j++) { const b = new THREE.Mesh(new THREE.BoxGeometry(0.8, 0.6, 0.2), lightMat); b.position.set((i - 1.5) * 0.95, (j - 1) * 0.75, 0); panel.add(b); }
    flood.add(panel);
  }

  // the big screen
  const screenTex = () => o.tex(1024, 512, (g, w, h) => {
    const gr = g.createLinearGradient(0, 0, w, h); gr.addColorStop(0, '#1e1b6e'); gr.addColorStop(1, '#3b1c8c'); g.fillStyle = gr; g.fillRect(0, 0, w, h);
    g.strokeStyle = '#8ab4ff'; g.lineWidth = 16; g.strokeRect(8, 8, w - 16, h - 16);
    const t = (o.title && o.title()) || 'FLAG CUP';
    g.fillStyle = '#fff'; g.textAlign = 'center'; g.textBaseline = 'middle';
    let fs = 170; g.font = `900 ${fs}px Arial`; while (g.measureText(t).width > w - 140 && fs > 40) { fs -= 6; g.font = `900 ${fs}px Arial`; }
    g.shadowColor = 'rgba(0,0,0,.5)'; g.shadowBlur = 18; g.fillText(t, w / 2, h / 2 - 20);
    g.font = '900 70px Arial'; g.fillStyle = '#ffd23a'; g.fillText('⚽  ★ ★ ★  ⚽', w / 2, h - 90);
  });
  const screen = new THREE.Mesh(new THREE.PlaneGeometry(9, 4.5), new THREE.MeshBasicMaterial({ map: screenTex() }));
  const sp = at(R0 + 13 * 0.95 + 0.8, Math.PI * 1.5, 0.5 + 13 * 0.62 + 2.6); screen.position.set(0, sp.y, sp.z + 0.9); root.add(screen);   // hangs over the glowing ribbon
  const frame = new THREE.Mesh(new THREE.BoxGeometry(9.5, 5, 0.4), lam('#111827')); frame.position.set(0, sp.y, sp.z + 0.65); root.add(frame);

  // a row of country flags along the front of the stands
  const flagRow = new THREE.Group(); root.add(flagRow);
  // the country flags come from the flag picture sheet (loaded once)
  let atlas = null, info = null;
  const loadAtlas = Promise.all([
    new Promise((res) => { const im = new Image(); im.onload = () => res(im); im.onerror = () => res(null); im.src = '/flags/flags.png?v=2'; }),
    fetch('/flags/flags.json?v=2').then((x) => x.json()).catch(() => null),
  ]).then(([im, j]) => { atlas = im; info = j; });
  const drawFlag = (g, code, w, h) => {
    const i = info.countries.findIndex((c) => c.code === code); if (i < 0) return;
    g.drawImage(atlas, (i % info.cols) * info.w, Math.floor(i / info.cols) * info.h, info.w, info.h, 0, 0, w, h);
  };
  const makeFlags = () => {
    flagRow.clear();
    if (!atlas || !info) return;
    const list = o.flags(), [rad, y] = rows[9];
    const count = 18;
    for (let i = 0; i < count; i++) {
      const code = list[i % list.length], a = A0 + 0.06 + (i + 0.5) / count * (A1 - A0 - 0.12);
      const t = o.tex(96, 64, (g, w, h) => drawFlag(g, code, w, h));
      const f = new THREE.Mesh(new THREE.PlaneGeometry(1.5, 1.0), new THREE.MeshBasicMaterial({ map: t, side: THREE.DoubleSide }));
      const p = at(rad - 0.05, a, y + 0.1); f.position.copy(p); f.lookAt(0, y + 0.1, CZ); flagRow.add(f);
    }
  };
  loadAtlas.then(makeFlags);

  // skies: clouds (day / sunset) and stars (night)
  const sky = new THREE.Group(); root.add(sky);
  const cloudMat = new THREE.MeshLambertMaterial({ color: '#ffffff', emissive: '#555555' });
  const clouds = new THREE.Group(); sky.add(clouds);
  for (let c = 0; c < 9; c++) {
    const g = new THREE.Group(), x = (r() * 2 - 1) * 40, y = 26 + r() * 14, z = -70 - r() * 40;
    for (let k = 0; k < 6; k++) { const b = new THREE.Mesh(new THREE.BoxGeometry(3 + r() * 4, 1.6 + r() * 1.4, 3), cloudMat); b.position.set((r() - 0.5) * 7, (r() - 0.3) * 1.5, (r() - 0.5) * 2); g.add(b); }
    g.position.set(x, y, z); clouds.add(g);
  }
  const sg = new THREE.BufferGeometry(), sp2 = new Float32Array(900 * 3);
  for (let i = 0; i < 900; i++) { sp2[i * 3] = (r() * 2 - 1) * 120; sp2[i * 3 + 1] = 22 + r() * 70; sp2[i * 3 + 2] = -90 - r() * 30; }
  sg.setAttribute('position', new THREE.BufferAttribute(sp2, 3));
  const stars = new THREE.Points(sg, new THREE.PointsMaterial({ color: '#ffffff', size: 0.35, fog: false })); sky.add(stars);
  const sunDisk = new THREE.Mesh(new THREE.CircleGeometry(6, 40), new THREE.MeshBasicMaterial({ color: '#ffd27a', fog: false })); sunDisk.position.set(0, 20, -110); sky.add(sunDisk);

  const MODES = {
    day: { stand: '#8b93a3', ribbon: '#3b82f6', cloud: ['#ffffff', '#666666'], clouds: true, stars: false, sun: false, flood: false },
    sunset: { stand: '#6e5a63', ribbon: '#ff6a3d', cloud: ['#ffb38a', '#7a3b2a'], clouds: true, stars: false, sun: true, flood: false },
    night: { stand: '#3c4152', ribbon: '#ff2d8a', cloud: ['#ffffff', '#000000'], clouds: false, stars: true, sun: false, flood: true },
  };
  return {
    root,
    show(mode) {
      root.visible = !!mode; if (!mode) return;
      const m = MODES[mode] || MODES.day;
      standMat.color.set(m.stand); ribbonMat.color.set(m.ribbon); cloudMat.color.set(m.cloud[0]); cloudMat.emissive.set(m.cloud[1]);
      clouds.visible = m.clouds; stars.visible = m.stars; sunDisk.visible = m.sun; flood.visible = m.flood;
    },
    refresh() { screen.material.map.dispose(); screen.material.map = screenTex(); makeFlags(); },
  };
}
