/**
 * Pure helpers for the layout panel (node tested).
 *
 * Two shapes meet here. The editor core (core/layouts.js) works on layout DEFINITIONS {id, name, bg?, ph: [...]} with
 * placeholder boxes as fractions of the slide. The server stores a team's layouts as a list of placeholder-style
 * elements (App\Services\Press\LayoutDefinition: id, type, role, x y w h with units fraction | slide, hint, size ...).
 *
 *   fromCustom(payload)            GET /api/layouts entry -> core definition
 *   fromLibrary(entry)             GET /api/layout-library entry -> core definition
 *   toServerDefinition(core)       core definition (from saveLayoutFromSlide) -> body.definition, validated shape
 *   wireframe(ph)                  [{key, left, top, width, height, kind, role}] in percent, for drawing a card
 *   planLayout(doc, slideId, def)  {ops, report} without changing anything (core applyLayout)
 *   summarise(plan, slide)         {moved, inPlace, added, left, addedRoles, leftNames, lines(tense)}
 *   sameLayoutSlides(doc, slide)   slides that use the layout the given slide uses now
 *   bulkOps(doc, slideIds, def)    ops for several slides and the combined summary
 */
import { applyLayout as corePlan } from '../../core/layouts.js';
import { docText } from '../../core/doc.js';

const REF_W = 1920;
const REF_H = 1080;
const r5 = (n) => Math.round(n * 100000) / 100000;
const clamp01 = (n) => Math.min(1, Math.max(0, n));

const MEDIA_TYPES = new Set(['image', 'video', 'chart', 'table']);
const STYLE_KEYS = ['font', 'weight', 'color', 'align', 'valign', 'fit', 'lh'];
const FIT_OK = new Set(['none', 'shrink', 'cover', 'contain', 'fill']);
const ALIGN_OK = new Set(['left', 'center', 'right', 'justify']);
const VALIGN_OK = new Set(['top', 'middle', 'bottom']);
const ROLE_OK = new Set(['title', 'subtitle', 'body', 'media', 'caption', 'footer', 'number', 'kpi']);

export function fromLibrary(entry) {
  return { id: entry.id, name: entry.name, ph: entry.ph || [], ...(entry.bg !== undefined ? { bg: entry.bg } : {}) };
}

export function fromCustom(payload) {
  const ph = [];
  for (const el of payload.definition || []) {
    if (el.type === 'audio') continue;
    const slideUnits = el.units === 'slide';
    const type = el.type === 'text' ? 'text' : MEDIA_TYPES.has(el.type) ? 'media' : 'shape';
    const role = ROLE_OK.has(el.role) ? el.role : type === 'text' ? 'body' : type === 'media' ? 'media' : 'caption';
    const box = {
      x: clamp01(slideUnits ? el.x / REF_W : el.x),
      y: clamp01(slideUnits ? el.y / REF_H : el.y),
      w: clamp01(slideUnits ? el.w / REF_W : el.w),
      h: clamp01(slideUnits ? el.h / REF_H : el.h),
    };
    const p = { role, type, ...box };
    if (el.name) p.name = el.name;
    if (el.hint && type === 'text') p.text = el.hint;
    if (el.size && type === 'text') p.sizeF = r5(el.size / REF_H);
    const style = {};
    for (const k of STYLE_KEYS) if (el[k] !== undefined && type === 'text') style[k] = el[k];
    if (Object.keys(style).length) p.style = style;
    ph.push(p);
  }
  return { id: payload.id, name: payload.name, ph };
}

export function toServerDefinition(core, max = 40) {
  const out = [];
  (core.ph || []).slice(0, max).forEach((p, i) => {
    const x = clamp01(p.x);
    const y = clamp01(p.y);
    const w = Math.min(clamp01(p.w), 1 - x);
    const h = Math.min(clamp01(p.h), 1 - y);
    if (!(w > 0.001) || !(h > 0.001)) return;
    const el = {
      id: `p${out.length + 1}`,
      type: p.type === 'media' ? 'image' : p.type === 'shape' ? 'shape' : 'text',
      units: 'fraction',
      x: r5(x), y: r5(y), w: r5(w), h: r5(h),
    };
    if (ROLE_OK.has(p.role)) el.role = p.role;
    if (p.name) el.name = String(p.name).slice(0, 80);
    if (p.type === 'text') {
      if (p.text) el.hint = String(p.text).replace(/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/g, '').slice(0, 120);
      if (p.sizeF) el.size = Math.min(1000, Math.max(1, Math.round(p.sizeF * REF_H)));
      const s = p.style || {};
      if (typeof s.font === 'string' && s.font) el.font = s.font;
      if (Number.isInteger(s.weight) && s.weight >= 100 && s.weight <= 900) el.weight = s.weight;
      if (typeof s.color === 'string') el.color = s.color;
      if (ALIGN_OK.has(s.align)) el.align = s.align;
      if (VALIGN_OK.has(s.valign)) el.valign = s.valign;
      if (FIT_OK.has(s.fit)) el.fit = s.fit;
      if (typeof s.lh === 'number' && s.lh >= 0.5 && s.lh <= 3) el.lh = s.lh;
    }
    out.push(el);
  });
  return out;
}

