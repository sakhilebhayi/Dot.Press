// SVG building blocks: context, text, marks, shapes, label collision and the legend/title frame.
import { esc, fx, fitText, wrapText, textWidth, clamp, makeFormatter, isNum } from './util.js';
import { resolveTheme, seriesStyle } from './theme.js';
import { clampSize } from './normalize.js';

const DEFAULT_FONT = "'Public Sans', 'Helvetica Neue', Arial, sans-serif";

/** Build the rendering context shared by every chart type. */
export function makeContext(chart, opts = {}) {
  const o = opts && typeof opts === 'object' ? opts : {};
  const [w, h] = clampSize(o.w, o.h);
  const theme = resolveTheme(o.theme);
  const fontBase = typeof o.font === 'string' && o.font.trim() ? o.font.trim().slice(0, 160) : null;
  const font = fontBase || (theme.t.body ? `'${theme.t.body.replace(/['"\\<>&]/g, '')}', Helvetica, Arial, sans-serif` : DEFAULT_FONT);
  const idPrefix = typeof o.idPrefix === 'string' && /^[A-Za-z][\w-]{0,39}$/.test(o.idPrefix) ? o.idPrefix : 'pc';
  const base = isNum(o.fontSize) ? clamp(o.fontSize, 8, 80) : clamp(Math.min(h * 0.045, w * 0.04), 10, 36);
  const fs = base * theme.t.scale;
  const c = theme.c;
  const ctx = {
    chart,
    w,
    h,
    theme,
    font,
    idPrefix,
    reduced: o.reduced === true,
    animate: o.animate === true && o.reduced !== true,
    fs,
    k: Math.max(1, fs / 14), // stroke scale: hairlines stay visible when slides are scaled down
    surface: typeof o.surface === 'string' ? o.surface : c.bg,
    ink: c.ink,
    muted: c.muted,
    line: c.line,
    fmt: makeFormatter(chart.opt),
    defs: [],
    body: [],
    notes: [],
    mark: 0,
    styles: chart.data.series.map((s, i) => seriesStyle(i, theme, s.color || (chart.opt.colors && chart.opt.colors[i]))),
    patterns: new Set(),
    chrome: null,
  };
  ctx.pad = Math.max(8, fs * 0.8);
  return ctx;
}

export const id = (ctx, name) => `${ctx.idPrefix}-${name}`;

// ---------------------------------------------------------------- attribute / element helpers

function attrString(attrs) {
  let s = '';
  for (const key of Object.keys(attrs)) {
    const v = attrs[key];
    if (v === undefined || v === null || v === false || v === '') continue;
    s += ` ${key}="${typeof v === 'number' ? fx(v) : esc(v)}"`;
  }
  return s;
}

export function el(tag, attrs = {}, inner = '') {
  const a = attrString(attrs);
  return inner === '' || inner === null || inner === undefined ? `<${tag}${a}/>` : `<${tag}${a}>${inner}</${tag}>`;
}

export function add(ctx, s) {
  ctx.body.push(s);
}

/** A text element. y is the vertical centre of the text line (browsers differ on baselines). */
export function text(ctx, x, y, str, o = {}) {
  const size = o.size || ctx.fs;
  const s = str == null ? '' : String(str);
  if (s === '') return '';
  const attrs = {
    x,
    y: y + size * 0.35,
    'font-size': size,
    'font-family': ctx.font,
    'font-weight': o.weight && o.weight !== 400 ? o.weight : undefined,
    'text-anchor': o.anchor && o.anchor !== 'start' ? o.anchor : undefined,
    fill: o.fill || ctx.ink,
    'fill-opacity': o.opacity !== undefined && o.opacity < 1 ? o.opacity : undefined,
    stroke: o.halo ? ctx.surface : undefined,
    'stroke-width': o.halo ? size * 0.3 : undefined,
    'stroke-linejoin': o.halo ? 'round' : undefined,
    'paint-order': o.halo ? 'stroke' : undefined,
    class: o.cls,
    transform: o.rotate ? `rotate(${fx(o.rotate)} ${fx(x)} ${fx(y)})` : undefined,
  };
  return el('text', attrs, esc(s));
}

/** Text that is also emitted into the body. */
export function put(ctx, x, y, str, o = {}) {
  const t = text(ctx, x, y, str, o);
  if (t) ctx.body.push(t);
  return t;
}

