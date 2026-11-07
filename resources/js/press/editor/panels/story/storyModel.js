/**
 * Words and shapes for the Story panel (pure, node tested). The server (Storyteller) returns:
 *   framework {id, name, summary, strength: 'strong'|'moderate'|'weak', coverage, forced}, alternatives [{id, name, coverage, score}],
 *   notes [string], beats [{id, label, question, optional, covered, slides: [slideId]}], mapping, checks [{id, label, status: 'ok'|'weak'|'missing',
 *   detail, slides: [slideId]}], missing, weak_transitions [{from, to, from_index, to_index, reason, question}], proposals [{id, kind, summary, ops}]
 */
import { slideNo } from '../intel/opsText.js';

const CORE = (beats) => beats.filter((b) => !b.optional);

/** present: the text supports the beat. weak: slides sit on the beat but say little about it. missing: no slide for it. */
export function beatStatus(beat) {
  if (beat.covered) return 'present';
  return beat.slides && beat.slides.length ? 'weak' : 'missing';
}

export const STATUS_WORD = { present: 'present', weak: 'weak', missing: 'missing' };
export const BEAD_STATE = { present: 'solid', weak: 'hollow', missing: 'hollow' };

export function beadRows(story, doc) {
  return (story?.beats || []).map((b) => {
    const status = beatStatus(b);
    return {
      id: b.id, label: b.label, question: b.question, optional: !!b.optional, status, word: STATUS_WORD[status],
      slides: (b.slides || []).map((id) => ({ id, no: slideNo(doc, id) })).filter((s) => s.no),
    };
  });
}

/** {found, total} over the beats the structure requires (optional beats do not count against the deck). */
export function coverageCount(story) {
  const core = CORE(story?.beats || []);
  return { found: core.filter((b) => b.covered).length, total: core.length };
}

/**
 * The plain explanation under the structure name.
 *   -> {headline, body, weak, offerFree}
 * `weak` is true when the structure does not fit well; `offerFree` when the free structure should be offered.
 */
export function detection(story) {
  const fw = story?.framework;
  if (!fw) return { headline: '', body: '', weak: false, offerFree: false };
  const { found, total } = coverageCount(story);
  const count = total ? `${found} of ${total} beats found` : 'no beats to look for';
  const weak = fw.strength === 'weak';
  if (fw.id === 'free' && !fw.forced) {
    return {
      headline: 'No standard structure fits this deck well.',
      body: 'None is forced on it. The free structure checks the basics instead: an opening, a middle that flows, evidence, what it means, and a close. Pick a structure yourself if you want to be held to one.',
      weak: true,
      offerFree: false,
    };
  }
  if (fw.id === 'free') {
    return { headline: 'Free structure.', body: `Checked against the basics only: ${count}. No fixed story shape is applied.`, weak: false, offerFree: false };
  }
  if (fw.forced) {
    return {
      headline: `You chose ${fw.name}: ${count}.`,
      body: weak ? 'This deck does not read much like that structure. The list below shows what is missing against it, and you decide whether it suits the talk.' : `${strengthWord(fw.strength)} It follows the order of this structure.`,
      weak,
      offerFree: weak,
    };
  }
  return {
    headline: `This reads like ${fw.name}: ${count}.`,
    body: weak ? 'The match is weak. Treat the gaps below as hints, not rules, or use the free structure.' : `${strengthWord(fw.strength)} The beats below are the questions this structure expects an audience to get answered.`,
    weak,
    offerFree: weak,
  };
}

function strengthWord(s) {
  return s === 'strong' ? 'A strong match.' : s === 'moderate' ? 'A reasonable match.' : 'A weak match.';
}

export const CHECK_PASS = (c) => c.status === 'ok';

export function checkRows(story, doc) {
  return (story?.checks || []).map((c) => ({
    id: c.id, label: c.label, pass: CHECK_PASS(c), word: CHECK_PASS(c) ? 'pass' : 'gap', status: c.status, detail: c.detail,
    slides: (c.slides || []).map((id) => ({ id, no: slideNo(doc, id) })).filter((s) => s.no),
  }));
}

export function checkSummary(rows) {
  const pass = rows.filter((r) => r.pass).length;
  return rows.length ? `${pass} of ${rows.length} pass` : '';
}

/** Where a story proposal puts things, in words. */
export function proposalTitle(p) {
  return p.kind === 'reorder' ? 'Reorder slides' : p.kind === 'add_beat' ? 'Add a placeholder slide' : p.summary;
}

export function transitionRows(story, doc) {
  return (story?.weak_transitions || []).map((t, i) => ({
    key: `${t.from}-${t.to}-${i}`, from: { id: t.from, no: slideNo(doc, t.from) }, to: { id: t.to, no: slideNo(doc, t.to) }, reason: t.reason, question: t.question,
  }));
}
