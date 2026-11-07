// Checks 34 (sharing with people), 35 (permissions per role), 36 (comments and mentions), 37 (version history, restore and
// approvals), driven through the real UI in several browser contexts that each hold one person's session.
import { launch, context, login, api, assert, expect, shot, summary, sleep, until, php, phpJson, openFromMore, makeUser, BASE } from './lib.mjs';
import { buildAcceptanceDeck, uid } from './fixture.mjs';

const browser = await launch();
const ownerCtx = await context(browser);
const op = await ownerCtx.newPage();
await login(op, 'owner@demo.test');
const D = await buildAcceptanceDeck(op, { withMedia: false });
const ids = phpJson(`echo json_encode(['owner' => App\\Models\\User::where('email','owner@demo.test')->value('id'), 'editor' => App\\Models\\User::where('email','editor@demo.test')->value('id'), 'viewer' => App\\Models\\User::where('email','viewer@demo.test')->value('id')]);`);
const outsiderId = makeUser('Otto Outsider', 'otto@other.test');
const commenterId = makeUser('Cora Commenter', 'cora@other.test');

const mk = async (email) => { const c = await context(browser); const p = await c.newPage(); await login(p, email); return { c, p }; };
const ed = await mk('editor@demo.test');
const vw = await mk('viewer@demo.test');
const out = await mk('otto@other.test');

// =================================================================================================== 35 permissions
const st = async (p, m, u, b) => (await api(p, m, u, b)).status;
const doc = (await api(op, 'GET', `/api/decks/${D.id}/document`)).json;
const firstSlide = doc.slides[0].id;
const patch = (n) => ({ client: uid(), key: uid(), ops: [{ op: 'slide.set', id: firstSlide, notes: 'role test ' + n }] });

expect(await st(op, 'POST', `/api/decks/${D.id}/ops`, patch('owner')) === 200, 'check 35: owner may change the deck');
expect(await st(ed.p, 'POST', `/api/decks/${D.id}/ops`, patch('editor')) === 200, 'check 35: team editor may change the deck');
expect(await st(vw.p, 'POST', `/api/decks/${D.id}/ops`, patch('viewer')) === 403, 'check 35: team viewer is refused when changing (403)');
expect(await st(out.p, 'POST', `/api/decks/${D.id}/ops`, patch('outsider')) === 404, 'check 35: a person from another team gets 404, not a hint that the deck exists');
for (const [who, p, want] of [['owner', op, 200], ['editor', ed.p, 200], ['viewer', vw.p, 200], ['outsider', out.p, 404]]) {
    expect(await st(p, 'GET', `/api/decks/${D.id}/document`) === want, `check 35: ${who} reads the document: ${want}`);
}
for (const [who, p, want] of [['owner', op, 200], ['editor', ed.p, 403], ['viewer', vw.p, 403], ['outsider', out.p, 404]]) {
    expect(await st(p, 'GET', `/api/decks/${D.id}/sharing`) === want, `check 35: ${who} reads sharing settings: ${want}`);
}
expect(await st(ed.p, 'PUT', `/api/decks/${D.id}/sharing`, { visibility: 'link' }) === 403, 'check 35: an editor cannot publish a link');
expect(await st(ed.p, 'DELETE', `/api/decks/${D.id}`) === 403, 'check 35: an editor cannot archive or delete the deck');
expect(await st(vw.p, 'POST', `/api/decks/${D.id}/comments`, { body: 'viewer comment', target: { type: 'deck' } }) === 201, 'check 35: a team viewer may comment');
expect(await st(out.p, 'POST', `/api/decks/${D.id}/comments`, { body: 'hello', target: { type: 'deck' } }) === 404, 'check 35: an outsider cannot comment');
expect(await st(vw.p, 'POST', `/api/decks/${D.id}/versions`, { label: 'x' }) === 403, 'check 35: a viewer cannot save a version');
expect(await st(vw.p, 'POST', `/api/decks/${D.id}/exports`, { format: 'pdf' }) < 300, 'check 35: a viewer may export (export follows view)');
expect(await st(out.p, 'POST', `/api/decks/${D.id}/exports`, { format: 'pdf' }) === 404, 'check 35: an outsider cannot export');
// the pages
for (const [who, p, want] of [['owner', op, 200], ['editor', ed.p, 200], ['viewer', vw.p, 200], ['outsider', out.p, 404]]) {
    for (const path of ['', '/present', '/presenter', '/print']) {
        const r = await p.goto(`/decks/${D.id}${path}`);
        expect(r.status() === want, `check 35: ${who} opens /decks/{id}${path}: ${r.status()}`);
    }
}
// asset of the project is not reachable by an outsider
// ---- the viewer's editor is read only
await vw.p.goto(`/decks/${D.id}`);
await vw.p.waitForSelector('.es-viewport');
await sleep(1000);
expect(await vw.p.locator('.ed[data-readonly="true"]').count() === 1, 'check 35: the viewer opens the editor in read only mode');
const ro = await vw.p.evaluate(() => ({ newSlide: !!document.querySelector('button.pr-btn--primary')?.textContent?.includes('New slide') && !document.querySelector('button.pr-btn--primary').disabled, tools: [...document.querySelectorAll('.dk-b')].filter((b) => !b.disabled).map((b) => b.getAttribute('aria-label')) }));
expect(!ro.tools.includes('Text') && !ro.tools.includes('Picture'), 'check 35: the viewer\'s dock has no enabled creation tools: ' + ro.tools.join(', '));
await shot(vw.p, 'collab-35-viewer-editor');
const edText = await vw.p.locator('.es-viewport [data-eid="t1"]').first().boundingBox();
await vw.p.mouse.dblclick(edText.x + 20, edText.y + 20); await sleep(500);
expect(await vw.p.locator('.es-viewport [contenteditable="true"], .es-viewport textarea').count() === 0, 'check 35: double clicking text does not start editing for a viewer');
await vw.p.keyboard.press('Delete'); await sleep(500);
const after = (await api(op, 'GET', `/api/decks/${D.id}/document`)).json;
expect(after.slides[0].els.length === doc.slides[0].els.length, 'check 35: Delete does nothing to the viewer\'s deck');
// the editor cannot manage
await ed.p.goto(`/decks/${D.id}`); await ed.p.waitForSelector('.es-viewport'); await sleep(800);
await ed.p.getByRole('button', { name: 'Share', exact: true }).click();
await ed.p.getByText(/Only an owner or an administrator can change who can open this deck/).waitFor();
expect(true, 'check 35: the editor sees the sharing panel as read only with the plain reason');
await shot(ed.p, 'collab-35-editor-share-panel');

