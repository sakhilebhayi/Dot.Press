/**
 * The slide's safe margin. One consistent value per deck, taken from the theme when the theme
 * names one (`theme.s.margin`), otherwise 5 percent of the slide width.
 *
 *   safeMargin(deck)   number, rounded to whole units
 *   marginRect(deck)   {x, y, w, h} the content area inside the margin
 *   marginLines(deck)  {x: [left, right], y: [top, bottom]} the guide positions
 *   gutter(deck)       half the margin, the default gap between neighbouring elements
 */
import { DEFAULT_THEME } from './theme.js';

export function safeMargin(deck) {
  const m = (deck.theme || DEFAULT_THEME).s?.margin;
  if (typeof m === 'number' && m >= 0 && m < Math.min(deck.w, deck.h) / 4) return Math.round(m);
  return Math.round(deck.w * 0.05);
}

export function marginRect(deck) {
  const m = safeMargin(deck);
  return { x: m, y: m, w: deck.w - 2 * m, h: deck.h - 2 * m };
}

export function marginLines(deck) {
  const m = safeMargin(deck);
  return { x: [m, deck.w - m], y: [m, deck.h - m] };
}

export const gutter = (deck) => Math.round(safeMargin(deck) / 2);
