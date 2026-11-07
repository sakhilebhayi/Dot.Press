// Shared helpers for the chart engine: safe numbers, escaping, text measurement,
// colour maths and number formatting. Pure JavaScript, no DOM, no dependencies.

// ---------------------------------------------------------------- numbers

export function isNum(v) {
  return typeof v === 'number' && Number.isFinite(v);
}

const MINUS = /[\u2212\u2012\u2013\u2014]/g;

/**
 * Parse a loosely formatted number from text: thousands separators, decimal commas,
 * percent signs, currency symbols and accounting negatives "(1 200)".
 * `decimal` is "." or ",". Returns null when the text is not a number.
 */
export function parseNumber(input, decimal = '.') {
  if (typeof input === 'number') return Number.isFinite(input) ? input : null;
  if (typeof input !== 'string') return null;
  let s = input.replace(MINUS, '-').trim();
  if (s === '' || s.length > 40) return null;
  let neg = false;
  if (/^\(.*\)$/.test(s)) {
    neg = true;
    s = s.slice(1, -1).trim();
  }
  // currency prefix (R only when a digit follows), percent sign and currency-code suffix
  s = s.replace(/^([+-]?)\s*(?:ZAR|USD|EUR|GBP|R|\$|\u20ac|\u00a3|\u00a5)\s*(?=[\d.,+-])/i, '$1');
  s = s.replace(/\s*(?:%|ZAR|USD|EUR|GBP)$/i, '');
  if (s.startsWith('+')) s = s.slice(1);
  // inner spaces are only thousands separators in groups of three ("1 234 567,5")
  if (/[\s\u00a0\u202f]/.test(s) && !/^[+-]?\d{1,3}(?:[\s\u00a0\u202f]\d{3})+(?:[.,]\d+)?$/.test(s)) return null;
  // thousands separators: spaces, nbsp, narrow nbsp, apostrophes
  s = s.replace(/[\s  '’]/g, '');
  if (s === '' || s === '-') return null;
  if (decimal === ',') s = s.replace(/\./g, '').replace(',', '.');
  else s = s.replace(/,/g, '');
  if (!/^-?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?$/i.test(s)) return null;
  const n = Number(s);
  if (!Number.isFinite(n)) return null;
  return neg ? -n : n;
}

/** Coerce anything to a finite number or null. */
export function toNum(v) {
  if (isNum(v)) return v;
  if (typeof v === 'string') return parseNumber(v);
  return null;
}

export function clamp(v, lo, hi) {
  return v < lo ? lo : v > hi ? hi : v;
}

/** Format a coordinate for SVG output: always finite, at most 2 decimals. */
export function fx(v) {
  if (!Number.isFinite(v)) return '0';
  const r = Math.round(v * 100) / 100;
  return Object.is(r, -0) ? '0' : String(r);
}

// ---------------------------------------------------------------- escaping

/** Escape text for use in XML text and attribute values; strips characters XML forbids. */
export function esc(value) {
  let s = value == null ? '' : String(value);
  // control characters and lone surrogates are not legal in XML 1.0
  s = s.replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f￾￿]/g, '');
  s = s.replace(/[\ud800-\udbff](?![\udc00-\udfff])|(?<![\ud800-\udbff])[\udc00-\udfff]/g, '');
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/** Clean user text for display: string, collapsed whitespace, length limited. */
export function cleanText(v, max = 200) {
  if (v == null) return '';
  let s = typeof v === 'string' ? v : typeof v === 'number' || typeof v === 'boolean' ? String(v) : '';
  s = s.replace(/[\u0000-\u001f\u007f]+/g, ' ').replace(/\s+/g, ' ').trim();
  return s.length > max ? s.slice(0, max - 1) + '…' : s;
}

// ---------------------------------------------------------------- text measurement

// Advance widths (per 1000 em) for ASCII 32..126, Helvetica/Arial metrics.
const ASCII_W = [
  278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
  556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
  1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
  667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
  333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
  556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
];

/** Safety factor so estimated widths err on the wide side for fonts such as Public Sans. */
export const TEXT_SAFETY = 1.05;

export function charWidth(code) {
  if (code >= 32 && code <= 126) return ASCII_W[code - 32];
  if (code === 0x2026) return 1000; // ellipsis
  if (code === 0xa0 || code === 0x202f || code === 0x2009) return 278;
  if (code < 32) return 0;
  if (code >= 0x300 && code <= 0x36f) return 0; // combining marks
  if (code >= 0x2e80 || (code >= 0x1f000 && code <= 0x1faff)) return 1000; // CJK, emoji
  if (code >= 0xff00 && code <= 0xffef) return 1000;
  if (code >= 0xc0 && code <= 0x24f) return 560; // accented latin
  if (code >= 0x2190 && code <= 0x2bff) return 800; // arrows, symbols
  return 600;
}

/** Estimated rendered width of `str` at font size `size`. */
export function textWidth(str, size, weight = 400) {
  const s = str == null ? '' : String(str);
  let w = 0;
  for (let i = 0; i < s.length; i++) {
    const c = s.charCodeAt(i);
    if (c >= 0xd800 && c <= 0xdbff) {
      w += 1000;
      i++;
      continue;
    }
    w += charWidth(c);
  }
  const bold = Number(weight) >= 600 ? 1.07 : 1;
  return (w / 1000) * size * bold * TEXT_SAFETY;
}

/** Truncate with an ellipsis so the text fits `maxW`. */
export function fitText(str, size, maxW, weight = 400) {
  const s = str == null ? '' : String(str);
  if (textWidth(s, size, weight) <= maxW) return s;
  if (maxW < textWidth('…', size, weight)) return '';
  let lo = 0;
  let hi = s.length;
  while (lo < hi) {
    const mid = (lo + hi + 1) >> 1;
    if (textWidth(s.slice(0, mid).trimEnd() + '…', size, weight) <= maxW) lo = mid;
    else hi = mid - 1;
  }
  return s.slice(0, lo).trimEnd() + '…';
}

/**
 * Wrap text to at most `maxLines` lines of width `maxW`. Long single words are broken.
 * The last line is truncated with an ellipsis if the text does not fit.
 */
export function wrapText(str, size, maxW, maxLines = 2, weight = 400) {
  const text = cleanText(str, 400);
  if (text === '') return [];
  const words = text.split(' ');
  const lines = [];
  let cur = '';
  let i = 0;
  while (i < words.length && lines.length < maxLines) {
    let w = words[i];
    if (cur === '') {
      if (textWidth(w, size, weight) <= maxW) {
        cur = w;
        i++;
      } else {
        // break an over-long word
        let k = w.length;
        while (k > 1 && textWidth(w.slice(0, k), size, weight) > maxW) k--;
        lines.push(w.slice(0, k));
        words[i] = w.slice(k);
        if (words[i] === '') i++;
      }
    } else if (textWidth(cur + ' ' + w, size, weight) <= maxW) {
      cur += ' ' + w;
      i++;
    } else {
      lines.push(cur);
      cur = '';
    }
  }
  if (cur !== '' && lines.length < maxLines) lines.push(cur);
  if (i < words.length && lines.length) {
    // leftover text: fold it into the last line and truncate
    const rest = words.slice(i).join(' ');
    lines[lines.length - 1] = fitText(lines[lines.length - 1] + ' ' + rest, size, maxW, weight);
  }
  return lines.slice(0, maxLines);
}

// ---------------------------------------------------------------- colour

export function parseColor(c) {
  if (typeof c !== 'string') return null;
  const m = /^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i.exec(c.trim());
  if (!m) return null;
  let h = m[1];
  if (h.length <= 4) h = h.split('').map((x) => x + x).join('');
  const r = parseInt(h.slice(0, 2), 16);
  const g = parseInt(h.slice(2, 4), 16);
  const b = parseInt(h.slice(4, 6), 16);
  const a = h.length === 8 ? parseInt(h.slice(6, 8), 16) / 255 : 1;
  return { r, g, b, a };
}

const hex2 = (n) => Math.round(clamp(n, 0, 255)).toString(16).padStart(2, '0');

export function toHex({ r, g, b }) {
  return '#' + hex2(r) + hex2(g) + hex2(b);
}

export function relLum(c) {
  const f = (v) => {
    const s = v / 255;
    return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
  };
  return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b);
}

