/**
 * Hit order: which element is under the pointer. Pure, slide units.
 *
 *   hitOrder(slide, pt, {pad, locked})   ids of elements under the point, topmost first.
 *        pad is a tolerance in slide units (callers pass 6 / zoom so it is constant on screen).
 *        Hidden elements never hit. Locked ones hit only with {locked: true}.
 *   pickTop(slide, pt, opts)             topmost hit id or null
 *   pickBelow(slide, pt, currentId, opts)  the next hit below `currentId` (alt+click selects behind), wrapping
 *   lockedAt(slide, pt, pad)             topmost locked, visible element under the point (to hint "locked")
 *   tabOrder(slide)                      pickable ids bottom to top (matches Tab through the layers)
 *   isLine(el)                           a line or arrow shape (box of height 0, endpoint handles)
 */
import { pointInBox } from '../../core/geometry.js';

export const isLine = (el) => el?.type === 'shape' && (el.kind === 'line' || el.kind === 'arrow');

export function hitOrder(slide, pt, { pad = 0, locked = false } = {}) {
  const out = [];
  if (!slide) return out;
  for (let i = slide.els.length - 1; i >= 0; i--) {
    const el = slide.els[i];
    if (el.hide) continue;
    if (el.lock && !locked) continue;
    // thin boxes (lines, hairline rules) get the full tolerance on their short side
    const thin = Math.min(el.w, el.h) < pad * 2;
    if (pointInBox(el, pt, thin || isLine(el) ? pad * 1.5 : 0)) out.push(el.id);
  }
  return out;
}

export const pickTop = (slide, pt, opts) => hitOrder(slide, pt, opts)[0] ?? null;

export function pickBelow(slide, pt, currentId, opts) {
  const list = hitOrder(slide, pt, opts);
  if (!list.length) return null;
  const i = list.indexOf(currentId);
  return list[(i + 1) % list.length];
}

export function lockedAt(slide, pt, pad = 0) {
  if (!slide) return null;
  for (let i = slide.els.length - 1; i >= 0; i--) {
    const el = slide.els[i];
    if (el.hide || !el.lock) continue;
    if (pointInBox(el, pt, pad)) return el.id;
  }
  return null;
}

export const tabOrder = (slide) => (slide ? slide.els.filter((e) => !e.hide && !e.lock).map((e) => e.id) : []);
