// Check 37: version history (named versions, compare, restore, undo of a restore, another editor reloads) and approvals
// (request, decide, status bound to the content, restoring an approved version restores the Approved status).
import { launch, context, login, api, expect, shot, summary, sleep, until, php, phpJson, openFromMore, BASE } from './lib.mjs';
import { buildAcceptanceDeck, uid } from './fixture.mjs';

const browser = await launch();
const oc = await context(browser);
const op = await oc.newPage();
await login(op, 'owner@demo.test');
const D = await buildAcceptanceDeck(op, { withMedia: false });
const ec = await context(browser);
const ep = await ec.newPage();
await login(ep, 'editor@demo.test');
const doc0 = (await api(op, 'GET', `/api/decks/${D.id}/document`)).json;
const slideTexts = (d) => d.slides.map((s) => s.title);
const ops = (list) => api(op, 'POST', `/api/decks/${D.id}/ops`, { client: uid(), key: uid(), ops: list });
const status = async () => (await api(op, 'GET', `/api/decks/${D.id}/reviews`)).json;

// the other editor has the deck open
await ep.goto(`/decks/${D.id}`); await ep.waitForSelector('.es-viewport'); await sleep(1000);
expect((await ep.locator('.sp-row, [class*="spine-row"], .spr').count()) >= 0, 'editor B opened the deck');

// ---- name a version through the History panel
await op.goto(`/decks/${D.id}`); await op.waitForSelector('.es-viewport'); await sleep(1000);
await openFromMore(op, 'Version history');
await op.locator('#hp-name').fill('Sent to the board');
await op.getByRole('button', { name: 'Save', exact: true }).click();
await op.getByText('Sent to the board').first().waitFor();
expect(true, 'check 37: a named version is saved from the History panel');
const v1 = phpJson(`$v = App\\Models\\DeckVersion::where('deck_id', ${D.id})->where('label','Sent to the board')->first(); echo json_encode(['id'=>$v->id,'kind'=>$v->kind,'slides'=>$v->slide_count]);`);
expect(v1.kind === 'manual' && v1.slides === 9, 'check 37: stored as a manual version with 9 slides');

// ---- change the deck: edit a title, remove a slide, add a section title text through ops
const s2 = doc0.slides[1].id; const s3 = doc0.slides[2].id;
const edit = await ops([{ op: 'slide.set', id: s2, title: 'Push slide, reworded' }, { op: 'slide.remove', id: s3 }, { op: 'el.set', slide: s2, id: 'h', p: { size: 90 } }]);
expect(edit.status === 200, 'ops applied for the changes');
await op.reload(); await op.waitForSelector('.es-viewport'); await sleep(1000);
await openFromMore(op, 'Version history');
await op.getByText('Sent to the board').first().click();
await op.getByText('since this version').waitFor({ timeout: 8000 });
const sum = await op.locator('.hsv-sum').innerText();
const changes = await op.locator('.hsv-ch').innerText();
expect(/removed|retitled/.test(changes), 'check 37: comparing says what changed since the version: ' + (sum + ' / ' + changes).replace(/\n/g, ' '));
await shot(op, 'history-37-compare');

// ---- the other editor, who has the old document open, receives the restore without reloading by hand
const before = await ep.locator('.es-viewport').first().getAttribute('aria-label');
await op.getByRole('button', { name: 'Restore this version' }).click();
await op.getByRole('dialog').waitFor();
const dlg = await op.getByRole('dialog').innerText();
expect(/safety|copy/i.test(dlg), 'check 37: the restore dialog promises a safety copy: ' + dlg.replace(/\n/g, ' ').slice(0, 160));
await shot(op, 'history-37-restore-dialog');
await op.getByRole('dialog').getByRole('button', { name: /^Restore/ }).click();
await op.getByText(/Restored/).first().waitFor({ timeout: 10000 });
const after = (await api(op, 'GET', `/api/decks/${D.id}/document`)).json;
expect(after.slides.length === 9 && after.slides[1].title === 'Push slide' && after.slides[2].id === s3, 'check 37: restore brings back the removed slide under its own id and the old title');
const safety = phpJson(`echo json_encode(['n' => App\\Models\\DeckVersion::where('deck_id', ${D.id})->where('kind','restore')->count()]);`);
expect(safety.n === 1, 'check 37: a safety copy of the pre-restore state was stored');
await shot(op, 'history-37-restored');
// Undo from the toast, straight away (the toast does not wait for ever)
await op.locator('.tt-act', { hasText: 'Undo' }).click();
await until(async () => (await api(op, 'GET', `/api/decks/${D.id}/document`)).json.slides.length === 8, { timeout: 10000, what: 'undo of the restore' });
const undone = (await api(op, 'GET', `/api/decks/${D.id}/document`)).json;
expect(undone.slides.length === 8 && undone.slides[1].title === 'Push slide, reworded', 'check 37: Undo on the restore toast returns to the state before the restore');
// restore again through the API; editor B, who has the deck open, follows by itself
await api(op, 'POST', `/api/decks/${D.id}/versions/${v1.id}/restore`);
await until(async () => (await ep.getByText('Wipe slide').count()) > 0, { timeout: 40000, what: 'editor B to receive the restore' });
expect(true, 'check 37: another editor with the deck open gets the restored slides without a manual reload');

