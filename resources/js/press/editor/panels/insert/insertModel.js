/**
 * What the Insert panel can add and the elements it builds. Pure: every builder takes the deck and returns a new element made by the
 * core factories (sizes follow the deck, colours are theme tokens). Placement is placement.js.
 *
 *   INSERT_TABS, tabForKind(kind)           the panel's tabs; the dock opens it with {kind}
 *   TEXT_PRESETS, buildText(deck, id, taken)   Title, Heading, Body, Caption, Quote, Bullets, Numbered
 *   SHAPES, SHAPE_LABELS, buildShape(deck, kind, taken)   all spec kinds plus line and arrow
 *   buildIcon(deck, name, taken)
 *   chartSize(deck, type), buildChart(deck, type, taken, sample)
 *   GRID_MAX, clampGrid(rows, cols), gridWords(rows, cols), buildTable(deck, rows, cols, taken)
 *   parseVideoUrl(text)                     {ok, provider?, id?, start?, url?, error?}
 *   buildVideoFromUrl(deck, parsed, taken), buildMediaElement(deck, kind, asset, taken)
 *   buildFromDrag(deck, drag, taken)        element for a ui.dragging {kind: 'insert', what, ...} payload (null for media drags)
 */
import {
  newText, newShape, newImage, newChart, newTable, newIcon, newVideo, newAudio, textDoc, newId,
} from '../../core/doc.js';
import { sampleChart, CHART_TYPES } from '../../../charts/index.js';
import { placeMedia } from '../../stage/lib/drop.js';

const r2 = (n) => Math.round(n * 100) / 100;

export const INSERT_TABS = [
  { id: 'text', label: 'Text', icon: 'text' },
  { id: 'shapes', label: 'Shapes', icon: 'shape' },
  { id: 'icons', label: 'Icons', icon: 'icon' },
  { id: 'charts', label: 'Charts', icon: 'chart' },
  { id: 'tables', label: 'Tables', icon: 'table' },
  { id: 'media', label: 'Media', icon: 'image' },
];

const KIND_TAB = { text: 'text', shape: 'shapes', line: 'shapes', arrow: 'shapes', icon: 'icons', chart: 'charts', table: 'tables', image: 'media', video: 'media', audio: 'media', media: 'media' };

/** The tab the dock's kind opens, or null when the kind is unknown or absent. Tool names such as 'shape:star' map to Shapes. */
export function tabForKind(kind) {
  if (!kind) return null;
  const base = String(kind).split(':')[0];
  return KIND_TAB[base] || null;
}

// ---- text -------------------------------------------------------------------------------------

const para = (text, marks) => (text ? { type: 'paragraph', content: [{ type: 'text', text, ...(marks ? { marks } : {}) }] } : { type: 'paragraph' });
const listDoc = (type, items) => ({ type: 'doc', content: [{ type, content: items.map((t) => ({ type: 'listItem', content: [para(t)] })) }] });

/**
 * sizeF is the font size as a fraction of the slide height (as in the layout library), wF the box width as a fraction of the slide width.
 * `lines` is how many lines the sample takes, used to size the box.
 */
export const TEXT_PRESETS = [
  { id: 'title', label: 'Title', hint: 'Large heading', font: '@heading', sizeF: 0.075, wF: 0.8, lh: 1.1, heading: true, lines: 1, make: () => textDoc('Title') },
  { id: 'heading', label: 'Heading', hint: 'Section heading', font: '@heading', sizeF: 0.05, wF: 0.6, lh: 1.15, heading: true, lines: 1, make: () => textDoc('Heading') },
  { id: 'body', label: 'Body', hint: 'Paragraph text', font: '@body', sizeF: 0.04, wF: 0.6, lh: 1.35, lines: 2, make: () => textDoc('Body text sits here.\nKeep it short.') },
  { id: 'caption', label: 'Caption', hint: 'Small, muted note', font: '@body', sizeF: 0.028, wF: 0.4, lh: 1.3, color: '@muted', lines: 1, make: () => textDoc('Caption') },
  { id: 'quote', label: 'Quote', hint: 'Italic pull quote', font: '@heading', sizeF: 0.05, wF: 0.7, lh: 1.25, heading: true, weight: 400, lines: 2, make: () => ({ type: 'doc', content: [para('“A quote worth keeping.”', [{ type: 'italic' }])] }) },
  { id: 'bullets', label: 'Bullets', hint: 'Bulleted list', font: '@body', sizeF: 0.04, wF: 0.6, lh: 1.35, lines: 3, ps: 0.3, make: () => listDoc('bulletList', ['First point', 'Second point', 'Third point']) },
  { id: 'numbered', label: 'Numbered', hint: 'Numbered list', font: '@body', sizeF: 0.04, wF: 0.6, lh: 1.35, lines: 3, ps: 0.3, make: () => listDoc('orderedList', ['First step', 'Second step', 'Third step']) },
];

