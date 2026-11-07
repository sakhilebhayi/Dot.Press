/**
 * Pure helpers for the history panel (no Vue, no DOM, node tested).
 *
 *   compareDocs(past, current)   what changed between a stored version (GET /versions/{id} snapshot) and the deck now
 *   describeSince(diff)          sentences for "since this version"
 *   describeRestore(diff)        sentences for "restoring this version would"
 *   groupByDay(versions, now)    version rows under Today, Yesterday and dates
 *   versionTitle(v), kindLabel(kind)
 *
 * Documents are {deck: {title, w, h, theme, sections}, slides: [{id, title, notes, els}]} (spec section 9). Slide ids may be
 * numbers in a snapshot and strings in the editor; they are compared as strings.
 */
import { docText } from '../../core/doc.js';

const sid = (s) => String(s.id);

/** JSON with sorted keys, so two equal values always give the same string. */
export function stable(v) {
  if (v === null || typeof v !== 'object') return JSON.stringify(v ?? null);
  if (Array.isArray(v)) return `[${v.map(stable).join(',')}]`;
  return `{${Object.keys(v).sort().filter((k) => v[k] !== undefined).map((k) => `${JSON.stringify(k)}:${stable(v[k])}`).join(',')}}`;
}

export function slideLabel(slide, index) {
  const t = String(slide?.title ?? '').trim();
  return t || `Slide ${index + 1}`;
}

