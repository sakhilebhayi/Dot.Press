/**
 * Icon categories, search and grid keyboard movement. Pure.
 *
 *   ICON_CATEGORIES                    [{id, label}] in display order, 'all' first
 *   categoryOf(icon)                   category id for an entry of ICONS: the explicit table first, then tag keywords, else 'symbols'
 *   categorize(icons)                  Map categoryId -> entries
 *   searchIcons(icons, query, category) entries matching every word of the query (id, label, tags), best matches first
 *   moveGridIndex(index, key, cols, count)  next index for an arrow, Home, End or Page key in a grid; -1 when the key is not a move
 */
export const ICON_CATEGORIES = [
  { id: 'all', label: 'All' },
  { id: 'arrows', label: 'Arrows and marks' },
  { id: 'people', label: 'People and places' },
  { id: 'data', label: 'Data and finance' },
  { id: 'comms', label: 'Communication' },
  { id: 'files', label: 'Files and media' },
  { id: 'security', label: 'Security and status' },
  { id: 'symbols', label: 'Ideas and tools' },
];

const TABLE = {
  arrows: ['arrow-right', 'arrow-left', 'arrow-up', 'arrow-down', 'arrow-up-right', 'chevron-right', 'chevron-down', 'check', 'check-circle', 'cross', 'cross-circle', 'plus', 'minus', 'refresh'],
  people: ['user', 'users', 'building', 'home', 'factory', 'hard-hat', 'pickaxe', 'cone', 'truck', 'globe', 'pin', 'compass', 'mountain'],
  data: ['chart-bar', 'chart-line', 'chart-pie', 'trending-up', 'trending-down', 'target', 'money', 'percent', 'scale', 'card', 'cart', 'briefcase', 'layers', 'grid', 'list', 'database', 'server', 'cloud', 'code'],
  comms: ['mail', 'phone', 'chat', 'quote', 'bell', 'share'],
  files: ['document', 'folder', 'link', 'download', 'upload', 'image', 'camera', 'mic', 'play', 'pause', 'search', 'eye', 'edit', 'trash', 'tag', 'bookmark', 'calendar', 'clock'],
  security: ['lock', 'unlock', 'key', 'shield', 'shield-check', 'flag', 'info', 'warning', 'question'],
};
const BY_ID = new Map(Object.entries(TABLE).flatMap(([cat, ids]) => ids.map((id) => [id, cat])));

// Keywords for icons added after this table was written.
const KEYWORDS = [
  ['arrows', ['arrow', 'next', 'back', 'forward']],
  ['people', ['person', 'team', 'office', 'place', 'location', 'map', 'transport']],
  ['data', ['chart', 'analytics', 'finance', 'money', 'price', 'revenue', 'data']],
  ['comms', ['email', 'message', 'call', 'notification']],
  ['files', ['file', 'photo', 'video', 'audio', 'save']],
  ['security', ['secure', 'security', 'safety', 'alert', 'status']],
];

export function categoryOf(icon) {
  if (!icon) return 'symbols';
  const known = BY_ID.get(icon.id);
  if (known) return known;
  const hay = [icon.id, ...(icon.tags || [])].join(' ').toLowerCase();
  for (const [cat, words] of KEYWORDS) if (words.some((w) => hay.includes(w))) return cat;
  return 'symbols';
}

export function categorize(icons) {
  const map = new Map(ICON_CATEGORIES.filter((c) => c.id !== 'all').map((c) => [c.id, []]));
  for (const ic of icons) map.get(categoryOf(ic)).push(ic);
  return map;
}

const norm = (s) => String(s || '').toLowerCase().replace(/[^a-z0-9 ]+/g, ' ').replace(/\s+/g, ' ').trim();

function score(icon, words) {
  const label = norm(icon.label);
  const id = norm(icon.id);
  const tags = (icon.tags || []).map(norm);
  let total = 0;
  for (const w of words) {
    let best = 0;
    if (label === w || id === w) best = 100;
    else if (label.startsWith(w) || id.startsWith(w)) best = 80;
    else if (label.split(' ').some((p) => p.startsWith(w))) best = 70;
    else if (tags.some((t) => t === w)) best = 60;
    else if (tags.some((t) => t.startsWith(w))) best = 45;
    else if (label.includes(w) || id.includes(w)) best = 30;
    else if (tags.some((t) => t.includes(w))) best = 20;
    if (best === 0) return 0;
    total += best;
  }
  return total;
}

export function searchIcons(icons, query = '', category = 'all') {
  const pool = category && category !== 'all' ? icons.filter((i) => categoryOf(i) === category) : icons;
  const words = norm(query).split(' ').filter(Boolean);
  if (!words.length) return pool;
  return pool
    .map((icon, order) => ({ icon, order, s: score(icon, words) }))
    .filter((x) => x.s > 0)
    .sort((a, b) => b.s - a.s || a.order - b.order)
    .map((x) => x.icon);
}

export function moveGridIndex(index, key, cols, count) {
  if (!count) return -1;
  const i = Math.min(Math.max(index, 0), count - 1);
  const at = {
    ArrowRight: i + 1,
    ArrowLeft: i - 1,
    ArrowDown: i + cols,
    ArrowUp: i - cols,
    Home: i - (i % cols),
    End: Math.min(count - 1, i - (i % cols) + cols - 1),
    PageDown: i + cols * 4,
    PageUp: i - cols * 4,
  }[key];
  if (at === undefined) return -1;
  return Math.min(Math.max(at, 0), count - 1);
}
