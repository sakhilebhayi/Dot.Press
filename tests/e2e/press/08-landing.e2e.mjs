// The arrival row and the ledger of the library, in a real browser: the original logo, a welcome with the person's name,
// exactly one primary action per role that leads somewhere real (and no second New in the header), the way back to InfoDot
// in the foot, a ledger whose rows select and open from the keyboard, no sideways scroll and 44px touch targets on a phone. Light and dark, 1440 and 375 wide. E2E_NEW_MEMBER (an account with no presentations) adds the empty state.
// The drawn page of each run is saved beside the screenshots as landing-<who>-<width>-<scheme>.html.
import fs from 'node:fs';
import path from 'node:path';
import { launch, newContext, suite, ok, eq, sleep, shot, waitUntil, BASE, SHOTS } from './lib.mjs';

const t = suite('08-landing');
const browser = await launch();
const people = [['owner', 'owner@demo.test', 'new'], ['editor', 'editor@demo.test', 'new'], ['viewer', 'viewer@demo.test', 'open']];
if (process.env.E2E_NEW_MEMBER) people.push(['new-member', process.env.E2E_NEW_MEMBER, 'new']);
const sizes = [[1440, 900, false], [375, 812, true]];

for (const [who, email, kind] of people) {
    for (const scheme of ['light', 'dark']) {
        for (const [w, h, touch] of sizes) {
            const id = `${who}-${w}-${scheme}`;
            await t.step(id, `${who} at ${w}px, ${scheme}: logo, welcome, one primary action, the way back, nothing overflows`, async () => {
                const { ctx, page } = await newContext(browser, email, { viewport: { width: w, height: h }, colorScheme: scheme, hasTouch: touch, isMobile: touch });
                t.page = page;
                await page.goto(BASE + '/dashboard');
                await page.waitForSelector('.ld');
                await waitUntil(page, () => document.querySelector('.lg-wait') === null);
                await sleep(1200); // the entrance has settled and the thumbnails are drawn
                const seen = await page.evaluate(() => {
                    const box = (e) => { const b = e.getBoundingClientRect(); return { w: Math.round(b.width), h: Math.round(b.height), top: Math.round(b.top), bottom: Math.round(b.bottom) }; };
                    const shown = (e) => e.offsetParent !== null && getComputedStyle(e).visibility !== 'hidden';
                    const primary = [...document.querySelectorAll('[data-primary-action]')];
                    const logo = [...document.querySelectorAll('.ld-logo img')].filter(shown);
                    const filled = [...document.querySelectorAll('.pr-btn--primary')].filter(shown);
                    return {
                        ground: getComputedStyle(document.querySelector('.lib')).backgroundColor,
                        overflow: document.documentElement.scrollWidth - innerWidth,
                        h1: [...document.querySelectorAll('h1')].map((e) => e.innerText.trim()),
                        h2Lines: Math.round(document.querySelector('.ld-h').getBoundingClientRect().height / parseFloat(getComputedStyle(document.querySelector('.ld-h')).lineHeight)),
                        headline: document.querySelector('.ld-h').innerText.trim(),
                        headerNew: document.querySelectorAll('.lh-new').length,
                        rows: document.querySelectorAll('.lr').length,
                        pageScrolls: document.documentElement.scrollHeight - innerHeight,
                        welcome: document.querySelector('.ld-hello')?.innerText || '',
                        primary: primary.map((e) => ({ text: e.innerText.trim(), href: e.getAttribute('href'), ...box(e) })),
                        filled: filled.length,
                        logo: logo.map((e) => ({ src: e.getAttribute('src'), natural: e.naturalWidth, alt: e.alt, ...box(e) })),
                        back: [...document.querySelectorAll('.foot nav a')].map((e) => ({ text: e.innerText.trim(), href: e.href, ...box(e) })),
                        small: [...document.querySelectorAll('.ld a, .ld button, .bar button, .bar select, .foot a, .lr-star, .lr-more')].filter(shown).filter((e) => e.getAttribute('aria-hidden') !== 'true').map((e) => ({ n: e.innerText.trim().slice(0, 24), ...box(e) })).filter((b) => b.h < 44),
                        featured: document.querySelector('.st-title')?.innerText || null,
                        drawn: document.querySelectorAll('.st-prev .ps-wrap').length,
                        rowDrawn: document.querySelectorAll('.lr-th .ps-wrap').length,
                        selected: document.querySelectorAll('.lr[aria-selected=true]').length,
                    };
                });
                fs.writeFileSync(path.join(SHOTS, `landing-${id}.html`), await page.content());
                await page.screenshot({ path: path.join(SHOTS, `landing-${id}.png`) });
                await page.screenshot({ path: path.join(SHOTS, `landing-${id}-full.png`), fullPage: true });

                eq(seen.ground, scheme === 'dark' ? 'rgb(16, 18, 20)' : 'rgb(238, 240, 241)', 'the theme follows the system setting');
                ok(seen.overflow <= 0, 'the page scrolls sideways by ' + seen.overflow);
                eq(seen.h1, ['Presentations'], 'one h1, the page title in the bar');
                ok(seen.h2Lines <= 2, 'the headline runs to ' + seen.h2Lines + ' lines');
                eq(seen.headerNew, 0, 'no second New in the header');
                if (w >= 1000) ok(seen.pageScrolls <= 0, 'the page itself scrolls by ' + seen.pageScrolls + ' (the ledger scrolls inside it)');
                ok(/^Welcome, \S+\.$/.test(seen.welcome), 'the welcome names the person: ' + seen.welcome);
                eq(seen.primary.length, 1, 'exactly one primary action');
                eq(seen.filled, 1, 'exactly one filled button on the page');
                ok(seen.primary[0].bottom <= h, 'the primary action is on the first screen (bottom at ' + seen.primary[0].bottom + ')');
                eq(seen.logo.length, 1, 'one logo is shown');
                eq(seen.logo[0].src, scheme === 'dark' ? '/images/logo-light.png' : '/images/logo.png', 'the logo for this ground');
                eq(seen.logo[0].natural, 2362, 'the logo is the original file, loaded');
                ok(seen.logo[0].w >= 88 && seen.logo[0].w === seen.logo[0].h, 'the logo keeps its shape: ' + JSON.stringify(seen.logo[0]));
                eq(seen.back.map((b) => b.text), ['InfoDot', 'Platforms'], 'the way back, in the foot');
                ok(/\/home$/.test(seen.back[0].href) && /\/platforms$/.test(seen.back[1].href), 'the way back goes to InfoDot home and its Platforms page');
                if (touch) eq(seen.small, [], 'touch targets under 44px');
                if (who === 'new-member') eq([seen.headline, seen.rows, seen.drawn], ['Nothing on this line yet.', 0, 0], 'a new member is told the line is empty and is shown no row and no slide');
                else {
                    ok(seen.rows > 0 && seen.rowDrawn > 0, 'the rows show real slide thumbnails: ' + seen.rowDrawn + ' of ' + seen.rows);
                    if (w >= 640) {
                        ok(seen.featured && seen.drawn === 1, 'the stage draws the selected presentation with the slide renderer: ' + seen.featured);
                        eq(seen.selected, 1, 'exactly one row is selected');
                        ok(seen.headline.includes(seen.featured), 'the headline names the featured presentation, which is the selected one: ' + seen.headline);
                    }
                }

                // the ledger: a click selects, the arrow keys move and select, Enter opens, a bad ?sel= selects nothing it should not
                if (who !== 'new-member' && w >= 1000) {
                    const titles = await page.locator('.lr-link').evaluateAll((els) => els.map((e) => e.textContent.trim()));
                    ok(titles.length >= 2, 'at least two rows to move between');
                    const stageTitle = async () => { await sleep(450); return page.locator('.st-title').innerText(); }; // the stage fades the old one out first
                    await page.locator('.lr-link').nth(1).click();
                    eq(await stageTitle(), titles[1], 'a click on a row selects it and the stage follows');
                    ok(/[?&]sel=\d+/.test(page.url()), 'the selection is in the address: ' + page.url());
                    await page.locator('.lr-link').nth(1).focus();
                    await page.keyboard.press('ArrowDown');
                    if (titles.length > 2) eq(await stageTitle(), titles[2], 'ArrowDown moves the selection to the next row');
                    await page.keyboard.press('Home');
                    eq(await stageTitle(), titles[0], 'Home goes to the first row');
                    await page.goto(BASE + '/dashboard?sel=999999');
                    await page.waitForSelector('.lr[aria-selected=true]');
                    eq(await page.locator('.lr[aria-selected=true]').count(), 1, 'a ?sel= that is not a loaded row selects the featured or first row instead');
                    await page.goto(BASE + '/dashboard');
                    await page.waitForSelector('.lr');
                    await page.locator('.lr-link').nth(0).focus();
                    await Promise.all([page.waitForURL(/\/decks\/\d+$/), page.keyboard.press('Enter')]);
                    await page.waitForSelector('.ed');
                    await page.goto(BASE + '/dashboard');
                    await page.waitForSelector('.ld');
                }

                // the primary action leads somewhere real
                if (kind === 'new') {
                    ok(/\/dashboard\?new=1$/.test(seen.primary[0].href), 'the primary action has a real address: ' + seen.primary[0].href);
                    await page.locator('[data-primary-action]').click();
                    await page.waitForSelector('.ns[role=dialog]');
                    ok(/[?&]new=1/.test(page.url()), 'the open sheet is in the address');
                    await page.goto(BASE + '/dashboard?new=1');
                    await page.waitForSelector('.ns[role=dialog]');
                    await page.keyboard.press('Escape');
                    await waitUntil(page, () => document.querySelector('.ns[role=dialog]') === null);
                    ok(!/[?&]new=1/.test(page.url()), 'closing the sheet clears the address');
                } else {
                    await Promise.all([page.waitForURL(/\/decks\/\d+$/), page.locator('[data-primary-action]').click()]);
                    await page.waitForSelector('.ed');
                }
                eq(page.errors.filter((e) => !/favicon/.test(e)), [], 'console errors');
                await ctx.close();
            });
        }
    }
}

