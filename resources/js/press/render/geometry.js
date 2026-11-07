// Geometry helpers for shapes, images and masks. Pure, no DOM.

const n2 = (v) => +(+v).toFixed(2);
const clamp = (v, lo, hi, def = lo) => (Number.isFinite(+v) ? Math.min(hi, Math.max(lo, +v)) : def);

export const SHAPE_KINDS = ['rect', 'round', 'ellipse', 'triangle', 'diamond', 'pentagon', 'hexagon', 'star', 'chevron', 'arrow-r', 'arrow-l', 'callout', 'cross', 'heart', 'line', 'arrow'];

function poly(points) {
    return 'M' + points.map(([x, y]) => `${n2(x)} ${n2(y)}`).join('L') + 'Z';
}

function roundedRect(w, h, r) {
    const rr = Math.max(0, Math.min(r, w / 2, h / 2));
    if (rr === 0) return `M0 0H${n2(w)}V${n2(h)}H0Z`;
    return `M${n2(rr)} 0H${n2(w - rr)}A${n2(rr)} ${n2(rr)} 0 0 1 ${n2(w)} ${n2(rr)}V${n2(h - rr)}A${n2(rr)} ${n2(rr)} 0 0 1 ${n2(w - rr)} ${n2(h)}H${n2(rr)}A${n2(rr)} ${n2(rr)} 0 0 1 0 ${n2(h - rr)}V${n2(rr)}A${n2(rr)} ${n2(rr)} 0 0 1 ${n2(rr)} 0Z`;
}

function regularPolygon(sides, w, h, rotate = -Math.PI / 2) {
    const pts = [];
    for (let i = 0; i < sides; i++) {
        const a = rotate + (i * 2 * Math.PI) / sides;
        pts.push([Math.cos(a), Math.sin(a)]);
    }
    // Fit the polygon's bounding box to the element box.
    const xs = pts.map((p) => p[0]);
    const ys = pts.map((p) => p[1]);
    const minX = Math.min(...xs);
    const maxX = Math.max(...xs);
    const minY = Math.min(...ys);
    const maxY = Math.max(...ys);
    return pts.map(([x, y]) => [((x - minX) / (maxX - minX)) * w, ((y - minY) / (maxY - minY)) * h]);
}

function starPoints(w, h, points = 5, inner = 0.382) {
    const pts = [];
    for (let i = 0; i < points * 2; i++) {
        const r = i % 2 === 0 ? 1 : inner;
        const a = -Math.PI / 2 + (i * Math.PI) / points;
        pts.push([Math.cos(a) * r, Math.sin(a) * r]);
    }
    const xs = pts.map((p) => p[0]);
    const ys = pts.map((p) => p[1]);
    const minX = Math.min(...xs);
    const maxX = Math.max(...xs);
    const minY = Math.min(...ys);
    const maxY = Math.max(...ys);
    return pts.map(([x, y]) => [((x - minX) / (maxX - minX)) * w, ((y - minY) / (maxY - minY)) * h]);
}

/**
 * SVG path data for a closed shape in a w x h box. `radius` applies to rect only; `round` is a pill-like rounded
 * rectangle using the theme radius clamped to half the short side. Lines have no closed path: see lineGeometry.
 * @returns {string|null}
 */
