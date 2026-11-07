// Checks 34 and 35: sharing at every visibility level, the public link (password, expiry, rotate, allow download),
// the guest viewer on /s/{token}, and what each role can and cannot do. Drives the real UI.
import fs from 'node:fs';
import { launch, context, login, api, assert, expect, shot, summary, sleep, until, phpJson, php, BASE } from './lib.mjs';
import { buildAcceptanceDeck } from './fixture.mjs';

const browser = await launch();
const owner = await context(browser);
const op = await owner.newPage();
await login(op);
const deck = await buildAcceptanceDeck(op);
const D = deck.id;
const sharing = () => api(op, 'GET', `/api/decks/${D}/sharing`).then((r) => r.json);

// ---- open the Share panel and walk the four levels
await op.goto(`/decks/${D}`);
await op.getByRole('button', { name: 'Share', exact: true }).click();
await op.getByRole('radiogroup', { name: 'Who can open this deck' }).waitFor();
await shot(op, 'share-panel-initial');
const radio = (name) => op.getByRole('radio', { name: new RegExp('^' + name) });

for (const [label, vis] of [['Private', 'private'], ['Shared', 'private'], ['Team', 'team'], ['Public link', 'link']]) {
    await radio(label).click();
    await until(async () => (await sharing()).visibility === vis, { what: label + ' => ' + vis });
    const s = await sharing();
    assert(s.visibility === vis, `visibility level "${label}" stores ${vis}`);
    if (label === 'Public link') assert(!!s.link.token && s.link.token.length === 40, 'public link has a 40 character token');
}
await shot(op, 'share-panel-link');

// ---- link settings through the UI
const url1 = await op.getByLabel('Public link', { exact: true }).inputValue();
assert(/\/s\/[A-Za-z0-9]{40}$/.test(url1), 'the share panel shows the link ' + url1.replace(/[A-Za-z0-9]{40}$/, '<token>'));
await op.getByLabel('New link password').fill('open-sesame');
await op.getByRole('button', { name: 'Set', exact: true }).click();
await until(async () => (await sharing()).link.has_password, { what: 'password set' });
assert(true, 'password set through the panel');
const tomorrow = new Date(Date.now() + 3 * 86400e3).toISOString().slice(0, 10);
await op.locator('#sp-exp').fill(tomorrow);
await op.locator('#sp-exp').dispatchEvent('change');
await until(async () => (await sharing()).link.expires_at, { what: 'expiry set' });
assert(true, 'expiry date set through the panel');
await op.getByLabel('Let visitors save as PDF').check().catch(async () => { await op.getByText('Let visitors save as PDF').click(); });
await until(async () => (await sharing()).link.allow_download, { what: 'allow download on' });
assert(true, 'allow download turned on in the panel');
await shot(op, 'share-panel-settings');

// ---- the guest
const guest = await context(browser);
const gp = await guest.newPage();
const gres = await gp.goto(url1);
assert(gres.status() === 200, 'guest gets 200 for the link');
assert(await gp.getByText('This presentation is protected').isVisible(), 'guest sees the password gate');
assert((await gp.content()).indexOf('SPEAKERNOTE') < 0 && (await gp.content()).indexOf('Q3 production') < 0, 'the locked page carries no deck data');
await gp.getByLabel('Password').fill('wrong-password');
await gp.getByRole('button', { name: 'Open presentation' }).click();
await gp.getByText('That password is not correct.').waitFor();
assert(true, 'a wrong password is refused with a plain message');
await shot(gp, 'share-guest-wrong-password');
await gp.getByLabel('Password').fill('open-sesame');
await gp.getByRole('button', { name: 'Open presentation' }).click();
await gp.waitForSelector('.pp-root .ps-wrap, .pp-root [class*=ps-]', { timeout: 15000 });
await sleep(1200);
await shot(gp, 'share-guest-player');
const html = await gp.content();
for (const leak of ['SPEAKERNOTE', 'HIDDENSECRET', 'HIDDENCONTENT', 'comments', 'share_password']) {
    expect(!html.includes(leak), `guest page does not contain "${leak}"`);
}
const counter = await gp.locator('.pp-count').textContent();
expect(/1 \/ 8/.test(counter), 'player counts 8 slides (hidden slide left out): ' + counter.trim());
for (let i = 0; i < 3; i++) { await gp.keyboard.press('ArrowRight'); await sleep(700); }
expect(/2 \/ 8/.test(await gp.locator('.pp-count').textContent()), 'keyboard navigation works on the shared page (two click steps, then slide 2)');