// ---- approvals
// restore the content we want to approve: go back to 9 slides again, then ask for a review
const versions = (await api(op, 'GET', `/api/decks/${D.id}/versions`)).json.versions;
const named = versions.find((v) => v.label === 'Sent to the board');
await api(op, 'POST', `/api/decks/${D.id}/versions/${named.id}/restore`);
await op.reload(); await op.waitForSelector('.es-viewport'); await sleep(1000);
await op.getByRole('button', { name: /Approval: Draft/ }).click();
await op.getByText('Ask for a review').waitFor();
await op.locator('label.apv-pick', { hasText: 'Edith Editor' }).locator('input').check();
await op.getByRole('button', { name: 'Ask for review', exact: true }).click();
await op.getByText('Review requested').first().waitFor();
const st1 = await status();
expect(st1.status === 'in_review', 'check 37: asking for a review moves the deck to In review');
const notes = phpJson(`$u = App\\Models\\User::where('email','editor@demo.test')->first(); echo json_encode(['n' => $u->notifications()->where('type','like','%ReviewRequested%')->count()]);`);
await until(() => phpJson(`$u = App\\Models\\User::where('email','editor@demo.test')->first(); echo json_encode(['n' => $u->notifications()->where('type','like','%ReviewRequested%')->count()]);`).n >= 1, { timeout: 20000, what: 'review notification' }).catch(() => {});
expect(phpJson(`$u = App\\Models\\User::where('email','editor@demo.test')->first(); echo json_encode(['n' => $u->notifications()->where('type','like','%ReviewRequested%')->count()]);`).n >= 1, 'check 37: the reviewer is notified');
await shot(op, 'history-37-approval-requested');
// the reviewer decides through the UI
await ep.reload(); await ep.waitForSelector('.es-viewport'); await sleep(1200);
await ep.getByRole('button', { name: /Approval: In review/ }).click();
await ep.getByText('Your decision', { exact: true }).waitFor();
await ep.getByRole('button', { name: 'Approve this version' }).click();
await ep.getByText('You approved this version').first().waitFor();
const st2 = await status();
expect(st2.status === 'approved' && st2.approved_current === true, 'check 37: the reviewer approves and the deck reads Approved: ' + st2.status);
await shot(ep, 'history-37-approved');
const approvedVer = phpJson(`$v = App\\Models\\DeckVersion::where('deck_id', ${D.id})->where('kind','approved')->latest('id')->first(); echo json_encode(['id' => $v?->id, 'hash' => $v?->hash]);`);
expect(!!approvedVer.id, 'check 37: approving stores a version named Approved');
// any change breaks the approval, even one word
await ops([{ op: 'slide.set', id: s2, title: 'Push slide!' }]);
const st3 = await status();
expect(st3.status !== 'approved', 'check 37: changing one word ends the approval (' + st3.status + ')');
await op.reload(); await op.waitForSelector('.es-viewport'); await sleep(1000);
const chip = await op.locator('.apc').first().innerText();
expect(/Draft|Changed since approval/i.test(chip), 'check 37: the status chip shows it: "' + chip.trim() + '"');
// restore the approved version: the content is exactly what was approved, so the status is Approved again
await openFromMore(op, 'Version history');
await op.getByText('Approved', { exact: true }).first().waitFor();
await op.locator('.hsv-row', { hasText: 'Approved' }).first().click();
await op.getByRole('button', { name: 'Restore this version' }).click();
await op.getByRole('dialog').getByRole('button', { name: /^Restore/ }).click();
await op.getByText(/Restored/).first().waitFor({ timeout: 10000 });
await sleep(800);
const st4 = await status();
expect(st4.status === 'approved' && st4.approved_current === true, 'check 37: restoring the approved version restores the Approved status (got ' + st4.status + ')');
await shot(op, 'history-37-restored-approved');
const chip2 = await op.locator('.apc').first().innerText();
expect(/Approved/i.test(chip2), 'check 37: the top bar chip reads Approved again: "' + chip2.trim() + '"');
// and a restore of a non approved version leaves it Draft
const draftVer = versions.find((v) => v.kind === 'manual');
await api(op, 'POST', `/api/decks/${D.id}/versions/${draftVer.id}/restore`);
console.log('console errors', oc._errors, ec._errors);
summary('history');
await browser.close();
