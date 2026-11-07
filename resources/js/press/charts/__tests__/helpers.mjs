// Test helpers: a tiny XML well-formedness checker and a geometry reader for the SVG the engine writes.
import { textWidth } from '../util.js';

const NAME = '[A-Za-z_][\\w.:-]*';

/** Parse XML into a tree, throwing on anything not well formed (tags, attributes, entities). */
export function parseXml(src) {
  let i = 0;
  const root = { name: '#root', attrs: {}, children: [], text: '' };
  const stack = [root];
  const entity = /&(?:amp|lt|gt|quot|apos|#\d+|#x[0-9a-fA-F]+);/g;
  const checkText = (t) => {
    if (/[<>]/.test(t)) throw new Error('raw angle bracket in text: ' + t.slice(0, 40));
    const bare = t.replace(entity, '');
    if (bare.includes('&')) throw new Error('bare ampersand in text: ' + t.slice(0, 40));
    if (/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/.test(t)) throw new Error('control character in text');
  };
  while (i < src.length) {
    if (src[i] === '<') {
      if (src.startsWith('<!--', i)) throw new Error('comment not expected');
      if (src[i + 1] === '/') {
        const end = src.indexOf('>', i);
        const name = src.slice(i + 2, end).trim();
        const top = stack.pop();
        if (!top || top.name !== name) throw new Error(`mismatched close tag </${name}> for <${top && top.name}>`);
        i = end + 1;
        continue;
      }
      const m = new RegExp(`^<(${NAME})`).exec(src.slice(i, i + 80));
      if (!m) throw new Error('bad tag at ' + i + ': ' + src.slice(i, i + 40));
      const el = { name: m[1], attrs: {}, children: [], text: '' };
      i += m[0].length;
      for (;;) {
        while (/\s/.test(src[i])) i++;
        if (src[i] === '/' && src[i + 1] === '>') {
          i += 2;
          stack[stack.length - 1].children.push(el);
          break;
        }
        if (src[i] === '>') {
          i += 1;
          stack[stack.length - 1].children.push(el);
          stack.push(el);
          break;
        }
        const attrRe = new RegExp(`(${NAME})="([^"<]*)"`, 'y');
        attrRe.lastIndex = i;
        const am = attrRe.exec(src);
        if (!am) throw new Error(`bad attribute in <${el.name}> at ${i}: ${src.slice(i, i + 60)}`);
        if (am[1] in el.attrs) throw new Error(`duplicate attribute ${am[1]} on <${el.name}>`);
        checkText(am[2]);
        el.attrs[am[1]] = am[2].replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
        i = attrRe.lastIndex;
      }
    } else {
      const next = src.indexOf('<', i);
      const t = src.slice(i, next === -1 ? src.length : next);
      checkText(t);
      stack[stack.length - 1].text += t;
      i = next === -1 ? src.length : next;
    }
  }
  if (stack.length !== 1) throw new Error('unclosed tag <' + stack[stack.length - 1].name + '>');
  if (root.children.length !== 1) throw new Error('expected one root element');
  return root.children[0];
}

export function walk(node, fn, ctx = { sx: 1, sy: 1, tx: 0, ty: 0 }) {
  fn(node, ctx);
  let c = ctx;
  if (node.attrs && node.attrs.transform) {
    const t = /translate\(([-\d.]+) ([-\d.]+)\)\s*scale\(([-\d.]+)\)/.exec(node.attrs.transform);
    if (t) c = { sx: ctx.sx * Number(t[3]), sy: ctx.sy * Number(t[3]), tx: ctx.tx + Number(t[1]) * ctx.sx, ty: ctx.ty + Number(t[2]) * ctx.sy };
  }
  for (const ch of node.children) walk(ch, fn, c);
}

const decode = (t) => t.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&');

/** Text content with entities decoded, as a reader would see it. */
export function textContent(node) {
  return decode(node.text) + node.children.map(textContent).join('');
}

/** Absolute points visited by an SVG path (endpoints and control points). */
export function pathPoints(d) {
  const toks = d.match(/[a-zA-Z]|-?\d*\.?\d+(?:e[-+]?\d+)?/g) || [];
  const pts = [];
  let x = 0;
  let y = 0;
  let sx = 0;
  let sy = 0;
  let k = 0;
  let cmd = '';
  const num = () => {
    const v = Number(toks[k++]);
    if (!Number.isFinite(v)) throw new Error('bad number in path: ' + d.slice(0, 60));
    return v;
  };
  while (k < toks.length) {
    if (/[a-zA-Z]/.test(toks[k])) cmd = toks[k++];
    const rel = cmd === cmd.toLowerCase();
    const C = cmd.toUpperCase();
    if (C === 'Z') {
      x = sx;
      y = sy;
      continue;
    }
    if (C === 'M' || C === 'L') {
      const a = num();
      const b = num();
      x = rel ? x + a : a;
      y = rel ? y + b : b;
      pts.push([x, y]);
      if (C === 'M') {
        sx = x;
        sy = y;
        cmd = rel ? 'l' : 'L';
      }
    } else if (C === 'H') {
      const a = num();
      x = rel ? x + a : a;
      pts.push([x, y]);
    } else if (C === 'V') {
      const b = num();
      y = rel ? y + b : b;
      pts.push([x, y]);
    } else if (C === 'C') {
      for (let n = 0; n < 3; n++) {
        const a = num();
        const b = num();
        const px = rel ? x + a : a;
        const py = rel ? y + b : b;
        pts.push([px, py]);
        if (n === 2) {
          x = px;
          y = py;
        }
      }
    } else if (C === 'A') {
      num();
      num();
      num();
      num();
      num();
      const a = num();
      const b = num();
      x = rel ? x + a : a;
      y = rel ? y + b : b;
      pts.push([x, y]);
    } else throw new Error('unsupported path command ' + cmd);
  }
  return pts;
}

/**
 * Check that geometry is finite and inside the viewBox and that text anchors and unrotated text boxes do not
 * leave the picture. Returns an array of problem strings (empty when fine).
 */
export function geometryProblems(svgString, tol = 1.5) {
  const root = parseXml(svgString);
  const vb = root.attrs.viewBox.split(' ').map(Number);
  const [, , W, H] = vb;
  const problems = [];
  const inside = (x, y, what) => {
    if (!Number.isFinite(x) || !Number.isFinite(y)) problems.push(`${what}: not finite`);
    else if (x < -tol || y < -tol || x > W + tol || y > H + tol) problems.push(`${what}: (${x.toFixed(1)}, ${y.toFixed(1)}) outside ${W}x${H}`);
  };
  walk(root, (n, c) => {
    const a = n.attrs;
    const tag = n.name;
    const P = (x, y) => [c.tx + x * c.sx, c.ty + y * c.sy];
    const num = (k) => (a[k] === undefined ? undefined : Number(a[k]));
    if (tag === 'rect') {
      if (a.width === undefined) return;
      const [x0, y0] = P(num('x') || 0, num('y') || 0);
      const [x1, y1] = P((num('x') || 0) + num('width'), (num('y') || 0) + num('height'));
      inside(x0, y0, 'rect');
      inside(x1, y1, 'rect');
    } else if (tag === 'circle') {
      const [x, y] = P(num('cx'), num('cy'));
      const r = num('r') * c.sx;
      inside(x - r, y - r, 'circle');
      inside(x + r, y + r, 'circle');
    } else if (tag === 'line') {
      inside(...P(num('x1'), num('y1')), 'line');
      inside(...P(num('x2'), num('y2')), 'line');
    } else if (tag === 'polyline' || tag === 'polygon') {
      a.points.trim().split(/\s+/).forEach((pt) => {
        const [x, y] = pt.split(',').map(Number);
        inside(...P(x, y), tag);
      });
    } else if (tag === 'path' && a.d) {
      for (const [x, y] of pathPoints(a.d)) inside(...P(x, y), 'path');
    } else if (tag === 'text') {
      const x = num('x');
      const y = num('y');
      inside(x, y, 'text anchor');
      const size = num('font-size');
      const str = textContent(n);
      if (!a.transform && Number.isFinite(size)) {
        const w = textWidth(str, size, Number(a['font-weight'] || 400)) / 1.05; // the estimate includes a safety factor
        const anchor = a['text-anchor'] || 'start';
        const left = anchor === 'middle' ? x - w / 2 : anchor === 'end' ? x - w : x;
        if (left < -tol || left + w > W + tol) problems.push(`text "${str.slice(0, 30)}" spans ${left.toFixed(1)}..${(left + w).toFixed(1)} of ${W}`);
        if (y - size * 0.8 < -tol || y + size * 0.3 > H + tol) problems.push(`text "${str.slice(0, 30)}" at y ${y.toFixed(1)} outside ${H}`);
      }
    }
  });
  return problems;
}

export function allAttributeValues(root) {
  const out = [];
  walk(root, (n) => {
    for (const v of Object.values(n.attrs)) out.push(v);
  });
  return out;
}

const hasClass = (n, c) => (n.attrs.class || '').split(' ').includes(c);

/** Bounding box of an element built from rect, circle, path (endpoints), line, text (estimated). */
export function bboxOf(n) {
  const a = n.attrs;
  const N = (k) => Number(a[k]);
  if (n.name === 'rect') return { x: N('x'), y: N('y'), w: N('width'), h: N('height') };
  if (n.name === 'circle') return { x: N('cx') - N('r'), y: N('cy') - N('r'), w: 2 * N('r'), h: 2 * N('r') };
  if (n.name === 'line') return { x: Math.min(N('x1'), N('x2')), y: Math.min(N('y1'), N('y2')), w: Math.abs(N('x1') - N('x2')), h: Math.abs(N('y1') - N('y2')) };
  if (n.name === 'path') {
    const pts = pathPoints(a.d);
    const xs = pts.map((p) => p[0]);
    const ys = pts.map((p) => p[1]);
    return { x: Math.min(...xs), y: Math.min(...ys), w: Math.max(...xs) - Math.min(...xs), h: Math.max(...ys) - Math.min(...ys) };
  }
  if (n.name === 'text') {
    const size = N('font-size');
    const w = textWidth(textContent(n), size, Number(a['font-weight'] || 400)) / 1.05;
    const anchor = a['text-anchor'] || 'start';
    const x = N('x');
    return { x: anchor === 'middle' ? x - w / 2 : anchor === 'end' ? x - w : x, y: N('y') - size * 0.75, w, h: size * 1.05 };
  }
  return null;
}

export const overlaps = (p, q, pad = 0) => p.x < q.x + q.w - pad && q.x < p.x + p.w - pad && p.y < q.y + q.h - pad && q.y < p.y + p.h - pad;

/** Unrotated text boxes of elements with the class, in document order. */
export function textBoxesWithClass(root, cls) {
  const out = [];
  walk(root, (n) => {
    if (n.name === 'text' && hasClass(n, cls) && !n.attrs.transform) out.push({ box: bboxOf(n), text: textContent(n) });
  });
  return out;
}

/** Boxes of every element inside the first group with the class. */
export function boxesInGroup(root, cls) {
  const out = [];
  let found = false;
  walk(root, (n) => {
    if (!found && hasClass(n, cls)) {
      found = true;
      walk(n, (m) => {
        if (m !== n) {
          const b = ['rect', 'circle', 'line', 'path', 'text'].includes(m.name) && !m.attrs.transform ? bboxOf(m) : null;
          if (b) out.push(b);
        }
      });
    }
  });
  return out;
}

export function marksBoxes(root) {
  const out = [];
  walk(root, (n, c) => {
    if (hasClass(n, 'pc-mark') && ['rect', 'circle', 'path'].includes(n.name) && c.sx === 1) {
      try {
        out.push(bboxOf(n));
      } catch {
        /* arcs and the like are skipped */
      }
    }
  });
  return out;
}
