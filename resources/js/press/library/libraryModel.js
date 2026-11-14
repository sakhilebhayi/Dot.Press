/**
 * Pure helpers for the library page (node tested).
 *
 *   FILTERS                 the filter tabs, in order: id is the `filter` query of GET /api/decks (null = everything)
 *   SORTS                   the sort choices (`sort` query)
 *   relativeTime(iso, now)  "just now", "5 min ago", "yesterday", "3 Nov", "3 Nov 2025"
 *   sizeLabel(w, h)         "16:9", "4:3", "Portrait", or "1280 x 720"
 *   statusBead(status)      {state, word}  draft = hollow, in_review = a turned square, approved = solid
 *   emptyCopy(filter, q)    the sentence under "Nothing on this line yet."
 *   rowMove(count, i, key)  the index an arrow key moves to in the ledger
 *   pickSelection(rows, wanted, featuredId)  the selected row id, validated against the loaded rows
 *   footCounts(rows, more)  {lead, detail} of the foot line, counted over the rows loaded
 *   SIZES                   the blank deck sizes of POST /api/decks
 *   landingCopy(landing, row)  the words of the arrival row: {headline, welcome, support, nothing}
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
 * Arrow key navigation down a ledger of `count` rows: Up and Down step by one (and stop at the ends), Home and End
 * jump to the first and last. Any other key leaves the index where it is. An index outside the list starts at the top.
 */
export function rowMove(count, i, key) {
  if (!count) return -1;
  if (i < 0 || i >= count) return 0;
  if (key === 'ArrowDown') return Math.min(count - 1, i + 1);
  if (key === 'ArrowUp') return Math.max(0, i - 1);
  if (key === 'Home') return 0;
  if (key === 'End') return count - 1;
  return i;
}

/**
 * The selected row. `wanted` is an id from the address (`?sel=`) or a click: it only counts when that row is among the
 * rows loaded now, so a stale or forged id never selects anything. Otherwise the presentation the landing features, when
 * it is in the list, else the first row, else nothing.
 */
export function pickSelection(rows, wanted, featuredId = null) {
  if (!rows.length) return null;
  const id = (v) => (v === null || v === undefined || v === '' ? null : String(v));
  for (const candidate of [wanted, featuredId]) {
    const hit = rows.find((r) => id(candidate) !== null && String(r.id) === id(candidate));
    if (hit) return hit.id;
  }
  return rows[0].id;
}

/** The foot line, counted over the rows loaded: "8 shown: 3 approved, 1 in review, 4 draft". Never a total of the library. */
export function footCounts(rows, more = false) {
  if (!rows.length) return { lead: 'Nothing shown yet.', detail: '' };
  const n = { approved: 0, in_review: 0, draft: 0 };
  for (const r of rows) n[r.status === 'approved' ? 'approved' : r.status === 'in_review' ? 'in_review' : 'draft'] += 1;
  const parts = [];
  if (n.approved) parts.push(`${n.approved} approved`);
  if (n.in_review) parts.push(`${n.in_review} in review`);
  if (n.draft) parts.push(`${n.draft} draft`);
  return { lead: `${rows.length} shown${more ? ', more to load' : ''}`, detail: parts.join(', ') };
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
 * The words of the arrival row. `landing` is the server's prop: {firstName, company, primary, featured}; `row` is the
 * presentation it features. The headline states what is true now: where the person was last, or that there is nothing yet.
 * The welcome names the person and the support sentence names their company; either may be missing (a person with no
 * name on file, a person with no team), and the sentence is then written without it.
 */
export function landingCopy(landing = {}, row = null) {
  const first = String(landing.firstName || '').trim();
  const company = String(landing.company || '').trim();
  const who = company || 'your team';
  const title = String(row?.title || '').trim();
  let headline = 'Nothing on this line yet.';
  if (row) headline = row.last_opened_at ? `You were last in ${title || 'an untitled presentation'}.` : `Newest in the library: ${title || 'an untitled presentation'}.`;
  return {
    headline,
    welcome: first ? `Welcome, ${first}.` : 'Welcome.',
    support: `These are the presentations ${who} makes.`,
    nothing: `Nothing to open yet. When someone at ${who} makes a presentation or shares one with you, it appears here.`,
  };
}
