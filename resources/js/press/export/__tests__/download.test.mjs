import test from 'node:test';
import assert from 'node:assert/strict';
import { filenameFromDisposition, formatBytes, downloadUrl } from '../download.js';

test('file name from Content-Disposition', () => {
    assert.equal(filenameFromDisposition('attachment; filename="board-pack.pdf"'), 'board-pack.pdf');
    assert.equal(filenameFromDisposition("attachment; filename=\"fallback.pdf\"; filename*=UTF-8''Caf%C3%A9.pdf"), 'Café.pdf');
    assert.equal(filenameFromDisposition('attachment; filename=deck.pptx'), 'deck.pptx');
    assert.equal(filenameFromDisposition('attachment; filename="a \\"b\\".pdf"'), 'a "b".pdf');
    assert.equal(filenameFromDisposition(null, 'x.bin'), 'x.bin');
});

test('byte sizes read plainly', () => {
    assert.equal(formatBytes(0), '0 B');
    assert.equal(formatBytes(1536), '1.5 KB');
    assert.equal(formatBytes(250 * 1024), '250 KB');
    assert.equal(formatBytes(5.5 * 1048576), '5.5 MB');
    assert.equal(formatBytes(120 * 1048576), '120 MB');
});

const res = (status, { json, headers = {} } = {}) => ({
    ok: status >= 200 && status < 300,
    status,
    headers: { get: (k) => headers[k.toLowerCase()] ?? null },
    json: async () => { if (json === undefined) throw new Error('no json'); return json; },
    blob: async () => ({ size: 3 }),
});

test('a failed download becomes a plain message with the status', async () => {
    await assert.rejects(downloadUrl('/x', { fetchFn: async () => res(503, { json: { message: 'The offline player is not built on this server.' } }) }), (e) => e.status === 503 && /not built/.test(e.message));
    await assert.rejects(downloadUrl('/x', { fetchFn: async () => res(410, {}) }), (e) => e.status === 410 && /410/.test(e.message));
    await assert.rejects(downloadUrl('/x', { fetchFn: async () => { throw new TypeError('x'); } }), (e) => e.network === true);
});
