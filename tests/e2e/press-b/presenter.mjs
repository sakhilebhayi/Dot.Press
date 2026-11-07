// Checks 31 and 32: presenter view (notes, timer, next slide), the audience window synced over BroadcastChannel, the phone
// remote and audience following over short polling (separate browser contexts), and that speaker notes never reach an
// audience surface.
import { launch, context, login, api, assert, expect, shot, summary, sleep, until, BASE, php, devices } from './lib.mjs';
import { buildAcceptanceDeck } from './fixture.mjs';

const browser = await launch();
const ctx = await context(browser);
const page = await ctx.newPage();
await login(page);
const D = await buildAcceptanceDeck(page);
const SLIDE_COUNT = 9;

await page.goto(`/decks/${D.id}/presenter`);
await page.waitForSelector('.pr-frame .pp-layer');
await sleep(1500);
await shot(page, 'presenter-31-console');

// ---- the console itself
const bodyText = () => page.locator('.pr').innerText();
expect(/Slide 1 of 9/.test(await bodyText()), 'check 31: console shows "Slide 1 of 9"');
expect(/step 0 of 2/.test(await bodyText()), 'check 31: console shows the animation step count of the slide ("step 0 of 2")');
expect(await page.locator('.pr-notes-body').innerText() === 'SPEAKERNOTE-ONE open with the headline.', 'check 32: notes of slide 1 are in the console');
expect((await page.locator('.pr-next h2').innerText()).toLowerCase() === 'next step on this slide', 'check 31: the next panel says the next thing is a step on this slide');
expect(await page.locator('.pr-next-frame').count() === 1, 'check 31: the next panel draws a preview');
expect(await page.locator('.pr-strip-item').count() === SLIDE_COUNT, 'check 31: the slide strip has one thumbnail per slide');
expect(await page.locator('.pr-strip-item.is-hidden').count() === 1, 'check 31: the strip marks the hidden slide');

// ---- timer
expect(await page.locator('.pr-elapsed').innerText() === '00:00', 'check 31: the timer starts at 00:00 and waits');
await page.keyboard.press('ArrowRight');   // first advance starts the timer by itself
await sleep(2300);
const t1 = await page.locator('.pr-elapsed').innerText();
expect(t1 !== '00:00', 'check 31: the timer starts with the first advance (' + t1 + ')');
await page.getByRole('button', { name: 'Pause', exact: true }).click();
await sleep(1200);
const p1 = await page.locator('.pr-elapsed').innerText(); await sleep(2100);
expect(await page.locator('.pr-elapsed').innerText() === p1, 'check 31: Pause holds the time (' + p1 + ')');
await page.getByRole('button', { name: 'Resume', exact: true }).click(); await sleep(1500);
expect(await page.locator('.pr-elapsed').innerText() !== p1, 'check 31: Resume runs the time on');
await page.locator('.pr-target input').fill('1'); await page.locator('.pr-target input').dispatchEvent('change');
await sleep(1200);
expect(/left/.test(await page.locator('.pr-time').innerText()), 'check 31: a target of 1 minute shows time left: ' + (await page.locator('.pr-time').innerText()).replace(/\n/g, ' '));
await page.getByRole('button', { name: 'Reset', exact: true }).click();
expect(await page.locator('.pr-elapsed').innerText() === '00:00', 'check 31: Reset returns the timer to 00:00');

// ---- navigate the console: steps then slides, notes follow
await page.keyboard.press('ArrowRight'); await sleep(900);
expect(/step 2 of 2/.test(await bodyText()), 'check 31: second click reaches step 2 of 2');
expect((await page.locator('.pr-next h2').innerText()).toLowerCase() === 'next slide', 'check 31: after the last step the next panel says "Next slide"');
await page.keyboard.press('ArrowRight'); await sleep(1200);
expect(/Slide 2 of 9/.test(await bodyText()), 'check 31: next click moves to slide 2');
expect(await page.locator('.pr-notes-body').innerText() === 'SPEAKERNOTE-TWO the drivers.', 'check 32: the notes follow the slide');
await page.locator('.pr-strip-item').nth(7).click(); await sleep(900);
expect(/Slide 8 of 9/.test(await bodyText()) && (await page.locator('.pr-notes-body').innerText()).includes('HIDDENSECRET'), 'check 31: the strip can jump to the hidden slide (the presenter may) and shows its notes');
await page.keyboard.press('ArrowRight'); await sleep(1200);
expect(/Slide 9 of 9/.test(await bodyText()), 'check 31: Next from the hidden slide goes on to slide 9');
await page.keyboard.press('Home'); await sleep(1000);

