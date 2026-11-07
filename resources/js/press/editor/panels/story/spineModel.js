/**
 * The argument spine editor's logic (pure, node tested).
 *
 * Server beat: {id, claim, slides: [slideId], section_id, hollow, evidence: {charts, tables, images, numbers, sources}}.
 * Edits POST {type: 'reorder'|'merge'|'split'|'rename', beat, after?, into?, at?, claim?} and answer
 * {ops, order, beats: [{id, claim, slides, section_id}], adopted_sections}: a PROPOSAL, applied only on confirm.
 */
import { moveItem } from '../../../ui/controls/reorder.js';
import { moveSummary, slideNo } from '../intel/opsText.js';

export function beatRows(beats, doc) {
  return (beats || []).map((b) => ({
    id: b.id,
    claim: b.claim || '',
    hollow: !!b.hollow,
    evidence: b.evidence || {},
    sectionId: b.section_id ?? null,
    slides: (b.slides || []).map((id) => ({ id: String(id), no: slideNo(doc, id) })),
  }));
}

export function slideRange(row) {
  const nums = row.slides.map((s) => s.no).filter(Boolean);
  if (!nums.length) return 'no slides';
  if (nums.length === 1) return `slide ${nums[0]}`;
  const contiguous = nums.every((n, i) => i === 0 || n === nums[i - 1] + 1);
  return contiguous ? `slides ${nums[0]} to ${nums[nums.length - 1]}` : `slides ${nums.join(', ')}`;
}

export const beatName = (row) => (row.claim ? row.claim : `Untitled beat (${slideRange(row)})`);

/** "2 charts, 3 figures" for a solid bead, "no evidence yet" for a hollow one. */
export function evidenceWords(row) {
  if (row.hollow) return 'no evidence yet';
  const e = row.evidence || {};
  const part = (n, one, many) => (n ? `${n} ${n === 1 ? one : many}` : null);
  const words = [part(e.charts, 'chart', 'charts'), part(e.tables, 'table', 'tables'), part(e.numbers, 'figure', 'figures'), part(e.sources, 'source', 'sources'), part(e.images, 'picture', 'pictures')].filter(Boolean);
  return words.length ? words.join(', ') : 'nothing to prove';
}

/** After a drag from index `from` to final index `to`: the beat to place it after (null = first). */
export function reorderRequest(rows, from, to) {
  if (from === to || from < 0 || from >= rows.length) return null;
  const next = moveItem(rows, from, to);
  const at = next.findIndex((r) => r.id === rows[from].id);
  return { type: 'reorder', beat: rows[from].id, after: at === 0 ? null : next[at - 1].id };
}

export function mergeTargets(rows, id) {
  return rows.filter((r) => r.id !== id).map((r) => ({ value: r.id, label: beatName(r), hint: slideRange(r) }));
}

/** A beat can be split at any slide but its first. */
export function splitOptions(row, doc) {
  return row.slides.slice(1).map((s) => {
    const sl = doc.slides.find((x) => x.id === s.id);
    return { value: s.id, label: `Slide ${s.no}${sl?.title ? `: ${sl.title}` : ''}` };
  });
}

const q = (s) => `"${s}"`;

/** The sentences a person reads before confirming: the edit itself, then the slide moves, then any side effect. */
export function previewEdit(edit, response, rows, doc) {
  const by = new Map(rows.map((r) => [r.id, r]));
  const me = by.get(edit.beat);
  const lines = [];
  if (edit.type === 'rename') lines.push(`Renames the claim ${q(beatName(me))} to ${q(response.beats.find((b) => b.id === edit.beat)?.claim || edit.claim)}.`);
  if (edit.type === 'reorder') {
    const target = edit.after ? by.get(edit.after) : null;
    lines.push(target ? `Moves ${q(beatName(me))} after ${q(beatName(target))}.` : `Moves ${q(beatName(me))} to the start.`);
  }
  if (edit.type === 'merge') {
    const into = by.get(edit.into);
    lines.push(`Merges ${q(beatName(me))} into ${q(beatName(into))}. Its ${me.slides.length === 1 ? 'slide joins' : `${me.slides.length} slides join`} the end of that beat.`);
  }
  if (edit.type === 'split') {
    const at = slideNo(doc, edit.at);
    const nb = response.beats.find((b) => b.slides.map(String).includes(String(edit.at)) && b.id !== edit.beat);
    lines.push(`Splits ${q(beatName(me))} at slide ${at}. Slide ${at} and the ones after it become a new beat${nb?.claim ? ` ${q(nb.claim)}` : ''}.`);
  }
  const moves = moveSummary(response.ops || [], doc);
  lines.push(...moves);
  if (!moves.length && edit.type !== 'rename') lines.push('No slides move.');
  if (response.adopted_sections) lines.push('The deck has no sections for every beat yet, so this also adds one section per beat, named by its claim. That keeps the spine stable. Undo removes them again.');
  return lines;
}
