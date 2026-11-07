/**
 * The Spine's list model and the reorder arithmetic (pure, node tested).
 *
 * Rows
 *   buildRows(slides, sections, collapsed:Set) -> [row]
 *     {kind:'section', key, id, title, count, collapsed, empty}      a section header
 *     {kind:'slide', key, slide, number, sectionId}                  one slide (number is the 1 based position in the deck)
 *   Sections group consecutive slides: a run of slides with the same section_id gets one header. The first run with no
 *   section has no header; a later run with no section gets a header with id null ("No section"). Sections without
 *   slides are listed at the end as empty headers so they can still be renamed, filled or removed. Slides of a
 *   collapsed section are left out of the list (the header keeps their count).
 *
 * Dropping
 *   dropGap(mids, y) -> index 0..mids.length: the gap in a list of row midpoints (screen y) that y falls in
 *   dropTarget(rows, gap, movingIds, slides) -> { after, sectionId }
 *     `after` is the slide id the moved slides should follow (null = first), computed ignoring the moving slides;
 *     `sectionId` is the section they join. Landing straight under a header joins that header's section; landing
 *     under a collapsed header goes to the end of that section.
 *   moveOps(slides, movingIds, target) -> [op]    slide.move (chained, in deck order) plus slide.set section_id where it changes
 *   stepTarget(slides, movingIds, dir) -> target | null    Alt+Arrow: move the block one slide up or down
 *   rangeSelect(slides, anchorId, id) -> [ids]    shift click
 */
export function buildRows(slides, sections = [], collapsed = new Set()) {
  const known = new Map(sections.map((s) => [s.id, s]));
  const rows = [];
  let runKey = Symbol('start');
  let current = null;
  let first = true;
  const used = new Set();
  slides.forEach((slide, i) => {
    const sid = slide.section_id && known.has(slide.section_id) ? slide.section_id : null;
    const run = sid ?? '\u0000none';
    if (run !== runKey) {
      runKey = run;
      const showHeader = sid !== null || !first;
      current = null;
      if (showHeader) {
        if (sid) used.add(sid);
        current = { kind: 'section', key: `sec:${sid ?? 'none'}:${i}`, id: sid, title: sid ? known.get(sid).title || 'Untitled section' : 'No section', count: 0, collapsed: sid ? collapsed.has(sid) : false, empty: false };
        rows.push(current);
      }
    }
    first = false;
    if (current) current.count += 1;
    if (!(current && current.collapsed)) rows.push({ kind: 'slide', key: `s:${slide.id}`, slide, number: i + 1, sectionId: sid });
  });
  for (const sec of sections) {
    if (!used.has(sec.id)) rows.push({ kind: 'section', key: `sec:${sec.id}:empty`, id: sec.id, title: sec.title || 'Untitled section', count: 0, collapsed: collapsed.has(sec.id), empty: true });
  }
  return rows;
}

export function dropGap(mids, y) {
  let gap = 0;
  while (gap < mids.length && y > mids[gap]) gap += 1;
  return gap;
}

export function dropTarget(rows, gap, movingIds, slides) {
  const moving = new Set(movingIds);
  // Walk back from the gap to the closest row that decides where we are.
  let after = null;
  let sectionId = null;
  let decided = false;
  for (let i = Math.min(gap, rows.length) - 1; i >= 0; i -= 1) {
    const row = rows[i];
    if (row.kind === 'slide') {
      if (moving.has(row.slide.id)) continue;
      after = row.slide.id;
      sectionId = row.sectionId;
      decided = true;
      break;
    }
    // A header: dropping right under it starts (or, collapsed, ends) that section.
    if (row.id !== null || row.empty === false) {
      sectionId = row.id;
      if (row.collapsed && row.id) {
        const last = [...slides].reverse().find((s) => s.section_id === row.id && !moving.has(s.id));
        after = last ? last.id : null;
      } else {
        after = precedingSlide(rows, i, moving);
      }
      decided = true;
      break;
    }
  }
  if (!decided) { after = null; sectionId = null; }
  return { after, sectionId };
}