// ---- the audience window (popup, same browser) synced by BroadcastChannel
const [pop] = await Promise.all([page.waitForEvent('popup'), page.getByRole('button', { name: 'Open the audience window' }).click()]);
await pop.waitForLoadState();
await pop.waitForSelector('.pa-view .pp-layer', { timeout: 15000 });
await sleep(1500);
const aSlide = () => pop.evaluate(() => { const l = document.querySelector('.pp-layer:not(.pp-layer-wait)'); return l ? +l.dataset.slide : -1; });
const aSettle = async () => { await until(() => pop.evaluate(() => document.querySelectorAll('.pp-layer:not(.pp-layer-wait)').length === 1 && document.getAnimations().filter((a) => !(a instanceof CSSAnimation)).length === 0), { what: 'audience settled' }); };
expect(await aSlide() === 0, 'check 31: the audience window opens on slide 1');
expect(/connected/i.test(await page.locator('.pr-note').innerText()), 'check 31: the console says the audience window is connected: ' + await page.locator('.pr-note').innerText());
await shot(pop, 'presenter-31-audience-window');
// the audience window carries no notes
const popHtml = await pop.content();
expect(!/SPEAKERNOTE|HIDDENSECRET/.test(popHtml), 'check 32: the audience window page has no speaker notes');
await page.bringToFront();
const press = async (k) => { await page.keyboard.press(k); await sleep(250); };
await press('ArrowRight'); await press('ArrowRight'); await press('ArrowRight');
await sleep(1500); await aSettle();
expect(await aSlide() === 1, 'check 31: advancing in the console moves the audience window to slide 2');
const visible = await pop.evaluate(() => [...document.querySelectorAll('.pp-layer:not(.pp-layer-wait) .p-el')].filter((e) => !e.hasAttribute('aria-hidden')).map((e) => e.dataset.eid));
expect(visible.includes('h') && !visible.includes('b1'), 'check 31: the audience shows the same step as the console (title yes, first bullet not yet): ' + visible.join(','));
await press('ArrowRight'); await sleep(1200);
const visible2 = await pop.evaluate(() => [...document.querySelectorAll('.pp-layer:not(.pp-layer-wait) .p-el')].filter((e) => !e.hasAttribute('aria-hidden')).map((e) => e.dataset.eid));
expect(visible2.includes('b1'), 'check 31: a click in the console reveals the bullet in the audience window');
await press('ArrowLeft'); await sleep(1200);
const visible3 = await pop.evaluate(() => [...document.querySelectorAll('.pp-layer:not(.pp-layer-wait) .p-el')].filter((e) => !e.hasAttribute('aria-hidden')).map((e) => e.dataset.eid));
expect(!visible3.includes('b1'), 'check 31: stepping back in the console hides it again in the audience window');
await page.locator('.pr-strip-item').nth(4).click(); await sleep(1800); await aSettle();
expect(await aSlide() === 4, 'check 31: jumping from the strip jumps the audience window');
await press('b'); await sleep(500);
expect(await pop.locator('.pp-cover.is-black').count() === 1, 'check 31: B blacks the audience window');
expect(/black screen/i.test(await page.locator('.pr-cover').innerText()), 'check 31: the console reminds you the audience sees black');
await press('b'); await sleep(400);
expect(await pop.locator('.pp-cover.is-black').count() === 0, 'check 31: B again lifts the black screen in the audience window');
// closing the console tells the window
// ---- live session: phone remote + follower, separate contexts without cookies
await page.getByRole('button', { name: 'Phone remote and live link' }).click();
await page.getByRole('button', { name: 'Start live session' }).click();
await page.locator('.pr-link code').first().waitFor();
const remoteUrl = (await page.locator('.pr-link code').nth(0).innerText()).trim();
const audUrl = (await page.locator('.pr-link code').nth(1).innerText()).trim();
expect(/\/remote\/[A-Za-z0-9]{40}\?k=/.test(remoteUrl) && /\/live\/[A-Za-z0-9]{40}$/.test(audUrl), 'check 31: the console shows a remote link and an audience link');
await shot(page, 'presenter-31-live-links');
const token = audUrl.split('/live/')[1];
const remoteKey = remoteUrl.split('?k=')[1];

