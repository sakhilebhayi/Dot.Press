import test from 'node:test';
import assert from 'node:assert/strict';
import {
  classifyFile, fileProblem, fitInside, placeMedia, replaceTarget, replacePatch, assetToElementProps, uploadMessage, toAssetEntry,
} from '../lib/drop.js';
import { S, T, R } from '../../core/__tests__/helpers.mjs';

const deck = { w: 1920, h: 1080 };

test('files are classified by mime type, then by extension', () => {
  assert.equal(classifyFile({ type: 'image/png', name: 'a' }), 'image');
  assert.equal(classifyFile({ type: 'video/mp4', name: 'a' }), 'video');
  assert.equal(classifyFile({ type: 'audio/mpeg', name: 'a' }), 'audio');
  assert.equal(classifyFile({ type: '', name: 'Photo.JPG' }), 'image');
  assert.equal(classifyFile({ type: '', name: 'clip.mov' }), 'video');
  assert.equal(classifyFile({ type: 'application/pdf', name: 'a.pdf' }), null);
  assert.equal(classifyFile(null), null);
  assert.match(fileProblem({ type: 'application/pdf', name: 'a.pdf', size: 3 }), /not a picture, video or audio/);
  assert.match(fileProblem({ type: 'image/png', name: 'a.png', size: 0 }), /empty/);
  assert.equal(fileProblem({ type: 'image/png', name: 'a.png', size: 9 }), null);
});

test('an image is fitted to the slide with its ratio kept and centred or dropped at a point', () => {
  const wide = placeMedia(deck, 'image', { w: 4000, h: 1000 });
  assert.equal(wide.w, 1152);
  assert.equal(wide.h, 288);
  assert.equal(wide.x, (1920 - 1152) / 2);
  const tall = placeMedia(deck, 'image', { w: 1000, h: 4000 });
  assert.equal(tall.h, 756);
  assert.equal(tall.w, 189);
  const at = placeMedia(deck, 'image', { w: 400, h: 300 }, { x: 900, y: 500 });
  assert.equal(Math.round(at.x + at.w / 2), 900);
  const edge = placeMedia(deck, 'image', { w: 400, h: 300 }, { x: 5, y: 5 });
  assert.ok(edge.x >= 0 && edge.y >= 0);
  const unknown = placeMedia(deck, 'image', null);
  assert.equal(Math.round((unknown.w / unknown.h) * 100), 133);
  assert.equal(placeMedia(deck, 'audio', null).w, placeMedia(deck, 'audio', null).h);
  assert.deepEqual(fitInside({ w: 100, h: 50 }, 50, 50), { w: 50, h: 25 });
});

test('dropping onto a picture replaces its source', () => {
  const slide = S('s', [
    { id: 'pic', type: 'image', x: 100, y: 100, w: 400, h: 300, rot: 0, op: 1, lock: false, hide: false, src: { asset: 1 }, crop: { x: 0.1, y: 0.1, w: 0.5, h: 0.5 }, mask: 'circle' },
    T('txt', 700, 100, 200, 100),
    { id: 'vid', type: 'video', x: 100, y: 600, w: 400, h: 200, rot: 0, op: 1, lock: false, hide: false, src: { asset: 2 } },
  ]);
  assert.equal(replaceTarget(slide, { x: 150, y: 150 }, 'image'), 'pic');
  assert.equal(replaceTarget(slide, { x: 150, y: 650 }, 'image'), null, 'a video is not replaced by a picture');
  assert.equal(replaceTarget(slide, { x: 150, y: 650 }, 'video'), 'vid');
  assert.equal(replaceTarget(slide, { x: 750, y: 150 }, 'image'), null, 'text on top: not a picture');
  assert.equal(replaceTarget(slide, { x: 1500, y: 900 }, 'image'), null);
  assert.equal(replaceTarget(slide, { x: 150, y: 150 }, 'audio'), null);
  assert.deepEqual(replacePatch(slide.els[0], { asset: 9 }), { src: { asset: 9 } }, 'frame, crop and mask are kept');
  assert.deepEqual(replacePatch(slide.els[2], { asset: 9 }), { src: { asset: 9 }, embed: null, poster: null });
});

test('asset payloads become element props and library entries', () => {
  const asset = { id: 7, kind: 'image', width: 800, height: 600, url: 'https://x/o', web: 'https://x/w', thumb: 'https://x/t', mime: 'image/png', name: 'a.png' };
  assert.deepEqual(assetToElementProps('image', asset), { src: { asset: 7 }, natural: { w: 800, h: 600 }, kind: 'image' });
  assert.equal(assetToElementProps('audio', { id: 1 }).natural, null);
  const e = toAssetEntry(asset);
  assert.equal(e.url, 'https://x/w');
  assert.equal(e.w, 800);
  assert.equal(e.mime, 'image/png');
});

test('upload errors become plain sentences', () => {
  assert.equal(uploadMessage({ response: { status: 422, data: { errors: { file: ['The file may not be larger than 20 MB.'] } } } }), 'The file may not be larger than 20 MB.');
  assert.equal(uploadMessage({ response: { status: 422, data: { message: 'Bad file.' } } }), 'Bad file.');
  assert.match(uploadMessage({ response: { status: 413 } }), /too large/);
  assert.match(uploadMessage({ response: { status: 403 } }), /view this deck/);
  assert.match(uploadMessage({ code: 'ERR_NETWORK' }), /could not reach/);
  assert.match(uploadMessage({ response: { status: 500 } }), /failed/);
});
