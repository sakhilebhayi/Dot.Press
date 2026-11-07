import { launch, newContext, shot, BASE, sleep, openDeck, createDeck } from './lib.mjs';
const b = await launch();
const { page, ctx } = await newContext(b, 'owner@demo.test', { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });
await page.goto(BASE + '/dashboard');
await sleep(600);
console.log(await page.evaluate(() => [...document.querySelectorAll('.lh > *, .lh-actions > *')].map((e) => { const b = e.getBoundingClientRect(); const cs = getComputedStyle(e); return `${e.tagName}.${e.className} x=${Math.round(b.x)} w=${Math.round(b.width)} display=${cs.display} label=${e.getAttribute('aria-label') || ''} text=${e.innerText.slice(0, 20)}`; }).join('\n')));
await b.close();