export function shapePath(kind, w, h, radius = 0) {
    w = Math.max(0, +w || 0);
    h = Math.max(0, +h || 0);
    switch (kind) {
        case 'rect':
            return roundedRect(w, h, radius);
        case 'round':
            return roundedRect(w, h, radius > 0 ? radius : Math.min(w, h) * 0.18);
        case 'ellipse': {
            const rx = w / 2;
            const ry = h / 2;
            return `M0 ${n2(ry)}A${n2(rx)} ${n2(ry)} 0 1 1 ${n2(w)} ${n2(ry)}A${n2(rx)} ${n2(ry)} 0 1 1 0 ${n2(ry)}Z`;
        }
        case 'triangle':
            return poly([
                [w / 2, 0],
                [w, h],
                [0, h],
            ]);
        case 'diamond':
            return poly([
                [w / 2, 0],
                [w, h / 2],
                [w / 2, h],
                [0, h / 2],
            ]);
        case 'pentagon':
            return poly(regularPolygon(5, w, h));
        case 'hexagon':
            return poly(regularPolygon(6, w, h, 0));
        case 'star':
            return poly(starPoints(w, h));
        case 'chevron': {
            const t = Math.min(w * 0.35, h * 0.6);
            return poly([
                [0, 0],
                [w - t, 0],
                [w, h / 2],
                [w - t, h],
                [0, h],
                [t, h / 2],
            ]);
        }
        case 'arrow-r':
        case 'arrow-l': {
            const head = Math.min(w * 0.45, h * 0.8);
            const sh = h * 0.5;
            const y0 = (h - sh) / 2;
            const pts =
                kind === 'arrow-r'
                    ? [
                          [0, y0],
                          [w - head, y0],
                          [w - head, 0],
                          [w, h / 2],
                          [w - head, h],
                          [w - head, y0 + sh],
                          [0, y0 + sh],
                      ]
                    : [
                          [w, y0],
                          [head, y0],
                          [head, 0],
                          [0, h / 2],
                          [head, h],
                          [head, y0 + sh],
                          [w, y0 + sh],
                      ];
            return poly(pts);
        }
        case 'callout': {
            const bh = h * 0.8;
            const r = Math.min(w, bh) * 0.12;
            const tx = w * 0.18;
            const tw = w * 0.16;
            const body = `M${n2(r)} 0H${n2(w - r)}A${n2(r)} ${n2(r)} 0 0 1 ${n2(w)} ${n2(r)}V${n2(bh - r)}A${n2(r)} ${n2(r)} 0 0 1 ${n2(w - r)} ${n2(bh)}H${n2(tx + tw)}L${n2(tx)} ${n2(h)}L${n2(tx + tw * 0.1)} ${n2(bh)}H${n2(r)}A${n2(r)} ${n2(r)} 0 0 1 0 ${n2(bh - r)}V${n2(r)}A${n2(r)} ${n2(r)} 0 0 1 ${n2(r)} 0Z`;
            return body;
        }
        case 'cross': {
            const a = Math.min(w, h) * 0.34;
            const x0 = (w - a) / 2;
            const y0 = (h - a) / 2;
            return poly([
                [x0, 0],
                [x0 + a, 0],
                [x0 + a, y0],
                [w, y0],
                [w, y0 + a],
                [x0 + a, y0 + a],
                [x0 + a, h],
                [x0, h],
                [x0, y0 + a],
                [0, y0 + a],
                [0, y0],
                [x0, y0],
            ]);
        }
        case 'heart': {
            const sx = w / 100;
            const sy = h / 90;
            const P = (x, y) => `${n2(x * sx)} ${n2(y * sy)}`;
            return `M${P(50, 88)}C${P(18, 62)} ${P(0, 42)} ${P(0, 26)}C${P(0, 10)} ${P(13, 0)} ${P(26, 0)}C${P(37, 0)} ${P(46, 6)} ${P(50, 16)}C${P(54, 6)} ${P(63, 0)} ${P(74, 0)}C${P(87, 0)} ${P(100, 10)} ${P(100, 26)}C${P(100, 42)} ${P(82, 62)} ${P(50, 88)}Z`;
        }
        default:
            return null;
    }
}

/** Dash pattern for a stroke width. */
export function dashArray(dash, sw) {
    const s = Math.max(1, +sw || 1);
    if (dash === 'dash') return { array: `${n2(s * 3)} ${n2(s * 2)}`, cap: 'butt' };
    if (dash === 'dot') return { array: `0.01 ${n2(s * 2.2)}`, cap: 'round' };
    return { array: null, cap: 'butt' };
}

