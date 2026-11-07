/**
 * The contextual bar: which controls for which selection, and where it floats. Pure.
 *
 *   barKind(els)                         'text' | 'picture' | 'shape' | 'line' | 'chart' | 'table' | 'icon' | 'media' | 'group' | 'multi' | null
 *   barControls(kind, {hasPanel, canEdit})  ordered control definitions (5 to 8), controls that need a panel
 *                                        that is not registered are left out
 *   placeBar({anchor, size, viewport, gap, margin})  {x, y, placement}
 *        anchor    screen rect {x, y, w, h} of the selection (rotated bounds)
 *        aboveInset  extra room above the anchor (the rotation handle sticks out there)
 *        placement 'above' | 'below' | 'inside'
 *
 * Control definition: {id, type, label, panel?, kinds?}
 *   type 'toggle' | 'button' | 'menu' | 'stepper' | 'color' | 'range'
 */
import { isLine } from './hit.js';

export function barKind(els) {
  if (!els || !els.length) return null;
  if (els.length > 1) return els.every((e) => e.group && e.group === els[0].group) ? 'group' : 'multi';
  const e = els[0];
  switch (e.type) {
    case 'text': return 'text';
    case 'image': return 'picture';
    case 'shape': return isLine(e) ? 'line' : 'shape';
    case 'chart': return 'chart';
    case 'table': return 'table';
    case 'icon': return 'icon';
    case 'video': case 'audio': return 'media';
    default: return null;
  }
}

const C = (id, type, label, extra = {}) => ({ id, type, label, ...extra });

export const CONTROLS = {
  font: C('font', 'menu', 'Font'),
  size: C('size', 'stepper', 'Size'),
  bold: C('bold', 'toggle', 'Bold'),
  italic: C('italic', 'toggle', 'Italic'),
  underline: C('underline', 'toggle', 'Underline'),
  color: C('color', 'color', 'Text colour'),
  align: C('align', 'menu', 'Align'),
  link: C('link', 'button', 'Link'),
  replace: C('replace', 'button', 'Replace'),
  crop: C('crop', 'button', 'Crop'),
  mask: C('mask', 'menu', 'Mask'),
  alt: C('alt', 'button', 'Alt text'),
  opacity: C('opacity', 'range', 'Opacity'),
  fill: C('fill', 'color', 'Fill'),
  stroke: C('stroke', 'color', 'Stroke'),
  radius: C('radius', 'stepper', 'Corner radius'),
  editData: C('editData', 'button', 'Edit data', { panel: 'chart' }),
  chartType: C('chartType', 'menu', 'Chart type'),
  editText: C('editText', 'button', 'Edit text'),
  addRow: C('addRow', 'button', 'Add row'),
  addCol: C('addCol', 'button', 'Add column'),
  del: C('del', 'menu', 'Delete'),
  merge: C('merge', 'button', 'Merge cells'),
  lineWidth: C('lineWidth', 'stepper', 'Line width'),
  ends: C('ends', 'menu', 'Line ends'),
  dash: C('dash', 'menu', 'Dash'),
  iconColor: C('iconColor', 'color', 'Icon colour'),
  swap: C('swap', 'button', 'Swap icon', { panel: 'icons' }),
  play: C('play', 'toggle', 'Play preview'),
  poster: C('poster', 'button', 'Poster', { panel: 'assets' }),
  loop: C('loop', 'toggle', 'Loop'),
  ungroup: C('ungroup', 'button', 'Ungroup'),
  alignMenu: C('alignMenu', 'menu', 'Align'),
  distribute: C('distribute', 'menu', 'Distribute'),
  group: C('group', 'button', 'Group'),
  matchSize: C('matchSize', 'menu', 'Match size'),
};

const SETS = {
  text: ['font', 'size', 'bold', 'italic', 'underline', 'color', 'align', 'link'],
  picture: ['replace', 'crop', 'mask', 'alt', 'opacity'],
  shape: ['fill', 'stroke', 'radius', 'opacity', 'editText'],
  chart: ['editData', 'chartType'],
  table: ['addRow', 'addCol', 'del', 'merge'],
  line: ['stroke', 'lineWidth', 'ends', 'dash'],
  icon: ['iconColor', 'swap'],
  media: ['play', 'poster', 'loop'],
  group: ['ungroup', 'alignMenu'],
  multi: ['alignMenu', 'distribute', 'group', 'matchSize'],
};

export function barControls(kind, { hasPanel = () => true, canEdit = true } = {}) {
  if (!kind || !canEdit) return [];
  return (SETS[kind] || []).map((id) => CONTROLS[id]).filter((c) => !c.panel || hasPanel(c.panel));
}

export function placeBar({
  anchor, size, viewport, gap = 12, margin = 8, aboveInset = 0,
}) {
  const cx = anchor.x + anchor.w / 2;
  let x = cx - size.w / 2;
  x = Math.min(Math.max(x, margin), Math.max(margin, viewport.w - size.w - margin));
  let placement = 'above';
  let y = anchor.y - aboveInset - gap - size.h;
  if (y < margin) {
    placement = 'below';
    y = anchor.y + anchor.h + gap;
    if (y + size.h > viewport.h - margin) {
      placement = 'inside';
      y = Math.min(Math.max(anchor.y + gap, margin), Math.max(margin, viewport.h - size.h - margin));
    }
  }
  return { x: Math.round(x), y: Math.round(y), placement };
}
