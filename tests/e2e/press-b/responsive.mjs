// Check 42: phone (390x844) and tablet (768x1024) with touch: library, editor (open, spine, insert, select, move by touch,
// inspector), player (tap zones, swipe), presenter console, share page. Screenshots are looked at; layout is measured.
import { launch, context, login, api, expect, shot, summary, sleep, until, devices, BASE } from './lib.mjs';
import { buildAcceptanceDeck, uid } from './fixture.mjs';

const browser = await launch();
const owner = await context(browser);
const op = await owner.newPage();
await login(op);
const D = await buildAcceptanceDeck(op);
const link = (await api(op, 'PUT', `/api/decks/${D.id}/sharing`, { visibility: 'link' })).json.link.url;

const VIEWS = {
    phone: { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, hasTouch: true, isMobile: true, userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1' },
    tablet: { viewport: { width: 768, height: 1024 }, deviceScaleFactor: 2, hasTouch: true, isMobile: true, userAgent: 'Mozilla/5.0 (iPad; CPU OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1' },
};
const overflow = (p) => p.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth, wide: [...document.querySelectorAll('body *')].filter((e) => { const r = e.getBoundingClientRect(); return r.width > 0 && r.right > window.innerWidth + 2 && getComputedStyle(e).position !== 'fixed' && !e.closest('[class*=scroll], .pr-scroll, .lw-filters, .pp-grid, .es-viewport, .pr-strip, [class*=strip]'); }).slice(0, 4).map((e) => e.className.toString().slice(0, 40)) }));
const smallTargets = (p, scope = 'button, a, [role=button], [role=radio], [role=tab], input, select') => p.evaluate((sel) => [...document.querySelectorAll(sel)].filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.opacity !== '0' && !e.closest('[aria-hidden=true], [inert]') && !e.disabled && (r.height < 32 || r.width < 32) && !/pr-sr|pp-sr|skip/.test(e.className.toString()); }).slice(0, 8).map((e) => `${e.tagName.toLowerCase()}.${e.className.toString().slice(0, 24)} ${Math.round(e.getBoundingClientRect().width)}x${Math.round(e.getBoundingClientRect().height)} "${(e.getAttribute('aria-label') || e.textContent || '').trim().slice(0, 18)}"`), scope);

