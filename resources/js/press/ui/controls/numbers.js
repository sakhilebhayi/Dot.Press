/**
 * Number field maths (pure, node tested).
 *   decimals(step)                          2 for 0.01
 *   snap(v, {min, max, step})               clamped and rounded to the step grid (anchored at min when given)
 *   parseNumber(text)                       '12', '12.5px', '12,5', '-3 %' -> number, '' or junk -> null
 *   formatNumber(v, step)                   no trailing zeros, rounded to the step's decimals
 *   nudge(v, dir, {step, shift, min, max})  arrow key: +-step, with shift +-10 steps
 *   scrub(start, dx, {step, min, max, pxPerStep, shift, alt})  drag the label: pxPerStep px per step, shift x10, alt x0.1 (not finer than 1/10 step)
 */
export const decimals = (step) => { const s = String(step); const i = s.indexOf('.'); return i < 0 ? 0 : Math.min(6, s.length - i - 1); };
const round = (v, d) => { const f = 10 ** d; return Math.round(v * f) / f; };

export function snap(v, { min = -Infinity, max = Infinity, step = 1 } = {}) {
  if (!Number.isFinite(v)) return null;
  const d = decimals(step);
  const base = Number.isFinite(min) ? min : 0;
  const stepped = step > 0 ? base + Math.round((v - base) / step) * step : v;
  return Math.min(max, Math.max(min, round(stepped, d + 2 > 8 ? 8 : d)));
}

export function parseNumber(text) {
  if (typeof text === 'number') return Number.isFinite(text) ? text : null;
  const m = /^\s*([+-]?\d*[.,]?\d+)/.exec(String(text ?? '').replace(/−/g, '-'));
  if (!m) return null;
  const n = Number(m[1].replace(',', '.'));
  return Number.isFinite(n) ? n : null;
}

export function formatNumber(v, step = 1) {
  if (!Number.isFinite(v)) return '';
  const d = decimals(step);
  return String(round(v, Math.max(d, 0)));
}

export function nudge(v, dir, { step = 1, shift = false, min = -Infinity, max = Infinity } = {}) {
  const cur = Number.isFinite(v) ? v : 0;
  return snap(cur + dir * step * (shift ? 10 : 1), { min, max, step });
}

export function scrub(start, dx, { step = 1, min = -Infinity, max = Infinity, pxPerStep = 4, shift = false, alt = false } = {}) {
  const mult = shift ? 10 : alt ? 0.1 : 1;
  const raw = start + (dx / pxPerStep) * step * mult;
  const fine = alt ? step / 10 : step;
  return snap(raw, { min, max, step: fine });
}
