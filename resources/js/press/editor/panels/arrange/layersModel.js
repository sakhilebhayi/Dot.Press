/**
 * The layer list of the Arrange panel (pure; node tested). The slide is shown top to bottom (the top of the list is the front of
 * the slide). Elements that share a `group` form one unit: it is one row, it moves as one block when reordered, and it can be
 * opened to show its members.
 *
 *   elementLabel(el)                       what the row calls an element: its `name`, else the start of its text, else its kind
 *   layerUnits(slide, {selected, collapsed})   [{key, kind: 'el'|'group', id, label, type, ids, members, lock, hide, selected,
 *                                              partial, collapsed}] front first. A group's `ids` are its members front first.
 *   unitsToOrder(units)                    the full id list for an `el.order` op (back to front)
 *   reorderOps(slide, units, from, to)     [el.order] for moving the unit at index `from` to index `to` (front first indexes), or []
 *   renamePatch(el, name)                  {id, p: {name}} (an empty name deletes it) or null when nothing changes
 *   unitToggle(unit, key)                  true when a lock/hide click should turn it on (false: off): off when every member has it
 */
const KIND_LABELS = {
  rect: 'Rectangle', round: 'Rounded rectangle', ellipse: 'Ellipse', triangle: 'Triangle', diamond: 'Diamond', pentagon: 'Pentagon',
  hexagon: 'Hexagon', star: 'Star', chevron: 'Chevron', 'arrow-r': 'Arrow right', 'arrow-l': 'Arrow left', callout: 'Callout', cross: 'Cross',
  heart: 'Heart', line: 'Line', arrow: 'Arrow',
};
const TYPE_LABELS = {
  text: 'Text', shape: 'Shape', image: 'Image', video: 'Video', audio: 'Audio', icon: 'Icon', chart: 'Chart', table: 'Table',
};

function plainText(doc) {
  let s = '';
  const walk = (n) => {
    if (!n || s.length > 80) return;
    if (n.type === 'text') s += n.text || '';
    else if (n.type === 'hardBreak') s += ' ';
    (n.content || []).forEach((c) => { walk(c); if (c.type === 'paragraph' || c.type === 'listItem') s += ' '; });
  };
  walk(doc);
  return s.replace(/\s+/g, ' ').trim();
}

export function elementLabel(el) {
  if (el.name && String(el.name).trim()) return String(el.name).trim();
  if (el.doc) {
    const t = plainText(el.doc);
    if (t) return t.length > 40 ? `${t.slice(0, 39)}…` : t;
  }
  if (el.type === 'shape') return KIND_LABELS[el.kind] || 'Shape';
  if (el.type === 'icon' && el.name) return `Icon ${el.name}`;
  return TYPE_LABELS[el.type] || 'Element';
}

export function layerUnits(slide, { selected = [], collapsed = [] } = {}) {
  const sel = new Set(selected);
  const closed = new Set(collapsed);
  const els = slide?.els || [];
  const byGroup = new Map();
  for (const e of els) if (e.group) { if (!byGroup.has(e.group)) byGroup.set(e.group, []); byGroup.get(e.group).push(e); }
  const units = [];
  const seen = new Set();
  const row = (e) => ({ id: e.id, label: elementLabel(e), type: e.type, kind: e.kind || null, lock: !!e.lock, hide: !!e.hide, role: e.role || null, selected: sel.has(e.id) });
  for (let i = els.length - 1; i >= 0; i -= 1) {
    const e = els[i];
    if (e.group && byGroup.get(e.group).length > 1) {
      if (seen.has(e.group)) continue;
      seen.add(e.group);
      const members = byGroup.get(e.group).slice().reverse();
      const rows = members.map(row);
      const n = rows.filter((r) => r.selected).length;
      units.push({
        key: `g:${e.group}`, kind: 'group', id: e.group, label: `Group of ${rows.length}`, type: 'group', ids: members.map((m) => m.id), members: rows,
        lock: rows.every((r) => r.lock), hide: rows.every((r) => r.hide), selected: n === rows.length, partial: n > 0 && n < rows.length, collapsed: closed.has(e.group),
        anyLock: rows.some((r) => r.lock), anyHide: rows.some((r) => r.hide),
      });
    } else {
      const r = row(e);
      units.push({
        key: `e:${e.id}`, kind: 'el', id: e.id, label: r.label, type: e.type, ids: [e.id], members: [r], lock: r.lock, hide: r.hide, selected: r.selected, partial: false, collapsed: false,
        anyLock: r.lock, anyHide: r.hide, role: r.role, shape: r.kind,
      });
    }
  }
  return units;
}

/** Back to front id list. Group members stay together and keep their own order. */
export function unitsToOrder(units) {
  const out = [];
  for (let i = units.length - 1; i >= 0; i -= 1) out.push(...units[i].ids.slice().reverse());
  return out;
}

export function reorderOps(slide, units, from, to) {
  if (from === to || from < 0 || to < 0 || from >= units.length || to >= units.length) return [];
  const next = units.slice();
  const [moved] = next.splice(from, 1);
  next.splice(to, 0, moved);
  const ids = unitsToOrder(next);
  const now = slide.els.map((e) => e.id);
  if (ids.length === now.length && ids.every((x, i) => x === now[i])) return [];
  return [{ op: 'el.order', slide: slide.id, ids }];
}

export function renamePatch(el, name) {
  const clean = String(name ?? '').replace(/\s+/g, ' ').trim().slice(0, 120);
  const next = clean || null;
  if ((el.name || null) === next) return null;
  return { id: el.id, p: { name: next } };
}

export const unitToggle = (unit, key) => !unit.members.every((m) => m[key]);