/** Bounding box for a text line placed with `text()`. */
export function textBox(x, y, str, size, anchor = 'start', weight = 400, rotate = 0) {
  const w = textWidth(str, size, weight);
  const h = size * 1.15;
  let left = anchor === 'middle' ? x - w / 2 : anchor === 'end' ? x - w : x;
  if (!rotate) return { x: left, y: y - h / 2, w, h };
  // axis-aligned box of the rotated text (anchor end, rotated about (x, y))
  const rad = (Math.abs(rotate) * Math.PI) / 180;
  const bw = w * Math.cos(rad) + h * Math.sin(rad);
  const bh = w * Math.sin(rad) + h * Math.cos(rad);
  return { x: anchor === 'end' ? x - bw : x, y: rotate < 0 ? y - bh + h / 2 : y - h / 2, w: bw, h: bh };
}

const overlap = (a, b, pad = 0) => a.x < b.x + b.w + pad && b.x < a.x + a.w + pad && a.y < b.y + b.h + pad && b.y < a.y + a.h + pad;

/** Collision-aware label placement. */
export function makePlacer(bounds, avoid = []) {
  const placed = [];
  const blocked = avoid.slice();
  return {
    blocked,
    placed,
    fits(box) {
      if (box.x < bounds.x - 0.5 || box.y < bounds.y - 0.5 || box.x + box.w > bounds.x + bounds.w + 0.5 || box.y + box.h > bounds.y + bounds.h + 0.5) return false;
      for (const p of placed) if (overlap(box, p, 1)) return false;
      for (const b of blocked) if (overlap(box, b, 0)) return false;
      return true;
    },
    take(box) {
      placed.push(box);
    },
    tryPlace(box) {
      if (!this.fits(box)) return false;
      placed.push(box);
      return true;
    },
  };
}

// ---------------------------------------------------------------- marks

/**
 * Register a data mark: gives it the pc-mark class, the --i stagger index, data attributes and a
 * <title> (native tooltip and screen reader name).
 */
export function mark(ctx, tag, attrs, o = {}) {
  const i = o.i !== undefined ? o.i : ctx.mark;
  ctx.mark += 1;
  const cls = ['pc-mark', o.cls].filter(Boolean).join(' ');
  const a = {
    ...attrs,
    class: cls,
    style: `--i:${Math.max(0, Math.min(9999, Math.round(i)))}`,
    'data-series': o.series,
    'data-index': o.index,
  };
  if (o.value !== undefined && isNum(o.value)) a['data-value'] = o.value;
  const title = o.title ? el('title', {}, esc(o.title)) : '';
  return el(tag, a, title + (o.inner || ''));
}

/** A rectangle with only the data end rounded. edge: top | bottom | left | right | none. */
export function roundedRect(x, y, w, h, r, edge) {
  const rr = Math.max(0, Math.min(r, w / 2, h / 2));
  const X = fx(x);
  const Y = fx(y);
  const W = w;
  const H = h;
  if (rr < 0.5 || edge === 'none') return `M${X} ${Y}h${fx(W)}v${fx(H)}h${fx(-W)}z`;
  const R = fx(rr);
  switch (edge) {
    case 'top':
      return `M${X} ${fx(y + H)}V${fx(y + rr)}a${R} ${R} 0 0 1 ${R} ${fx(-rr)}h${fx(W - 2 * rr)}a${R} ${R} 0 0 1 ${R} ${R}V${fx(y + H)}z`;
    case 'bottom':
      return `M${X} ${Y}h${fx(W)}V${fx(y + H - rr)}a${R} ${R} 0 0 1 ${fx(-rr)} ${R}h${fx(-(W - 2 * rr))}a${R} ${R} 0 0 1 ${fx(-rr)} ${fx(-rr)}z`;
    case 'right':
      return `M${X} ${Y}h${fx(W - rr)}a${R} ${R} 0 0 1 ${R} ${R}v${fx(H - 2 * rr)}a${R} ${R} 0 0 1 ${fx(-rr)} ${R}h${fx(-(W - rr))}z`;
    case 'left':
      return `M${fx(x + W)} ${Y}h${fx(-(W - rr))}a${R} ${R} 0 0 0 ${fx(-rr)} ${R}v${fx(H - 2 * rr)}a${R} ${R} 0 0 0 ${R} ${R}h${fx(W - rr)}z`;
    default:
      return `M${X} ${Y}h${fx(W)}v${fx(H)}h${fx(-W)}z`;
  }
}

