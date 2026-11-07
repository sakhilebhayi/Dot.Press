import test from 'node:test';
import assert from 'node:assert/strict';
import { DEFAULT_THEME, resolveColor } from '../../../core/theme.js';
import { mkDoc, S, T, R } from '../../../core/__tests__/helpers.mjs';
import { applyOps } from '../../../core/ops.js';
import {
  COLOR_ROLES, apiErrorText, cleanTheme, draftDirty, draftFromTheme, draftPayload, hexOf, isCurrent, isModified, setDraft, splitThemes, themeKind,
} from '../themeModel.js';
import { contrastChecks, worstLevel } from '../contrast.js';
import {
  changeLines, changeSentence, themeChanges, themeReport, previewDocFor,
} from '../themeReport.js';
import { findHardColours, mappingOps } from '../consistency.js';
import { sampleSlide } from '../sampleDoc.js';
import {
  addColour, addFont, addLogo, draftFromKit, emptyKit, hasProblems, kitBody, kitProblems, kitThemeCard, removeAt, setAt,
} from '../brandKitModel.js';
import { columnsOf, gridTarget } from '../gridNav.js';

const night = {
  id: 'night', name: 'Night', builtin: true, warnings: [],
  c: { ...DEFAULT_THEME.c, bg: '#0d1320', ink: '#eef2fa', surface: '#17213a', muted: '#a2b1cc', line: '#2d3b5c', accent: '#6cb4ff' },
  t: { heading: 'Space Grotesk', body: 'IBM Plex Sans', hw: 600, scale: 1 }, s: { radius: 10, stroke: 2 }, bg: null,
};

// ---- themeModel -------------------------------------------------------------------------------
test('cleanTheme keeps only the spec keys and fills what is missing from the default', () => {
  const t = cleanTheme({ id: 'custom:3', name: 'X', db_id: 3, warnings: ['w'], builtin: false, c: { accent: '#111111' } });
  assert.deepEqual(Object.keys(t).sort(), ['bg', 'c', 'id', 'name', 's', 't']);
  assert.equal(t.c.accent, '#111111');
  assert.equal(t.c.bg, DEFAULT_THEME.c.bg);
  assert.equal(t.c.chart.length, 8);
  assert.equal(cleanTheme({ c: { chart: ['#000000'] } }).c.chart.length, 8);
});

test('themes are told apart by id and grouped in server order', () => {
  assert.equal(themeKind({ id: 'slate' }), 'builtin');
  assert.equal(themeKind({ id: 'custom:4' }), 'custom');
  assert.equal(themeKind({ id: 'brand-kit:2' }), 'brand');
  const g = splitThemes([{ id: 'slate' }, { id: 'custom:1' }, { id: 'brand-kit:1' }, { id: 'ledger' }, null, { nope: 1 }]);
  assert.deepEqual(g.builtin.map((t) => t.id), ['slate', 'ledger']);
  assert.equal(g.custom.length, 1);
  assert.equal(g.brand.length, 1);
  assert.deepEqual(splitThemes(undefined), { builtin: [], custom: [], brand: [] });
});

test('the deck theme is current by id, and modified when its snapshot differs from the library entry', () => {
  assert.ok(isCurrent({ id: 'night' }, night));
  assert.ok(!isCurrent({ id: 'other' }, night));
  assert.ok(!isCurrent(null, night));
  assert.ok(!isModified({ ...cleanTheme(night) }, night));
  assert.ok(isModified({ ...cleanTheme(night), c: { ...night.c, accent: '#ff0000' } }, night));
});

test('draft edits are immutable and reach nested paths and chart slots', () => {
  const d = draftFromTheme(night);
  const d2 = setDraft(d, 'c.accent', '#ff0000');
  const d3 = setDraft(d2, 'c.chart.2', '#00ff00');
  const d4 = setDraft(d3, 't.heading', 'Inter');
  assert.equal(d.c.accent, night.c.accent);
  assert.equal(d2.c.accent, '#ff0000');
  assert.equal(d3.c.chart[2], '#00ff00');
  assert.equal(d4.t.heading, 'Inter');
  assert.equal(setDraft(d, 'name', 'New').name, 'New');
  assert.equal(setDraft(d, 'bg', { g: 'linear', a: 90, stops: [[0, '#000000'], [1, '#ffffff']] }).bg.g, 'linear');
});

