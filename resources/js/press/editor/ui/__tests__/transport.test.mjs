import test from 'node:test';
import assert from 'node:assert/strict';
import { toApiError, createApi, createTransport, createKeepaliveBeacon, safeStorage } from '../transport.js';

test('toApiError: network, status, retry-after and validation messages', () => {
  const net = toApiError({ message: 'x' });
  assert.equal(net.network, true);
  assert.equal(net.status, undefined);
  assert.equal(toApiError({ code: 'ECONNABORTED' }).message, 'The request timed out');
  const limited = toApiError({ response: { status: 429, headers: { 'retry-after': '7' }, data: { message: 'Too many' } } });
  assert.equal(limited.status, 429);
  assert.equal(limited.retryAfter, 7);
  assert.equal(limited.message, 'Too many');
  const invalid = toApiError({ response: { status: 422, headers: {}, data: { message: 'bad', errors: { title: ['The title is required.'] } } } });
  assert.equal(invalid.message, 'The title is required.');
  assert.equal(toApiError({ response: { status: 500, headers: {}, data: null } }).message, 'The server answered 500');
});

test('createApi returns data and rethrows translated errors once', async () => {
  const http = {
    get: async (url, cfg) => ({ data: { url, params: cfg.params } }),
    post: async () => { const e = new Error('boom'); e.response = { status: 403, headers: {}, data: { message: 'No' } }; throw e; },
  };
  const api = createApi(http);
  assert.deepEqual(await api.get('/a', { q: 1 }), { url: '/a', params: { q: 1 } });
  await assert.rejects(() => api.post('/b', {}), (e) => e.status === 403 && e.message === 'No');
});

test('createTransport talks to the three deck endpoints', async () => {
  const calls = [];
  const http = { get: async (u, c) => { calls.push(['get', u, c.params]); return { data: { ok: 1 } }; }, post: async (u, d) => { calls.push(['post', u, d]); return { data: { seq: 5 } }; } };
  const t = createTransport(http, 12);
  assert.deepEqual(await t.postOps({ client: 'c', key: 'k', ops: [] }), { seq: 5 });
  await t.getChanges(9);
  await t.getDocument();
  assert.deepEqual(calls.map((c) => c.slice(0, 2)), [['post', '/api/decks/12/ops'], ['get', '/api/decks/12/changes'], ['get', '/api/decks/12/document']]);
  assert.deepEqual(calls[1][2], { after: 9 });
  assert.equal('beacon' in t, false);
  assert.equal(typeof createTransport(http, 12, { beacon: () => true }).beacon, 'function');
});

test('keepalive beacon sends the CSRF token and refuses oversized bodies', () => {
  let sent = null;
  const beacon = createKeepaliveBeacon(3, { fetchFn: (url, init) => { sent = { url, init }; return Promise.resolve(); }, cookie: () => 'a=1; XSRF-TOKEN=abc%3D; b=2' });
  assert.equal(beacon({ client: 'c', key: 'k', ops: [] }), true);
  assert.equal(sent.url, '/api/decks/3/ops');
  assert.equal(sent.init.keepalive, true);
  assert.equal(sent.init.headers['X-XSRF-TOKEN'], 'abc=');
  assert.equal(beacon({ ops: ['x'.repeat(70000)] }), false);
  assert.equal(createKeepaliveBeacon(3, { fetchFn: null })({}), false);
});

test('safeStorage works over localStorage and falls back to memory when it throws', () => {
  const store = new Map();
  const good = { localStorage: { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, v), removeItem: (k) => store.delete(k) } };
  const s = safeStorage(good);
  s.set('a', '1');
  assert.equal(s.get('a'), '1');
  assert.equal(s.ok(), true);
  s.remove('a');
  assert.equal(s.get('a'), null);

  const broken = { get localStorage() { throw new Error('denied'); } };
  const m = safeStorage(broken);
  assert.equal(m.ok(), false);
  m.set('a', '2');
  assert.equal(m.get('a'), '2');
  m.remove('a');
  assert.equal(m.get('a'), null);

  const full = { localStorage: { getItem: () => null, setItem: (k) => { if (k !== '__press_probe__') throw new Error('quota'); }, removeItem: () => {} } };
  const f = safeStorage(full);
  f.set('x', 'y');
  assert.equal(f.get('x'), 'y');
  assert.equal(f.ok(), false);
});
