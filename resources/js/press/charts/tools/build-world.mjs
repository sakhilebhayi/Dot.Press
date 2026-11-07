// Dev tool: builds ../world.js from the Natural Earth 110m admin-0 countries GeoJSON.
// Usage: node tools/build-world.mjs path/to/ne_110m_admin_0_countries.geojson
// Natural Earth is public domain (naturalearthdata.com). Projection: Robinson.
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const src = process.argv[2];
if (!src) { console.error('usage: build-world.mjs <geojson>'); process.exit(1); }
const geo = JSON.parse(readFileSync(src, 'utf8'));

const PLEN = [1.0, 0.9986, 0.9954, 0.99, 0.9822, 0.973, 0.96, 0.9427, 0.9216, 0.8962, 0.8679, 0.835, 0.7986, 0.7597, 0.7186, 0.6732, 0.6213, 0.5722, 0.5322];
const PDFE = [0.0, 0.062, 0.124, 0.186, 0.248, 0.31, 0.372, 0.434, 0.4958, 0.5571, 0.6176, 0.6769, 0.7346, 0.7903, 0.8435, 0.8936, 0.9394, 0.9761, 1.0];
function robinson(lon, lat) {
  const a = Math.min(90, Math.abs(lat)) / 5;
  const i = Math.min(17, Math.floor(a));
  const f = a - i;
  const plen = PLEN[i] + (PLEN[i + 1] - PLEN[i]) * f;
  const pdfe = PDFE[i] + (PDFE[i + 1] - PDFE[i]) * f;
  const x = 0.8487 * plen * (lon * Math.PI / 180);
  const y = 1.3523 * pdfe * Math.sign(lat || 1);
  return [x, -y];
}
const W = 1200;
const SCALE = W / (2 * 0.8487 * Math.PI);

function dp(pts, tol) {
  if (pts.length < 3) return pts;
  const keep = new Uint8Array(pts.length); keep[0] = keep[pts.length - 1] = 1;
  const stack = [[0, pts.length - 1]];
  while (stack.length) {
    const [s, e] = stack.pop();
    let md = 0, mi = -1;
    const [ax, ay] = pts[s], [bx, by] = pts[e];
    const dx = bx - ax, dy = by - ay, len = Math.hypot(dx, dy) || 1e-9;
    for (let i = s + 1; i < e; i++) {
      const d = len < 1e-6 ? Math.hypot(pts[i][0] - ax, pts[i][1] - ay) : Math.abs((pts[i][0] - ax) * dy - (pts[i][1] - ay) * dx) / len;
      if (d > md) { md = d; mi = i; }
    }
    if (md > tol && mi > 0) { keep[mi] = 1; stack.push([s, mi], [mi, e]); }
  }
  return pts.filter((_, i) => keep[i]);
}
const area = (r) => { let a = 0; for (let i = 0; i < r.length; i++) { const [x1, y1] = r[i], [x2, y2] = r[(i + 1) % r.length]; a += x1 * y2 - x2 * y1; } return Math.abs(a / 2); };

const TOL = 0.5;
const raw = [];
for (const f of geo.features) {
  const p = f.properties;
  let code = p.ISO_A2;
  if (!code || code === '-99') code = p.ISO_A2_EH;
  if (!code || code === '-99') code = p.WB_A2;
  if (!code || code === '-99') { if (p.ADM0_A3 === 'KOS' || p.NAME === 'Kosovo') code = 'XK'; else if (p.NAME === 'N. Cyprus') code = 'XC'; else if (p.NAME === 'Somaliland') code = 'XS'; else continue; }
  if (code === 'AQ') continue;
  const polys = f.geometry.type === 'Polygon' ? [f.geometry.coordinates] : f.geometry.coordinates;
  const rings = [];
  for (const poly of polys) {
    const outer = poly[0].map(([lo, la]) => { const [x, y] = robinson(lo, la); return [x * SCALE, y * SCALE]; });
    rings.push(outer);
  }
  raw.push({ code, name: p.NAME_LONG && p.NAME_LONG.length < 28 ? p.NAME_LONG : p.NAME, rings });
}
let minY = Infinity, maxY = -Infinity;
for (const c of raw) for (const r of c.rings) for (const [, y] of r) { if (y < minY) minY = y; if (y > maxY) maxY = y; }
const H = Math.ceil(maxY - minY);
const out = [];
for (const c of raw) {
  const rings = c.rings.map((r) => r.map(([x, y]) => [x + W / 2, y - minY]));
  const sorted = rings.map((r) => ({ r, a: area(r) })).sort((a, b) => b.a - a.a);
  let d = '';
  sorted.forEach((o, idx) => {
    if (idx > 0 && o.a < 1.2) return;
    const pts = dp(o.r, TOL).map(([x, y]) => [Math.round(x * 2) / 2, Math.round(y * 2) / 2]);
    // drop consecutive duplicates
    const q0 = pts.filter((p, i) => i === 0 || p[0] !== pts[i - 1][0] || p[1] !== pts[i - 1][1]);
    const q = q0.length > 1 && q0[0][0] === q0[q0.length - 1][0] && q0[0][1] === q0[q0.length - 1][1] ? q0.slice(0, -1) : q0;
    if (q.length < 3) return;
    const f = (n) => String(n);
    let s = 'M' + f(q[0][0]) + ' ' + f(q[0][1]) + 'l';
    let px = q[0][0], py = q[0][1], first = true;
    for (let i = 1; i < q.length; i++) {
      const dx = q[i][0] - px, dy = q[i][1] - py; px = q[i][0]; py = q[i][1];
      const a = f(dx), b = f(dy);
      s += (first ? '' : (a[0] === '-' ? '' : ' ')) + a + (b[0] === '-' ? '' : ' ') + b;
      first = false;
    }
    d += s + 'z';
  });
  if (d) out.push({ c: c.code, n: c.name, d });
}
out.sort((a, b) => a.c.localeCompare(b.c));
const here = dirname(fileURLToPath(import.meta.url));
const js = `// World map geometry for the Dot.Press "map" chart.
// NOTICE: derived from Natural Earth 110m admin-0 countries (naturalearthdata.com),
// which is in the public domain. Projected with the Robinson projection, simplified
// (Douglas-Peucker) and written as relative path data in a ${W} x ${H} box.
// Antarctica is omitted. Codes are ISO 3166 alpha-2; XK is Kosovo, XC N. Cyprus, XS Somaliland.
// Regenerate with: node resources/js/press/charts/tools/build-world.mjs <ne_110m_admin_0_countries.geojson>
export const WORLD_W = ${W};
export const WORLD_H = ${H};
export const WORLD = ${JSON.stringify(out)};
`;
writeFileSync(join(here, '..', 'world.js'), js);
console.log(out.length, 'countries', js.length, 'bytes');