export const textPreset = (id) => TEXT_PRESETS.find((p) => p.id === id) || null;

export function buildText(deck, presetId, taken = []) {
  const p = textPreset(presetId);
  if (!p) return null;
  const size = r2(deck.h * p.sizeF);
  const w = r2(deck.w * p.wF);
  const h = r2(Math.max(size * p.lh * p.lines + size * (p.ps || 0) * (p.lines - 1), size * 1.4));
  const hw = deck.theme?.t?.hw ?? 700;
  const el = newText(deck, { taken });
  return {
    ...el,
    doc: p.make(),
    font: p.font,
    size,
    weight: p.weight ?? (p.heading ? hw : 400),
    color: p.color || '@ink',
    lh: p.lh,
    ps: p.ps ?? 0.4,
    w,
    h,
  };
}

// ---- shapes -----------------------------------------------------------------------------------

export const SHAPE_LABELS = {
  rect: 'Rectangle', round: 'Rounded rectangle', ellipse: 'Ellipse', triangle: 'Triangle', diamond: 'Diamond', pentagon: 'Pentagon',
  hexagon: 'Hexagon', star: 'Star', chevron: 'Chevron', 'arrow-r': 'Arrow right', 'arrow-l': 'Arrow left', callout: 'Callout', cross: 'Cross',
  heart: 'Heart', line: 'Line', arrow: 'Arrow',
};
export const SHAPES = Object.keys(SHAPE_LABELS);
const WIDE = { rect: 0.65, round: 0.65, callout: 0.7, chevron: 0.6, 'arrow-r': 0.6, 'arrow-l': 0.6 };

export function buildShape(deck, kind, taken = []) {
  if (!SHAPE_LABELS[kind]) return null;
  const base = newShape(deck, { kind, taken });
  const ratio = WIDE[kind];
  if (!ratio) return base;
  const w = r2(base.w * 1.3);
  const h = r2(w * ratio);
  return { ...base, w, h };
}

// ---- icons ------------------------------------------------------------------------------------

export function buildIcon(deck, name, taken = []) {
  return newIcon(deck, { name, taken });
}

// ---- charts -----------------------------------------------------------------------------------

// Width and height as fractions of the slide.
const CHART_F = {
  pie: [0.5, 0.65], doughnut: [0.5, 0.65], radar: [0.5, 0.65], funnel: [0.55, 0.6], kpi: [0.7, 0.3], map: [0.72, 0.62], scatter: [0.6, 0.6], bubble: [0.6, 0.6],
};
export const chartSize = (deck, type) => {
  const [fw, fh] = CHART_F[type] || [0.6, 0.55];
  return { w: r2(deck.w * fw), h: r2(deck.h * fh) };
};

export function buildChart(deck, type, taken = [], sample = null) {
  const known = CHART_TYPES.some((t) => t.id === type);
  if (!known) return null;
  const chart = sample || sampleChart(type);
  const base = newChart(deck, { chartType: type, taken });
  const { w, h } = chartSize(deck, type);
  return {
    ...base,
    w,
    h,
    chart: {
      ...base.chart,
      ...chart,
      type,
      opt: { ...base.chart.opt, ...(chart.opt || {}) },
      src: chart.src || { kind: 'manual' },
    },
  };
}

// ---- tables -----------------------------------------------------------------------------------

export const GRID_MAX = Object.freeze({ rows: 10, cols: 8 });

export function clampGrid(rows, cols) {
  return {
    rows: Math.min(GRID_MAX.rows, Math.max(1, Math.floor(+rows) || 1)),
    cols: Math.min(GRID_MAX.cols, Math.max(1, Math.floor(+cols) || 1)),
  };
}

export function gridWords(rows, cols) {
  const g = clampGrid(rows, cols);
  return `${g.rows} ${g.rows === 1 ? 'row' : 'rows'}, ${g.cols} ${g.cols === 1 ? 'column' : 'columns'}`;
}

export function buildTable(deck, rows, cols, taken = []) {
  const g = clampGrid(rows, cols);
  const base = newTable(deck, { rows: g.rows, cols: g.cols, taken });
  const u = deck.w / 1920;
  // Wide tables get smaller type, tall tables shorter rows, so a 10 by 8 grid still fits the slide.
  const size = r2(30 * u * Math.min(1, Math.max(0.6, 6 / g.cols)));
  const rowh = r2(Math.min(70 * u, (deck.h * 0.8) / g.rows));
  return {
    ...base,
    size,
    rowh: Array.from({ length: g.rows }, () => rowh),
    h: r2(rowh * g.rows),
  };
}

