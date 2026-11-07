// Axis tick generation.
import { isNum } from './util.js';

const NICE = [1, 2, 2.5, 5, 10];

function cleanFloat(v) {
  // remove binary float noise (0.1 + 0.2) without changing real digits
  const r = Number(v.toPrecision(12));
  return Object.is(r, -0) ? 0 : r;
}

/**
 * Nice axis ticks covering [min, max] with about `count` ticks.
 * Returns { ticks, step, min, max } where min and max are the extended, rounded bounds
 * (ticks[0] === min, ticks[ticks.length - 1] === max). Never throws: bad input gives [0, 1].
 * `opts.exact` keeps the bounds at the given min and max instead of extending them
 * (ticks are then the nice values inside the range).
 */
export function niceTicks(min, max, count = 5, opts = {}) {
  let lo = isNum(min) ? min : 0;
  let hi = isNum(max) ? max : lo + 1;
  if (lo > hi) [lo, hi] = [hi, lo];
  const n = Math.max(2, Math.min(50, Math.floor(isNum(count) ? count : 5)));
  if (hi === lo) {
    if (lo === 0) hi = 1;
    else {
      const pad = Math.abs(lo) * 0.1 || 1;
      lo -= pad;
      hi += pad;
    }
  }
  const range = hi - lo;
  const rough = range / (n - 1);
  const exp0 = Math.floor(Math.log10(rough)) - 1;
  let step = 0;
  search: for (let e = exp0; e < exp0 + 6; e++) {
    for (const m of NICE) {
      const sz = m * Math.pow(10, e);
      if (sz < rough * 0.999) continue;
      const needed = Math.ceil(hi / sz - 1e-12) - Math.floor(lo / sz + 1e-12) + 1;
      if (needed <= n) {
        step = sz;
        break search;
      }
    }
  }
  if (!step) {
    // a range that straddles zero cannot always be covered by n ticks: allow one more
    search2: for (let e = exp0; e < exp0 + 6; e++) {
      for (const m of NICE) {
        const sz = m * Math.pow(10, e);
        if (sz < rough * 0.999) continue;
        if (Math.ceil(hi / sz - 1e-12) - Math.floor(lo / sz + 1e-12) + 1 <= n + 1) {
          step = sz;
          break search2;
        }
      }
    }
  }
  if (!Number.isFinite(step) || step <= 0) step = Math.pow(10, Math.ceil(Math.log10(range)));
  let first;
  let last;
  if (opts.exact) {
    first = Math.ceil(lo / step - 1e-12) * step;
    last = Math.floor(hi / step + 1e-12) * step;
  } else {
    first = Math.floor(lo / step + 1e-12) * step;
    last = Math.ceil(hi / step - 1e-12) * step;
  }
  const ticks = [];
  const cnt = Math.min(200, Math.round((last - first) / step));
  for (let i = 0; i <= cnt; i++) ticks.push(cleanFloat(first + i * step));
  return {
    ticks,
    step: cleanFloat(step),
    min: opts.exact ? lo : ticks[0],
    max: opts.exact ? hi : ticks[ticks.length - 1],
  };
}
