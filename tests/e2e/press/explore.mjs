import { MOD, launch, newContext, shot, BASE, sleep, openDeck, createDeck, savedDoc, saved } from './lib.mjs';
const b = await launch();
const A = await newContext(b, 'owner@demo.test');
const B = await newContext(b, 'editor@demo.test');
const id = await createDeck(A.page, 'collab ' + Date.now());
await openDeck(A.page, id);
const r = await B.page.evaluate(async (i) => (await fetch('/api/decks/' + i + '/document', { headers: { Accept: 'application/json' } })).status, id);
console.log('editor can read the owner deck:', r);
await b.close();
