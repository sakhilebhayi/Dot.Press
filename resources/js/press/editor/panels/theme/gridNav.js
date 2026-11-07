/**
 * Keyboard movement through a grid of radio cards (pure, node tested).
 *   gridTarget(key, index, count, cols) -> the index to move to, or null when the key is not a movement key
 *   columnsOf(tops, tolerance)          how many cards share the first row, from their top positions
 * Arrow keys move one card (left, right) or one row (up, down); Home and End jump; the result stays inside the grid.
 */
export function gridTarget(key, index, count, cols = 1) {
  if (!count) return null;
  const to = {
    ArrowRight: index + 1, ArrowDown: index + cols, ArrowLeft: index - 1, ArrowUp: index - cols, Home: 0, End: count - 1,
  }[key];
  if (to === undefined) return null;
  return Math.min(count - 1, Math.max(0, to));
}

export function columnsOf(tops, tolerance = 4) {
  if (!tops.length) return 1;
  return Math.max(1, tops.filter((t) => Math.abs(t - tops[0]) < tolerance).length);
}