test('a token chosen in a colour field becomes the hex it stands for inside the draft', () => {
  const d = draftFromTheme(night);
  assert.equal(hexOf('@bg', d), '#0d1320');
  assert.equal(hexOf('#ABCDEF', d), '#abcdef');
  assert.equal(hexOf('@chart1', d), d.c.chart[0]);
  assert.equal(hexOf(null, d), '#000000');
});

test('the save body holds name, colours, type, shape and background; dirty compares to the saved copy', () => {
  const d = draftFromTheme(night, '  Night copy ');
  const body = draftPayload(d);
  assert.deepEqual(Object.keys(body).sort(), ['bg', 'c', 'name', 's', 't']);
  assert.equal(body.name, 'Night copy');
  assert.equal(body.c.chart.length, 8);
  assert.ok(!draftDirty(d, draftFromTheme(night, '  Night copy ')));
  assert.ok(draftDirty(setDraft(d, 's.radius', 3), d));
  assert.equal(COLOR_ROLES.length, 10);
});

test('API errors turn into plain sentences', () => {
  assert.match(apiErrorText({ network: true }), /could not be reached/);
  assert.match(apiErrorText({ status: 403 }, 'themes'), /look at themes/);
  assert.match(apiErrorText({ status: 429 }), /Wait a moment/);
  assert.match(apiErrorText({ status: 404 }), /no longer exists/);
  assert.equal(apiErrorText({ status: 422, data: { errors: { name: ['The name is taken.'] } } }), 'The name is taken.');
  assert.match(apiErrorText({ status: 500 }), /server had a problem/);
  assert.equal(apiErrorText(null), '');
});

// ---- contrast ---------------------------------------------------------------------------------
test('ink on bg: 7:1 and above is fine, under 7 a warning, under 4.5 an issue', () => {
  const check = (ink) => contrastChecks({ ...DEFAULT_THEME, c: { ...DEFAULT_THEME.c, ink } }).find((c) => c.id === 'ink-bg');
  assert.equal(check('#111827').level, 'ok');
  const warn = check('#6b7280'); // about 4.8:1 on white
  assert.equal(warn.level, 'warn');
  assert.match(warn.text, /below the 7:1 we recommend/);
  const issue = check('#9ca3af');
  assert.equal(issue.level, 'issue');
  assert.match(issue.text, /Many people will struggle/);
  assert.equal(worstLevel([check('#111827'), warn]), 'warn');
  assert.equal(worstLevel([warn, issue]), 'issue');
  assert.equal(worstLevel([check('#111827')]), 'ok');
});

test('every check is reported, with the ratio, for a valid theme', () => {
  const checks = contrastChecks(night);
  assert.equal(checks.length, 6);
  assert.ok(checks.every((c) => typeof c.ratio === 'number' && c.text.length > 10));
  assert.equal(checks.find((c) => c.id === 'ink-bg').ratio, 16.55);
  assert.equal(worstLevel(checks), 'ok');
});

test('a colour that is not hex cannot be measured and counts as an issue', () => {
  const c = contrastChecks({ ...DEFAULT_THEME, c: { ...DEFAULT_THEME.c, ink: 'banana' } }).find((x) => x.id === 'ink-bg');
  assert.equal(c.ratio, null);
  assert.equal(c.level, 'issue');
});

