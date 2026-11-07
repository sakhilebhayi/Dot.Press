/**
 * Morph pairing for the Motion panel (pure, node tested).
 *
 * The player pairs an element of the arriving slide with an element of the leaving slide when both have the same
 * morph key (`morph`, which defaults to the element id) and the same type (player/transitions.js morphPairs). This
 * module lists those pairs, the elements left without a partner, and plans the `el.set` ops that pair or unpair.
 *
 *   analyzeMorph(from, to)                  {pairs, onlyFrom, onlyTo}
 *   pairOps(from, to, aEl, bEl)             ops that make aEl (on `from`) and bEl (on `to`) one pair
 *   unpairOps(from, to, pair)               ops that break a pair
 *   elementLabel(el)                        name, else a text snippet, else the type
 */
import { docText } from '../../core/doc.js';

export const TYPE_LABEL = { text: 'Text', shape: 'Shape', image: 'Picture', video: 'Video', audio: 'Audio', icon: 'Icon', chart: 'Chart', table: 'Table' };
const ID = /^[A-Za-z0-9_-]{1,40}$/;

export const keyOf = (el) => (typeof el?.morph === 'string' && el.morph ? el.morph : el?.id);
const live = (el) => el && !el.hide && typeof el.id === 'string';

export function elementLabel(el) {
  if (!el) return '';
  if (typeof el.name === 'string' && el.name.trim()) return el.name.trim().slice(0, 40);
  let text = '';
  try { text = el.doc ? docText(el.doc).replace(/\s+/g, ' ').trim() : ''; } catch { text = ''; }
  if (text) return text.length > 28 ? `${text.slice(0, 27)}…` : text;
  return TYPE_LABEL[el.type] || el.type || 'Element';
}

export function analyzeMorph(from, to) {
  const aEls = (from?.els || []).filter(live);
  const bEls = (to?.els || []).filter(live);
  const mapA = new Map();
  for (const a of aEls) mapA.set(keyOf(a), a);
  const pairs = [];
  const usedA = new Set();
  const usedB = new Set();
  for (const b of bEls) {
    const key = keyOf(b);
    const a = mapA.get(key);
    if (!a || usedA.has(a.id) || a.type !== b.type) continue;
    usedA.add(a.id);
    usedB.add(b.id);
    pairs.push({ key, aId: a.id, bId: b.id, a, b, type: b.type, via: a.morph || b.morph ? 'key' : 'id' });
  }
  return {
    pairs,
    onlyFrom: aEls.filter((e) => !usedA.has(e.id)),
    onlyTo: bEls.filter((e) => !usedB.has(e.id)),
  };
}

function freshKey(base, taken) {
  const root = String(base).slice(0, 36);
  let k = root;
  let n = 1;
  while (taken.has(k) || !ID.test(k)) { n += 1; k = `${root.slice(0, 36 - String(n).length)}${n}`; }
  return k;
}
const keysOf = (slide) => new Set((slide?.els || []).filter(live).map(keyOf));

/** Make aEl (on `from`) and bEl (on `to`) a pair by giving both the same morph key. */
export function pairOps(from, to, aEl, bEl) {
  if (!from || !to || !aEl || !bEl || aEl.type !== bEl.type) return [];
  if (keyOf(aEl) === keyOf(bEl)) return [];
  const otherB = (to.els || []).filter((e) => live(e) && e.id !== bEl.id).map(keyOf);
  const otherA = (from.els || []).filter((e) => live(e) && e.id !== aEl.id).map(keyOf);
  const ops = [];
  const set = (slide, el, key) => ops.push({ op: 'el.set', slide: slide.id, id: el.id, p: { morph: key } });
  const existing = typeof aEl.morph === 'string' && aEl.morph ? aEl.morph : null;
  if (existing && !otherB.includes(existing)) {
    set(to, bEl, existing);
  } else {
    const key = freshKey(`m-${aEl.id}`, new Set([...keysOf(from), ...keysOf(to)]));
    set(from, aEl, key);
    set(to, bEl, key);
  }
  return ops;
}

/** Break a pair: the arriving element gets a key of its own, so nothing pairs with it by id or by key. */
export function unpairOps(from, to, pair) {
  if (!to || !pair) return [];
  const key = freshKey(`x-${pair.bId}`, new Set([...keysOf(from), ...keysOf(to)]));
  return [{ op: 'el.set', slide: to.id, id: pair.bId, p: { morph: key } }];
}

/** Candidate partners in `onlyFrom` for an element of `to`: same type, nearest first. */
export function suggestPartners(aList, bEl) {
  const cx = (e) => (+e.x || 0) + (+e.w || 0) / 2;
  const cy = (e) => (+e.y || 0) + (+e.h || 0) / 2;
  return aList
    .filter((a) => a.type === bEl.type)
    .sort((p, q) => Math.hypot(cx(p) - cx(bEl), cy(p) - cy(bEl)) - Math.hypot(cx(q) - cx(bEl), cy(q) - cy(bEl)));
}
