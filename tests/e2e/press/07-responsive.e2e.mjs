// The editor and the library at phone, tablet and laptop widths, in light and dark: nothing overflows, nothing is clipped off screen,
// the Inspector can be opened from a button, panels can be found by name, the slide stays visible beside or above the panel.
import { MOD, launch, newContext, suite, ok, eq, sleep, shot, savedDoc, saved, openDeck, createDeck, sendOps, center, BASE } from './lib.mjs';

const t = suite('07-responsive');
const browser = await launch();
const owner = await newContext(browser, 'owner@demo.test');
const deckId = await createDeck(owner.page, 'E2E responsive ' + Date.now());
const slideId = (await savedDoc(owner.page, deckId)).slides[0].id;
await sendOps(owner.page, deckId, [
    { op: 'el.add', slide: slideId, el: { id: 'box', type: 'shape', kind: 'rect', x: 300, y: 300, w: 500, h: 300, fill: '#2e86ab' } },
    { op: 'el.add', slide: slideId, el: { id: 'words', type: 'text', x: 900, y: 300, w: 800, h: 200, doc: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Responsive check' }] }] }, size: 80, color: '@ink' } },
]);
await owner.ctx.close();

const sizes = [['phone', 390, 844, true], ['tablet', 768, 1024, true], ['small laptop', 1024, 768, false], ['laptop', 1180, 800, false], ['wide', 1440, 900, false]];
for (const scheme of ['light', 'dark']) {
    for (const [name, w, h, touch] of sizes) {
        const id = `${name.replace(' ', '-')}-${scheme}`;
        await t.step(id, `${name} ${w}x${h}, ${scheme}: no horizontal overflow, every control reachable, Inspector opens from its button, the slide stays visible beside or above the panel`, async () => {
            const { ctx, page } = await newContext(browser, 'owner@demo.test', { viewport: { width: w, height: h }, colorScheme: scheme, hasTouch: touch, isMobile: touch && w < 600 });
            t.page = page;
            await openDeck(page, deckId);
            await sleep(500);
            const overflow = await page.evaluate(() => ({ doc: document.documentElement.scrollWidth - innerWidth, ed: document.querySelector('.ed').scrollWidth - document.querySelector('.ed').clientWidth }));
            ok(overflow.doc <= 0, 'horizontal overflow ' + JSON.stringify(overflow));
            // every visible button of the top bar and the dock lies inside the viewport
            const out = await page.evaluate(() => [...document.querySelectorAll('.tb button:not([hidden]), .tb a, .dk > button')].filter((e) => e.offsetParent !== null).map((e) => { const b = e.getBoundingClientRect(); return { n: (e.getAttribute('aria-label') || e.innerText || e.className).slice(0, 30), l: Math.round(b.left), r: Math.round(b.right), w: Math.round(b.width), h: Math.round(b.height) }; }).filter((b) => b.r > innerWidth + 1 || b.l < -1));
            if (w >= 768) eq(out, [], 'top bar and dock buttons inside the viewport');
            // a sheet of the app (the slide) is on screen and big enough to work with
            const sheet = await page.locator('.es-sheet-in').boundingBox();
            ok(sheet.width > Math.min(250, w * 0.6), 'the slide is drawn: ' + sheet.width);
            // Inspector button: in the top bar (768 and up) or the dock (under 1180) or the overflow menu (phone)
            const topBtn = page.locator('.tb-insp');
            const dockBtn = page.locator('.dk-insp');
            const target = (await topBtn.isVisible()) ? topBtn : (await dockBtn.isVisible()) ? dockBtn : null;
            ok(target, 'a visible Inspector button exists');
            if (w < 1180) ok(await dockBtn.isVisible(), 'the dock has the Inspector button under 1180');
            await target.click();
            await sleep(700);
            const insp = await page.locator('.ed-insp').boundingBox();
            ok(insp && insp.width > 200 && insp.height > 150, 'the inspector is open: ' + JSON.stringify(insp));
            const sheet2 = await page.locator('.es-sheet-in').boundingBox();
            ok(sheet2.width > Math.min(250, w * 0.5) && sheet2.height > 100, 'the slide is still visible beside or above the panel: ' + JSON.stringify(sheet2));
            ok(!(w >= 768 && w < 1180) || sheet2.x + sheet2.width <= insp.x + 2, 'the slide does not sit under the drawer at tablet widths');
            if (w < 768) ok(sheet2.y + sheet2.height <= insp.y + 2, 'the slide is above the bottom sheet');
            // panels by name: the picker shows the active panel's name, the list has every panel
            const pick = page.locator('.in-pick');
            ok(await pick.isVisible(), 'the panel picker is visible');
            const label = (await pick.innerText()).trim();
            ok(label.length >= 4, 'the active panel is named in words: ' + label);
            await pick.click();
            const items = await page.getByRole('menuitem').allInnerTexts();
            ok(items.length >= 8, 'the list names the panels: ' + items.length);
            await page.keyboard.press('Escape');
            await shot(page, `07-${id}`);
            // close it with the same button
            await target.click();
            await sleep(500);
            eq(await page.locator('.ed-insp').count(), 0, 'the Inspector button closes it again');
            // the slide list opens and closes
            if (w < 1024) {
                await page.locator('.tb-menu').click();
                await sleep(500);
                ok(await page.locator('.sp').isVisible(), 'the slide list opens');
                await page.locator('.ed-scrim').click({ position: { x: w - 10, y: h / 2 } });
                await sleep(400);
            }
            await ctx.close();
        });
    }
}

await t.step('library-phone', 'the library at 390 wide: the New flow opens, the templates tab scrolls, nothing overflows', async () => {
    const { ctx, page } = await newContext(browser, 'owner@demo.test', { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });
    t.page = page;
    await page.goto(BASE + '/dashboard');
    await sleep(500);
    const over = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
    ok(over <= 0, 'library overflows by ' + over);
    await shot(page, '07-library-phone');
    await page.locator('[data-primary-action]').click(); // the one New of the library
    await page.getByRole('tab', { name: 'Templates' }).click();
    await sleep(800);
    const over2 = await page.evaluate(() => document.querySelector('.ns').scrollWidth - document.querySelector('.ns').clientWidth);
    ok(over2 <= 0, 'the New sheet overflows by ' + over2);
    await shot(page, '07-new-phone');
    await ctx.close();
});

const bad = t.summary();
await browser.close();
process.exit(bad ? 1 : 0);
