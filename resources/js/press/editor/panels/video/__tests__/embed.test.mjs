import test from 'node:test';
import assert from 'node:assert/strict';
import { parseEmbed, classifyVideoUrl, parseStart, formatStart, captureProblem } from '../lib/embed.js';

const ID = 'dQw4w9WgXcQ';

test('every common YouTube form gives the id', () => {
  const forms = [
    `https://www.youtube.com/watch?v=${ID}`,
    `https://youtube.com/watch?v=${ID}&feature=share`,
    `https://m.youtube.com/watch?v=${ID}`,
    `https://music.youtube.com/watch?v=${ID}&list=abc`,
    `https://youtu.be/${ID}`,
    `https://youtu.be/${ID}?si=xyz`,
    `https://www.youtube.com/embed/${ID}`,
    `https://www.youtube-nocookie.com/embed/${ID}?rel=0`,
    `https://www.youtube.com/shorts/${ID}`,
    `https://www.youtube.com/live/${ID}?feature=share`,
    `https://www.youtube.com/v/${ID}`,
    `youtube.com/watch?v=${ID}`,
    `  https://youtu.be/${ID}  `,
    `HTTPS://WWW.YOUTUBE.COM/watch?v=${ID}`,
  ];
  for (const f of forms) assert.deepEqual({ ...parseEmbed(f), start: 0 }, { provider: 'youtube', id: ID, start: 0 }, f);
});

test('YouTube start times are read from t and start', () => {
  assert.equal(parseEmbed(`https://youtu.be/${ID}?t=90`).start, 90);
  assert.equal(parseEmbed(`https://www.youtube.com/watch?v=${ID}&t=1m30s`).start, 90);
  assert.equal(parseEmbed(`https://www.youtube.com/embed/${ID}?start=12`).start, 12);
  assert.equal(parseEmbed(`https://www.youtube.com/watch?v=${ID}`).start, 0);
});

test('every common Vimeo form gives the id', () => {
  const forms = [
    'https://vimeo.com/76979871',
    'https://www.vimeo.com/76979871',
    'https://vimeo.com/76979871?share=copy',
    'https://player.vimeo.com/video/76979871',
    'https://player.vimeo.com/video/76979871?h=abc123',
    'https://vimeo.com/channels/staffpicks/76979871',
    'https://vimeo.com/groups/name/videos/76979871',
    'https://vimeo.com/album/1234567/video/76979871',
    'https://vimeo.com/76979871/abcdef1234',
    'vimeo.com/76979871',
  ];
  for (const f of forms) assert.deepEqual({ ...parseEmbed(f), start: 0 }, { provider: 'vimeo', id: '76979871', start: 0 }, f);
  assert.equal(parseEmbed('https://vimeo.com/76979871#t=45s').start, 45);
});

test('anything else is rejected', () => {
  const bad = [
    '', '   ', null, undefined, 42, {},
    `http://www.youtube.com/watch?v=${ID}`,
    `javascript:alert(1)//youtube.com/watch?v=${ID}`,
    `data:text/html,https://youtu.be/${ID}`,
    `ftp://youtube.com/watch?v=${ID}`,
    `https://youtube.com.evil.test/watch?v=${ID}`,
    `https://evilyoutube.com/watch?v=${ID}`,
    `https://notyoutu.be/${ID}`,
    `https://youtube.com@evil.test/watch?v=${ID}`,
    `https://user:pw@www.youtube.com/watch?v=${ID}`,
    `https://www.youtube.com:8080/watch?v=${ID}`,
    'https://www.youtube.com/watch?v=short',
    'https://www.youtube.com/watch?v=' + 'a'.repeat(30),
    'https://www.youtube.com/watch',
    'https://www.youtube.com/playlist?list=PL123',
    'https://www.youtube.com/channel/UC1234567890',
    'https://www.youtube.com/embed/' + ID + '/extra',
    'https://vimeo.com/',
    'https://vimeo.com/abc',
    'https://vimeo.com/123',
    'https://vimeo.com/user/someone',
    'https://vimeo.com.evil.test/76979871',
    'https://player.vimeo.com/76979871',
    'https://example.com/watch?v=' + ID,
    'https://www.dailymotion.com/video/x7tgad0',
    `https://youtu.be/${ID}\n<script>`,
    'https://www.youtube.com/watch?v=' + ID + '"onload="x',
  ];
  for (const b of bad) assert.equal(parseEmbed(b), null, String(b));
});

test('classifyVideoUrl sorts embeds, files and errors with plain messages', () => {
  assert.deepEqual(classifyVideoUrl(`https://youtu.be/${ID}?t=5`), { kind: 'embed', embed: { provider: 'youtube', id: ID }, start: 5 });
  assert.deepEqual(classifyVideoUrl('https://vimeo.com/76979871'), { kind: 'embed', embed: { provider: 'vimeo', id: '76979871' }, start: 0 });
  assert.deepEqual(classifyVideoUrl('https://cdn.example.com/media/clip.MP4?token=1'), { kind: 'file', url: 'https://cdn.example.com/media/clip.MP4?token=1' });
  assert.equal(classifyVideoUrl('https://example.com/watch').kind, 'error');
  assert.match(classifyVideoUrl('http://example.com/a.mp4').message, /https/);
  assert.match(classifyVideoUrl('').message, /Paste/);
  assert.equal(classifyVideoUrl('javascript:alert(1)').kind, 'error');
  assert.equal(classifyVideoUrl('https://user:pw@example.com/a.mp4').kind, 'error');
  for (const s of ['', 'http://a.com/x.mp4', 'https://example.com/watch', 'nonsense']) assert.doesNotMatch(classifyVideoUrl(s).message, /[\u2013\u2014]/);
});

test('start times', () => {
  assert.equal(parseStart('90'), 90);
  assert.equal(parseStart('1m30s'), 90);
  assert.equal(parseStart('1:30'), 90);
  assert.equal(parseStart('1:02:03'), 3723);
  assert.equal(parseStart('2h'), 7200);
  assert.equal(parseStart('abc'), 0);
  assert.equal(parseStart(''), 0);
  assert.equal(parseStart(null), 0);
  assert.equal(parseStart('999999'), 86400);
  assert.equal(formatStart(90), '1:30');
  assert.equal(formatStart(3723), '1:02:03');
  assert.equal(formatStart(-5), '0:00');
});

test('captureProblem', () => {
  assert.match(captureProblem({ hasAssetSource: false, ready: true, videoWidth: 10 }), /uploaded/);
  assert.match(captureProblem({ hasAssetSource: true, ready: false, videoWidth: 0 }), /Wait/);
  assert.equal(captureProblem({ hasAssetSource: true, ready: true, videoWidth: 640 }), null);
});