/** Marker shape element string. */
export function shapeEl(shape, cx, cy, r, attrs = {}, o = {}) {
  const common = { fill: attrs.fill, stroke: attrs.stroke, 'stroke-width': attrs['stroke-width'], 'fill-opacity': attrs['fill-opacity'] };
  const build = (tag, a) => {
    if (o.mark) return mark(o.ctx, tag, { ...a, ...common }, o.mark);
    return el(tag, { ...a, ...common });
  };
  switch (shape) {
    case 'square':
      return build('rect', { x: cx - r * 0.88, y: cy - r * 0.88, width: r * 1.76, height: r * 1.76, rx: r * 0.15 });
    case 'diamond':
      return build('path', { d: `M${fx(cx)} ${fx(cy - r * 1.25)}L${fx(cx + r * 1.25)} ${fx(cy)}L${fx(cx)} ${fx(cy + r * 1.25)}L${fx(cx - r * 1.25)} ${fx(cy)}z` });
    case 'triangle':
      return build('path', { d: `M${fx(cx)} ${fx(cy - r * 1.2)}L${fx(cx + r * 1.15)} ${fx(cy + r * 0.85)}L${fx(cx - r * 1.15)} ${fx(cy + r * 0.85)}z` });
    case 'triangle-down':
      return build('path', { d: `M${fx(cx)} ${fx(cy + r * 1.2)}L${fx(cx + r * 1.15)} ${fx(cy - r * 0.85)}L${fx(cx - r * 1.15)} ${fx(cy - r * 0.85)}z` });
    case 'plus': {
      const a = r * 0.45;
      const b = r * 1.2;
      return build('path', { d: `M${fx(cx - a)} ${fx(cy - b)}h${fx(2 * a)}v${fx(b - a)}h${fx(b - a)}v${fx(2 * a)}h${fx(-(b - a))}v${fx(b - a)}h${fx(-2 * a)}v${fx(-(b - a))}h${fx(-(b - a))}v${fx(-2 * a)}h${fx(b - a)}z` });
    }
    case 'hex': {
      const pts = [];
      for (let i = 0; i < 6; i++) {
        const a = (Math.PI / 3) * i + Math.PI / 6;
        pts.push(`${fx(cx + r * 1.15 * Math.cos(a))} ${fx(cy + r * 1.15 * Math.sin(a))}`);
      }
      return build('path', { d: `M${pts.join('L')}z` });
    }
    case 'star': {
      const pts = [];
      for (let i = 0; i < 10; i++) {
        const a = (Math.PI / 5) * i - Math.PI / 2;
        const rad = i % 2 === 0 ? r * 1.35 : r * 0.6;
        pts.push(`${fx(cx + rad * Math.cos(a))} ${fx(cy + rad * Math.sin(a))}`);
      }
      return build('path', { d: `M${pts.join('L')}z` });
    }
    default:
      return build('circle', { cx, cy, r });
  }
}

/** A <pattern> for hatched fills (series beyond the palette). Returns the fill reference. */
export function hatchFill(ctx, color) {
  const key = String(color).replace('#', '');
  const pid = id(ctx, 'hatch-' + key);
  if (!ctx.patterns.has(pid)) {
    ctx.patterns.add(pid);
    const sz = Math.max(5, ctx.fs * 0.4);
    ctx.defs.push(
      el(
        'pattern',
        { id: pid, width: sz, height: sz, patternUnits: 'userSpaceOnUse', patternTransform: 'rotate(45)' },
        el('rect', { width: sz, height: sz, fill: color }) + el('rect', { width: sz * 0.34, height: sz, fill: '#ffffff', 'fill-opacity': 0.5 }),
      ),
    );
  }
  return `url(#${pid})`;
}

/** Fill for a series' bars and areas. */
export function seriesFill(ctx, i) {
  const st = ctx.styles[i] || ctx.styles[0];
  return st.pattern ? hatchFill(ctx, st.color) : st.color;
}

// ---------------------------------------------------------------- legend, title and notes

function legendKey(ctx, item, x, y, size) {
  const stroke = Math.max(2, ctx.k * 2);
  const parts = [];
  if (item.kind === 'line') {
    parts.push(el('line', { x1: x, y1: y, x2: x + size * 1.9, y2: y, stroke: item.color, 'stroke-width': stroke, 'stroke-linecap': 'round', 'stroke-dasharray': item.dash || undefined }));
    parts.push(shapeEl(item.marker || 'circle', x + size * 0.95, y, Math.max(3, size * 0.32), { fill: item.color, stroke: ctx.surface, 'stroke-width': Math.max(1, ctx.k) }));
  } else if (item.kind === 'marker') {
    parts.push(shapeEl(item.marker || 'circle', x + size * 0.5, y, Math.max(3.5, size * 0.38), { fill: item.color, 'fill-opacity': item.opacity }));
  } else {
    parts.push(el('rect', { x, y: y - size * 0.42, width: size * 0.84, height: size * 0.84, rx: size * 0.16, fill: item.fill || item.color }));
  }
  return parts.join('');
}