/**
 * A line (kinds `line` and `arrow`) runs horizontally through the vertical centre of its box, from the left edge to
 * the right edge. Rotate the element for other directions. Ends: none | arrow | dot.
 * Returns the (possibly shortened) line and the end marks as path data in box coordinates.
 */
export function lineGeometry(w, h, sw, ends = {}, kind = 'line') {
    const width = Math.max(0, +w || 0);
    const s = Math.max(1, +sw || 1);
    const y = (+h || 0) / 2;
    const norm = (v) => (v === 'arrow' || v === 'dot' ? v : 'none');
    const e = { s: norm(ends?.s), e: norm(ends?.e) };
    if (kind === 'arrow' && !ends?.s && !ends?.e) e.e = 'arrow';
    const head = Math.min(s * 4 + 6, width / 2);
    const dotR = Math.min(s * 1.8 + 2, width / 4);
    let x1 = 0;
    let x2 = width;
    const marks = [];
    if (e.s === 'arrow') {
        marks.push({ d: `M${n2(head)} ${n2(y - head * 0.55)}L0 ${n2(y)}L${n2(head)} ${n2(y + head * 0.55)}Z`, fill: true });
        x1 = head * 0.8;
    } else if (e.s === 'dot') {
        marks.push({ d: circlePath(dotR, y, dotR), fill: true });
        x1 = dotR;
    }
    if (e.e === 'arrow') {
        marks.push({ d: `M${n2(width - head)} ${n2(y - head * 0.55)}L${n2(width)} ${n2(y)}L${n2(width - head)} ${n2(y + head * 0.55)}Z`, fill: true });
        x2 = width - head * 0.8;
    } else if (e.e === 'dot') {
        marks.push({ d: circlePath(width - dotR, y, dotR), fill: true });
        x2 = width - dotR;
    }
    return { x1: n2(x1), x2: n2(Math.max(x1, x2)), y: n2(y), marks, ends: e };
}

function circlePath(cx, cy, r) {
    return `M${n2(cx - r)} ${n2(cy)}A${n2(r)} ${n2(r)} 0 1 1 ${n2(cx + r)} ${n2(cy)}A${n2(r)} ${n2(r)} 0 1 1 ${n2(cx - r)} ${n2(cy)}Z`;
}

/**
 * SVG gradient coordinates (userSpaceOnUse) that match the CSS linear-gradient angle for a w x h box.
 * Angle 0 points to the top, 90 to the right.
 */
export function svgGradientCoords(angle, w, h) {
    const a = (((+angle || 0) % 360) * Math.PI) / 180;
    const sin = Math.sin(a);
    const cos = Math.cos(a);
    const half = (Math.abs(w * sin) + Math.abs(h * cos)) / 2;
    const cx = w / 2;
    const cy = h / 2;
    return { x1: n2(cx - sin * half), y1: n2(cy + cos * half), x2: n2(cx + sin * half), y2: n2(cy - cos * half) };
}

/** A shadow spec to a CSS drop-shadow() argument, using a resolved colour. */
export function dropShadow(shadow, color) {
    if (!shadow || typeof shadow !== 'object' || !color) return null;
    const x = clamp(shadow.x, -400, 400, 0);
    const y = clamp(shadow.y, -400, 400, 0);
    const b = clamp(shadow.blur, 0, 400, 0);
    return `drop-shadow(${n2(x)}px ${n2(y)}px ${n2(b)}px ${color})`;
}

// ---- images ----

/** The visible window in source fractions, clamped so it always lies inside the image. */
export function normalizeCrop(crop) {
    const c = crop && typeof crop === 'object' ? crop : {};
    let w = clamp(c.w ?? 1, 0.01, 1, 1);
    let h = clamp(c.h ?? 1, 0.01, 1, 1);
    const x = clamp(c.x ?? 0, 0, 1 - w, 0);
    const y = clamp(c.y ?? 0, 0, 1 - h, 0);
    return { x, y, w, h };
}