// ---- report ----------------------------------------------------------------------------------
const doc = () => mkDoc([
  S('s1', [
    T('t1', 0, 0, 100, 50, { color: '@ink', font: '@heading' }),
    T('t2', 0, 60, 100, 50, { color: '#c0392b' }),
    R('r1', 0, 120, 50, 50, { fill: '#2f6fed' }),
    R('r2', 60, 120, 50, 50, { fill: '@accent' }),
    T('t3', 0, 200, 50, 50, { color: '@ink', fill: '#ffffff' }),
  ]),
  S('s2', [R('r3', 0, 0, 10, 10, { fill: '@surface' })]),
]);

test('the report counts elements that follow the theme and elements that keep their own colours', () => {
  const r = themeReport(doc(), night);
  assert.equal(r.following, 4); // t1, r2, t3 (token + hex) and r3
  assert.equal(r.keepOwn, 2); // t2 and r1
  assert.equal(r.partial, 1); // t3 also has a hex fill
  assert.equal(r.total, 6);
  assert.equal(r.sentence, '4 elements follow the theme, 2 keep their own colours.');
  assert.deepEqual(r.slides.map((s) => s.slide), ['s1', 's2']);
});

test('the report is grammatical for one element and for none', () => {
  const one = mkDoc([S('s1', [R('r', 0, 0, 10, 10, { fill: '#123456' })])]);
  assert.equal(themeReport(one, night).sentence, '0 elements follow the theme, 1 keeps its own colours.');
  const two = mkDoc([S('s1', [R('r', 0, 0, 10, 10, { fill: '@accent' })])]);
  assert.equal(themeReport(two, night).sentence, '1 element follows the theme, 0 keep their own colours.');
});

test('themeChanges lists what differs and changeSentence says it in one line', () => {
  const same = themeChanges(night, night);
  assert.equal(same.colours, 0);
  assert.equal(changeSentence(same), '');
  const ch = themeChanges(DEFAULT_THEME, night);
  assert.ok(ch.colours >= 6);
  assert.equal(ch.fonts.length, 2);
  assert.deepEqual(ch.radius, { from: 12, to: 10 });
  assert.match(changeSentence(ch), /^Changes \d+ colours, both fonts and corner radius\.$/);
  assert.ok(changeLines(ch).some((l) => l.includes('Public Sans to Space Grotesk')));
  assert.equal(changeSentence({ colours: 1, fonts: [{ role: 'body' }], radius: null, stroke: null, scale: null, background: true }), 'Changes 1 colour, the body font and the default background.');
});

test('the preview document is the core applyTheme result: tokens follow, hex stays', () => {
  const d = doc();
  const out = previewDocFor(d, night);
  assert.equal(out.deck.theme, night);
  assert.equal(out.slides, d.slides);
  const r = applyOps(d, [{ op: 'deck.set', theme: night }]);
  assert.equal(r.doc.deck.theme.id, 'night');
});

// ---- consistency -----------------------------------------------------------------------------
test('hard coded colours that match no token get the nearest token as a proposal, close ones first ticked', () => {
  const d = mkDoc([S('s1', [
    T('t1', 0, 0, 10, 10, { color: '#c0392b' }),
    R('r1', 0, 0, 10, 10, { fill: '#c0392b' }),
    R('r2', 0, 0, 10, 10, { fill: '#2f6fed' }),
    R('r3', 0, 0, 10, 10, { fill: '#2563eb' }), // equals the slate accent: already a token colour
    R('r4', 0, 0, 10, 10, { fill: '#ff000080' }), // see-through: left alone
  ])]);
  const { proposals, alphaKept } = findHardColours(d, DEFAULT_THEME);
  assert.equal(alphaKept, 1);
  assert.deepEqual(proposals.map((p) => p.hex), ['#c0392b', '#2f6fed']);
  assert.equal(proposals[0].count, 2);
  assert.equal(proposals[0].token, '@bad');
  assert.ok(proposals[0].close);
  assert.equal(proposals[1].token, '@accent');
  assert.ok(proposals.every((p) => typeof p.distance === 'number'));
});

test('a colour far from every token is proposed but not close', () => {
  const d = mkDoc([S('s1', [R('r1', 0, 0, 10, 10, { fill: '#ff00ff' })])]);
  const { proposals } = findHardColours(d, DEFAULT_THEME, { maxDistance: 10 });
  assert.equal(proposals.length, 1);
  assert.equal(proposals[0].close, false);
});

