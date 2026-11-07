// Check 36: comments with mentions, threads, resolve, replies, notifications, who may comment, pins. Two people in two
// browsers: the owner writes, the editor receives.
import { launch, context, login, api, assert, expect, shot, summary, sleep, until, php, phpJson, openFromMore, makeUser, clickEl, BASE } from './lib.mjs';
import { buildAcceptanceDeck, uid } from './fixture.mjs';

const browser = await launch();
const oc = await context(browser);
const op = await oc.newPage();
await login(op, 'owner@demo.test');
const D = await buildAcceptanceDeck(op, { withMedia: false });
const ec = await context(browser);
const ep = await ec.newPage();
await login(ep, 'editor@demo.test');
const ids = phpJson(`echo json_encode(['editor' => App\\Models\\User::where('email','editor@demo.test')->value('id'), 'viewer' => App\\Models\\User::where('email','viewer@demo.test')->value('id')]);`);
const outsiderId = makeUser('Otto Outsider', 'otto@other.test');
const notifCount = (userId, type = 'DeckMention') => phpJson(`echo json_encode(['n' => App\\Models\\User::find(${userId})->notifications()->where('type', 'like', '%${type}%')->count()]);`).n;
const before = notifCount(ids.editor);

// ---- the owner opens Comments, picks the second slide, writes a comment that mentions the editor by typing @Ed
await op.goto(`/decks/${D.id}`);
await op.waitForSelector('.es-viewport'); await sleep(800);
await op.getByText('Push slide').first().click();
await openFromMore(op, 'Comments');
const box = op.getByRole('combobox', { name: 'Comment' }).first();
await box.waitFor();
expect(await op.getByText(/Slide 2/).first().isVisible(), 'check 36: the composer aims at the current slide ("Slide 2")');
await box.click();
await op.keyboard.type('Please check the drivers wording @Ed', { delay: 30 });
await op.getByRole('listbox', { name: 'People to mention' }).waitFor({ timeout: 8000 });
const options = await op.getByRole('option').allInnerTexts();
expect(options.some((o) => /Edith Editor/.test(o)) && !options.some((o) => /Otto Outsider/.test(o)), 'check 36: mention suggestions list people with access (Edith) and not outsiders: ' + options.join(' | ').replace(/\n/g, ' '));
await shot(op, 'comments-36-mention-list');
await op.keyboard.press('Enter');
await op.keyboard.type(' thanks.');
await op.getByRole('button', { name: 'Comment', exact: true }).click();
await op.locator('.cmt').first().waitFor();
const bodyText = await op.locator('.cmt-body').first().innerText();
expect(/@Edith Editor/.test(bodyText), 'check 36: the posted comment shows the mention: "' + bodyText.replace(/\n/g, ' ') + '"');
const stored = phpJson(`$c = App\\Models\\DeckComment::latest('id')->first(); echo json_encode(['body' => $c->body, 'mentions' => $c->mentions, 'slide' => $c->slide_id ?? null]);`);
expect(stored.mentions.includes(ids.editor) && /@\[Edith Editor\]\(\d+\)/.test(stored.body), 'check 36: stored with the mention token and the mentioned user id: ' + stored.body);
await until(() => notifCount(ids.editor) >= before + 1, { timeout: 20000, what: 'mention notification' }).catch(() => {});
expect(notifCount(ids.editor) === before + 1, 'check 36: the mentioned person got exactly one notification (database queue worker ran it)');
await shot(op, 'comments-36-posted');

// a mention of a person without access is neutralised and nobody is notified
const evil = await api(op, 'POST', `/api/decks/${D.id}/comments`, { body: `fake mention @[Otto Outsider](${outsiderId}) and @[Edith Editor](${ids.editor})`, target: { type: 'deck' } });
expect(evil.status === 201, 'check 36: a comment with a forged mention is accepted');
const ev = phpJson(`$c = App\\Models\\DeckComment::latest('id')->first(); echo json_encode(['body' => $c->body, 'mentions' => $c->mentions]);`);
expect(!ev.mentions.includes(outsiderId) && !/\(\d+\)/.test(ev.body.split('and')[0]), 'check 36: the outsider mention is reduced to plain text: ' + ev.body);
expect(phpJson(`echo json_encode(['n' => App\\Models\\User::find(${outsiderId})->notifications()->count()]);`).n === 0, 'check 36: the outsider received no notification');

