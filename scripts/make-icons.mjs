// Builds the Dot.Press icon set from the mark (an amber dot on an ink rail): SVG, favicon.ico, PNG sizes, the Apple
// touch icon, the web manifest icons and, with --og, the Open Graph image.
//
//   node scripts/make-icons.mjs                       icons only
//   node scripts/make-icons.mjs --og http://127.0.0.1:8832   also the 1200x630 image from the running landing page
//
// Only node built-ins. PNGs are drawn by a headless Chrome that is already installed (set CHROME to its path if it is
// not in the usual place). The icons are committed, so this runs when the mark changes, never at deploy time.
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const pub = join(root, 'public');
const INK = '#14171a';
const PAPER = '#eef0f1';
const AMBER = '#f2a100';

const CHROME = process.env.CHROME || [
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser',
].find((p) => existsSync(p));
if (!CHROME) { console.error('No Chrome found. Set CHROME to a Chrome or Chromium binary.'); process.exit(1); }

/** The tile: ink ground, a paper rail, an amber dot with a paper outline. inset shrinks the art for maskable icons. */
function tile({ radius = 7, inset = 0 } = {}) {
    const s = 1 - inset * 2;
    const t = (v) => (16 + (v - 16) * s).toFixed(3);
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">
  <rect width="32" height="32" rx="${radius}" fill="${INK}"/>
  <line x1="0" y1="16" x2="32" y2="16" stroke="${PAPER}" stroke-width="${(2 * s).toFixed(3)}"/>
  <circle cx="${t(12)}" cy="16" r="${(6.4 * s).toFixed(3)}" fill="${AMBER}" stroke="${PAPER}" stroke-width="${(2 * s).toFixed(3)}"/>
</svg>
`;
}

/** The favicon draws itself for the tab colour: a dark tile on a light tab, a light tile on a dark tab. */
const faviconSvg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">
  <style>
    .t{fill:${INK}}.r{stroke:${PAPER}}.d{stroke:${PAPER}}
    @media (prefers-color-scheme: dark){.t{fill:${PAPER}}.r,.d{stroke:${INK}}}
  </style>
  <rect class="t" width="32" height="32" rx="7"/>
  <line class="r" x1="0" y1="16" x2="32" y2="16" stroke-width="2"/>
  <circle class="d" cx="12" cy="16" r="6.4" fill="${AMBER}" stroke-width="2"/>
</svg>
`;

const work = mkdtempSync(join(tmpdir(), 'press-icons-'));
function shot(html, w, h, out, bg = '00000000') {
    const page = join(work, `${w}x${h}-${Math.random().toString(36).slice(2)}.html`);
    writeFileSync(page, html);
    execFileSync(CHROME, ['--headless=new', '--disable-gpu', '--hide-scrollbars', '--force-device-scale-factor=1', `--default-background-color=${bg}`, `--window-size=${w},${h}`, `--screenshot=${out}`, pathToFileURL(page).href], { stdio: 'ignore' });
}
const raster = (svg, size, out) => shot(`<!doctype html><meta charset="utf-8"><style>html,body{margin:0;background:transparent}svg{display:block;width:${size}px;height:${size}px}</style>${svg}`, size, size, out);

writeFileSync(join(pub, 'favicon.svg'), faviconSvg);
const rounded = tile({ radius: 7 });
const square = tile({ radius: 0 });
const maskable = tile({ radius: 0, inset: 0.1 });
const sizes = { 'favicon-16x16.png': [rounded, 16], 'favicon-32x32.png': [rounded, 32], 'favicon-48.tmp.png': [rounded, 48], 'apple-touch-icon.png': [square, 180], 'icon-192.png': [rounded, 192], 'icon-512.png': [rounded, 512], 'icon-maskable-512.png': [maskable, 512], 'dot_pres.png': [rounded, 512] };
for (const [name, [svg, size]] of Object.entries(sizes)) raster(svg, size, join(pub, name));

// favicon.ico: three PNG images (16, 32, 48) in one ICO container.
const imgs = [16, 32, 48].map((n) => readFileSync(join(pub, n === 48 ? 'favicon-48.tmp.png' : `favicon-${n}x${n}.png`)));
const head = Buffer.alloc(6); head.writeUInt16LE(1, 2); head.writeUInt16LE(imgs.length, 4);
let offset = 6 + imgs.length * 16;
const dir = imgs.map((png, i) => {
    const e = Buffer.alloc(16); const n = [16, 32, 48][i];
    e.writeUInt8(n, 0); e.writeUInt8(n, 1); e.writeUInt16LE(1, 4); e.writeUInt16LE(32, 6); e.writeUInt32LE(png.length, 8); e.writeUInt32LE(offset, 12);
    offset += png.length; return e;
});
writeFileSync(join(pub, 'favicon.ico'), Buffer.concat([head, ...dir, ...imgs]));
rmSync(join(pub, 'favicon-48.tmp.png'));

writeFileSync(join(pub, 'site.webmanifest'), JSON.stringify({
    name: 'Dot.Press', short_name: 'Press',
    description: 'Presentations built as a line of claims: each slide is a bead, hollow until it shows evidence.',
    start_url: '/dashboard', scope: '/', display: 'standalone',
    background_color: PAPER, theme_color: INK,
    icons: [
        { src: '/favicon.svg', sizes: 'any', type: 'image/svg+xml' },
        { src: '/icon-192.png', sizes: '192x192', type: 'image/png' },
        { src: '/icon-512.png', sizes: '512x512', type: 'image/png' },
        { src: '/icon-maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
    ],
}, null, 2) + '\n');

const og = process.argv.indexOf('--og');
if (og > -1) {
    // The landing page draws itself at 1200x630 when asked (?og=1): the real sample slide, the real beads, the real type.
    const url = `${process.argv[og + 1].replace(/\/$/, '')}/?og=1`;
    execFileSync(CHROME, ['--headless=new', '--disable-gpu', '--hide-scrollbars', '--force-device-scale-factor=1', '--virtual-time-budget=8000', '--window-size=1200,630', `--screenshot=${join(pub, 'og.png')}`, url], { stdio: 'ignore' });
}

rmSync(work, { recursive: true, force: true });
console.log('icons written to public/' + (og > -1 ? ' (with og.png)' : ''));
