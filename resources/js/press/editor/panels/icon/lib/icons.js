/**
 * Icon search and grid keyboard maths. Pure.
 *
 *   searchIcons(icons, query)       icons matching id, label or tags: exact id first, then word starts, then anywhere
 *   gridStep(index, key, cols, count)   the index a roving grid moves to for ArrowLeft/Right/Up/Down, Home, End, PageUp, PageDown;
 *                                   null for any other key. Arrows stop at the edges of the list; up and down keep the column.
 *   sizePatch(el, size, deck)       patch {x, y, w, h} that makes the icon square at `size`, keeping its centre inside the slide
 *   SW_RANGE                        the stroke width control: min, max, step in icon grid units
 */
export const SW_RANGE = { min: 0.5, max: 4, step: 0.25 };

const norm = (s) => String(s ?? '').toLowerCase().trim();

export function searchIcons(icons, query) {
  const q = norm(query);
  if (!q) return icons.slice();
  const words = q.split(/\s+/).filter(Boolean);
  const scored = [];
  for (const ic of icons) {
    const hay = [ic.id, ic.label, ...(ic.tags || [])].map(norm);
    let score = 0;
    let all = true;
    for (const w of words) {
      let best = 0;
      for (const h of hay) {
        if (h === w) best = Math.max(best, 4);
        else if (h.split(/[\s-]+/).some((p) => p.startsWith(w))) best = Math.max(best, 3);
        else if (h.includes(w)) best = Math.max(best, 1);
      }
      if (!best) { all = false; break; }
      score += best;
    }
    if (all) scored.push({ ic, score, i: scored.length });
  }
  return scored.sort((a, b) => b.score - a.score || a.i - b.i).map((s) => s.ic);
}

export function gridStep(index, key, cols, count) {
  if (!(count > 0)) return null;
  const c = Math.max(1, cols | 0);
  const i = Math.min(Math.max(0, index | 0), count - 1);
  switch (key) {
    case 'ArrowLeft': return Math.max(0, i - 1);
    case 'ArrowRight': return Math.min(count - 1, i + 1);
    case 'ArrowUp': return i - c >= 0 ? i - c : i;
    case 'ArrowDown': return i + c <= count - 1 ? i + c : i;
    case 'Home': return 0;
    case 'End': return count - 1;
    case 'PageUp': return Math.max(0, i - c * 3);
    case 'PageDown': return Math.min(count - 1, i + c * 3);
    default: return null;
  }
}

export function sizePatch(el, size, deck = { w: 1920, h: 1080 }) {
  const s = Math.round(Math.min(Math.max(+size || 0, 8), Math.min(deck.w, deck.h)) * 100) / 100;
  if (!(s > 0)) return null;
  const cx = (+el.x || 0) + (+el.w || s) / 2;
  const cy = (+el.y || 0) + (+el.h || s) / 2;
  const x = Math.min(Math.max(cx - s / 2, 0), Math.max(0, deck.w - s));
  const y = Math.min(Math.max(cy - s / 2, 0), Math.max(0, deck.h - s));
  return { x: Math.round(x * 100) / 100, y: Math.round(y * 100) / 100, w: s, h: s };
}
