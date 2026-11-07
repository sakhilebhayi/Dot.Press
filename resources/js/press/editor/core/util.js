/**
 * Small pure helpers shared by every core module. No DOM, no dependencies.
 *
 *   ID_RE            the element id pattern from the spec (1 to 40 of A-Za-z0-9_-)
 *   isObj(v)         plain object test (arrays and null are not objects)
 *   clone(v)         deep clone of JSON-shaped data
 *   deepEqual(a, b)  structural equality of JSON-shaped data
 *   round2(n)        round half away from zero to 2 decimals (the geometry rounding rule)
 *   clamp(n, lo, hi)
 *   uniqueId(taken, prefix, rng)  fresh id that is not in the Set/array `taken`
 */

export const ID_RE = /^[A-Za-z0-9_-]{1,40}$/;

export function isObj(v) {
  return v !== null && typeof v === 'object' && !Array.isArray(v);
}

export function clone(v) {
  if (Array.isArray(v)) return v.map(clone);
  if (isObj(v)) {
    const out = {};
    for (const k of Object.keys(v)) out[k] = clone(v[k]);
    return out;
  }
  return v;
}

export function deepEqual(a, b) {
  if (a === b) return true;
  if (typeof a !== typeof b || a === null || b === null) return false;
  if (Array.isArray(a)) {
    if (!Array.isArray(b) || a.length !== b.length) return false;
    for (let i = 0; i < a.length; i++) if (!deepEqual(a[i], b[i])) return false;
    return true;
  }
  if (typeof a === 'object') {
    if (Array.isArray(b)) return false;
    const ka = Object.keys(a);
    if (ka.length !== Object.keys(b).length) return false;
    for (const k of ka) {
      if (!Object.prototype.hasOwnProperty.call(b, k) || !deepEqual(a[k], b[k])) return false;
    }
    return true;
  }
  return false;
}

/** Round half away from zero to 2 decimals. PHP round($n, 2) gives the same result. */
export function round2(n) {
  if (!Number.isFinite(n)) return n;
  const s = n < 0 ? -1 : 1;
  return s * Math.round(Math.abs(n) * 100 + 1e-9) / 100 + 0;
}

export function clamp(n, lo, hi) {
  return Math.min(Math.max(n, lo), hi);
}

const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyz';

/** A random id of `len` base36 characters. `rng` returns [0,1). */
export function randomToken(len = 8, rng = Math.random) {
  let s = '';
  for (let i = 0; i < len; i++) s += ALPHABET[Math.floor(rng() * ALPHABET.length) % ALPHABET.length];
  return s;
}

/** Collision safe id: keeps drawing until the id is not in `taken` (a Set or an array). */
export function uniqueId(taken, prefix = 'e', rng = Math.random) {
  const has = taken instanceof Set ? (x) => taken.has(x) : (x) => (taken || []).includes(x);
  for (let i = 0; i < 1000; i++) {
    const id = prefix + randomToken(8 + Math.floor(i / 50), rng);
    if (!has(id)) return id;
  }
  // The rng is degenerate (tests): fall back to a counter that is guaranteed to differ.
  let n = 0;
  while (has(`${prefix}x${n}`)) n++;
  return `${prefix}x${n}`;
}
