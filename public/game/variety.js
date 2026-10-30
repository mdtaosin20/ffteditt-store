// 🎲 Unlimited looks for the Shorts games (Cup Kick, Action Kick): a new stadium and new ball colours from a number.
// The same number always gives the same look, so a look you like can be made again.
// Colours are picked from safe ranges (real-looking skies and grass, team colours in the crowd), so nothing looks strange.
const rng = (s) => () => { s |= 0; s = s + 0x6D2B79F5 | 0; let t = Math.imul(s ^ s >>> 15, 1 | s); t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; };
const hsl = (h, s, l) => `hsl(${Math.round(((h % 360) + 360) % 360)},${Math.round(s)}%,${Math.round(l)}%)`;
const between = (r, a, b) => a + r() * (b - a);
const pick = (r, a) => a[Math.floor(r() * a.length)];

export const newSeed = () => 1 + Math.floor(Math.random() * 99998);

// team colours people know from football shirts (two per team)
const TEAM = [[0, 85, 48], [215, 80, 45], [48, 95, 52], [130, 60, 38], [270, 55, 45], [25, 95, 52], [190, 75, 45], [345, 70, 45], [0, 0, 96], [220, 25, 18], [160, 60, 40], [55, 90, 60]];

/** A stadium: sky, light, grass, stands, roof and the crowd's colours. */
export function genStadium(seed) {
  const r = rng(seed * 7919 + 13);
  const time = r() < 0.4 ? 'day' : r() < 0.55 ? 'night' : 'dusk';
  const th = { name: '🎲 Stadium #' + seed, stadium: true, seed };
  if (time === 'day') {
    const h = between(r, 198, 222);
    th.sky = [hsl(h, between(r, 60, 75), between(r, 42, 52)), hsl(h, 70, between(r, 70, 78)), hsl(h - 5, 75, between(r, 88, 93))];
    th.fogC = hsl(h, 60, 86); th.fog = [45, 120];
    th.sun = ['#fffaf0', between(r, 2.3, 2.8)]; th.hemi = ['#ffffff', '#3c7a3c', 1.0]; th.exp = 1.0; th.lamps = false;
  } else if (time === 'night') {
    const h = between(r, 215, 275);
    th.bg = hsl(h, between(r, 40, 60), between(r, 6, 11)); th.fog = [30, 60];
    th.sun = ['#fff4e0', between(r, 1.8, 2.2)]; th.hemi = ['#dfe8ff', '#2c5a2c', 0.7]; th.exp = 0.9; th.lamps = true;
  } else {
    const h = between(r, 245, 275), warm = between(r, 335, 385);
    th.sky = [hsl(h, 45, between(r, 16, 24)), hsl(warm, between(r, 60, 75), between(r, 56, 64)), hsl(between(r, 25, 42), 90, between(r, 66, 74))];
    th.fogC = hsl(warm + 10, 55, 65); th.fog = [40, 110];
    th.sun = ['#ffb36b', between(r, 2.0, 2.3)]; th.hemi = ['#ffd2b0', '#3a4a2a', 0.8]; th.exp = 0.95; th.lamps = true; th.extra = 'sunset';
  }
  // grass: always a real grass green, a little different each time
  const gh = between(r, 98, 128), gs = between(r, 42, 58), gl = time === 'day' ? between(r, 36, 44) : between(r, 30, 37);
  th.ground = [hsl(gh, gs, gl), hsl(gh, gs, gl - 4), 'rgba(255,255,255,.92)'];
  // stands and roof: grey with a light tint of one team colour
  const t1 = pick(r, TEAM); let t2 = pick(r, TEAM); if (t2 === t1) t2 = TEAM[(TEAM.indexOf(t1) + 3) % TEAM.length];
  th.stand = hsl(t1[0], between(r, 8, 22), time === 'day' ? between(r, 55, 66) : between(r, 22, 32));
  th.roof = hsl(t1[0], between(r, 10, 25), time === 'day' ? between(r, 75, 84) : between(r, 8, 14));
  // the crowd: mostly the two teams' colours, some white and some other shirts
  const tc = (t, d) => hsl(t[0], t[1], Math.max(10, Math.min(96, t[2] + d)));
  th.crowd = [tc(t1, 0), tc(t1, 8), tc(t2, 0), tc(t2, -6), '#f4f6fa', tc(pick(r, TEAM), 0)];
  if (time !== 'dusk' && r() < 0.12) { th.extra = 'snow'; th.ground[0] = hsl(gh, gs - 10, gl + 6); }   // sometimes it snows
  return th;
}

/** Ball colour groups (two colours: the ball and its patches). */
export const BALL_GROUPS = {
  classic: ['⚪ Classic (white & black)', '#fafafa', '#151515'],
  red: ['🔴 Red & white', '#e11d2e', '#fafafa'],
  yellow: ['🟡 Yellow & blue', '#ffd21f', '#1d3fbf'],
  orange: ['🟠 Orange & black', '#ff7a1a', '#141414'],
  blue: ['🔵 Blue & white', '#1e63e9', '#fafafa'],
  green: ['🟢 Neon green & black', '#7cf03a', '#121212'],
  purple: ['🟣 Purple & gold', '#6d28d9', '#f5c518'],
  pink: ['🩷 Pink & white', '#ff4fa3', '#fafafa'],
  gold: ['🟤 Black & gold', '#1a1a1a', '#e7b416'],
  sky: ['🩵 Sky blue & navy', '#58c4f5', '#0f2a5c'],
};

/** "Unlimited" ball colours from a number: a group above, or a new pair that is easy to see on grass. */
export function genBall(seed) {
  const r = rng(seed * 104729 + 7);
  const keys = Object.keys(BALL_GROUPS).filter((k) => k !== 'classic');
  if (r() < 0.4) { const g = BALL_GROUPS[pick(r, keys)]; return { name: g[0], base: g[1], patch: g[2] }; }
  let h = between(r, 0, 360); if (h > 85 && h < 150) h += 70;   // no green ball on green grass
  const light = r() < 0.5;
  const base = light ? hsl(h, between(r, 75, 95), between(r, 52, 62)) : '#f7f7f7';
  // patches: black, white or a deep shade of the same colour (no muddy mixes)
  const patch = light ? pick(r, ['#141414', '#fafafa', hsl(h, 85, 18)]) : hsl(h, between(r, 75, 90), between(r, 38, 48));
  return { name: '🎲 Ball colours #' + seed, base, patch };
}
