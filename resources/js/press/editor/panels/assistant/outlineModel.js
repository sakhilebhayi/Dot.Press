/**
 * Editing an outline before any slide exists (pure, node tested). The server answers POST /api/ai/outline with
 * {mode, degraded, degraded_reason, notes_on_mode, framework: {id, name, auto, strength, reason, fit}, title, beats: [{id, label,
 * question, slides: [content model]}], warnings, unsupported_numbers, length_requested, length_delivered}. Here it becomes a flat list
 * of rows the person can rename, edit, delete and reorder. Every function returns a new list.
 */
import { moveItem } from '../../../ui/controls/reorder.js';

let seq = 0;
const key = () => `o${++seq}`;

export function outlineRows(resp) {
  const rows = [];
  for (const beat of resp?.beats || []) {
    for (const model of beat.slides || []) rows.push({ key: key(), beatId: beat.id, beatLabel: beat.label, question: beat.question, model: structuredCloneSafe(model) });
  }
  return rows;
}

function structuredCloneSafe(v) { return JSON.parse(JSON.stringify(v)); }

export function renameRow(rows, k, title) {
  const t = String(title ?? '').slice(0, 200);
  return rows.map((r) => (r.key === k ? { ...r, model: { ...r.model, title: t, slots: { ...r.model.slots, title: t } } } : r));
}

/** Bullets are edited one per line. Kinds that keep text elsewhere keep it there. */
export function setBullets(rows, k, text) {
  const lines = String(text ?? '').split('\n').map((l) => l.replace(/^\s*[-*•]\s*/, '').trim()).filter(Boolean).slice(0, 12);
  return rows.map((r) => (r.key === k ? { ...r, model: { ...r.model, slots: { ...r.model.slots, bullets: lines } } } : r));
}

export const bulletText = (row) => (row.model.slots?.bullets || []).join('\n');
export const hasBullets = (row) => ['bullets', 'two', 'image-left', 'image-right', 'closing', 'data', 'kpi'].includes(row.model.kind);

export function removeRow(rows, k) {
  return rows.filter((r) => r.key !== k);
}

export function moveRow(rows, from, to) {
  return moveItem(rows, from, to);
}

/** Replace every row of one beat with the slides of a fresh answer for that beat, in the same place. */
export function replaceBeat(rows, beatId, resp) {
  const fresh = (resp?.beats || []).find((b) => b.id === beatId);
  if (!fresh) return rows;
  const first = rows.findIndex((r) => r.beatId === beatId);
  const kept = rows.filter((r) => r.beatId !== beatId);
  const insert = (fresh.slides || []).map((m) => ({ key: key(), beatId, beatLabel: fresh.label, question: fresh.question, model: structuredCloneSafe(m) }));
  const at = first < 0 ? kept.length : Math.min(first, kept.length);
  return [...kept.slice(0, at), ...insert, ...kept.slice(at)];
}

/** Which rows start a new beat in the display: [{row, header: label|null}]. */
export function withHeaders(rows) {
  return rows.map((row, i) => ({ row, header: i === 0 || rows[i - 1].beatId !== row.beatId ? row.beatLabel : null }));
}

export function outlineStats(rows) {
  const needs = rows.filter((r) => r.model.needs_evidence || (r.model.needs || []).length).length;
  return { slides: rows.length, needs };
}

/** What the person is told about a model slide, in a few words. */
export function rowWords(row) {
  const s = row.model.slots || {};
  const parts = [];
  if (s.chart) parts.push('chart');
  if ((s.kpis || []).length) parts.push(`${s.kpis.length} figures`);
  if ((s.bullets || []).length) parts.push(`${s.bullets.length} points`);
  if (s.quote) parts.push('quote');
  if ((s.steps || []).length) parts.push(`${s.steps.length} steps`);
  if ((s.left || []).length || (s.right || []).length) parts.push('two sides');
  return parts.join(', ');
}

/** Request body for POST /api/ai/outline from the form (brief may be empty when pasted text exists). */
export function outlineRequest(form) {
  const source = String(form.source || '').trim();
  let brief = String(form.brief || '').trim();
  if (!brief && source) brief = source.split('\n').find((l) => l.trim())?.trim().slice(0, 120) || '';
  const req = { brief };
  if (source) req.source = source;
  if (form.framework && form.framework !== 'auto') req.framework = form.framework;
  if (String(form.audience || '').trim()) req.audience = String(form.audience).trim().slice(0, 200);
  req.length = Math.min(40, Math.max(5, Math.round(Number(form.length) || 10)));
  return req;
}

export function formError(form) {
  const source = String(form.source || '').trim();
  const brief = String(form.brief || '').trim();
  if (brief.length < 3 && source.length < 3) return 'Describe the deck in a few words, or paste your notes.';
  return '';
}