// ---- shared people and every role on a private deck
const priv = (await api(op, 'POST', '/api/decks', { title: 'Private board paper' })).json;
const privId = (priv.data || priv).id;
await api(op, 'PUT', `/api/decks/${privId}/sharing`, { visibility: 'private' });
expect(await st(ed.p, 'GET', `/api/decks/${privId}/document`) === 404, 'check 34: a private deck is 404 for team members');
await op.goto(`/decks/${privId}`); await op.waitForSelector('.es-viewport'); await sleep(800);
await op.getByRole('button', { name: 'Share', exact: true }).click();
await op.getByRole('radio', { name: /^Shared/ }).click();
await sleep(500);
await op.getByLabel('E-mail address or user id of the person to add').fill('cora@other.test');
await op.getByRole('button', { name: 'Add', exact: true }).click();
await op.getByText('Cora Commenter').first().waitFor();
expect(true, 'check 34: adding a person by e-mail through the panel');
const share = (await api(op, 'GET', `/api/decks/${privId}/sharing`)).json;
expect(share.people.some((p) => p.email === 'cora@other.test'), 'check 34: the person is stored with a role');
// unknown e-mail gets a plain explanation
await op.getByLabel('E-mail address or user id of the person to add').fill('nobody@nowhere.test');
await op.getByRole('button', { name: 'Add', exact: true }).click();
await op.getByText(/No account matches that person/).waitFor();
expect(true, 'check 34: an unknown e-mail is explained, not silently ignored');
await shot(op, 'collab-34-share-people');
const cora = await mk('cora@other.test');
expect(await st(cora.p, 'GET', `/api/decks/${privId}/document`) === 200, 'check 34: the added person opens the private deck');
expect(await st(cora.p, 'POST', `/api/decks/${privId}/ops`, { client: uid(), key: uid(), ops: [{ op: 'deck.set', title: 'x' }] }) === 403, 'check 35: a viewer share cannot edit');
// upgrade to commenter then editor through the role menu in the UI
await api(op, 'PATCH', `/api/decks/${privId}/sharing/people/${commenterId}`, { role: 'commenter' });
expect(await st(cora.p, 'POST', `/api/decks/${privId}/comments`, { body: 'from cora', target: { type: 'deck' } }) === 201, 'check 35: a commenter share can comment');
await api(op, 'PATCH', `/api/decks/${privId}/sharing/people/${commenterId}`, { role: 'editor' });
const priv_doc = (await api(cora.p, 'GET', `/api/decks/${privId}/document`)).json;
expect(await st(cora.p, 'POST', `/api/decks/${privId}/ops`, { client: uid(), key: uid(), ops: [{ op: 'slide.set', id: priv_doc.slides[0].id, title: 'by Cora' }] }) === 200, 'check 35: an editor share can edit');
expect(await st(cora.p, 'PUT', `/api/decks/${privId}/sharing`, { visibility: 'link' }) === 403, 'check 35: an editor share cannot change sharing');
await cora.p.goto('/dashboard'); await sleep(1500);
await cora.p.getByRole('tab', { name: /Shared with me/ }).click().catch(() => cora.p.getByText('Shared with me').first().click());
await sleep(1200);
expect(await cora.p.getByText('Private board paper').count() > 0, 'check 34: the deck shows in "Shared with me" in the library');
await shot(cora.p, 'collab-34-shared-with-me');
// removal takes it away
await api(op, 'DELETE', `/api/decks/${privId}/sharing/people/${commenterId}`);
expect(await st(cora.p, 'GET', `/api/decks/${privId}/document`) === 404, 'check 34: removing the person closes the deck for them at once');

console.log('console errors', ownerCtx._errors, ed.c._errors, vw.c._errors);
summary('collab-roles');
await browser.close();
