/**
 * The bead chip: the slide's own Spine state, shown next to the slide on the canvas.
 *
 * It is editor furniture only. It lives outside the slide's box, is never part of the document, never printed and never
 * exported. What it says comes from the one evidence function that colours the Spine's beads (ui/evidence.js), so the rail
 * and the canvas cannot disagree.
 *
 *   chipModel(slide)                        -> { state: 'solid'|'hollow', word, message }
 *   chipPlacement(origin, zoom, deck, size) -> { left, top, where: 'above'|'below'|'inside' }
 */
import { slideEvidence } from '../../ui/evidence.js';

const CHIP_H = 32;
const GAP = 8;

/** What to do next, in the words of the product, derived from the same reasons the Spine's tooltip uses. */
export function nextStep(ev) {
  const needEvidence = !ev.evidence;
  const needClaim = !ev.claim;
  if (!ev.title && needEvidence) return 'Write the claim this slide makes and add a chart, table or figure';
  if (!ev.title) return 'Write the claim this slide makes';
  if (needEvidence && needClaim) return 'Add a chart, table or figure, and say what is true in the title';
  if (needEvidence) return 'Add a chart, table or figure';
  if (needClaim) return 'Say what is true in the title, with a verb or a number';
  return '';
}

export function chipModel(slide) {
  const ev = slideEvidence(slide);
  if (ev.state === 'solid') return { state: 'solid', word: 'solid', message: 'Claim with evidence' };
  return { state: 'hollow', word: 'hollow', message: nextStep(ev) };
}

/**
 * Where the chip sits, in the stage's own pixels. Above the slide's top left corner when there is room, else under its
 * bottom left corner, else tucked inside the slide's top left (a slide zoomed past the stage).
 */
export function chipPlacement(origin, zoom, deck, size) {
  const sw = (deck?.w || 0) * zoom;
  const sh = (deck?.h || 0) * zoom;
  const x = Math.max(8, Math.min(origin.x, Math.max(8, (size?.w || 0) - 120)));
  if (origin.y - CHIP_H - GAP >= 4) return { left: x, top: origin.y - CHIP_H - GAP, where: 'above' };
  const below = origin.y + sh + GAP;
  if (size?.h && below + CHIP_H <= size.h - 4) return { left: x, top: below, where: 'below' };
  return { left: Math.max(8, origin.x + 8), top: Math.max(8, origin.y + 8), where: 'inside', width: sw };
}
