import test from 'node:test';
import assert from 'node:assert/strict';
import { mapKey, createNumberBuffer, isEditable } from '../keys.js';
import { parseMessage, channelName, newChannelKey } from '../channel.js';
import { parseLiveState, followSession, createThrottle, sendCommand } from '../live.js';

const k = (key, extra = {}) => mapKey({ key, ...extra });

test('navigation keys', () => {
    for (const key of ['ArrowRight', 'ArrowDown', 'PageDown', ' ']) assert.equal(k(key).action, 'next', key);
    for (const key of ['ArrowLeft', 'ArrowUp', 'PageUp', 'Backspace']) assert.equal(k(key).action, 'prev', key);
    assert.equal(k(' ', { shiftKey: true }).action, 'prev');
    assert.equal(k('Home').action, 'first');
    assert.equal(k('End').action, 'last');
});

test('letter keys, case insensitive; period blacks out', () => {
    assert.equal(k('g').action, 'grid');
    assert.equal(k('G').action, 'grid');
    assert.equal(k('b').action, 'blackout');
    assert.equal(k('.').action, 'blackout');
    assert.equal(k('w').action, 'whiteout');
    assert.equal(k('n').action, 'notes');
    assert.equal(k('f').action, 'fullscreen');
    assert.equal(k('l').action, 'laser');
    assert.equal(k('Escape').action, 'escape');
    assert.equal(k('x'), null);
});

test('Enter jumps only while a number is being typed; Backspace edits the number', () => {
    assert.equal(k('Enter').action, 'next');
    assert.equal(mapKey({ key: 'Enter' }, { typing: true }).action, 'jump');
    assert.equal(mapKey({ key: 'Backspace' }, { typing: true }).action, 'digit-back');
    assert.deepEqual(k('7'), { action: 'digit', digit: '7' });
});

test('modified keys are ignored so browser shortcuts keep working', () => {
    assert.equal(k('f', { metaKey: true }), null);
    assert.equal(k('ArrowRight', { altKey: true }), null);
    assert.equal(k('g', { ctrlKey: true }), null);
    assert.equal(mapKey(null), null);
    assert.equal(mapKey({ key: 5 }), null);
});

test('number buffer', () => {
    const b = createNumberBuffer(3);
    assert.equal(b.active, false);
    b.push('1'); b.push('2');
    assert.equal(b.value, '12');
    b.back();
    assert.equal(b.value, '1');
    b.push('2'); b.push('3'); b.push('4');
    assert.equal(b.value, '234');
    assert.equal(b.commit(), 234);
    assert.equal(b.commit(), null);
});

test('isEditable', () => {
    assert.equal(isEditable({ tagName: 'INPUT' }), true);
    assert.equal(isEditable({ tagName: 'DIV', isContentEditable: true }), true);
    assert.equal(isEditable({ tagName: 'DIV' }), false);
    assert.equal(isEditable(null), false);
});

test('parseMessage rebuilds messages from known fields only', () => {
    assert.deepEqual(parseMessage({ t: 'state', slide: 3.9, step: -2, cover: 'black', seq: 4, extra: '<script>' }), { t: 'state', slide: 3, step: 0, cover: 'black', seq: 4 });
    assert.equal(parseMessage({ t: 'state', slide: 1, step: 0, cover: 'red' }).cover, null);
    assert.equal(parseMessage({ t: 'state', slide: 'x', step: {} }).slide, 0);
    assert.equal(parseMessage({ t: 'state', slide: 1e12, step: 0 }).slide, 5000);
    assert.deepEqual(parseMessage({ t: 'laser', x: 2, y: -1, on: 1 }), { t: 'laser', x: 1, y: 0, on: true });
    assert.deepEqual(parseMessage({ t: 'cmd', action: 'next' }), { t: 'cmd', action: 'next' });
    assert.equal(parseMessage({ t: 'cmd', action: 'rm -rf' }), null);
    assert.equal(parseMessage({ t: 'wat' }), null);
    assert.equal(parseMessage('state'), null);
    assert.equal(parseMessage(null), null);
});

test('channel names are safe and keys are unique', () => {
    assert.equal(channelName('12', 'ab/../c'), 'dot-press:12:abc');
    assert.notEqual(newChannelKey(), newChannelKey());
    assert.match(newChannelKey(), /^[a-z0-9]{12}$/);
});

test('parseLiveState clamps and ignores unknown fields', () => {
    assert.deepEqual(parseLiveState({ slide: 2, step: 1, cover: 'white', ended: false, rev: 9, evil: 'secret' }), { slide: 2, step: 1, cover: 'white', ended: false, rev: 9, count: 0 });
    assert.equal(parseLiveState({ slide: -4 }).slide, 0);
    assert.equal(parseLiveState(null), null);
});

function res(status, body, headers = {}) {
    return { status, ok: status >= 200 && status < 300, headers: { get: (n) => headers[n] ?? null }, json: async () => body };
}

test('followSession sends If-None-Match, reports changes and stops when the session ends', async () => {
    const calls = [];
    const queue = [res(200, { slide: 1, step: 0, rev: 1 }, { ETag: '"a"' }), res(304), res(200, { slide: 2, step: 0, rev: 2, ended: true }, { ETag: '"b"' })];
    const states = [];
    let ended = 0;
    await new Promise((resolve) => {
        followSession({
            token: 'tok',
            interval: 1,
            fetchImpl: async (url, init) => { calls.push([url, init.headers['If-None-Match']]); return queue.shift(); },
            onState: (s) => states.push(s.slide),
            onEnded: () => { ended++; resolve(); },
        });
    });
    assert.deepEqual(states, [1, 2]);
    assert.equal(ended, 1);
    assert.equal(calls[0][1], undefined);
    assert.equal(calls[1][1], '"a"');
    assert.equal(calls[0][0], '/live/tok/state');
});

test('followSession treats 404 as ended and survives network errors', async () => {
    let n = 0;
    const errors = [];
    await new Promise((resolve) => {
        followSession({
            token: 't', interval: 1,
            fetchImpl: async () => { n++; if (n === 1) throw new Error('offline'); return res(404, null); },
            onError: (m) => errors.push(m),
            onEnded: resolve,
        });
    });
    assert.equal(errors.length, 1);
    assert.match(errors[0], /offline/i);
});

test('sendCommand posts json with the key and no credentials', async () => {
    let seen;
    const ok = await sendCommand({ token: 'a b', key: 'kk', action: 'next', fetchImpl: async (url, init) => { seen = [url, init]; return res(200, {}); } });
    assert.equal(ok, true);
    assert.equal(seen[0], '/live/a%20b/command');
    assert.deepEqual(JSON.parse(seen[1].body), { action: 'next', k: 'kk' });
    assert.equal(seen[1].credentials, 'omit');
});

test('throttle sends the first value at once and the latest value after the wait', async () => {
    const out = [];
    const t = createThrottle((v) => out.push(v), 30);
    t(1); t(2); t(3);
    assert.deepEqual(out, [1]);
    await new Promise((r) => setTimeout(r, 60));
    assert.deepEqual(out, [1, 3]);
});

test('parseLiveState keeps title and notes only when the server sent them', () => {
    const s = parseLiveState({ slide: 1, step: 0, title: 'Intro', notes: 'say hi', next_title: 'Plan', count: 12 });
    assert.equal(s.title, 'Intro');
    assert.equal(s.notes, 'say hi');
    assert.equal(s.nextTitle, 'Plan');
    assert.equal(s.count, 12);
    assert.equal('notes' in parseLiveState({ slide: 1 }), false);
});
