// Shared test helpers: document builders and a seeded rng.
import { createDoc } from '../doc.js';

export function mulberry32(seed) {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

export const T = (id, x, y, w, h, o = {}) => ({ id, type: 'text', x, y, w, h, rot: 0, op: 1, lock: false, hide: false, ...o });
export const R = (id, x, y, w, h, o = {}) => ({ id, type: 'shape', kind: 'rect', x, y, w, h, rot: 0, op: 1, lock: false, hide: false, fill: '@accent', ...o });
export const S = (id, els = [], o = {}) => ({
  id, title: '', notes: '', section_id: null, hidden: false, layout: 'blank', bg: null, transition: null, rev: 0, els, ...o,
});

export function mkDoc(slides, deckOpts = {}) {
  return createDoc({
    deck: { id: 'd1', title: 'Deck', w: 1920, h: 1080, ...deckOpts, slide_order: slides.map((s) => s.id) },
    slides,
    seq: 0,
  });
}

/** A fresh counter based id source for deterministic duplicates. */
export function counterIds() {
  let n = 0;
  return (taken, prefix) => {
    let id;
    do { id = `${prefix}${++n}`; } while (taken.has(id));
    return id;
  };
}

const KINDS = ['text', 'shape', 'image', 'table'];

export function randomEl(rnd, id, w = 1920, h = 1080) {
  const type = KINDS[Math.floor(rnd() * KINDS.length)];
  const base = {
    id, type, x: Math.round(rnd() * w * 0.8), y: Math.round(rnd() * h * 0.8),
    w: 20 + Math.round(rnd() * 400), h: 20 + Math.round(rnd() * 300), rot: Math.round(rnd() * 3) * 30, op: 1, lock: false, hide: false,
  };
  if (type === 'text') return { ...base, size: 20 + Math.round(rnd() * 40), color: '@ink', sw: 2 };
  if (type === 'shape') return { ...base, kind: 'rect', fill: '@accent', sw: 3, radius: 8 };
  if (type === 'table') return { ...base, rows: [[{ t: 'a' }]], colw: [1], rowh: [60, 60], size: 30 };
  return { ...base, src: { url: 'https://example.com/a.png' }, fit: 'cover' };
}

export function randomDoc(rnd) {
  const n = 1 + Math.floor(rnd() * 3);
  const slides = [];
  for (let i = 0; i < n; i++) {
    const els = [];
    const m = Math.floor(rnd() * 6);
    for (let j = 0; j < m; j++) {
      const e = randomEl(rnd, `e${i}_${j}`);
      if (rnd() < 0.2) e.group = `g${i}`;
      els.push(e);
    }
    slides.push(S(`s${i}`, els));
  }
  return mkDoc(slides, { sections: [{ id: 'x1', title: 'One' }] });
}

/** A random op against `doc`; sometimes targets things that do not exist. */
export function randomOp(rnd, doc, counter) {
  const pick = (arr) => arr[Math.floor(rnd() * arr.length)];
  const slide = pick(doc.slides);
  const slideId = rnd() < 0.05 ? 'ghost' : slide.id;
  const el = slide.els.length ? pick(slide.els) : null;
  const elId = el && rnd() > 0.05 ? el.id : 'ghost';
  const kinds = ['el.set', 'el.set', 'el.set', 'el.add', 'el.remove', 'el.order', 'el.move', 'slide.add', 'slide.set', 'slide.move',
    'slide.remove', 'slide.dup', 'section.add', 'section.set', 'section.remove', 'deck.set', 'deck.resize'];
  const k = pick(kinds);
  const next = () => ++counter.n;
  switch (k) {
    case 'el.set': {
      const p = {};
      if (rnd() < 0.7) p.x = Math.round(rnd() * 1000);
      if (rnd() < 0.5) p.w = 10 + Math.round(rnd() * 500);
      if (rnd() < 0.3) p.size = 10 + Math.round(rnd() * 90);
      if (rnd() < 0.3) p.color = null;
      if (rnd() < 0.2) p.link = 'https://example.com';
      if (rnd() < 0.2) p.group = rnd() < 0.5 ? null : 'gx';
      return { op: 'el.set', slide: slideId, id: elId, p };
    }
    case 'el.add': return { op: 'el.add', slide: slideId, el: randomEl(rnd, `n${next()}`), ...(rnd() < 0.5 ? { index: Math.floor(rnd() * 5) } : {}) };
    case 'el.remove': return { op: 'el.remove', slide: slideId, id: elId };
    case 'el.order': return { op: 'el.order', slide: slideId, ids: slide.els.map((e) => e.id).sort(() => rnd() - 0.5).slice(0, 1 + Math.floor(rnd() * 5)) };
    case 'el.move': return { op: 'el.move', from: slideId, id: elId, to: pick(doc.slides).id, ...(rnd() < 0.5 ? { index: Math.floor(rnd() * 4) } : {}) };
    case 'slide.add': return { op: 'slide.add', tid: `t${next()}`, ...(rnd() < 0.7 ? { after: rnd() < 0.2 ? null : slide.id } : {}), slide: { title: 'x', els: rnd() < 0.5 ? [randomEl(rnd, `n${next()}`)] : [] } };
    case 'slide.set': return { op: 'slide.set', id: slideId, ...pick([{ title: `T${next()}` }, { hidden: true }, { bg: '@surface', layout: 'two' }, { bg: null }]) };
    case 'slide.move': return { op: 'slide.move', id: slideId, ...(rnd() < 0.8 ? { after: rnd() < 0.2 ? null : pick(doc.slides).id } : {}) };
    case 'slide.remove': return { op: 'slide.remove', id: slideId };
    case 'slide.dup': return { op: 'slide.dup', id: slideId, tid: `t${next()}`, ...(rnd() < 0.5 ? { after: pick(doc.slides).id } : {}) };
    case 'section.add': return { op: 'section.add', id: `x${next()}`, title: 'S', ...(rnd() < 0.5 ? { after: rnd() < 0.3 ? null : 'x1' } : {}) };
    case 'section.set': return { op: 'section.set', id: 'x1', title: `R${next()}` };
    case 'section.remove': return { op: 'section.remove', id: pick(['x1', 'x2', 'x3']) };
    case 'deck.set': return { op: 'deck.set', ...pick([{ title: `D${next()}` }, { status: 'approved' }, { theme: { id: `th${next()}` } }]) };
    default: return { op: 'deck.resize', w: pick([1280, 1920, 1000, 2560]), h: pick([720, 1080, 1000, 500]), mode: pick(['scale', 'anchor', 'fit']) };
  }
}

/** Strip fields the server owns so documents compare by content. */
export function content(doc) {
  const { rev: _r, updated_at: _u, ...deck } = doc.deck;
  return { deck, slides: doc.slides.map(({ rev: _sr, ...s }) => s) };
}