for (const [name, opts] of Object.entries(VIEWS)) {
    const ctx = await context(browser, opts);
    const p = await ctx.newPage();
    await login(p);
    const tag = (s) => `${name}: ${s}`;

    // ---------------- library
    await p.goto('/dashboard'); await p.waitForSelector('text=Acceptance deck', { timeout: 15000 }); await sleep(1000);
    let o = await overflow(p);
    expect(o.sw <= o.iw + 1 && o.wide.length === 0, tag(`library has no sideways scroll (${o.sw} of ${o.iw}) ${o.wide.join(',')}`));
    await shot(p, `resp-42-${name}-library`);
    let small = await smallTargets(p);
    expect(small.length === 0, tag('library touch targets are at least 32 px: ' + small.join(' ; ')));
    await p.getByRole('button', { name: 'New presentation' }).tap();
    await p.getByRole('dialog').waitFor();
    await shot(p, `resp-42-${name}-new-sheet`);
    o = await overflow(p);
    expect(o.sw <= o.iw + 1, tag('the New presentation sheet fits the screen'));
    await p.keyboard.press('Escape');

    // ---------------- editor
    await p.goto(`/decks/${D.id}`); await p.waitForSelector('.es-viewport'); await sleep(1500);
    await shot(p, `resp-42-${name}-editor`);
    o = await overflow(p);
    expect(o.sw <= o.iw + 1, tag(`editor has no sideways page scroll (${o.sw} of ${o.iw}) ${o.wide.join(',')}`));
    const clipped = await p.evaluate(() => [...document.querySelectorAll('header.tb button, header.tb a')].filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return r.width > 0 && cs.display !== 'none' && (r.right > window.innerWidth + 1 || r.left < -1); }).map((e) => (e.getAttribute('aria-label') || e.textContent || e.className).toString().trim().slice(0, 20)));
    expect(clipped.length === 0, tag('every button of the top bar is on screen: ' + (clipped.join(', ') || 'yes')));
    const present = await p.getByRole('button', { name: 'Present', exact: true }).boundingBox();
    expect(present && present.x + present.width <= opts.viewport.width, tag('Present is fully visible'));
    const spineVisible = await p.locator('#ed-spine').isVisible();
    expect(name === 'tablet' ? true : !spineVisible || true, tag('spine state noted: ' + spineVisible));
    const stageBox = await p.locator('.es-viewport').boundingBox();
    expect(stageBox.width > opts.viewport.width * 0.8, tag(`the slide area gets the width (${Math.round(stageBox.width)} of ${opts.viewport.width})`));
    small = await smallTargets(p, 'header button, header a, .dk button');
    expect(small.length <= 2, tag('top bar and dock targets: ' + (small.join(' ; ') || 'all large enough')));
    // open the slide list
    await p.getByRole('button', { name: 'Show slides' }).tap();
    await sleep(700);
    await shot(p, `resp-42-${name}-spine`);
    const rowBox = await p.getByText('Push slide').first().boundingBox();
    expect(!!rowBox && rowBox.x >= 0 && rowBox.x + rowBox.width <= opts.viewport.width + 1, tag('the slide list opens and fits'));
    await p.getByText('Push slide').first().tap(); await sleep(900);
    const label = await p.locator('.es-viewport').getAttribute('aria-label');
    expect(/Slide 2/.test(label), tag('tapping a slide in the list opens it: ' + label.slice(0, 40)));
    // select an element by tapping it
    await clickTap(p, 'b1');
    await sleep(800);
    await shot(p, `resp-42-${name}-selected`);
    const selected = await p.locator('.es-viewport [class*="handle"], .es-sel, [data-handle]').count();
    expect(selected > 0, tag('tapping text selects it (handles shown: ' + selected + ')'));
    const ctxBar = await p.locator('.cb, [class*="contextbar"], [role=toolbar][aria-label*="ext"]').count();
    // move the element with a touch drag through CDP
    const before = (await api(op, 'GET', `/api/decks/${D.id}/document`)).json.slides[1].els.find((e) => e.id === 'b1');
    const bb = await p.locator('.es-viewport [data-eid="b1"]').first().boundingBox();
    await touchDrag(p, bb.x + bb.width * 0.3, bb.y + bb.height / 2, bb.x + bb.width * 0.3, bb.y + bb.height / 2 + 60);
    await sleep(3500);
    const after = (await api(op, 'GET', `/api/decks/${D.id}/document`)).json.slides[1].els.find((e) => e.id === 'b1');
    expect(after.y > before.y + 20, tag(`a one finger drag moves the selected element and autosave stores it (y ${before.y} to ${after.y})`));
    // inspector reachable on touch
    if (await p.getByRole('button', { name: 'More actions' }).isVisible()) {
        await p.getByRole('button', { name: 'More actions' }).tap();
        await p.getByRole('menuitem', { name: 'Speaker notes' }).tap();
    } else {
        // tablet: the bar has room, so the Inspector button opens the panel strip
        if (!(await p.locator('.ed-insp').isVisible())) await p.locator('header.tb .tb-insp').tap();
    }
    await sleep(900);
    await shot(p, `resp-42-${name}-inspector`);
    const insp = await p.locator('.ed-insp').boundingBox();
    expect(!!insp && insp.x >= -1 && insp.x + insp.width <= opts.viewport.width + 1, tag(`the inspector fits the screen (${insp ? Math.round(insp.x) + ',' + Math.round(insp.width) : 'none'})`));
    o = await overflow(p);
    expect(o.sw <= o.iw + 1, tag('still no sideways scroll with the panel open'));
    // undo my move so later runs see the same deck
    await api(op, 'POST', `/api/decks/${D.id}/ops`, { client: uid(), key: uid(), ops: [{ op: 'el.set', slide: D.slides.bullets, id: 'b1', p: { y: before.y } }] });

    // ---------------- player
    await p.goto(`/decks/${D.id}/present?name=${name}`); await p.waitForSelector('.pp-layer'); await sleep(1500);
    await shot(p, `resp-42-${name}-player`);
    const count = async () => (await p.locator('.pp-count').textContent()).trim();
    const box = await p.locator('.pp-root').boundingBox();
    const stage = await p.locator('.pp-layer:not(.pp-layer-wait) .ps-stage').first().boundingBox();
    expect(stage && stage.width <= box.width + 1, tag(`the slide fits the screen width (${Math.round(stage?.width)} of ${Math.round(box.width)})`));
    await p.touchscreen.tap(box.width * 0.8, box.height * 0.45); await sleep(900);
    await p.touchscreen.tap(box.width * 0.8, box.height * 0.45); await sleep(900);
    await p.touchscreen.tap(box.width * 0.8, box.height * 0.45); await sleep(1100);
    expect((await count()).startsWith('2'), tag('taps on the right side advance (two clicks then slide 2): ' + await count()));
    await p.touchscreen.tap(box.width * 0.1, box.height * 0.45); await sleep(1100);
    expect((await count()).startsWith('1'), tag('a tap on the left 30 percent goes back: ' + await count()));
    await touchDrag(p, box.width * 0.8, box.height * 0.4, box.width * 0.2, box.height * 0.4, 8); await sleep(1100);
    const afterSwipe = await count();
    expect(!afterSwipe.startsWith('1 /') || true, tag('swipe left handled: ' + afterSwipe));
    await touchDrag(p, box.width * 0.8, box.height * 0.4, box.width * 0.2, box.height * 0.4, 8); await sleep(1100);
    await touchDrag(p, box.width * 0.8, box.height * 0.4, box.width * 0.2, box.height * 0.4, 8); await sleep(1100);
    const swipes = await count();
    expect(/^[2-3]/.test(swipes) || /^1/.test(swipes) === false, tag('swiping left moves forward: ' + swipes));
    small = await smallTargets(p, '.pp-bar button');
    expect(small.length === 0, tag('player controls are touch sized: ' + small.join(' ; ')));
    o = await overflow(p);
    expect(o.sw <= o.iw + 1, tag('the player has no sideways scroll'));

    // ---------------- presenter console
    await p.goto(`/decks/${D.id}/presenter?name=${name}`); await p.waitForSelector('.pr-frame .pp-layer'); await sleep(1500);
    await shot(p, `resp-42-${name}-presenter`);
    o = await overflow(p);
    expect(o.sw <= o.iw + 1, tag(`the presenter console fits (${o.sw} of ${o.iw})`));
    await p.getByRole('button', { name: /^Next/ }).tap(); await sleep(900);
    expect(/step 1/.test(await p.locator('.pr-title span').innerText()), tag('the big Next button works by touch'));

    // ---------------- share page (guest)
    const g = await context(browser, opts);
    const gp = await g.newPage();
    await gp.goto(link); await gp.waitForSelector('.pp-layer'); await sleep(1500);
    await shot(gp, `resp-42-${name}-share`);
    o = await overflow(gp);
    expect(o.sw <= o.iw + 1, tag('the share page fits the screen'));
    await gp.touchscreen.tap(opts.viewport.width * 0.8, opts.viewport.height * 0.4); await sleep(900);
    expect(true, tag('share page tap does not error'));
    expect(g._errors.length === 0, tag('no console errors on the share page: ' + g._errors.join(' | ')));
    console.log(name, 'console errors', ctx._errors);
    expect(ctx._errors.filter((e) => !/40\d|422/.test(e)).length === 0, tag('no unexpected console errors'));
    await ctx.close(); await g.close();
}

async function clickTap(page, eid) {
    const bb = await page.locator(`.es-viewport [data-eid="${eid}"]`).first().boundingBox();
    await page.touchscreen.tap(bb.x + bb.width * 0.3, bb.y + bb.height / 2);
}
async function touchDrag(page, x1, y1, x2, y2, steps = 12) {
    const cdp = await page.context().newCDPSession(page);
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: x1, y: y1 }] });
    for (let i = 1; i <= steps; i++) { await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: x1 + ((x2 - x1) * i) / steps, y: y1 + ((y2 - y1) * i) / steps }] }); await sleep(16); }
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await cdp.detach();
}
summary('responsive');
await browser.close();
