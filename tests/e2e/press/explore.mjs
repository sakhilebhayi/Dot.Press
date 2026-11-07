import { launch, newContext, shot, BASE, sleep, openDeck } from './lib.mjs';
const b = await launch();
const { page } = await newContext(b, 'owner@demo.test');
await openDeck(page, 1);
console.log(await page.getByRole('button', { name: 'Section', exact: true }).count());
console.log(await page.getByRole('button', { name: /Section/ }).evaluateAll(els=>els.map(e=>e.outerHTML.slice(0,200))));
await b.close();