const phone = await context(browser, { ...devices['iPhone 13'], viewport: { width: 390, height: 844 } });
const rp = await phone.newPage();
await rp.goto(remoteUrl);
await rp.getByText(/Connected/).waitFor({ timeout: 10000 });
const remoteCount = () => rp.locator('.rm-count').innerText();
await shot(rp, 'presenter-31-remote');
const here = await page.evaluate(() => document.querySelector('.pr-title span').innerText);
expect((await remoteCount()).startsWith('5 /'), 'check 31: the phone remote shows the console position (5 / 9): ' + await remoteCount());
const fol = await context(browser, { viewport: { width: 1280, height: 720 } });
const fp = await fol.newPage();
await fp.goto(audUrl);
await fp.waitForSelector('.pa-view .pp-layer', { timeout: 15000 });
await sleep(1500);
const fSlide = () => fp.evaluate(() => { const l = document.querySelector('.pp-layer:not(.pp-layer-wait)'); return l ? +l.dataset.slide : -1; });
expect(await fSlide() === 4, 'check 31: the audience follower opens on the presenter\'s slide (5)');
// the remote presses Next on a phone (touch tap)
const t0 = Date.now();
await rp.getByRole('button', { name: 'Next', exact: true }).tap();
await until(async () => { const t = (await page.locator('.pr-title span').innerText()); return /Slide 6 of 9|step 1/.test(t) || /Slide 5 of 9 . step 1/.test(t); }, { timeout: 8000, what: 'console to follow the remote' }).catch(() => {});
const dt = Date.now() - t0;
const consoleNow = await page.locator('.pr-title span').innerText();
expect(consoleNow !== here, `check 31: the phone remote's Next moves the console (${dt} ms, "${consoleNow}")`);
expect(dt < 6000, 'check 31: the remote reaches the console within 6 s');
// slide 5 has 3 click steps; walk to the next slide with the remote
for (let i = 0; i < 3; i++) { await rp.getByRole('button', { name: 'Next', exact: true }).tap(); await sleep(1700); }
await until(async () => /Slide 6 of 9/.test(await page.locator('.pr-title span').innerText()), { timeout: 10000, what: 'console on slide 6' }).catch(() => {});
expect(/Slide 6 of 9/.test(await page.locator('.pr-title span').innerText()), 'check 31: the remote walks the console through the steps to slide 6');
await until(async () => (await fSlide()) === 5, { timeout: 8000, what: 'follower to slide 6' }).catch(() => {});
expect(await fSlide() === 5, 'check 31: the audience follower follows to slide 6 within seconds');
// black from the remote
await rp.getByRole('button', { name: 'Black' }).tap();
await until(() => fp.locator('.pp-cover.is-black').count().then((n) => n === 1), { timeout: 8000, what: 'follower black' }).catch(() => {});
expect(await fp.locator('.pp-cover.is-black').count() === 1, 'check 31: Black on the remote blacks the follower too');
await rp.getByRole('button', { name: 'Black' }).tap(); await sleep(2500);
// notes on the remote
await rp.getByRole('button', { name: 'Notes' }).tap(); await sleep(1500);
const remoteNotes = await rp.locator('.rm-notes').innerText();
expect(/SPEAKERNOTE-SIX/.test(remoteNotes), 'check 32: the phone remote shows the notes of the current slide: "' + remoteNotes.slice(0, 60).replace(/\n/g, ' ') + '"');
await shot(rp, 'presenter-32-remote-notes');

// ---- notes never reach an audience surface
const followerHtml = await fp.content();
expect(!/SPEAKERNOTE|HIDDENSECRET|HIDDENCONTENT/.test(followerHtml), 'check 32: the follower page has no notes and no hidden slide content');
const stateNoKey = await fp.evaluate(async (t) => (await fetch(`/live/${t}/state`)).text(), token);
expect(!/SPEAKERNOTE|notes|title/.test(stateNoKey), 'check 32: /live/{token}/state without the key has no notes or titles: ' + stateNoKey.slice(0, 120));
const stateBadKey = await fp.evaluate(async (t) => (await fetch(`/live/${t}/state?k=wrong`)).text(), token);
expect(!/SPEAKERNOTE|notes/.test(stateBadKey), 'check 32: a wrong key gets no notes');
const stateKey = await fp.evaluate(async ([t, k]) => (await fetch(`/live/${t}/state?k=${k}`)).text(), [token, remoteKey]);
expect(/SPEAKERNOTE-SIX/.test(stateKey), 'check 32: only the remote key unlocks the notes');
const cmdNoKey = await fp.evaluate(async (t) => (await fetch(`/live/${t}/command`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ action: 'next', k: 'nope' }) })).status, token);
expect(cmdNoKey === 403, 'check 31: a command with a wrong key is refused (403)');
const hiddenInFollow = await fp.evaluate(() => [...document.querySelectorAll('.pp-layer')].map((l) => l.innerText).join(' '));
expect(!/HIDDENCONTENT/.test(hiddenInFollow), 'check 32: hidden slide content is not in the follower DOM');

// ---- ending the session
await page.getByRole('button', { name: 'End live session' }).click();
await sleep(500);
await until(() => fp.getByText(/session ended/i).count().then((n) => n > 0), { timeout: 8000, what: 'follower told the session ended' }).catch(() => {});
expect(await fp.getByText(/session ended/i).count() > 0, 'check 31: the follower is told the session ended');
await until(() => rp.getByText(/ended this session/i).count().then((n) => n > 0), { timeout: 8000, what: 'remote told' }).catch(() => {});
expect(await rp.getByText(/ended this session/i).count() > 0, 'check 31: the phone remote is told the session ended');
const after = await fp.evaluate(async (t) => (await fetch(`/live/${t}/state`)).status, token);
expect(after === 410, 'check 31: the ended session answers 410');

// ---- closing the console tells the audience window
await page.close({ runBeforeUnload: true });
await sleep(1500);
expect(await pop.getByText(/session ended|presenter ended/i).count() > 0, 'check 31: closing the console tells the audience window the session ended');

console.log('console errors:', ctx._errors, phone._errors, fol._errors);
expect(ctx._errors.length === 0, 'no console errors in the presenter session');
summary('presenter');
await browser.close();