export function contrast(a, b) {
  const ca = typeof a === 'string' ? parseColor(a) : a;
  const cb = typeof b === 'string' ? parseColor(b) : b;
  if (!ca || !cb) return 1;
  const la = relLum(ca);
  const lb = relLum(cb);
  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

/** Mix colour `a` toward `b` by t (0..1). Invalid colours return `a`. */
export function mix(a, b, t) {
  const ca = parseColor(a);
  const cb = parseColor(b);
  if (!ca || !cb) return typeof a === 'string' ? a : '#888888';
  const u = clamp(t, 0, 1);
  return toHex({ r: ca.r + (cb.r - ca.r) * u, g: ca.g + (cb.g - ca.g) * u, b: ca.b + (cb.b - ca.b) * u });
}

const INK_DARK = '#0b0b0b';
const INK_LIGHT = '#ffffff';

/** The text colour (white or near black) with the better contrast on `fill`. */
export function inkOn(fill) {
  const c = parseColor(fill);
  if (!c) return INK_DARK;
  return contrast(c, INK_LIGHT) >= contrast(c, INK_DARK) ? INK_LIGHT : INK_DARK;
}

export function isDark(color) {
  const c = parseColor(color);
  return c ? relLum(c) < 0.25 : false;
}

// ---------------------------------------------------------------- number formats

const sepGroup = (intStr) => intStr.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

/** Fixed decimals with thousands separators, no locale dependence. */
export function groupNum(v, dec) {
  if (!isNum(v)) return '';
  const neg = v < 0;
  let s = Math.abs(v).toFixed(clamp(dec, 0, 6));
  const [i, d] = s.split('.');
  s = sepGroup(i) + (d ? '.' + d : '');
  if (neg && Number(s.replace(/,/g, '')) !== 0) s = '-' + s;
  return s;
}

function trimZeros(s) {
  return s.includes('.') ? s.replace(/0+$/, '').replace(/\.$/, '') : s;
}

/** Default decimals for a value when none are configured: integers plain, otherwise up to 2. */
export function autoDecimals(v) {
  if (!isNum(v)) return 0;
  const a = Math.abs(v);
  if (Number.isInteger(v)) return 0;
  if (a >= 1000) return 0;
  if (a >= 100) return 1;
  if (a >= 1) return 2;
  if (a === 0) return 0;
  return 3;
}

export function compactNum(v, dec) {
  if (!isNum(v)) return '';
  const a = Math.abs(v);
  const units = [
    [1e12, 'T'],
    [1e9, 'B'],
    [1e6, 'M'],
    [1e3, 'K'],
  ];
  const sign = v < 0 ? '-' : '';
  for (const [div, u] of units) {
    if (a >= div * 0.9995) {
      const q = a / div;
      const d = dec != null ? dec : q >= 100 ? 0 : q >= 10 ? 1 : 2;
      return sign + trimZeros(q.toFixed(d)) + u;
    }
  }
  const d = dec != null ? dec : autoDecimals(a);
  return sign + trimZeros(a.toFixed(clamp(d, 0, 4)));
}

/**
 * Build a value formatter from chart options.
 * fmt: num | pct | cur | compact. Percent values are already percentage points (12.5 -> "12.5%").
 * `compactAxis` makes axis ticks compact from 1,000 up.
 */
export function makeFormatter(opt = {}) {
  const fmt = ['num', 'pct', 'cur', 'compact'].includes(opt.fmt) ? opt.fmt : 'num';
  const cur = typeof opt.cur === 'string' ? opt.cur.slice(0, 4) : 'R';
  const dec = Number.isInteger(opt.dec) && opt.dec >= 0 && opt.dec <= 3 ? opt.dec : null;
  const f = (v, o = {}) => {
    if (!isNum(v)) return '';
    const useCompact = fmt === 'compact' || (o.axis && Math.abs(v) >= 10000);
    let body;
    if (useCompact) body = compactNum(Math.abs(v), dec);
    else if (dec != null) body = groupNum(Math.abs(v), dec);
    else if (fmt === 'cur') body = groupNum(Math.abs(v), Number.isInteger(v) ? 0 : 2);
    else body = trimZeros(groupNum(Math.abs(v), autoDecimals(v)));
    if (fmt === 'pct') body += '%';
    const neg = v < 0 && Number(body.replace(/[^0-9.]/g, '')) !== 0;
    if (fmt === 'cur') body = cur + body;
    const out = (neg ? '-' : '') + body;
    return out;
  };
  f.fmt = fmt;
  f.cur = cur;
  return f;
}

/** Percent share formatter: 42.46 -> "42%" (or one decimal under 10%). */
export function pctShare(share) {
  if (!isNum(share)) return '';
  const p = share * 100;
  if (p > 0 && p < 1) return '<1%';
  const d = p < 10 && Math.abs(p - Math.round(p)) > 0.05 ? 1 : 0;
  return trimZeros(p.toFixed(d)) + '%';
}

/** Decimals needed to print multiples of `step` exactly (0.25 -> 2, 2.5 -> 1, 5 -> 0), at most 3. */
export function stepDecimals(step) {
  if (!isNum(step) || step <= 0) return 0;
  for (let d = 0; d <= 3; d++) {
    const m = step * Math.pow(10, d);
    if (Math.abs(m - Math.round(m)) < 1e-6) return d;
  }
  return 3;
}

/** Axis tick formatter: decimals follow the tick step so every label on an axis is consistent. */
export function tickFormatter(opt, step) {
  const base = makeFormatter(opt);
  if (base.fmt === 'compact') return (v) => base(v);
  const dec = stepDecimals(step);
  const f2 = makeFormatter({ ...opt, dec });
  return (v) => f2(v, { axis: true });
}