/**
 * Where to place the full source image inside a box so that the crop window shows according to `fit`.
 * `nat` is the natural size of the source. Returns the image rectangle relative to the box.
 *   fill:    the window is stretched to the box
 *   cover:   the window is scaled to cover the box and centred
 *   contain: the window is scaled to fit inside the box and centred
 */
export function imagePlacement({ crop, nat, box, fit = 'cover' }) {
    const c = normalizeCrop(crop);
    const bw = Math.max(1, +box.w || 1);
    const bh = Math.max(1, +box.h || 1);
    const nw = Math.max(1, +nat?.w || bw);
    const nh = Math.max(1, +nat?.h || bh);
    const ww = c.w * nw;
    const wh = c.h * nh;
    if (fit === 'fill') {
        const width = bw / c.w;
        const height = bh / c.h;
        return { left: n2(-c.x * width), top: n2(-c.y * height), width: n2(width), height: n2(height) };
    }
    const scale = fit === 'contain' ? Math.min(bw / ww, bh / wh) : Math.max(bw / ww, bh / wh);
    const width = nw * scale;
    const height = nh * scale;
    const left = (bw - ww * scale) / 2 - c.x * width;
    const top = (bh - wh * scale) / 2 - c.y * height;
    return { left: n2(left), top: n2(top), width: n2(width), height: n2(height) };
}

/** CSS clip-path for an image mask. `none` returns null. */
export function maskClip(mask, radius = 16) {
    switch (mask) {
        case 'round':
            return `inset(0 round ${n2(clamp(radius, 0, 400, 16))}px)`;
        case 'circle':
            return 'circle(closest-side at 50% 50%)';
        case 'ellipse':
            return 'ellipse(50% 50% at 50% 50%)';
        case 'triangle':
            return 'polygon(50% 0%, 100% 100%, 0% 100%)';
        case 'hexagon':
            return 'polygon(25% 3.4%, 75% 3.4%, 100% 50%, 75% 96.6%, 25% 96.6%, 0% 50%)';
        case 'star': {
            const pts = [];
            for (let i = 0; i < 10; i++) {
                const r = i % 2 === 0 ? 50 : 19.1;
                const a = -Math.PI / 2 + (i * Math.PI) / 5;
                pts.push(`${n2(50 + Math.cos(a) * r * 1.0515)}% ${n2(52.5 + Math.sin(a) * r * 1.0515)}%`);
            }
            return `polygon(${pts.join(', ')})`;
        }
        default:
            return null;
    }
}

/** CSS filter for image adjustments `{br,ct,sat,blur,gray,sepia,hue}`. Returns null when nothing changes. */
export function adjustFilter(adj) {
    if (!adj || typeof adj !== 'object') return null;
    const f = [];
    const br = clamp(adj.br, -100, 100, 0);
    const ct = clamp(adj.ct, -100, 100, 0);
    const sat = clamp(adj.sat, -100, 100, 0);
    const blur = clamp(adj.blur, 0, 100, 0);
    const gray = clamp(adj.gray, 0, 100, 0);
    const sepia = clamp(adj.sepia, 0, 100, 0);
    const hue = clamp(adj.hue, -360, 360, 0);
    if (br) f.push(`brightness(${n2(1 + br / 100)})`);
    if (ct) f.push(`contrast(${n2(1 + ct / 100)})`);
    if (sat) f.push(`saturate(${n2(1 + sat / 100)})`);
    if (gray) f.push(`grayscale(${n2(gray / 100)})`);
    if (sepia) f.push(`sepia(${n2(sepia / 100)})`);
    if (hue) f.push(`hue-rotate(${n2(hue)}deg)`);
    if (blur) f.push(`blur(${n2(blur)}px)`);
    return f.length ? f.join(' ') : null;
}