/** Indexes of `b` that are part of a longest common subsequence with `a` (both arrays of ids). */
function stayers(a, b) {
  const n = a.length, m = b.length;
  if (!n || !m) return new Set();
  const dp = Array.from({ length: n + 1 }, () => new Uint16Array(m + 1));
  for (let i = n - 1; i >= 0; i -= 1) {
    for (let j = m - 1; j >= 0; j -= 1) {
      dp[i][j] = a[i] === b[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
    }
  }
  const keep = new Set();
  let i = 0, j = 0;
  while (i < n && j < m) {
    if (a[i] === b[j]) { keep.add(b[j]); i += 1; j += 1; } else if (dp[i + 1][j] >= dp[i][j + 1]) i += 1; else j += 1;
  }
  return keep;
}

function elementStats(past, now) {
  const before = new Map((past.els || []).map((e) => [e.id, stable(e)]));
  const after = new Map((now.els || []).map((e) => [e.id, stable(e)]));
  let added = 0, removed = 0, changed = 0;
  for (const [id, s] of after) { if (!before.has(id)) added += 1; else if (before.get(id) !== s) changed += 1; }
  for (const id of before.keys()) if (!after.has(id)) removed += 1;
  return { before: before.size, after: after.size, added, removed, changed };
}

function notesOf(slide) { return String(slide?.notes ?? '').trim(); }

/**
 * @param past    the stored snapshot (the version)
 * @param current the deck as it is now
 * Direction: everything is described as "what is different now, compared with the version".
 */
export function compareDocs(past, current) {
  const ps = past?.slides || [];
  const cs = current?.slides || [];
  const pById = new Map(ps.map((s, i) => [sid(s), { s, i }]));
  const cById = new Map(cs.map((s, i) => [sid(s), { s, i }]));

  const added = cs.filter((s) => !pById.has(sid(s))).map((s) => ({ id: sid(s), title: slideLabel(s, cById.get(sid(s)).i), index: cById.get(sid(s)).i }));
  const removed = ps.filter((s) => !cById.has(sid(s))).map((s) => ({ id: sid(s), title: slideLabel(s, pById.get(sid(s)).i), index: pById.get(sid(s)).i }));

  const commonPast = ps.map(sid).filter((id) => cById.has(id));
  const commonNow = cs.map(sid).filter((id) => pById.has(id));
  const keep = stayers(commonPast, commonNow);

  const retitled = [];
  const moved = [];
  const notes = [];
  const edited = [];
  for (const id of commonNow) {
    const p = pById.get(id), c = cById.get(id);
    const pt = String(p.s.title ?? '').trim(), ct = String(c.s.title ?? '').trim();
    if (pt !== ct) retitled.push({ id, from: pt || `Slide ${p.i + 1}`, to: ct || `Slide ${c.i + 1}`, index: c.i });
    if (!keep.has(id)) moved.push({ id, title: slideLabel(c.s, c.i), from: p.i, to: c.i });
    if (notesOf(p.s) !== notesOf(c.s)) notes.push({ id, title: slideLabel(c.s, c.i), index: c.i, from: notesOf(p.s), to: notesOf(c.s) });
    const e = elementStats(p.s, c.s);
    if (e.added || e.removed || e.changed) edited.push({ id, title: slideLabel(c.s, c.i), index: c.i, ...e });
  }

  const count = (slides) => slides.reduce((n, s) => n + (s.els?.length || 0), 0);
  const elements = { before: count(ps), after: count(cs) };
  elements.delta = elements.after - elements.before;

  const pd = past?.deck || {}, cd = current?.deck || {};
  const deck = {
    title: (pd.title ?? '') !== (cd.title ?? '') ? { from: pd.title ?? '', to: cd.title ?? '' } : null,
    size: (pd.w !== cd.w || pd.h !== cd.h) ? { from: `${pd.w} by ${pd.h}`, to: `${cd.w} by ${cd.h}` } : null,
    theme: stable(pd.theme ?? null) !== stable(cd.theme ?? null),
    sections: stable((pd.sections || []).map((s) => [s.id, s.title])) !== stable((cd.sections || []).map((s) => [s.id, s.title])),
  };

  const identical = !added.length && !removed.length && !retitled.length && !moved.length && !notes.length && !edited.length && !deck.title && !deck.size && !deck.theme && !deck.sections;
  return { identical, added, removed, retitled, moved, notes, edited, elements, deck, slides: { before: ps.length, after: cs.length } };
}

const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

/** Sentences: what is different in the deck now compared with the version. */
export function describeSince(diff) {
  if (diff.identical) return ['The deck is the same as this version.'];
  const out = [];
  if (diff.added.length) out.push(`${plural(diff.added.length, 'slide', 'slides')} added since`);
  if (diff.removed.length) out.push(`${plural(diff.removed.length, 'slide', 'slides')} removed since`);
  if (diff.retitled.length) out.push(`${plural(diff.retitled.length, 'slide', 'slides')} retitled`);
  if (diff.moved.length) out.push(`${plural(diff.moved.length, 'slide', 'slides')} moved`);
  if (diff.elements.delta !== 0) out.push(`Elements ${diff.elements.before} to ${diff.elements.after} (${diff.elements.delta > 0 ? '+' : ''}${diff.elements.delta})`);
  else if (diff.edited.length) out.push(`${plural(diff.edited.length, 'slide has', 'slides have')} edited elements`);
  if (diff.notes.length) out.push(`Speaker notes changed on ${plural(diff.notes.length, 'slide', 'slides')}`);
  if (diff.deck.title) out.push('Deck title changed');
  if (diff.deck.size) out.push('Slide size changed');
  if (diff.deck.theme) out.push('Theme changed');
  if (diff.deck.sections) out.push('Sections changed');
  return out;
}

/** Sentences: what restoring the version would do to the deck as it is now. */
export function describeRestore(diff) {
  if (diff.identical) return ['Nothing would change. The deck already matches this version.'];
  const out = [];
  if (diff.removed.length) out.push(`Brings back ${plural(diff.removed.length, 'slide', 'slides')}`);
  if (diff.added.length) out.push(`Removes ${plural(diff.added.length, 'slide', 'slides')} made since`);
  if (diff.retitled.length) out.push(`Restores the old title on ${plural(diff.retitled.length, 'slide', 'slides')}`);
  if (diff.moved.length) out.push(`Puts ${plural(diff.moved.length, 'slide', 'slides')} back in their old place`);
  if (diff.elements.delta !== 0) out.push(`Elements go from ${diff.elements.after} to ${diff.elements.before}`);
  else if (diff.edited.length) out.push(`Undoes edits to elements on ${plural(diff.edited.length, 'slide', 'slides')}`);
  if (diff.notes.length) out.push(`Restores speaker notes on ${plural(diff.notes.length, 'slide', 'slides')}`);
  if (diff.deck.title) out.push('Restores the deck title');
  if (diff.deck.size) out.push('Restores the slide size');
  if (diff.deck.theme) out.push('Restores the theme');
  if (diff.deck.sections) out.push('Restores the sections');
  return out;
}

/** A short plain version of a text document, for tests and previews (re-exported so callers need one import). */
export { docText };

// ---- the version list -----------------------------------------------------------------------------------

const KINDS = {
  auto: { label: 'Autosaved', word: 'auto' },
  manual: { label: 'Saved by hand', word: 'saved' },
  restore: { label: 'Safety copy before a restore', word: 'safety' },
  approved: { label: 'Approved', word: 'approved' },
};

export function kindLabel(kind) { return (KINDS[kind] || KINDS.auto).label; }
export function kindWord(kind) { return (KINDS[kind] || KINDS.auto).word; }

/** The name shown for a version: its label, else what kind it is. */
export function versionTitle(v) {
  const l = String(v?.label ?? '').trim();
  return l || kindLabel(v?.kind);
}

/** The bead for a kind: approved is solid, manual is a ring with a dot, auto and safety copies are hollow. */
export function kindBead(kind) {
  if (kind === 'approved') return { state: 'solid', evidence: 'solid' };
  if (kind === 'manual') return { state: 'current', evidence: 'hollow' };
  return { state: 'hollow', evidence: 'hollow' };
}

const startOfDay = (t) => { const d = new Date(t); d.setHours(0, 0, 0, 0); return d.getTime(); };

/** [{key, label, items}] newest first. Days are in the viewer's time zone. */
export function groupByDay(versions, now = Date.now()) {
  const today = startOfDay(now);
  const out = [];
  for (const v of versions || []) {
    const t = Date.parse(v.created_at);
    if (!Number.isFinite(t)) continue;
    const day = startOfDay(t);
    const diff = Math.round((today - day) / 86400000);
    const label = diff === 0 ? 'Today' : diff === 1 ? 'Yesterday' : new Date(t).toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: new Date(t).getFullYear() === new Date(now).getFullYear() ? undefined : 'numeric' });
    const last = out[out.length - 1];
    if (last && last.key === day) last.items.push(v); else out.push({ key: day, label, items: [v] });
  }
  return out;
}

/** "14:32" in the viewer's locale. */
export function clockTime(iso) {
  const t = Date.parse(iso);
  return Number.isFinite(t) ? new Date(t).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }) : '';
}

/** The name given to "Save a named version": trimmed, at most 120 characters (the server limit). */
export function cleanVersionName(name) {
  return String(name ?? '').replace(/\s+/g, ' ').trim().slice(0, 120);
}