const keyWidth = (item, size) => (item.kind === 'line' ? size * 1.9 : size * 0.84);

/**
 * Plan frame: reserves room for title, subtitle, legend and notes, and returns the inner box
 * the chart body may use. Call `drawChrome(ctx)` once the body is drawn.
 * legend: [{ name, color, kind: swatch|line|marker, marker, dash, fill, opacity }]
 */
export function planFrame(ctx, legend = [], o = {}) {
  const { w, h, fs, pad } = ctx;
  const opt = ctx.chart.opt;
  let top = pad;
  let bottom = h - pad;
  let left = pad;
  let right = w - pad;
  const chrome = { title: null, sub: null, legend: null, notes: [] };

  if (opt.title) {
    const size = fs * 1.3;
    const lines = h >= 300 && textWidth(opt.title, size, 700) > right - left ? wrapText(opt.title, size, right - left, 2, 700) : [fitText(opt.title, size, right - left, 700)];
    chrome.title = { lines, size, x: left, y: top + size * 0.65, lh: size * 1.2 };
    top += lines.length * size * 1.2 + fs * 0.1;
  }
  if (opt.sub) {
    const size = fs * 0.92;
    chrome.sub = { text: fitText(opt.sub, size, right - left), size, x: left, y: top + size * 0.6 };
    top += size * 1.35;
  }
  if (chrome.title || chrome.sub) top += fs * 0.45;

  // notes at the bottom (axis starts at, values not drawn)
  const notes = ctx.notes.slice(0, 3);
  if (notes.length) {
    const size = fs * 0.8;
    const lh = size * 1.3;
    chrome.notes = notes.map((n, i) => ({ text: fitText(n, size, right - left), size, x: left, y: bottom - (notes.length - 1 - i) * lh - size * 0.4 }));
    bottom -= notes.length * lh + fs * 0.3;
  }

  const items = legend.filter((it) => it && it.name !== '');
  let position = items.length && !o.noLegend ? opt.legend : 'none';
  if (position === 'right' && (w < 340 || items.length > 14)) position = 'bottom';
  const size = fs * 0.9;
  if (position === 'bottom' || position === 'top') {
    const avail = right - left;
    const gap = fs * 1.3;
    const maxItemW = Math.max(40, avail * 0.7);
    const rows = [[]];
    let rowW = 0;
    const laid = [];
    for (let i = 0; i < items.length; i++) {
      const it = items[i];
      const label = fitText(it.name, size, maxItemW - keyWidth(it, size) - size * 0.5);
      const iw = keyWidth(it, size) + size * 0.5 + textWidth(label, size);
      if (rowW > 0 && rowW + gap + iw > avail) {
        rows.push([]);
        rowW = 0;
      }
      rows[rows.length - 1].push({ it, label, iw });
      rowW += (rowW > 0 ? gap : 0) + iw;
      laid.push(rows.length);
    }
    let shown = rows;
    let more = 0;
    const maxRows = Math.max(2, Math.floor((h * 0.3) / (size * 1.55)));
    if (rows.length > maxRows) {
      shown = rows.slice(0, maxRows);
      const kept = shown.reduce((a, r) => a + r.length, 0);
      more = items.length - kept;
      // make room for the "+n more" text on the last row
      const last = shown[maxRows - 1];
      while (last.length > 1 && last.reduce((a, x) => a + x.iw + gap, 0) + textWidth('+99 more', size) > avail) {
        last.pop();
        more++;
      }
    }
    const rh = size * 1.55;
    const height = shown.length * rh + fs * 0.2;
    const y0 = position === 'bottom' ? bottom - height : top;
    chrome.legend = { position, rows: shown, more, gap, size, rh, x: left, w: avail, y: y0 };
    if (position === 'bottom') bottom -= height + fs * 0.3;
    else top += height + fs * 0.3;
  } else if (position === 'right') {
    const maxW = Math.min(w * 0.28, 260);
    const rowsAll = items.map((it) => {
      const label = fitText(it.name, size, maxW - keyWidth(it, size) - size * 0.6);
      return { it, label, iw: keyWidth(it, size) + size * 0.6 + textWidth(label, size) };
    });
    const rh = size * 1.6;
    const maxRows = Math.max(1, Math.floor((bottom - top) / rh));
    let shown = rowsAll;
    let more = 0;
    if (rowsAll.length > maxRows) {
      shown = rowsAll.slice(0, Math.max(1, maxRows - 1));
      more = rowsAll.length - shown.length;
    }
    const lw = Math.max(...shown.map((r) => r.iw), more ? textWidth('+99 more', size) : 0);
    const height = (shown.length + (more ? 1 : 0)) * rh;
    const ly = top + Math.max(0, (bottom - top - height) / 2);
    chrome.legend = { position, rows: shown.map((r) => [r]), more, gap: 0, size, rh, x: right - lw, w: lw, y: ly, column: true };
    right -= lw + fs * 1.4;
  }
  ctx.chrome = chrome;
  return { x: left, y: top, w: Math.max(10, right - left), h: Math.max(10, bottom - top) };
}