// ---- the editor sees it (panel open polls every 15 s), replies, resolves
await ep.goto(`/decks/${D.id}`);
await ep.waitForSelector('.es-viewport'); await sleep(1000);
await openFromMore(ep, 'Comments');
await ep.locator('.cmt').first().waitFor();
const unread = await ep.locator('.cmt-new').count();
expect(unread >= 1, 'check 36: the editor sees unread threads marked "new" (' + unread + ')');
await shot(ep, 'comments-36-editor-view');
const th = ep.locator('.cmt', { hasText: 'Please check the drivers wording' }).first();
await th.getByRole('button', { name: 'Reply' }).click();
await ep.keyboard.type('Looks fine to me, I will tidy slide 2.');
await ep.getByRole('button', { name: 'Reply', exact: true }).last().click().catch(async () => { await ep.keyboard.press('Control+Enter'); });
await th.getByText('Looks fine to me').waitFor({ timeout: 8000 });
expect(true, 'check 36: the editor replies in the thread');
await th.getByRole('button', { name: 'Resolve' }).click();
await until(async () => (await ep.locator('.cmt', { hasText: 'Please check the drivers wording' }).count()) === 0, { timeout: 8000, what: 'resolved thread leaves the Open list' });
expect(true, 'check 36: the editor resolves the thread (it leaves the Open list)');
// the owner sees the resolution without reloading (comments poll every 15 s with the panel open)
await until(async () => (await op.locator('.cmt', { hasText: 'Please check the drivers wording' }).count()) === 0, { timeout: 40000, what: 'owner panel to drop the resolved thread' });
expect(true, 'check 36: the resolution reaches the owner while the panel is open (no reload)');
await op.getByRole('radio', { name: /Resolved/ }).click();
await op.locator('.cmt.is-resolved', { hasText: 'Looks fine to me' }).first().waitFor();
expect(true, 'check 36: the Resolved filter lists the thread with the editor\'s reply');
await shot(op, 'comments-36-resolved');
// reopen
await op.locator('.cmt.is-resolved').first().getByRole('button', { name: 'Reopen' }).click();
await sleep(800);
// ---- an element comment makes a pin on the stage
await op.getByRole('radio', { name: /^Open/ }).click().catch(() => {});
await clickEl(op, 'b1');
await sleep(600);
await openFromMore(op, 'Comments');
await op.getByRole('combobox', { name: 'Comment' }).first().click();
await op.keyboard.type('This bullet needs a source.');
await op.getByRole('button', { name: 'Comment', exact: true }).click();
await sleep(1200);
const pins = await op.locator('.es-viewport [class*="pin"]').count();
expect(pins >= 1, 'check 36: an element comment draws a pin on the stage (' + pins + ')');
await shot(op, 'comments-36-pin');

// ---- delete: only the author or a manager; viewers can comment but not delete others'
const v = await context(browser);
const vp = await v.newPage();
await login(vp, 'viewer@demo.test');
const list = (await api(vp, 'GET', `/api/decks/${D.id}/comments?filter=all`)).json;
const first = list.threads[0];
expect((await api(vp, 'DELETE', `/api/decks/${D.id}/comments/${first.id}`)).status === 403, 'check 36: a viewer cannot delete someone else\'s comment');
expect((await api(vp, 'POST', `/api/decks/${D.id}/comments`, { body: 'viewer says hi', target: { type: 'deck' } })).status === 201, 'check 36: a viewer can comment');
const long = 'x'.repeat(6100);
expect((await api(vp, 'POST', `/api/decks/${D.id}/comments`, { body: long, target: { type: 'deck' } })).status === 422, 'check 36: an overlong comment is refused (422)');
const xss = await api(vp, 'POST', `/api/decks/${D.id}/comments`, { body: '<img src=x onerror=alert(1)> hello', target: { type: 'deck' } });
await op.reload(); await op.waitForSelector('.es-viewport'); await sleep(800);
await openFromMore(op, 'Comments');
await op.getByText('hello').first().waitFor();
expect(await op.locator('.cmt-body img').count() === 0, 'check 36: HTML typed in a comment is shown as text, never rendered');

console.log('console errors', oc._errors, ec._errors);
expect(oc._errors.filter((e) => !/40[34]|422/.test(e)).length === 0, 'no unexpected console errors in the owner session');
summary('comments');
await browser.close();
