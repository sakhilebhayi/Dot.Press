/**
 * Pure helpers for the library page (node tested).
 *
 *   FILTERS                 the filter tabs, in order: id is the `filter` query of GET /api/decks (null = everything)
 *   SORTS                   the sort choices (`sort` query)
 *   relativeTime(iso, now)  "just now", "5 min ago", "yesterday", "3 Nov", "3 Nov 2025"
 *   sizeLabel(w, h)         "16:9", "4:3", "Portrait", or "1280 x 720"
 *   statusBead(status)      {state, word}  draft = hollow, in_review = a turned square, approved = solid
 *   emptyCopy(filter, q)    the sentence under "Nothing on this line yet."
 *   gridMove(rects, i, key) the index the arrow key moves to in a wall of tiles (rects in reading order)
 *   SIZES                   the blank deck sizes of POST /api/decks
 *   landingCopy(landing)    the words of the landing block: {headline, welcome, support, nothing}
 */
export const FILTERS = [
  { id: null, label: 'All' },
  { id: 'mine', label: 'Mine' },
  { id: 'team', label: 'Team' },
  { id: 'shared', label: 'Shared with me' },
  { id: 'favourites', label: 'Favourites' },
  { id: 'recent', label: 'Recent' },
  { id: 'archived', label: 'Archived' },
];

export const SORTS = [
  { id: 'updated', label: 'Last edited' },
  { id: 'title', label: 'Title' },
  { id: 'created', label: 'Newest' },
];

export const SIZES = [
  { id: '16:9', label: 'Widescreen', note: '16:9', w: 1920, h: 1080 },
  { id: '4:3', label: 'Standard', note: '4:3', w: 1440, h: 1080 },
  { id: 'portrait', label: 'Portrait', note: '9:16', w: 1080, h: 1920 },
  { id: 'custom', label: 'Custom', note: 'your size', w: 1920, h: 1080 },
];

const MIN = 60 * 1000;
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

export function relativeTime(iso, now = Date.now()) {
  if (!iso) return '';
  const t = Date.parse(iso);
  if (Number.isNaN(t)) return '';
  const diff = now - t;
  if (diff < MIN) return 'just now';
  if (diff < 60 * MIN) return `${Math.floor(diff / MIN)} min ago`;
  if (diff < 24 * 60 * MIN) {
    const h = Math.floor(diff / (60 * MIN));
    return `${h} ${h === 1 ? 'hour' : 'hours'} ago`;
  }
  const d = new Date(t);
  const n = new Date(now);
  const startOf = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
  const days = Math.round((startOf(n) - startOf(d)) / (24 * 60 * MIN));
  if (days === 1) return 'yesterday';
  if (days > 1 && days < 7) return `${days} days ago`;
  const base = `${d.getDate()} ${MONTHS[d.getMonth()]}`;
  return d.getFullYear() === n.getFullYear() ? base : `${base} ${d.getFullYear()}`;
}

export function sizeLabel(w, h) {
  const r = w / h;
  const near = (a) => Math.abs(r - a) < 0.01;
  if (near(16 / 9)) return '16:9';
  if (near(4 / 3)) return '4:3';
  if (near(9 / 16)) return 'Portrait';
  return `${w} x ${h}`;
}

export function statusBead(status) {
  if (status === 'approved') return { state: 'solid', word: 'approved' };
  if (status === 'in_review') return { state: 'issue', word: 'in review' };
  return { state: 'hollow', word: 'draft' };
}

export function emptyCopy(filter, q = '') {
  if (q) return `No presentation matches "${q}".`;
  switch (filter) {
    case 'mine': return 'Presentations you create land here.';
    case 'team': return 'Presentations your team makes will appear here.';
    case 'shared': return 'When someone shares a presentation with you, it shows up here.';
    case 'favourites': return 'Star a presentation and it waits here.';
    case 'recent': return 'Open a presentation and it appears here.';
    case 'archived': return 'Archived presentations are kept here until you delete them for good.';
    default: return 'Start with one slide and a claim.';
  }
}

/**
 * Arrow key navigation across a wall. `rects` are {left, top, right, bottom} in reading order.
 * Left and right step by one, up and down go to the tile whose centre is nearest in the line above or below.
 */