// ---- video and media --------------------------------------------------------------------------

const YT_ID = /^[A-Za-z0-9_-]{11}$/;
const VIMEO_ID = /^\d{4,12}$/;
const FILE_EXT = /\.(mp4|webm|mov|m4v|ogv)$/i;

function startSeconds(v) {
  if (!v) return 0;
  const m = /^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s?)?$/.exec(String(v));
  if (!m) return 0;
  return (+m[1] || 0) * 3600 + (+m[2] || 0) * 60 + (+m[3] || 0);
}

/** A YouTube, Vimeo or direct video link. Only https is accepted, as the document requires. */
export function parseVideoUrl(text) {
  const raw = String(text ?? '').trim();
  if (!raw) return { ok: false, error: 'Paste a YouTube, Vimeo or video file link.' };
  let u;
  try {
    u = new URL(/^[a-z][a-z0-9+.-]*:/i.test(raw) ? raw : `https://${raw}`);
  } catch {
    return { ok: false, error: 'That does not look like a link.' };
  }
  if (u.protocol !== 'https:') return { ok: false, error: 'Use a link that starts with https.' };
  const host = u.hostname.replace(/^www\./, '').replace(/^m\./, '').toLowerCase();
  const start = startSeconds(u.searchParams.get('t') || u.searchParams.get('start') || u.hash.replace(/^#t=/, ''));
  if (host === 'youtu.be' || host === 'youtube.com' || host === 'youtube-nocookie.com' || host === 'music.youtube.com') {
    const parts = u.pathname.split('/').filter(Boolean);
    let id = '';
    if (host === 'youtu.be') id = parts[0] || '';
    else if (['embed', 'shorts', 'live', 'v'].includes(parts[0])) id = parts[1] || '';
    else id = u.searchParams.get('v') || '';
    if (!YT_ID.test(id)) return { ok: false, error: 'That YouTube link has no video in it.' };
    return { ok: true, provider: 'youtube', id, start, label: 'YouTube' };
  }
  if (host === 'vimeo.com' || host === 'player.vimeo.com') {
    const id = u.pathname.split('/').filter(Boolean).find((p) => VIMEO_ID.test(p)) || '';
    if (!id) return { ok: false, error: 'That Vimeo link has no video in it.' };
    return { ok: true, provider: 'vimeo', id, start, label: 'Vimeo' };
  }
  if (FILE_EXT.test(u.pathname)) {
    u.hash = '';
    return { ok: true, provider: 'file', url: u.toString(), start: 0, label: 'Video file' };
  }
  return { ok: false, error: 'Only YouTube, Vimeo or a direct video file (mp4, webm, mov) can be added by link.' };
}

export function buildVideoFromUrl(deck, parsed, taken = []) {
  if (!parsed?.ok) return null;
  const base = newVideo(deck, { taken });
  if (parsed.provider === 'file') return { ...base, src: { url: parsed.url } };
  const { src: _drop, ...rest } = base;
  return { ...rest, embed: { provider: parsed.provider, id: parsed.id }, ...(parsed.start ? { start: parsed.start } : {}) };
}

/** An image, video or audio element for a library asset ({id, kind, width, height, alt}). */
export function buildMediaElement(deck, kind, asset, taken = []) {
  const src = { asset: asset.id };
  const w = asset.width ?? asset.w ?? null;
  const h = asset.height ?? asset.h ?? null;
  const nat = w && h ? { w, h } : null;
  const base = kind === 'image' ? newImage(deck, { src, natural: nat || undefined, taken })
    : kind === 'video' ? newVideo(deck, { src, taken })
      : newAudio(deck, { src, taken });
  const box = placeMedia(deck, kind, nat);
  const el = { ...base, id: base.id || newId(taken, 'e'), ...box };
  if (asset.alt && kind !== 'audio') el.alt = asset.alt;
  return el;
}

// ---- drags ------------------------------------------------------------------------------------

/**
 * The payload on ui.dragging while something from this panel is dragged: {kind: 'insert', what, ...}.
 *   what 'text'   {preset}        what 'shape' {shape}      what 'icon' {name}
 *   what 'chart'  {type}          what 'table' {rows, cols}  what 'media' {asset} (an asset id with its payload)
 */
export function buildFromDrag(deck, drag, taken = []) {
  if (!drag || drag.kind !== 'insert') return null;
  switch (drag.what) {
    case 'text': return buildText(deck, drag.preset, taken);
    case 'shape': return buildShape(deck, drag.shape, taken);
    case 'icon': return drag.name ? buildIcon(deck, drag.name, taken) : null;
    case 'chart': return buildChart(deck, drag.type, taken);
    case 'table': return buildTable(deck, drag.rows, drag.cols, taken);
    default: return null;
  }
}
