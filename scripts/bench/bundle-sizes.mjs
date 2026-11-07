// Bundle sizes per route from the Vite manifest: the JavaScript and CSS a route loads up front (its entry and every static
// import), what it can load later (dynamic imports), the offline player, and the font payload.
//   npm run build && node scripts/bench/bundle-sizes.mjs [--json out.json] [--modules Editor]
// --modules <name> lists the source files that contribute most bytes to a chunk; it needs a build with source maps:
//   npx vite build --sourcemap --outDir /tmp/press-sm && node scripts/bench/bundle-sizes.mjs --dir /tmp/press-sm --modules Editor
import { readFileSync, existsSync, readdirSync, statSync } from 'node:fs';
import { gzipSync, brotliCompressSync } from 'node:zlib';
import { join } from 'node:path';
import { args, recorder } from './lib.mjs';

const a = args();
const dir = a.dir && a.dir !== true ? a.dir : 'public/build';
const manifest = JSON.parse(readFileSync(join(dir, '.vite/manifest.json').replace('/.vite', existsSync(join(dir, '.vite/manifest.json')) ? '/.vite' : ''), 'utf8'));
const rec = recorder('Dot.Press bundle sizes (KB)');
const kb = (n) => n / 1024;
const sizeCache = new Map();
function sizes(file) {
    if (!sizeCache.has(file)) {
        const buf = readFileSync(join(dir, file));
        sizeCache.set(file, { raw: buf.length, gz: gzipSync(buf, { level: 9 }).length, br: brotliCompressSync(buf).length });
    }
    return sizeCache.get(file);
}

function closure(key, seen = new Set(), dyn = new Set()) {
    const chunk = manifest[key];
    if (!chunk || seen.has(key)) return { seen, dyn };
    seen.add(key);
    for (const imp of chunk.imports || []) closure(imp, seen, dyn);
    if (key !== 'resources/js/app.js') for (const d of chunk.dynamicImports || []) dyn.add(d); // the app's own dynamic imports are the page glob
    return { seen, dyn };
}

const entries = Object.entries(manifest).filter(([key]) => key === 'resources/js/app.js' || key.startsWith('resources/js/Pages/Press/') || key === 'resources/js/Pages/Welcome.vue');
const appKey = Object.keys(manifest).find((k) => k === 'resources/js/app.js');
for (const [key, chunk] of entries) {
    const name = key.replace('resources/js/', '');
    const { seen, dyn } = closure(key);
    if (appKey && key !== appKey) closure(appKey, seen, new Set()); // every page runs inside the app shell; its page glob is not 'lazy' for this page
    const js = [...seen].map((k) => manifest[k].file).filter((f) => f.endsWith('.js'));
    const css = new Set();
    for (const k of seen) for (const c of manifest[k].css || []) css.add(c);
    const add = (files) => files.reduce((acc, f) => { const s = sizes(f); return { raw: acc.raw + s.raw, gz: acc.gz + s.gz, br: acc.br + s.br }; }, { raw: 0, gz: 0, br: 0 });
    const j = add(js);
    const c = add([...css]);
    rec.put(name, 'js_upfront_kb', kb(j.raw));
    rec.put(name, 'js_upfront_gzip_kb', kb(j.gz));
    rec.put(name, 'js_upfront_brotli_kb', kb(j.br));
    rec.put(name, 'css_upfront_gzip_kb', kb(c.gz));
    rec.put(name, 'js_chunks_upfront', js.length);
    const lazy = [...dyn].filter((d) => !seen.has(d));
    if (lazy.length) {
        const l = add(lazy.map((d) => manifest[d].file).filter((f) => f.endsWith('.js')));
        rec.put(name, 'js_lazy_gzip_kb', kb(l.gz));
        rec.put(name, 'js_lazy_chunks', lazy.length);
    }
    if (a.modules && name.includes(String(a.modules))) rec.put(name, 'largest_chunk', js.map((f) => `${f}:${Math.round(kb(sizes(f).raw))}KB`).sort((x, y) => parseInt(y.split(':')[1]) - parseInt(x.split(':')[1])).slice(0, 4).join(' '));
}