test('mapping ops change only the accepted colours and only the keys that changed, in text and chart too', () => {
  const d = mkDoc([S('s1', [
    T('t1', 0, 0, 10, 10, { color: '#c0392b', doc: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'a', marks: [{ type: 'textStyle', attrs: { color: '#c0392b' } }] }] }] } }),
    R('r2', 0, 0, 10, 10, { fill: '#2f6fed', stroke: '#2f6fed' }),
    R('r3', 0, 0, 10, 10, { fill: '@accent' }),
  ])]);
  const ops = mappingOps(d, [{ hex: '#C0392B', token: '@bad' }]);
  assert.equal(ops.length, 1);
  assert.equal(ops[0].id, 't1');
  assert.deepEqual(Object.keys(ops[0].p).sort(), ['color', 'doc']);
  assert.equal(ops[0].p.color, '@bad');
  assert.equal(ops[0].p.doc.content[0].content[0].marks[0].attrs.color, '@bad');
  const out = applyOps(d, ops).doc;
  assert.equal(out.slides[0].els[0].color, '@bad');
  assert.equal(out.slides[0].els[1].fill, '#2f6fed');
  assert.deepEqual(mappingOps(d, []), []);
  assert.deepEqual(mappingOps(d, [{ hex: 'nope', token: '@bad' }]), []);
  const both = mappingOps(d, [{ hex: '#2f6fed', token: '@accent' }]);
  assert.deepEqual(both[0].p, { fill: '@accent', stroke: '@accent' });
});

// ---- sample document ------------------------------------------------------------------------
test('the sample slide holds a title, text, shapes and a chart that all use theme tokens', () => {
  const { deck, slide } = sampleSlide(night);
  assert.equal(deck.theme.id, 'night');
  assert.deepEqual(slide.els.map((e) => e.type), ['text', 'text', 'shape', 'shape', 'chart']);
  assert.equal(slide.els[0].role, 'title');
  assert.equal(slide.els[0].weight, 600);
  assert.equal(slide.els[0].font, '@heading');
  assert.equal(slide.els[2].fill, '@accent');
  assert.equal(slide.els[3].fill, '@accent2');
  assert.equal(slide.els[4].chart.opt.legend, 'none');
  for (const e of slide.els) {
    assert.ok(e.x >= 0 && e.y >= 0 && e.x + e.w <= deck.w && e.y + e.h <= deck.h, `${e.id} inside`);
  }
  assert.equal(resolveColor(slide.els[2].fill, deck.theme), '#6cb4ff');
});

test('the sample slide scales to any size and does not alter the theme it was given', () => {
  const s = sampleSlide(night, { w: 800, h: 450 });
  assert.equal(s.deck.w, 800);
  assert.ok(s.slide.els.every((e) => e.x + e.w <= 800 + 0.01));
  assert.equal(night.c.accent, '#6cb4ff');
});

// ---- brand kits ------------------------------------------------------------------------------
test('a kit draft round trips to the API body and back', () => {
  const kit = { id: 7, name: 'Acme', is_default: true, tokens: { palette: [{ name: 'Navy', hex: '#112233', role: 'ink' }, { name: 'Sky', hex: '#66aaff' }], fonts: [{ family: 'Inter', role: 'heading' }], logos: [5] }, logo_assets: [{ id: 5, name: 'logo.png', url: '/a', thumb: '/b' }] };
  const d = draftFromKit(kit);
  assert.deepEqual(kitBody(d), { name: 'Acme', is_default: true, tokens: { palette: [{ name: 'Navy', hex: '#112233', role: 'ink' }, { name: 'Sky', hex: '#66aaff' }], fonts: [{ family: 'Inter', role: 'heading' }], logos: [5] } });
  assert.equal(d.logo_assets.length, 1);
});

