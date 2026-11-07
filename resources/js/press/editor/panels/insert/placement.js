/**
 * Where a new element goes and how big it may be. Pure, no DOM.
 *
 *   fitToSlide(deck, el, maxF?)            el scaled down (never up) to fit maxF of the slide, keeping its ratio; lines keep h 0
 *   placeNew(deck, el, {point, existing})  el positioned at the slide centre, or centred on `point` (slide units), kept inside the
 *                                          slide. With no point, a spot already taken by another element is stepped down and right
 *                                          so repeated inserts fan out instead of stacking
 *   insertionPointOf(editor)               the stage's insertion point when it offers one, else null (slide centre)
 */
const r2 = (n) => Math.round(n * 100) / 100;

export function fitToSlide(deck, el, maxF = 0.94) {
  const maxW = deck.w * maxF;
  const maxH = deck.h * maxF;
  const k = Math.min(1, el.w > 0 ? maxW / el.w : 1, el.h > 0 ? maxH / el.h : 1);
  if (k >= 1) return el;
  return { ...el, w: r2(el.w * k), h: r2(el.h * k) };
}

function clampBox(deck, x, y, w, h) {
  return {
    x: r2(Math.min(Math.max(x, 0), Math.max(0, deck.w - w))),
    y: r2(Math.min(Math.max(y, 0), Math.max(0, deck.h - h))),
  };
}

const taken = (existing, x, y) => existing.some((e) => Math.abs(e.x - x) < 1 && Math.abs(e.y - y) < 1);

export function placeNew(deck, el, { point = null, existing = [] } = {}) {
  const sized = fitToSlide(deck, el);
  const { w, h } = sized;
  if (point && Number.isFinite(point.x) && Number.isFinite(point.y)) {
    return { ...sized, ...clampBox(deck, point.x - w / 2, point.y - h / 2, w, h) };
  }
  let { x, y } = clampBox(deck, (deck.w - w) / 2, (deck.h - h) / 2, w, h);
  const step = r2(Math.max(16, deck.w * 0.0125));
  for (let i = 0; i < 24 && taken(existing, x, y); i += 1) {
    const next = clampBox(deck, x + step, y + step, w, h);
    if (next.x === x && next.y === y) break;
    ({ x, y } = next);
  }
  return { ...sized, x, y };
}

/** The stage may offer `insertionPoint()` returning {x, y} in slide units (for example the last pointer position). */
export function insertionPointOf(editor) {
  try {
    const p = editor?.stage?.insertionPoint?.();
    return p && Number.isFinite(p.x) && Number.isFinite(p.y) ? { x: p.x, y: p.y } : null;
  } catch {
    return null;
  }
}