await t.step('create', 'the primary action ends in the editor on a new presentation', async () => {
    const { ctx, page } = await newContext(browser, 'owner@demo.test');
    t.page = page;
    await page.goto(BASE + '/dashboard');
    await page.locator('[data-primary-action]').click();
    await page.waitForSelector('.ns[role=dialog]');
    await page.getByLabel('Title').first().fill('E2E landing ' + Date.now());
    await Promise.all([page.waitForURL(/\/decks\/\d+$/), page.getByRole('button', { name: 'Create presentation' }).click()]);
    await page.waitForSelector('.ed');
    await ctx.close();
});

await t.step('search', 'the landing steps aside while a search is typed and comes back after', async () => {
    const { ctx, page } = await newContext(browser, 'owner@demo.test');
    t.page = page;
    await page.goto(BASE + '/dashboard');
    await page.waitForSelector('.ld');
    await page.fill('.dsr-input', 'mining');
    await waitUntil(page, () => document.querySelector('.ld') === null);
    eq(await page.locator('h1').count(), 1, 'one h1 while searching');
    eq(await page.locator('.lh-new').count(), 0, 'no header New while searching');
    await page.fill('.dsr-input', '');
    await page.waitForSelector('.ld');
    await ctx.close();
});

await browser.close();
process.exit(t.summary() ? 1 : 0);
