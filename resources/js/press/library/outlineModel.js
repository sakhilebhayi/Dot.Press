/**
 * The outline flow (node tested): reading the answer of POST /api/ai/outline, editing it, and turning it into slides.
 *
 * The route answers {mode: 'local'|'ai', outline: [{beat, label, slides: [...]}]}. A slide in `slides` is a string (the
 * claim) or an object with title (or claim, heading), points (or bullets) and notes. Anything else is skipped.
 *
 *   normalizeOutline(res)            -> {mode, modeLabel, beats: [{id, beat, label, slides: [{id, title, points, notes}]}]} | null
 *   modeLabel(mode)                  'Local rules' | 'AI model'
 *   moveBeat(beats, id, dir)         new array, the beat moved one place up (-1) or down (1)
 *   outlineToDeck(outline, deck, {makeId}) -> {sections: [{id, title}], slides: [slide], count}
 *       slide = {title, layout, notes, section_id, els}; every beat becomes a section named by its label; the first slide of
 *       the first beat is a title slide, the others are title and bullets when they have points, else title only.
 *   buildOps(deckSlideId, sections, slides, {makeId})  -> chunks of ops (at most 150 each) ready for POST /ops
 */
export function modeLabel(mode) { return mode === 'ai' ? 'AI model' : 'Local rules'; }

const text = (v) => (typeof v === 'string' ? v.trim() : '');

function normSlide(s, nextId) {
  if (typeof s === 'string') return text(s) ? { id: nextId(), title: text(s), points: [], notes: '' } : null;
  if (!s || typeof s !== 'object') return null;
  const title = text(s.title) || text(s.claim) || text(s.heading) || text(s.text);
  if (!title) return null;
  const rawPoints = Array.isArray(s.points) ? s.points : Array.isArray(s.bullets) ? s.bullets : [];
  return { id: nextId(), title, points: rawPoints.map(text).filter(Boolean), notes: text(s.notes) };
}

export function normalizeOutline(res) {
  const list = res && Array.isArray(res.outline) ? res.outline : null;
  if (!list) return null;
  let n = 0;
  const nextId = () => `o${++n}`;
  const beats = [];
  for (const b of list) {
    if (!b || typeof b !== 'object') continue;
    const slides = (Array.isArray(b.slides) ? b.slides : []).map((s) => normSlide(s, nextId)).filter(Boolean);
    const label = text(b.label) || text(b.beat);
    if (!label && !slides.length) continue;
    beats.push({ id: nextId(), beat: text(b.beat) || label, label: label || text(b.beat), slides });
  }
  if (!beats.length) return null;
  return { mode: res.mode === 'ai' ? 'ai' : 'local', modeLabel: modeLabel(res.mode), beats };
}

export function moveBeat(beats, id, dir) {
  const i = beats.findIndex((b) => b.id === id);
  const j = i + dir;
  if (i < 0 || j < 0 || j >= beats.length) return beats;
  const next = beats.slice();
  [next[i], next[j]] = [next[j], next[i]];
  return next;
}

const bulletDoc = (points) => ({
  type: 'doc',
  content: [{ type: 'bulletList', content: points.map((p) => ({ type: 'listItem', content: [{ type: 'paragraph', content: [{ type: 'text', text: p }] }] })) }],
});
const paraDoc = (str) => ({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: str }] }] });