// --modules: which source files make up the biggest chunks (needs source maps next to the chunks).
function moduleBytes(file) {
    const mapFile = join(dir, `${file}.map`);
    if (!existsSync(mapFile)) return null;
    const map = JSON.parse(readFileSync(mapFile, 'utf8'));
    const code = readFileSync(join(dir, file), 'utf8').split('\n');
    const B64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
    const decode = (seg) => { const out = []; let shift = 0; let value = 0; for (const ch of seg) { const d = B64.indexOf(ch); value += (d & 31) << shift; if (d & 32) shift += 5; else { out.push(value & 1 ? -(value >> 1) : value >> 1); value = 0; shift = 0; } } return out; };
    const bytes = new Map();
    let src = 0;
    map.mappings.split(';').forEach((line, row) => {
        let col = 0;
        const segs = line.split(',').filter(Boolean).map((sg) => { const f = decode(sg); col += f[0]; if (f.length > 1) src += f[1]; return { col, src: f.length > 1 ? src : null }; });
        segs.forEach((sg, i) => {
            if (sg.src === null) return;
            const end = i + 1 < segs.length ? segs[i + 1].col : (code[row] || '').length;
            const name = (map.sources[sg.src] || '').replace(/^(\.\.\/)+/, '');
            bytes.set(name, (bytes.get(name) || 0) + Math.max(0, end - sg.col));
        });
    });
    return [...bytes.entries()].sort((x, y) => y[1] - x[1]);
}
if (a.modules) {
    const chunkFile = Object.values(manifest).map((c) => c.file).filter((f) => f.endsWith('.js') && f.includes(String(a.modules)));
    for (const f of chunkFile) {
        const rows = moduleBytes(f);
        if (!rows) { console.warn(`no source map for ${f}`); continue; }
        rec.put(`modules ${f}`, 'total_kb', kb(rows.reduce((t, r) => t + r[1], 0)));
        rows.slice(0, 25).forEach(([n, b]) => rec.put(`modules ${f}`, n.slice(-70), kb(b)));
    }
}

if (existsSync('public/build/standalone/press-player.iife.js')) {
    const js = readFileSync('public/build/standalone/press-player.iife.js');
    const css = readFileSync('public/build/standalone/press-player.css');
    rec.put('offline_player', 'js_kb', kb(js.length));
    rec.put('offline_player', 'js_gzip_kb', kb(gzipSync(js).length));
    rec.put('offline_player', 'css_gzip_kb', kb(gzipSync(css).length));
}

// Fonts: the interface fonts are loaded by every editor page; slide fonts only when a slide uses the family.
function fontSizes(path) {
    if (!existsSync(path)) return [];
    return readdirSync(path, { withFileTypes: true }).flatMap((d) => (d.isDirectory() ? fontSizes(join(path, d.name)) : /\.(woff2?|ttf|otf)$/.test(d.name) ? [{ name: d.name, bytes: statSync(join(path, d.name)).size }] : []));
}
const ui = fontSizes('public/fonts/ui');
const slide = fontSizes('public/fonts').filter((f) => !ui.some((u) => u.name === f.name));
rec.put('fonts', 'interface_fonts_total_kb', kb(ui.reduce((s, f) => s + f.bytes, 0)));
rec.put('fonts', 'interface_font_files', ui.length);
rec.put('fonts', 'slide_fonts_total_kb', kb(slide.reduce((s, f) => s + f.bytes, 0)));
rec.put('fonts', 'slide_font_files', slide.length);
const css = existsSync('public/fonts/fonts.css') ? readFileSync('public/fonts/fonts.css', 'utf8') : '';
rec.put('fonts', 'slide_fonts_have_unicode_range_subsets', /unicode-range/.test(css) ? 'yes' : 'no');
const largest = [...slide].sort((x, y) => y.bytes - x.bytes)[0];
if (largest) rec.put('fonts', 'largest_slide_font', `${largest.name} ${Math.round(kb(largest.bytes))} KB`);

rec.print();
rec.save(a.json);