/** Emit the title, subtitle, legend and notes planned by planFrame. */
export function drawChrome(ctx) {
  const c = ctx.chrome;
  if (!c) return;
  const out = [];
  if (c.title) c.title.lines.forEach((ln, i) => out.push(text(ctx, c.title.x, c.title.y + i * c.title.lh, ln, { size: c.title.size, weight: 700 })));
  if (c.sub) out.push(text(ctx, c.sub.x, c.sub.y, c.sub.text, { size: c.sub.size, fill: ctx.muted }));
  if (c.legend) {
    const L = c.legend;
    const g = [];
    L.rows.forEach((row, ri) => {
      const rowW = row.reduce((a, r, i) => a + r.iw + (i ? L.gap : 0), 0) + (ri === L.rows.length - 1 && L.more ? L.gap + textWidth(`+${L.more} more`, L.size) : 0);
      let x = L.column ? L.x : L.x + Math.max(0, (L.w - rowW) / 2);
      const y = L.y + ri * L.rh + L.rh / 2;
      row.forEach((r) => {
        g.push(legendKey(ctx, r.it, x, y, L.size));
        g.push(text(ctx, x + keyWidth(r.it, L.size) + L.size * 0.5, y, r.label, { size: L.size }));
        x += r.iw + L.gap;
      });
      if (ri === L.rows.length - 1 && L.more && !L.column) g.push(text(ctx, x, y, `+${L.more} more`, { size: L.size, fill: ctx.muted }));
    });
    if (L.column && L.more) g.push(text(ctx, L.x, L.y + L.rows.length * L.rh + L.rh / 2, `+${L.more} more`, { size: L.size, fill: ctx.muted }));
    out.push(el('g', { class: 'pc-legend' }, g.join('')));
  }
  for (const n of c.notes) out.push(text(ctx, n.x, n.y, n.text, { size: n.size, fill: ctx.muted, cls: 'pc-note' }));
  ctx.body.push(el('g', { class: 'pc-chrome' }, out.join('')));
}

/** Empty-state message drawn in the middle of the box. */
export function drawEmpty(ctx, box, message) {
  const size = ctx.fs * 1.05;
  const lines = wrapText(message, size, Math.max(60, box.w - ctx.fs * 2), 2);
  lines.forEach((ln, i) => put(ctx, box.x + box.w / 2, box.y + box.h / 2 + (i - (lines.length - 1) / 2) * size * 1.3, ln, { size, anchor: 'middle', fill: ctx.muted }));
}

/** Wrap the finished body into a standalone <svg>. */
export function finishSvg(ctx, titleText, descText) {
  const tid = id(ctx, 'title');
  const did = id(ctx, 'desc');
  const cls = ['pc-chart', `pc-${ctx.chart.type}`, ctx.animate ? 'pc-anim' : '', ctx.reduced ? 'pc-reduced' : ''].filter(Boolean).join(' ');
  const head =
    el('title', { id: tid }, esc(titleText)) + el('desc', { id: did }, esc(descText)) + (ctx.defs.length ? el('defs', {}, ctx.defs.join('')) : '');
  return (
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${ctx.w} ${ctx.h}" width="${ctx.w}" height="${ctx.h}" role="img" aria-labelledby="${esc(tid)} ${esc(did)}" class="${esc(cls)}" data-chart="${esc(ctx.chart.type)}" style="--pc-bg:${esc(ctx.theme.c.bg)}">` +
    head +
    ctx.body.join('') +
    '</svg>'
  );
}

export function legendForSeries(ctx, kinds) {
  const { series } = ctx.chart.data;
  return series.map((s, i) => {
    const st = ctx.styles[i];
    const kind = kinds ? kinds[i] : 'swatch';
    return { name: s.name, color: st.color, kind, marker: st.marker, dash: st.dash, fill: kind === 'swatch' ? seriesFill(ctx, i) : undefined };
  });
}

export { overlap };