export function outlineToDeck(beats, deck, { makeId }) {
  const W = deck.w || 1920;
  const H = deck.h || 1080;
  const u = W / 1920;
  const taken = new Set();
  const id = (prefix) => { const v = makeId(taken, prefix); taken.add(v); return v; };
  const sections = [];
  const slides = [];
  beats.forEach((beat, bi) => {
    const secId = id('sec');
    sections.push({ id: secId, title: beat.label || beat.beat || 'Section' });
    beat.slides.forEach((s, si) => {
      const first = bi === 0 && si === 0;
      const hasPoints = s.points.length > 0;
      const els = [];
      const margin = 140 * u;
      if (first) {
        els.push({ id: 'title', type: 'text', role: 'title', x: margin, y: H * 0.32, w: W - margin * 2, h: H * 0.3, doc: paraDoc(s.title), font: '@heading', size: Math.round(96 * u), weight: 700, color: '@ink', align: 'left', valign: 'bottom', lh: 1.05, fit: 'shrink' });
      } else {
        els.push({ id: 'title', type: 'text', role: 'title', x: margin, y: H * 0.08, w: W - margin * 2, h: H * 0.2, doc: paraDoc(s.title), font: '@heading', size: Math.round(68 * u), weight: 700, color: '@ink', align: 'left', valign: 'top', lh: 1.1, fit: 'shrink' });
        if (hasPoints) els.push({ id: 'body', type: 'text', role: 'body', x: margin, y: H * 0.33, w: W - margin * 2, h: H * 0.55, doc: bulletDoc(s.points), font: '@body', size: Math.round(44 * u), weight: 400, color: '@ink', align: 'left', valign: 'top', lh: 1.4, ps: 0.4, fit: 'shrink' });
      }
      slides.push({ title: s.title, layout: first ? 'title' : hasPoints ? 'bullets' : 'blank', notes: s.notes || '', section_id: secId, els });
    });
  });
  return { sections, slides, count: slides.length };
}

const CHUNK = 150;

/**
 * POST /api/decks makes a deck with one blank slide. The ops add the sections and the slides after it and finally remove
 * that blank slide (the document keeps at least one slide, so removal comes last).
 */
export function buildOps(blankSlideId, sections, slides, { makeId }) {
  const ops = [];
  let after = null;
  for (const s of sections) ops.push({ op: 'section.add', id: s.id, title: s.title });
  const taken = new Set();
  slides.forEach((slide) => {
    const tid = makeId(taken, 's');
    taken.add(tid);
    ops.push({ op: 'slide.add', tid, after, slide });
    after = tid;
  });
  if (blankSlideId != null) ops.push({ op: 'slide.remove', id: String(blankSlideId) });
  const chunks = [];
  for (let i = 0; i < ops.length; i += CHUNK) chunks.push(ops.slice(i, i + CHUNK));
  return chunks;
}

/** What to name the deck: the first line of the brief, cut at a word boundary. */
export function titleFromBrief(brief) {
  const line = String(brief || '').split(/\n/)[0].replace(/\s+/g, ' ').trim();
  if (!line) return 'Untitled presentation';
  if (line.length <= 70) return line.replace(/[.\s]+$/, '');
  const cut = line.slice(0, 70);
  return `${cut.slice(0, cut.lastIndexOf(' ') > 30 ? cut.lastIndexOf(' ') : 70)}`.replace(/[.,;:\s]+$/, '');
}

/**
 * Reading the answer of POST /api/imports/pptx (and any report-like object): the new deck id and a flat list of
 * what could not be converted.   -> {deckId, notes: [{kind: 'note'|'skipped', text}]}
 */
export function normalizeImport(res) {
  const deckId = res?.deck?.id ?? res?.deck_id ?? res?.id ?? null;
  const notes = [];
  const push = (kind, v) => {
    if (typeof v === 'string' && v.trim()) notes.push({ kind, text: v.trim() });
    else if (v && typeof v === 'object') {
      const t = text(v.message) || text(v.reason) || text(v.text) || text(v.what);
      const where = v.slide != null ? `Slide ${v.slide}: ` : '';
      if (t) notes.push({ kind, text: `${where}${t}` });
    }
  };
  const report = res?.report ?? res?.warnings ?? null;
  if (Array.isArray(report)) report.forEach((v) => push('note', v));
  else if (report && typeof report === 'object') {
    for (const [key, val] of Object.entries(report)) {
      const kind = /skip|unsupport|lost|dropped|fail/i.test(key) ? 'skipped' : 'note';
      if (Array.isArray(val)) val.forEach((v) => push(kind, v));
      else if (typeof val === 'string') push(kind, val);
    }
  }
  return { deckId: deckId == null ? null : String(deckId), notes };
}