test('palette, fonts and logos respect their limits; add and remove keep logo assets in step', () => {
  let d = emptyKit();
  for (let i = 0; i < 14; i++) d = addColour(d, '#000000', 12);
  assert.equal(d.palette.length, 12);
  d = addFont(addFont(addFont(addFont(d, 'Inter', 3), 'Inter', 3), 'Inter', 3), 'Inter', 3);
  assert.deepEqual(d.fonts.map((f) => f.role), ['heading', 'body', 'accent']);
  d = addLogo(d, { id: 1, name: 'a', url: 'u', thumb: 't' }, 2);
  d = addLogo(d, { id: 1, name: 'a', url: 'u', thumb: 't' }, 2);
  d = addLogo(d, { id: 2, name: 'b', url: 'u', thumb: 't' }, 2);
  d = addLogo(d, { id: 3, name: 'c', url: 'u', thumb: 't' }, 2);
  assert.deepEqual(d.logos, [1, 2]);
  d = removeAt(d, 'logos', 0);
  assert.deepEqual(d.logos, [2]);
  assert.deepEqual(d.logo_assets.map((l) => l.id), [2]);
  d = setAt(d, 'palette', 0, { name: 'Brand blue' });
  assert.equal(d.palette[0].name, 'Brand blue');
  assert.equal(removeAt(d, 'palette', 0).palette.length, 11);
});

test('kit problems name the field and say what to do', () => {
  assert.equal(kitProblems(emptyKit()).name, 'Give the kit a name.');
  let d = { ...emptyKit(), name: 'A', palette: [{ name: '', hex: '#000000', role: '' }, { name: 'x', hex: 'red', role: '' }, { name: 'ok', hex: '#123456', role: 'bg' }, { name: 'ok2', hex: '#123457', role: 'bg' }], fonts: [{ family: 'Inter', role: 'body' }, { family: 'Inter', role: 'body' }, { family: '!!', role: 'heading' }] };
  const p = kitProblems(d);
  assert.equal(p.palette[0], 'Name this colour.');
  assert.match(p.palette[1], /hex colour/);
  assert.match(p.palette[3], /Only one colour can be the background/);
  assert.match(p.fonts[1], /Only one font can be the body font/);
  assert.match(p.fonts[2], /letters, digits/);
  assert.ok(hasProblems(p));
  assert.ok(!hasProblems(kitProblems({ ...emptyKit(), name: 'Fine' })));
  assert.ok(hasProblems(kitProblems({ ...emptyKit(), name: 'x'.repeat(81) })));
});

test('a kit is shown in the gallery as its converted theme', () => {
  const card = kitThemeCard({ id: 3, name: 'Acme', is_default: true, theme: { id: 'brand-kit:3', name: 'Acme', c: {} }, warnings: ['w'] });
  assert.equal(card.id, 'brand-kit:3');
  assert.equal(card.kitName, 'Acme');
  assert.ok(card.isDefault);
  assert.equal(themeKind(card), 'brand');
});

// ---- grid navigation -------------------------------------------------------------------------
test('arrow keys move along the grid, up and down by a row, and stay inside it', () => {
  assert.equal(gridTarget('ArrowRight', 0, 6, 2), 1);
  assert.equal(gridTarget('ArrowDown', 0, 6, 2), 2);
  assert.equal(gridTarget('ArrowDown', 5, 6, 2), 5);
  assert.equal(gridTarget('ArrowLeft', 0, 6, 2), 0);
  assert.equal(gridTarget('ArrowUp', 3, 6, 2), 1);
  assert.equal(gridTarget('Home', 4, 6, 2), 0);
  assert.equal(gridTarget('End', 0, 6, 2), 5);
  assert.equal(gridTarget('a', 0, 6, 2), null);
  assert.equal(gridTarget('ArrowRight', 0, 0, 2), null);
  assert.equal(columnsOf([10, 10.5, 140, 140]), 2);
  assert.equal(columnsOf([]), 1);
  assert.equal(columnsOf([5, 100, 200]), 1);
});
