/**
 * Viewport maths for the stage. Pure.
 *
 * Coordinates
 *   slide    units of the document, origin at the slide's top left
 *   screen   CSS pixels inside the stage viewport element, origin at its top left
 * The sheet (the slide) is drawn `deck.w * zoom` wide. With pan {0, 0} it is centred in the viewport;
 * `pan` is the offset of the sheet from that centred position, in screen pixels.
 *
 *   FIT_PAD, MIN_ZOOM, MAX_ZOOM
 *   clampZoom(z)
 *   fitZoom(vp, deck, pad?)                   zoom at which the whole slide is visible (matches store.zoomFit)
 *   effectiveZoom(uiZoom, vp, deck)           'fit' | number -> number
 *   sheetOrigin(vp, deck, zoom, pan)          {x, y} screen position of the slide's top left
 *   toSlide(pt, origin, zoom) / toScreen(pt, origin, zoom)
 *   zoomAtPoint(view, nextZoom, anchor)       -> {zoom, pan} the slide point under `anchor` stays under it
 *   clampPan(pan, vp, deck, zoom, keep?)      keeps at least `keep` px of the sheet in the viewport
 *   zoomToRect(rect, vp, deck, opts?)         -> {zoom, pan} so the slide rect fills the viewport
 *   normalizeWheel(e, vp)                     -> {dx, dy} in pixels (line and page modes converted)
 *   wheelZoomFactor(deltaY, pinch)            multiplicative zoom change for one wheel event
 *   pinchStep(prev, next)                     -> {scale, cx, cy, dx, dy} between two two-finger snapshots
 *   snapZoom(z)                               snaps to 100 percent when within 2 percent
 *   stepZoomTo(z, dir)                        next stop of the zoom ladder
 */
export const FIT_PAD = 48;
export const MIN_ZOOM = 0.1;
export const MAX_ZOOM = 4;
const LADDER = [0.1, 0.25, 0.33, 0.5, 0.67, 0.75, 1, 1.25, 1.5, 2, 3, 4];

export const clampZoom = (z) => Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, z));

export function fitZoom(vp, deck, pad = FIT_PAD) {
  if (!vp || !vp.w || !vp.h || !deck?.w || !deck?.h) return 1;
  return clampZoom(Math.min((vp.w - pad) / deck.w, (vp.h - pad) / deck.h));
}

export function effectiveZoom(uiZoom, vp, deck) {
  return uiZoom === 'fit' || uiZoom == null || !Number.isFinite(+uiZoom) ? fitZoom(vp, deck) : clampZoom(+uiZoom);
}

export function sheetOrigin(vp, deck, zoom, pan = { x: 0, y: 0 }) {
  return {
    x: (vp.w - deck.w * zoom) / 2 + (pan.x || 0),
    y: (vp.h - deck.h * zoom) / 2 + (pan.y || 0),
  };
}

export const toSlide = (pt, origin, zoom) => ({ x: (pt.x - origin.x) / zoom, y: (pt.y - origin.y) / zoom });
export const toScreen = (pt, origin, zoom) => ({ x: origin.x + pt.x * zoom, y: origin.y + pt.y * zoom });

export function zoomAtPoint({ zoom, pan, vp, deck }, nextZoom, anchor) {
  const z2 = clampZoom(nextZoom);
  const o1 = sheetOrigin(vp, deck, zoom, pan);
  const s = toSlide(anchor, o1, zoom);
  const ox = anchor.x - s.x * z2;
  const oy = anchor.y - s.y * z2;
  return {
    zoom: z2,
    pan: { x: ox - (vp.w - deck.w * z2) / 2, y: oy - (vp.h - deck.h * z2) / 2 },
  };
}

export function clampPan(pan, vp, deck, zoom, keep = 96) {
  const w = deck.w * zoom;
  const h = deck.h * zoom;
  const k = Math.min(keep, w / 2, h / 2, vp.w / 2, vp.h / 2);
  // sheet left edge must stay left of (vp.w - k), right edge right of k
  const maxX = (vp.w + w) / 2 - k;
  const maxY = (vp.h + h) / 2 - k;
  return {
    x: Math.min(maxX, Math.max(-maxX, pan.x)),
    y: Math.min(maxY, Math.max(-maxY, pan.y)),
  };
}

export function zoomToRect(rect, vp, deck, { pad = 96, maxZoom = 2 } = {}) {
  if (!rect || !rect.w || !rect.h) return { zoom: fitZoom(vp, deck), pan: { x: 0, y: 0 } };
  const z = Math.min(maxZoom, clampZoom(Math.min((vp.w - pad) / rect.w, (vp.h - pad) / rect.h)));
  const cx = rect.x + rect.w / 2;
  const cy = rect.y + rect.h / 2;
  // we want the rect centre at the viewport centre: origin + c * z = vp / 2
  const ox = vp.w / 2 - cx * z;
  const oy = vp.h / 2 - cy * z;
  return { zoom: z, pan: clampPan({ x: ox - (vp.w - deck.w * z) / 2, y: oy - (vp.h - deck.h * z) / 2 }, vp, deck, z) };
}

export function normalizeWheel(e, vp = { w: 800, h: 600 }) {
  let dx = e.deltaX || 0;
  let dy = e.deltaY || 0;
  if (e.deltaMode === 1) { dx *= 16; dy *= 16; } else if (e.deltaMode === 2) { dx *= vp.w; dy *= vp.h; }
  if (e.shiftKey && !dx) { dx = dy; dy = 0; }
  return { dx, dy };
}

/** Trackpad pinch arrives as ctrl+wheel with small deltas; a mouse wheel with ctrl has large steps. */
export function wheelZoomFactor(deltaY, pinch = false) {
  const k = pinch ? 0.01 : 0.0025;
  const d = Math.max(-120, Math.min(120, deltaY));
  return Math.exp(-d * k);
}

export function pinchStep(prev, next) {
  const d0 = Math.hypot(prev.b.x - prev.a.x, prev.b.y - prev.a.y) || 1;
  const d1 = Math.hypot(next.b.x - next.a.x, next.b.y - next.a.y) || 1;
  const c0 = { x: (prev.a.x + prev.b.x) / 2, y: (prev.a.y + prev.b.y) / 2 };
  const c1 = { x: (next.a.x + next.b.x) / 2, y: (next.a.y + next.b.y) / 2 };
  return { scale: d1 / d0, cx: c1.x, cy: c1.y, dx: c1.x - c0.x, dy: c1.y - c0.y };
}

export function snapZoom(z) {
  return Math.abs(z - 1) < 0.02 ? 1 : z;
}

export function stepZoomTo(z, dir) {
  if (dir > 0) return LADDER.find((s) => s > z + 1e-6) ?? MAX_ZOOM;
  const lower = LADDER.filter((s) => s < z - 1e-6);
  return lower.length ? lower[lower.length - 1] : MIN_ZOOM;
}
