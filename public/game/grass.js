// 🌱 Real 3D grass for the Shorts games: many small grass blades standing on the field, in the colours of the
// mowed stripes, darker at the root and lighter at the tip, moving a little in the wind.
// Only where the camera looks (near the cup and the players); the white lines stay free.
import * as THREE from 'three';

const rng = (s) => () => { s |= 0; s = s + 0x6D2B79F5 | 0; let t = Math.imul(s ^ s >>> 15, 1 | s); t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; };

/** o = { scene, count, field: {z0, depth, width, stripes}, lines(x, z) → true where a white line is } */
export function makeGrass(o) {
  const N = o.count || 60000;
  // one blade: a thin bent triangle pair (5 points), root at y = 0
  const g = new THREE.BufferGeometry();
  const P = [-0.5, 0, 0, 0.5, 0, 0, -0.32, 0.55, 0.06, 0.32, 0.55, 0.06, 0, 1, 0.2];
  const C = [0.7, 0.7, 0.7, 0.7, 0.7, 0.7, 0.95, 0.95, 0.95, 0.95, 0.95, 0.95, 1.15, 1.15, 1.15];   // dark root → light tip
  g.setAttribute('position', new THREE.Float32BufferAttribute(P, 3));
  g.setAttribute('color', new THREE.Float32BufferAttribute(C, 3));
  g.setIndex([0, 1, 2, 1, 3, 2, 2, 3, 4]);
  g.computeVertexNormals();
  const mat = new THREE.MeshLambertMaterial({ vertexColors: true, side: THREE.DoubleSide });
  const time = { value: 0 };
  mat.onBeforeCompile = (sh) => {   // wind: the tips move a little, each blade a bit differently
    sh.uniforms.uTime = time;
    sh.vertexShader = 'uniform float uTime;\n' + sh.vertexShader.replace('#include <begin_vertex>', `#include <begin_vertex>
      vec2 wp = vec2(instanceMatrix[3][0], instanceMatrix[3][2]);
      float sway = sin(uTime * 1.7 + wp.x * 0.9 + wp.y * 0.6) * 0.5 + sin(uTime * 2.9 + wp.x * 2.3) * 0.2;
      transformed.x += sway * 0.22 * position.y * position.y;`);
  };
  const mesh = new THREE.InstancedMesh(g, mat, N);
  mesh.frustumCulled = false; mesh.receiveShadow = true;
  const r = rng(91), m = new THREE.Matrix4(), q = new THREE.Quaternion(), e = new THREE.Euler(), s = new THREE.Vector3(), p = new THREE.Vector3();
  const F = o.field, place = [];
  let n = 0, tries = 0;
  while (n < N && tries < N * 4) {
    tries++;
    // more blades near the camera (z from +6 to -22), the strip gets wider further away
    const z = 6 - Math.pow(r(), 1.35) * 28, half = 2.2 + (6 - z) * 0.34;
    const x = (r() * 2 - 1) * half;
    if (o.lines && o.lines(x, z)) continue;
    const h = 0.05 + r() * 0.07, w = 0.03 + r() * 0.02;
    e.set((r() - 0.5) * 0.5, r() * Math.PI * 2, (r() - 0.5) * 0.5); q.setFromEuler(e);
    s.set(w, h, w); p.set(x, 0, z);
    m.compose(p, q, s); mesh.setMatrixAt(n, m);
    place.push([x, z, 0.9 + r() * 0.2]);
    n++;
  }
  mesh.count = n;
  o.scene.add(mesh);
  const col = new THREE.Color(), a = new THREE.Color(), b = new THREE.Color();
  return {
    mesh,
    /** paint the blades in the stripe colours of the ground (or hide them: sand, snow, street) */
    paint(c1, c2, show) {
      mesh.visible = !!show; if (!show) return;
      a.set(c1); b.set(c2);
      for (let k = 0; k < n; k++) {
        const [x, z, v] = place[k], i = Math.floor((z - F.z0) / F.depth * F.stripes);
        col.copy(i % 2 ? a : b).multiplyScalar(v); mesh.setColorAt(k, col);
      }
      if (mesh.instanceColor) mesh.instanceColor.needsUpdate = true;
    },
    tick(sec) { time.value = sec; },
  };
}