function precedingSlide(rows, headerIndex, moving) {
  for (let i = headerIndex - 1; i >= 0; i -= 1) {
    if (rows[i].kind === 'slide' && !moving.has(rows[i].slide.id)) return rows[i].slide.id;
  }
  return null;
}

export function moveOps(slides, movingIds, { after, sectionId }) {
  const moving = new Set(movingIds);
  const ordered = slides.filter((s) => moving.has(s.id));
  if (!ordered.length) return [];
  // No change when the block already sits there, in place and in the same section.
  const rest = slides.filter((s) => !moving.has(s.id));
  const at = after === null ? 0 : rest.findIndex((s) => s.id === after) + 1;
  if (after !== null && at === 0) return [];
  const result = [...rest.slice(0, at), ...ordered, ...rest.slice(at)];
  const sameOrder = result.every((s, i) => s.id === slides[i].id);
  const ops = [];
  let prev = after;
  for (const s of ordered) {
    if (!sameOrder) ops.push({ op: 'slide.move', id: s.id, after: prev });
    prev = s.id;
    if ((s.section_id ?? null) !== (sectionId ?? null)) ops.push({ op: 'slide.set', id: s.id, section_id: sectionId ?? null });
  }
  return ops;
}

export function stepTarget(slides, movingIds, dir) {
  const moving = new Set(movingIds);
  const idx = slides.map((s, i) => (moving.has(s.id) ? i : -1)).filter((i) => i >= 0);
  if (!idx.length) return null;
  const rest = slides.filter((s) => !moving.has(s.id));
  const firstAt = idx[0];
  const lastAt = idx[idx.length - 1];
  if (dir < 0) {
    if (firstAt === 0) return null;
    const over = slides[firstAt - 1];
    const k = rest.findIndex((s) => s.id === over.id);
    return { after: k > 0 ? rest[k - 1].id : null, sectionId: over.section_id ?? null };
  }
  if (lastAt === slides.length - 1) return null;
  const over = slides[lastAt + 1];
  return { after: over.id, sectionId: over.section_id ?? null };
}

export function rangeSelect(slides, anchorId, id) {
  const a = slides.findIndex((s) => s.id === anchorId);
  const b = slides.findIndex((s) => s.id === id);
  if (a < 0 || b < 0) return id ? [id] : [];
  const [lo, hi] = a < b ? [a, b] : [b, a];
  return slides.slice(lo, hi + 1).map((s) => s.id);
}

/** The slide ids a section header stands for, in deck order (used by remove and move to section). */
export function slidesOfSection(slides, sectionId) {
  return slides.filter((s) => (s.section_id ?? null) === (sectionId ?? null)).map((s) => s.id);
}

/**
 * Ops for "Add section here": a new section starts at the slide and takes the rest of that slide's run.
 */
export function addSectionOps(slides, slideId, sectionId, title) {
  const i = slides.findIndex((s) => s.id === slideId);
  if (i < 0) return [];
  const origin = slides[i].section_id ?? null;
  const ops = [{ op: 'section.add', id: sectionId, title }];
  for (let k = i; k < slides.length; k += 1) {
    if ((slides[k].section_id ?? null) !== origin) break;
    ops.push({ op: 'slide.set', id: slides[k].id, section_id: sectionId });
  }
  return ops;
}

/**
 * The drop gap in a wrapped grid (the sheets view). `rects` are the rows in list order as
 * {left, top, right, bottom, header}; a header spans the full width. Returns a gap index 0..rects.length.
 * A pointer on the left half of a cell drops before it, on the right half after it; a header takes the whole line.
 */
export function gridGap(rects, x, y) {
  if (!rects.length) return 0;
  let best = -1;
  let bestDist = Infinity;
  rects.forEach((r, i) => {
    const dy = y < r.top ? r.top - y : y > r.bottom ? y - r.bottom : 0;
    const dx = x < r.left ? r.left - x : x > r.right ? x - r.right : 0;
    const d = dy * 4 + dx; // the line matters more than the column
    if (d < bestDist) { bestDist = d; best = i; }
  });
  const r = rects[best];
  if (r.header) return y < (r.top + r.bottom) / 2 ? best : best + 1;
  return x < (r.left + r.right) / 2 ? best : best + 1;
}