// document route for the guest
const doc = await gp.evaluate(async (u) => (await fetch(u + '/document')).json(), url1);
expect(doc.slides.length === 8 && doc.slides.every((s) => s.notes === null), 'guest document: 8 slides, notes null');
expect(!JSON.stringify(doc).includes('HIDDEN'), 'guest document has no hidden slide content');
// the video asset streams for the guest
const vid = Object.values(doc.assets).find((a) => /video/.test(a.mime || ''));
expect(!!vid, 'guest asset map has the video');
if (vid) {
    const r = await gp.evaluate(async (u) => { const x = await fetch(u, { headers: { Range: 'bytes=0-99' } }); return { s: x.status, cr: x.headers.get('content-range'), ct: x.headers.get('content-type') }; }, vid.url);
    expect(r.s === 206 && /bytes 0-99/.test(r.cr || ''), 'guest video supports range requests: ' + JSON.stringify(r));
}

// ---- allow download: print view
const token = url1.split('/s/')[1];
const printBtn = gp.getByRole('link', { name: 'Print or save as PDF' });
await gp.mouse.move(400, 400);
expect(await printBtn.count() === 1, 'shared page offers the print button when downloads are allowed');
const pr = await gp.goto(`${BASE}/s/${token}/print`);
assert(pr.status() === 200, 'guest print view opens (200)');
await gp.waitForSelector('.prt-sheet', { timeout: 15000 });
await sleep(1500);
const sheets = await gp.locator('.prt-sheet').count();
expect(sheets === 8, `print view has 8 sheets (hidden slide left out): ${sheets}`);
const phtml = await gp.content();
for (const leak of ['SPEAKERNOTE', 'HIDDENSECRET', 'HIDDENCONTENT']) expect(!phtml.includes(leak), `guest print view does not contain "${leak}"`);
await shot(gp, 'share-guest-print');
// the printed PDF from the guest print view
const pdfPath = '/tmp/press-b-out/guest-print.pdf';
await gp.pdf({ path: pdfPath, preferCSSPageSize: true, printBackground: true });
expect(fs.statSync(pdfPath).size > 5000, 'browser prints the guest view to a PDF');

// a locked link does not print before the password is given
const guest2 = await context(browser);
const g2 = await guest2.newPage();
await g2.goto(`${BASE}/s/${token}/print`);
expect(!g2.url().includes('/print'), 'print view of a protected link redirects a visitor who has not unlocked: ' + g2.url().replace(token, '<t>'));

// allow download off => no button, route 404
await api(op, 'PUT', `/api/decks/${D}/sharing`, { allow_download: false });
const r404 = await gp.goto(`${BASE}/s/${token}/print`);
expect(r404.status() === 404, 'print view is 404 once downloads are off');
await gp.goto(url1); await gp.waitForSelector('.pp-root'); 
expect(await gp.getByRole('link', { name: 'Print or save as PDF' }).count() === 0, 'no print button when downloads are off');

// ---- rotate through the UI
await op.getByRole('button', { name: 'Replace the link' }).first().click();
await op.getByRole('dialog').getByRole('button', { name: 'Replace the link' }).click();
await until(async () => (await sharing()).link.token !== token, { what: 'rotated' });
const url2 = await op.getByLabel('Public link', { exact: true }).inputValue();
expect(url2 !== url1, 'rotating gives a new address');
const old = await guest2.newPage();
const oldRes = await old.goto(url1);
expect(oldRes.status() === 404, 'old link answers 404 after rotating');

// ---- expiry: past date is dead, same as a link that never existed
php(`App\\Models\\Deck::withoutGlobalScopes()->whereKey(${D})->update(['share_expires_at' => now()->subMinute()]);`);
const exp = await old.goto(url2);
expect(exp.status() === 404, 'expired link answers 404');
await op.reload();
await op.getByRole('button', { name: 'Share', exact: true }).click();
await op.getByText('This link has expired').waitFor();
expect(true, 'the owner sees "This link has expired" in the panel');
await shot(op, 'share-panel-expired');
// remove expiry through the UI, link works again
await op.getByRole('button', { name: 'Remove', exact: true }).last().click().catch(() => {});
await until(async () => (await sharing()).link.expires_at === null, { what: 'expiry removed' });
const back = await old.goto(url2);
expect(back.status() === 200, 'link works again once the expiry is removed');

// leaving the link level turns the link off
await radio('Team').click();
await op.getByRole('dialog').getByRole('button', { name: 'Turn it off' }).click();
await until(async () => (await sharing()).visibility === 'team', { what: 'back to team' });
const dead = await old.goto(url2);
expect(dead.status() === 404, 'leaving the public link level kills the link');

console.log('console errors:', owner._errors, guest._errors);
expect(owner._errors.length === 0, 'no console errors in the owner session');
summary('share');
await browser.close();