const KIND = {
  title: 'title', subtitle: 'body', body: 'body', caption: 'body', footer: 'body', media: 'media', number: 'number', kpi: 'number',
};

export function wireframe(ph) {
  const pct = (n) => Math.round(clamp01(n) * 10000) / 100;
  return (ph || []).map((p, i) => ({
    key: `${p.role}-${i}`,
    role: p.role,
    left: pct(p.x),
    top: pct(p.y),
    width: Math.max(1, pct(Math.min(p.w, 1 - clamp01(p.x)))),
    height: Math.max(1, pct(Math.min(p.h, 1 - clamp01(p.y)))),
    kind: p.type === 'media' ? 'media' : KIND[p.role] || 'body',
  }));
}

export function planLayout(doc, slideId, def, opts = {}) {
  return corePlan(doc, slideId, def, opts);
}

const GEOMETRY = ['x', 'y', 'w', 'h', 'rot'];
const ROLE_WORD = {
  title: 'title', subtitle: 'subtitle', body: 'text', media: 'picture', caption: 'caption', footer: 'footer', number: 'number', kpi: 'figure',
};

function labelOf(el) {
  if (el.name) return el.name;
  if (el.type === 'text') {
    const t = docText(el.doc).trim().replace(/\s+/g, ' ');
    if (t) return t.length > 28 ? `${t.slice(0, 27)}...` : t;
  }
  return el.type === 'shape' ? 'shape' : el.type;
}

export function summarise(plan, slide) {
  const { ops, report } = plan;
  const movedIds = new Set(ops.filter((o) => o.op === 'el.set' && Object.keys(o.p || {}).some((k) => GEOMETRY.includes(k))).map((o) => o.id));
  const moved = report.matched.filter((m) => movedIds.has(m.id)).length;
  const inPlace = report.matched.length - moved;
  const added = report.added.length;
  const addedRoles = report.added.map((a) => ROLE_WORD[a.role] || a.role);
  const byId = new Map((slide?.els || []).map((e) => [e.id, e]));
  const leftNames = report.unmatched.map((u) => labelOf(byId.get(u.id) || { type: 'element' }));
  const locked = report.unmatched.filter((u) => u.reason === 'locked').length;
  return {
    matched: report.matched.length, moved, inPlace, added, addedRoles, left: report.unmatched.length, leftNames, locked,
  };
}

const n = (c, one, many) => `${c} ${c === 1 ? one : many}`;

/** Plain sentences. tense 'plan' (before applying) or 'done' (after). */
export function resultLines(s, tense = 'plan') {
  const plan = tense === 'plan';
  const lines = [];
  if (s.moved) lines.push(plan ? `${n(s.moved, 'element moves', 'elements move')} into the layout's boxes.` : `${n(s.moved, 'element', 'elements')} moved into the layout's boxes.`);
  if (s.inPlace) lines.push(`${n(s.inPlace, 'element is', 'elements are')} already in the right place.`);
  if (s.added) lines.push(plan ? `${n(s.added, 'empty placeholder is', 'empty placeholders are')} added (${[...new Set(s.addedRoles)].join(', ')}).` : `${n(s.added, 'empty placeholder', 'empty placeholders')} added (${[...new Set(s.addedRoles)].join(', ')}).`);
  if (s.left) {
    const names = s.leftNames.slice(0, 3).join(', ') + (s.leftNames.length > 3 ? ` and ${s.leftNames.length - 3} more` : '');
    const why = s.locked ? (s.locked === s.left ? (s.left === 1 ? ' It is locked.' : ' They are locked.') : ` ${n(s.locked, 'is', 'are')} locked.`) : '';
    const one = s.left === 1;
    const verb = plan ? (one ? 'element stays where it is' : 'elements stay where they are') : (one ? 'element was left where it was' : 'elements were left where they were');
    lines.push(`${s.left} ${verb} (${names}).${why}`);
  }
  if (!lines.length) lines.push(plan ? 'Nothing on the slide needs to change.' : 'Nothing needed to change.');
  return lines;
}

export function sameLayoutSlides(doc, slide) {
  if (!slide) return [];
  return doc.slides.filter((s) => s.layout === slide.layout);
}

export function bulkOps(doc, slideIds, def, opts = {}) {
  const ops = [];
  const total = { matched: 0, moved: 0, inPlace: 0, added: 0, addedRoles: [], left: 0, leftNames: [], locked: 0 };
  let touched = 0;
  for (const id of slideIds) {
    const slide = doc.slides.find((s) => s.id === id);
    const plan = planLayout(doc, id, def, opts);
    if (plan.ops.length) touched++;
    ops.push(...plan.ops);
    const s = summarise(plan, slide);
    total.matched += s.matched; total.moved += s.moved; total.inPlace += s.inPlace; total.added += s.added; total.left += s.left; total.locked += s.locked;
    total.addedRoles.push(...s.addedRoles);
    total.leftNames.push(...s.leftNames);
  }
  return { ops, summary: total, touched, slides: slideIds.length };
}
