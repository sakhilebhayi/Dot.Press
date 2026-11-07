import test from 'node:test';
import assert from 'node:assert/strict';
import { safeUrl, safeAssetUrl, resolveSrc, embedInfo, slideAssetIds } from '../media.js';

test('safeUrl only accepts clean https urls', () => {
    assert.equal(safeUrl('https://a.example/x.png'), 'https://a.example/x.png');
    for (const bad of ['http://a.example', 'javascript:alert(1)', 'data:text/html,x', 'https://a.example/"x', 'https://a b', '//a.example', 5, null]) assert.equal(safeUrl(bad), null);
});

test('safeAssetUrl: relative signed urls and data images pass, script data does not', () => {
    assert.equal(safeAssetUrl('/api/assets/3/download?signature=abc'), '/api/assets/3/download?signature=abc');
    assert.ok(safeAssetUrl('data:image/png;base64,AAAA'));
    assert.equal(safeAssetUrl('data:text/html;base64,AAAA'), null);
    assert.equal(safeAssetUrl('//evil.example/x'), null);
    assert.equal(safeAssetUrl('javascript:alert(1)'), null);
});

test('resolveSrc', () => {
    const assets = { 7: { url: '/a/7', thumb: '/a/7t', w: 100, h: 50, mime: 'image/png', name: 'x.png' } };
    assert.equal(resolveSrc({ asset: 7 }, assets).thumb, '/a/7t');
    assert.equal(resolveSrc({ asset: 8 }, assets), null);
    assert.equal(resolveSrc({ url: 'https://a.example/i.jpg' }, assets).url, 'https://a.example/i.jpg');
    assert.equal(resolveSrc({ url: 'http://a.example/i.jpg' }, assets), null);
    assert.equal(resolveSrc(null, assets), null);
});

test('embedInfo validates ids and uses privacy friendly hosts', () => {
    const y = embedInfo({ provider: 'youtube', id: 'dQw4w9WgXcQ' }, 30);
    assert.match(y.src, /^https:\/\/www\.youtube-nocookie\.com\/embed\/dQw4w9WgXcQ\?/);
    assert.match(y.src, /start=30/);
    assert.equal(embedInfo({ provider: 'youtube', id: '"><script>' }), null);
    const v = embedInfo({ provider: 'vimeo', id: '123456789' });
    assert.match(v.src, /^https:\/\/player\.vimeo\.com\/video\/123456789\?.*dnt=1/);
    assert.equal(embedInfo({ provider: 'vimeo', id: 'abc' }), null);
    assert.equal(embedInfo({ provider: 'other', id: '12345678' }), null);
});

test('slideAssetIds collects every asset a slide uses', () => {
    const ids = slideAssetIds({ bg: { img: { asset: 9 } }, els: [{ type: 'image', src: { asset: 1 } }, { type: 'video', src: { asset: 2 }, poster: { asset: 3 } }, { type: 'image', src: { url: 'https://x.y/z.png' } }] });
    assert.deepEqual(ids.sort(), ['1', '2', '3', '9']);
});
