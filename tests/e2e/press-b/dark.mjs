// Dark mode pass over the surfaces of checks 34 to 42: screenshots to look at, and a contrast sanity check of text on its background.
import { launch, context, login, api, expect, shot, summary, sleep, openFromMore } from './lib.mjs';
import { buildAcceptanceDeck } from './fixture.mjs';
const browser = await launch();
const ctx = await context(browser, { colorScheme: 'dark' });
const p = await ctx.newPage();
await login(p);
const D = await buildAcceptanceDeck(p);
await api(p, 'PUT', `/api/decks/${D.id}/sharing`, { visibility: 'link' });
const lum = (c) => { const [r, g, b] = c.match(/[\d.]+/g).slice(0, 3).map(Number).map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
const lowContrast = () => p.evaluate(() => {
    const out = [];
    const parse = (c) => c.match(/[\d.]+/g).map(Number);
    const bgOf = (el) => { for (let e = el; e; e = e.parentElement) { const c = getComputedStyle(e).backgroundColor; const a = parse(c); if (a.length === 3 || a[3] > 0.5) return c; } return 'rgb(0,0,0)'; };
    const L = (c) => { const [r, g, b] = parse(c).slice(0, 3).map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
    for (const el of document.querySelectorAll('.ed-insp *, header.tb *, .lw *, .xp *')) {
        if (el.children.length || !el.textContent.trim() || el.closest('[aria-hidden=true], svg')) continue;
        const r = el.getBoundingClientRect(); if (!r.width || !r.height) continue;
        const cs = getComputedStyle(el); if (cs.visibility === 'hidden' || cs.opacity === '0') continue;
        const fg = cs.color, bg = bgOf(el);
        const l1 = L(fg), l2 = L(bg); const ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
        if (ratio < 3) out.push(`${el.tagName.toLowerCase()}.${el.className.toString().slice(0, 20)} "${el.textContent.trim().slice(0, 20)}" ${ratio.toFixed(1)}`);
    }
    return [...new Set(out)].slice(0, 8);
});
await p.goto('/dashboard'); await p.waitForSelector('text=Acceptance deck'); await sleep(1200);
await shot(p, 'dark-library');
let lc = await lowContrast(); expect(lc.length === 0, 'dark: library text contrast >= 3: ' + lc.join(' ; '));
await p.goto(`/decks/${D.id}`); await p.waitForSelector('.es-viewport'); await sleep(1200);
await shot(p, 'dark-editor');
for (const [label, fn] of [
    ['share', async () => { await p.getByRole('button', { name: 'Share', exact: true }).click(); }],
    ['history', async () => { await openFromMore(p, 'Version history'); }],
    ['comments', async () => { await openFromMore(p, 'Comments'); }],
    ['approval', async () => { await p.getByRole('button', { name: /Approval:/ }).click(); }],
    ['export', async () => { await p.getByRole('button', { name: 'Export', exact: true }).click(); await p.getByRole('menuitem', { name: 'PowerPoint', exact: true }).click(); await p.getByRole('button', { name: /Check before exporting/ }).click(); await p.locator('.xp-pf').waitFor({ timeout: 20000 }).catch(async (e) => { await shot(p, 'dark-export-timeout'); console.log(await p.locator('.xp').innerText()); throw e; }); }],
]) { await fn(); await sleep(900); await shot(p, 'dark-' + label); lc = await lowContrast(); expect(lc.length === 0, `dark: ${label} panel text contrast >= 3: ` + lc.join(' ; ')); }
await p.goto(`/decks/${D.id}/presenter`); await p.waitForSelector('.pr-frame .pp-layer'); await sleep(1200); await shot(p, 'dark-presenter');
const g = await context(browser, { colorScheme: 'dark' }); const gp = await g.newPage();
await gp.goto((await api(p, 'GET', `/api/decks/${D.id}/sharing`)).json.link.url); await gp.waitForSelector('.pp-layer'); await sleep(1200); await shot(gp, 'dark-share');
expect(ctx._errors.length === 0, 'no console errors in dark mode: ' + ctx._errors.join('|'));
summary('dark');
await browser.close();
