import test from 'node:test';
import assert from 'node:assert/strict';
import {
  applyTemps, buildPins, cleanName, countThreads, elementLabel, failureMessage, filterThreads, insertPick, isUnread, mentionQuery, mentionToken, mentionedIds,
  parseSegments, plainText, postPayload, resolveTarget, scopeLabel, serverSlideId, suggestPeople, tempComment, timeAgo, toBody, toDisplay, unreadIds,
} from '../commentsModel.js';

const ann = { id: 4, name: 'Ann Lee', role: 'editor', can_comment: true };
const anna = { id: 5, name: 'Anna Maria', role: 'viewer', can_comment: true };
const bob = { id: 6, name: 'Bob Stone', role: 'owner', can_comment: true };

test('mention token uses the cleaned name and the id', () => {
  assert.equal(mentionToken(ann), '@[Ann Lee](4)');
  assert.equal(mentionToken({ id: 9, name: 'A [x] (y)' }), '@[A x y](9)');
  assert.equal(cleanName('   '), 'Someone');
});

test('segments split text and mentions for display', () => {
  const segs = parseSegments('Hi @[Ann Lee](4), see @[Bob Stone](6)!');
  assert.deepEqual(segs.map((s) => s.type), ['text', 'mention', 'text', 'mention', 'text']);
  assert.equal(segs[1].name, 'Ann Lee');
  assert.equal(segs[1].id, 4);
  assert.equal(plainText('Hi @[Ann Lee](4)!'), 'Hi @Ann Lee!');
  assert.deepEqual(mentionedIds('@[A](1) @[B](2) @[A](1)'), [1, 2]);
  assert.deepEqual(parseSegments(''), []);
});

