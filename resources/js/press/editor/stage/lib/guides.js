/**
 * Rulers, grid and guides. Pure.
 *
 *   rulerTicks(min, max, zoom)        ticks between two slide positions: [{pos, label?, major}] with a step
 *                                     that keeps ticks about 8 to 20 screen pixels apart
 *   gridLines(deck, size)             {x: [..], y: [..]} grid lines in slide units
 *   centreGuides(deck)                the two slide centre lines
 *   marginGuides(deck)                lines for the safe margin (from core/margins)
 *   addGuide(guides, orient, pos)     new list (rounded, deduplicated, inside the slide)
 *   moveGuide / removeGuide
 */
import { marginLines } from '../../core/margins.js';
import { round2 } from '../../core/util.js';

const STEPS = [1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000];

export function tickStep(zoom) {
  for (const s of STEPS) if (s * zoom >= 8) return s;
  return STEPS[STEPS.length - 1];
}

export function rulerTicks(min, max, zoom) {
  const step = tickStep(zoom);
  const major = STEPS.find((s) => s * zoom >= 64 && s % step === 0) ?? step * 10;
  const out = [];
  const first = Math.ceil(min / step) * step;
  for (let p = first; p <= max; p += step) {
    const isMajor = Math.abs(p % major) < 1e-6;
    out.push({ pos: p, major: isMajor, label: isMajor ? String(Math.round(p)) : null });
  }
  return out;
}

export function gridLines(deck, size) {
  const g = Math.max(4, size || 0);
  const x = [];
  const y = [];
  for (let p = g; p < deck.w; p += g) x.push(p);
  for (let p = g; p < deck.h; p += g) y.push(p);
  return { x, y };
}

export const centreGuides = (deck) => ({ x: deck.w / 2, y: deck.h / 2 });
export const marginGuides = (deck) => marginLines(deck);

export function addGuide(guides, orient, pos, deck) {
  const max = orient === 'v' ? deck.w : deck.h;
  const p = round2(pos);
  if (p <= 0 || p >= max) return guides;
  if (guides.some((g) => g.orient === orient && Math.abs(g.pos - p) < 0.5)) return guides;
  return [...guides, { orient, pos: p }];
}

export function moveGuide(guides, index, pos, deck) {
  const g = guides[index];
  if (!g) return guides;
  const max = g.orient === 'v' ? deck.w : deck.h;
  const p = round2(pos);
  if (p <= 0 || p >= max) return guides.filter((_, i) => i !== index);
  return guides.map((x, i) => (i === index ? { ...x, pos: p } : x));
}

export const removeGuide = (guides, index) => guides.filter((_, i) => i !== index);