export function gridMove(rects, i, key) {
  const n = rects.length;
  if (!n) return -1;
  if (i < 0) return 0;
  if (key === 'ArrowLeft') return Math.max(0, i - 1);
  if (key === 'ArrowRight') return Math.min(n - 1, i + 1);
  if (key === 'Home') return 0;
  if (key === 'End') return n - 1;
  if (key !== 'ArrowUp' && key !== 'ArrowDown') return i;
  const cur = rects[i];
  const cx = (cur.left + cur.right) / 2;
  const dir = key === 'ArrowDown' ? 1 : -1;
  let bestLine = null;
  for (let k = 0; k < n; k += 1) {
    const r = rects[k];
    const below = r.top > cur.top + 4;
    const above = r.top < cur.top - 4;
    if ((dir > 0 && below) || (dir < 0 && above)) {
      const gap = Math.abs(r.top - cur.top);
      if (bestLine === null || gap < bestLine) bestLine = gap;
    }
  }
  if (bestLine === null) return i;
  let best = i;
  let bestDx = Infinity;
  for (let k = 0; k < n; k += 1) {
    const r = rects[k];
    if (Math.abs(Math.abs(r.top - cur.top) - bestLine) > 4 || (dir > 0 ? r.top <= cur.top + 4 : r.top >= cur.top - 4)) continue;
    const dx = Math.abs((r.left + r.right) / 2 - cx);
    if (dx < bestDx) { bestDx = dx; best = k; }
  }
  return best;
}

/** The thumbnail deck for SlideView: the row has w and h and, once the API sends it, the theme. */
export function previewDeck(row) {
  return { w: row.w || 1920, h: row.h || 1080, theme: row.theme || undefined };
}
export function previewSlide(row) {
  return { id: String(row.first_slide ?? row.id), bg: row.preview?.bg ?? null, els: row.preview?.els ?? [] };
}

/**
 * GET /api/templates answers {data: [{key, name, category, category_name, blurb, narrative, slides, theme, aspect, tags}], categories}.
 * The sheet works with {id, name, category, categoryName, blurb, narrative, slides, theme, aspect}; the id is the template key.
 */
export function normalizeTemplates(res) {
  const list = Array.isArray(res) ? res : Array.isArray(res?.data) ? res.data : Array.isArray(res?.templates) ? res.templates : [];
  return list
    .filter((t) => t && (t.key != null || t.id != null) && (t.name || t.title))
    .map((t) => ({
      id: String(t.key ?? t.id),
      name: t.name || t.title,
      category: t.category || 'other',
      categoryName: t.category_name || t.categoryName || '',
      blurb: t.blurb || '',
      narrative: t.narrative || '',
      slides: Number(t.slides) || 0,
      theme: t.theme || null,
      aspect: t.aspect || '16:9',
    }));
}

/** The category chips of the template gallery, in the order the server sent them, only those that hold a template. */
export function templateCategories(list, categories = []) {
  const counts = new Map();
  for (const t of list) counts.set(t.category, (counts.get(t.category) || 0) + 1);
  const known = (Array.isArray(categories) ? categories : []).filter((c) => counts.has(c.id)).map((c) => ({ id: c.id, name: c.name, count: counts.get(c.id) }));
  for (const [id, count] of counts) if (!known.some((c) => c.id === id)) known.push({ id, name: list.find((t) => t.category === id)?.categoryName || id, count });
  return known;
}

/**
 * The words of the landing block. `landing` is the server's prop: {firstName, company, primary, featured}.
 * The welcome names the person and the support sentence names their company; either may be missing (a person
 * with no name on file, a person with no team), and the sentence is then written without it.
 */
export function landingCopy(landing = {}) {
  const first = String(landing.firstName || '').trim();
  const company = String(landing.company || '').trim();
  const who = company || 'your team';
  const reads = !landing.primary || landing.primary.kind !== 'new';
  return {
    headline: 'Make your case, one claim at a time.',
    welcome: first ? `Welcome, ${first}.` : 'Welcome.',
    support: reads
      ? `Dot.Press holds the presentations ${who} makes. Each slide states a claim, and its dot fills in once the slide shows the evidence. You can open, present and export them.`
      : `Dot.Press is where ${who} builds its presentations. Each slide states a claim, and its dot fills in once the slide shows the evidence.`,
    nothing: `Nothing to open yet. When someone at ${who} makes a presentation or shares one with you, it appears here.`,
  };
}