test('mentionQuery finds the @ being typed and refuses e-mail addresses and finished tokens', () => {
  assert.deepEqual(mentionQuery('hello @an', 9), { start: 6, end: 9, query: 'an' });
  assert.deepEqual(mentionQuery('@', 1), { start: 0, end: 1, query: '' });
  assert.equal(mentionQuery('write to me@example.com', 23), null);
  assert.equal(mentionQuery('hello @an', 5), null);
  assert.equal(mentionQuery('line one\n@x', 4), null);
  assert.equal(mentionQuery('(@an', 4)?.query, 'an');
  assert.equal(mentionQuery('@aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 45), null);
});

test('suggestPeople ranks prefix first, skips yourself and people who cannot comment', () => {
  const people = [bob, anna, ann, { id: 7, name: 'Cy Ann', can_comment: true }, { id: 8, name: 'Ana Viewer', can_comment: false }];
  assert.deepEqual(suggestPeople(people, 'ann').map((p) => p.id), [4, 5, 7]);
  assert.deepEqual(suggestPeople(people, 'ann', { exclude: [4] }).map((p) => p.id), [5, 7]);
  assert.deepEqual(suggestPeople(people, 'stone').map((p) => p.id), [6]);
  assert.equal(suggestPeople(people, '').length, 4);
  assert.equal(suggestPeople(people, '', { limit: 2 }).length, 2);
  assert.deepEqual(suggestPeople(people, 'zzz'), []);
});

test('typing names and sending tokens round trips', () => {
  const q = mentionQuery('Thanks @an', 10);
  const r = insertPick('Thanks @an', q, ann);
  assert.equal(r.text, 'Thanks @Ann Lee ');
  assert.equal(r.caret, r.text.length);
  assert.equal(toBody(`${r.text}for this`, [r.pick]), 'Thanks @[Ann Lee](4) for this');
  const d = toDisplay('Thanks @[Ann Lee](4) for this');
  assert.equal(d.text, 'Thanks @Ann Lee for this');
  assert.equal(toBody(d.text, d.picks), 'Thanks @[Ann Lee](4) for this');
});

test('toBody drops picks whose name was deleted, keeps word boundaries and handles two people with one name', () => {
  assert.equal(toBody('no mention left', [{ id: 4, name: 'Ann Lee' }]), 'no mention left');
  assert.equal(toBody('@Anna Maria wrote', [{ id: 4, name: 'Ann' }, { id: 5, name: 'Anna Maria' }]), '@[Anna Maria](5) wrote');
  const two = [{ id: 1, name: 'Sam' }, { id: 2, name: 'Sam' }];
  assert.equal(toBody('@Sam and @Sam', two), '@[Sam](1) and @[Sam](2)');
  assert.equal(toBody('mail@Sam', [{ id: 1, name: 'Sam' }]), 'mail@Sam');
});

test('timeAgo', () => {
  const now = Date.parse('2026-11-07T12:00:00Z');
  const ago = (s) => new Date(now - s * 1000).toISOString();
  assert.equal(timeAgo(ago(10), now), 'just now');
  assert.equal(timeAgo(ago(5 * 60), now), '5 min ago');
  assert.equal(timeAgo(ago(3 * 3600), now), '3 h ago');
  assert.equal(timeAgo(ago(30 * 3600), now), 'yesterday');
  assert.equal(timeAgo(ago(4 * 86400), now), '4 d ago');
  assert.equal(timeAgo('nonsense', now), '');
});

const threads = [
  { id: 3, slide_id: 12, element_id: 'e1', anchor: { x: 10, y: 20 }, resolved: false, author: { id: 2 }, created_at: '2026-11-07T10:00:00Z', replies: [{ id: 4, author: { id: 1 }, created_at: '2026-11-07T11:00:00Z' }, { id: 5, author: { id: 2 }, created_at: '2026-11-07T11:30:00Z' }] },
  { id: 2, slide_id: null, element_id: null, anchor: null, resolved: true, author: { id: 1 }, created_at: '2026-11-06T10:00:00Z', replies: [] },
  { id: 1, slide_id: 13, element_id: null, anchor: null, resolved: false, author: { id: 1 }, created_at: '2026-11-05T10:00:00Z', replies: [] },
];

test('filters and counts', () => {
  assert.deepEqual(filterThreads(threads, { state: 'open' }).map((t) => t.id), [3, 1]);
  assert.deepEqual(filterThreads(threads, { state: 'resolved' }).map((t) => t.id), [2]);
  assert.deepEqual(filterThreads(threads, { state: 'all', slideId: '12' }).map((t) => t.id), [3]);
  assert.deepEqual(countThreads(threads, '13'), { open: 2, resolved: 1, all: 3, onSlide: 1 });
});

test('pins: one per threaded point with its reply count and resolved state', () => {
  assert.deepEqual(buildPins(threads), [{ id: 3, slide_id: '12', x: 10, y: 20, count: 2, resolved: false }]);
  assert.deepEqual(buildPins([{ id: 9, slide_id: 4, anchor: { x: 'a', y: 1 } }]), []);
  assert.deepEqual(buildPins([{ id: 9, slide_id: null, anchor: { x: 1, y: 1 } }]), []);
  assert.deepEqual(buildPins(null), []);
});

test('scope labels name the slide, the element or the point', () => {
  const slides = [{ id: '11', els: [] }, { id: '12', els: [{ id: 'e1', type: 'text', doc: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Revenue grew' }] }] } }] }];
  assert.equal(scopeLabel({ slide_id: null }, slides), 'Whole deck');
  assert.equal(scopeLabel({ slide_id: 11 }, slides), 'Slide 1');
  assert.equal(scopeLabel({ slide_id: 11, anchor: { x: 1, y: 1 } }, slides), 'Slide 1, point');
  assert.equal(scopeLabel({ slide_id: 12, element_id: 'e1' }, slides), 'Slide 2, "Revenue grew"');
  assert.equal(scopeLabel({ slide_id: 12, element_id: 'gone' }, slides), 'Slide 2, a removed element');
  assert.equal(scopeLabel({ slide_id: 99 }, slides), 'A deleted slide');
  assert.equal(elementLabel({ type: 'image' }), 'Picture');
  assert.equal(elementLabel({ name: 'Logo', type: 'image' }), 'Logo');
});

test('unread: other people newer than the baseline, not your own, replies count', () => {
  const seen = Date.parse('2026-11-07T10:30:00Z');
  assert.equal(isUnread(threads[0], seen, 2), true); // the reply by user 1 at 11:00
  assert.equal(isUnread(threads[0], seen, 1), true); // the reply by user 2 at 11:30
  assert.deepEqual(unreadIds(threads, seen, 1), [3]);
  assert.deepEqual(unreadIds(threads, 0, 1), [3]); // thread 2 and 1 are by me
  assert.deepEqual(unreadIds([{ ...threads[0], pending: true }], 0, 1), []);
});

test('server slide ids: numbers only, temporary ids are not sent', () => {
  assert.equal(serverSlideId('12'), 12);
  assert.equal(serverSlideId(12), 12);
  assert.equal(serverSlideId('s_ab12'), null);
  assert.equal(serverSlideId(null), null);
});

test('targets and payloads', () => {
  const slide = { id: '12' };
  const el = { id: 'e1', x: 100, y: 50, w: 200, h: 80 };
  assert.deepEqual(resolveTarget('deck', { slide }), { slide_id: null, element_id: null, anchor: null });
  assert.deepEqual(resolveTarget('slide', { slide }), { slide_id: 12, element_id: null, anchor: null });
  assert.deepEqual(resolveTarget('element', { slide, element: el }), { slide_id: 12, element_id: 'e1', anchor: { x: 300, y: 50 } });
  assert.deepEqual(resolveTarget('point', { slide, anchor: { x: 5, y: 6, element: 'e1' } }), { slide_id: 12, element_id: 'e1', anchor: { x: 5, y: 6 } });
  assert.equal(resolveTarget('slide', { slide: { id: 's_tmp1' } }), null);
  assert.equal(resolveTarget('element', { slide }), null);
  assert.deepEqual(postPayload({ body: ' hi ', target: resolveTarget('point', { slide, anchor: { x: 5, y: 6 } }) }), { body: 'hi', slide_id: 12, anchor: { x: 5, y: 6 } });
  assert.deepEqual(postPayload({ body: 'x', parent: { id: 3 }, target: { slide_id: 9 } }), { body: 'x', parent_id: 3 });
  assert.deepEqual(postPayload({ body: 'x', target: { slide_id: null } }), { body: 'x' });
});

test('optimistic comments are laid back over the server list and replies follow their parent', () => {
  const me = { id: 1, name: 'Me' };
  const rootTmp = { tmp: 't1', parent_id: null, comment: tempComment({ tmp: 't1', body: 'new root', me, target: { slide_id: 12, anchor: { x: 1, y: 2 } }, now: 0 }) };
  const replyTmp = { tmp: 't2', parent_id: 3, comment: tempComment({ tmp: 't2', body: 'a reply', me, parent: { id: 3, slide_id: 12, element_id: 'e1', anchor: { x: 10, y: 20 } }, now: 0 }) };
  const orphan = { tmp: 't3', parent_id: 999, comment: tempComment({ tmp: 't3', body: 'lost', me, parent: { id: 999 }, now: 0 }) };
  const out = applyTemps(threads, [rootTmp, replyTmp, orphan]);
  assert.equal(out[0].id, 't1');
  assert.equal(out[0].pending, true);
  assert.equal(out.find((t) => t.id === 3).replies.at(-1).id, 't2');
  assert.equal(out.length, threads.length + 1);
  assert.equal(threads[0].replies.length, 2); // input untouched
  assert.equal(applyTemps(threads, []), threads);
  assert.deepEqual(buildPins(out).map((p) => p.id), ['t1', 3]);
});

test('failure messages are plain', () => {
  assert.match(failureMessage({ network: true }), /No connection/);
  assert.match(failureMessage({ status: 403 }), /not allowed/);
  assert.match(failureMessage({ status: 429, retryAfter: 12 }), /12 seconds/);
  assert.equal(failureMessage({ status: 422, message: 'The body field is required.' }), 'The body field is required.');
});
