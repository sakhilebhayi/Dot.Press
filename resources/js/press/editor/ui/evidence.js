/**
 * The state of a bead on the Spine: is the slide a claim with evidence, or a claim without it yet?
 *
 * A slide is `solid` (evidence) when BOTH hold, otherwise `hollow`:
 *
 *   1. Evidence. The slide holds at least one visible element that can carry proof:
 *        - a chart, a table, a picture or a video, or
 *        - a text element (not the title, subtitle, footer or slide number) whose text holds a digit or a quotation
 *          mark ("..." or curly or guillemet quotes). A bare apostrophe is not a quotation mark.
 *      Hidden elements never count. Text that is just the slide title never counts, so a title with a number in it
 *      does not prove itself.
 *   2. Claim. The title (the slide title, or the text of a title placeholder when the slide has no title) states a
 *      claim: at least 4 words, and either a number or a verb-like word from the small list below (or a word of
 *      five or more letters ending in "ed", which catches "doubled", "reduced", "approved").
 *      "Output by week" is a topic and stays hollow. "Three things drove the result" is a claim.
 *
 * It is a heuristic, not a verdict: it only asks "did you say something, and did you show anything". The words are
 * the reason shown on hover, so a hollow bead always tells the person what is missing.
 *
 *   slideEvidence(slide) -> { state: 'solid'|'hollow', evidence: bool, claim: bool, title: string, reasons: [string] }
 *   slideTitle(slide)    -> the claim text to show in the Spine (title, or the title placeholder text, or '')
 *   evidenceSummary(...)  -> plain sentence for tooltips and the live region
 *   wordCount(text)
 */
import { docText } from './../core/doc.js';

const EVIDENCE_TYPES = new Set(['chart', 'table', 'image', 'video']);
const SKIP_ROLES = new Set(['title', 'subtitle', 'footer', 'number', 'caption']);
const QUOTE_RE = /["“”„«»]/;
const DIGIT_RE = /\d/;

export const CLAIM_VERBS = new Set([
  'is', 'are', 'was', 'were', 'be', 'been', 'being', 'has', 'have', 'had', 'does', 'do', 'did', 'can', 'could', 'will',
  'would', 'should', 'must', 'may', 'might', 'needs', 'need', 'makes', 'make', 'made', 'means', 'mean', 'drives', 'drove',
  'drive', 'driven', 'grows', 'grew', 'grow', 'grown', 'rises', 'rose', 'rise', 'risen', 'falls', 'fell', 'fall', 'fallen',
  'beats', 'beat', 'cuts', 'cut', 'saves', 'save', 'costs', 'cost', 'earns', 'earn', 'wins', 'win', 'won', 'loses', 'lose',
  'lost', 'fixes', 'fix', 'hits', 'hit', 'shows', 'show', 'showed', 'shown', 'proves', 'prove', 'proved', 'leads', 'lead',
  'led', 'helps', 'help', 'let', 'lets', 'gets', 'get', 'got', 'takes', 'take', 'took', 'gives', 'give', 'gave', 'brings',
  'bring', 'brought', 'keeps', 'keep', 'kept', 'moves', 'move', 'runs', 'run', 'ran', 'sells', 'sell', 'sold', 'builds',
  'build', 'built', 'beats', 'exceeds', 'exceed', 'misses', 'miss', 'holds', 'hold', 'held', 'turns', 'turn', 'stays',
  'stay', 'starts', 'start', 'stops', 'stop', 'creates', 'create', 'reduces', 'reduce', 'increases', 'increase',
  'improves', 'improve', 'lowers', 'lower', 'raises', 'raise', 'doubles', 'double', 'halves', 'halve', 'matters',
  'matter', 'works', 'work', 'pays', 'pay', 'depends', 'depend', 'requires', 'require', 'enables', 'enable', 'removes',
  'remove', 'adds', 'add', 'beat', 'outperforms', 'outperform', 'wins', 'fails', 'fail', 'passes', 'pass', 'grows',
]);

/** Words in a string, split on whitespace and punctuation but keeping apostrophes and digits with the word. */
export function wordsOf(text) {
  return String(text || '').match(/[\p{L}\p{N}][\p{L}\p{N}'’%.,-]*/gu) || [];
}
export const wordCount = (text) => wordsOf(text).length;

export function isClaimTitle(text) {
  const words = wordsOf(text);
  if (words.length < 4) return false;
  if (DIGIT_RE.test(text)) return true;
  return words.some((w) => {
    const lw = w.toLowerCase().replace(/[^\p{L}']/gu, '');
    return CLAIM_VERBS.has(lw) || (lw.length >= 5 && lw.endsWith('ed'));
  });
}

const elText = (el) => (el.doc ? docText(el.doc) : '').trim();

export function slideTitle(slide) {
  if (!slide) return '';
  const own = String(slide.title || '').trim();
  if (own) return own;
  const el = (slide.els || []).find((e) => e.type === 'text' && e.role === 'title' && !e.hide);
  return el ? elText(el).replace(/\s*\n+\s*/g, ' ') : '';
}

export function slideEvidence(slide) {
  const title = slideTitle(slide);
  const els = (slide?.els || []).filter((e) => !e.hide);
  let evidence = false;
  for (const el of els) {
    if (EVIDENCE_TYPES.has(el.type)) { evidence = true; break; }
    if (el.type === 'text' || el.type === 'shape') {
      if (SKIP_ROLES.has(el.role)) continue;
      const text = elText(el);
      if (!text || text.replace(/\s+/g, ' ') === title) continue;
      if (DIGIT_RE.test(text) || QUOTE_RE.test(text)) { evidence = true; break; }
    }
  }
  const claim = isClaimTitle(title);
  const reasons = [];
  if (!claim) reasons.push(title ? 'The title reads as a topic. Say what is true, in a sentence with a verb or a number' : 'This slide has no title yet. Write the claim it makes');
  if (!evidence) reasons.push('No chart, table, picture or figure yet');
  return { state: evidence && claim ? 'solid' : 'hollow', evidence, claim, title, reasons };
}

/** One line for hover text and announcements. */
export function evidenceSummary(ev, hidden = false) {
  const base = ev.state === 'solid' ? 'Claim with evidence' : ev.reasons.join('. ');
  return hidden ? `${base}. Hidden from the presentation` : base;
}
