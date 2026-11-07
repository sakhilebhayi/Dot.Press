/**
 * Pure helpers for the asset library panel (GET/PATCH/DELETE /api/assets). No DOM, no Vue.
 *
 *   SORTS, KINDS                       option lists for the controls
 *   buildParams({q, kind, sort, limit, cursor})   query params; empty values are left out
 *   mergePage(list, page, {replace})   list with the page's items appended (or replacing), no duplicate ids, order kept
 *   upsertAsset(list, asset)           list with the asset replaced in place (by id)
 *   usageWords(usedIn)                 {word, short, used}: "Used in 3 decks" for speech, "3 decks" for a narrow card
 *   formatBytes(n), dimensions(asset), kindOf(asset)
 *   parseDeleteError(err)              {type: 'in_use'|'forbidden'|'missing'|'other', message, decks, brandKits}
 *   createRefetchGate({minGap, now})   {attempt() -> bool}  lets one refetch through per gap when images fail to load
 *   replaceableKind(selected)          'image' | 'video' | null for the one selected element that a library file can replace
 *   assetPayload(asset)                the small object the stage's drop code reads from ui.dragging.asset
 */
export const SORTS = [
  { value: 'newest', label: 'Newest first' },
  { value: 'oldest', label: 'Oldest first' },
  { value: 'name', label: 'Name' },
  { value: 'size', label: 'Largest first' },
];
export const KINDS = [
  { value: 'all', label: 'All' },
  { value: 'image', label: 'Pictures' },
  { value: 'video', label: 'Video' },
  { value: 'audio', label: 'Audio' },
];

export function buildParams({
  q = '', kind = 'all', sort = 'newest', limit = 30, cursor = null,
} = {}) {
  const p = {};
  const term = String(q ?? '').trim().slice(0, 100);
  if (term) p.q = term;
  if (kind && kind !== 'all') p.kind = kind;
  if (sort && sort !== 'newest') p.sort = sort;
  if (limit && limit !== 30) p.limit = Math.min(100, Math.max(1, Math.floor(limit)));
  if (cursor) p.cursor = cursor;
  return p;
}

export function mergePage(list, page, { replace = false } = {}) {
  const base = replace ? [] : list;
  const seen = new Set(base.map((a) => a.id));
  const out = [...base];
  for (const a of page || []) {
    if (a && !seen.has(a.id)) { seen.add(a.id); out.push(a); }
  }
  return out;
}

export function upsertAsset(list, asset) {
  return list.map((a) => (a.id === asset.id ? { ...a, ...asset } : a));
}

const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

export function usageWords(usedIn) {
  const decks = usedIn?.decks ?? 0;
  const kits = usedIn?.brand_kits ?? 0;
  if (!decks && !kits) return { word: 'Not used', short: 'Not used', used: false };
  const parts = [];
  if (decks) parts.push(`Used in ${plural(decks, 'deck', 'decks')}`);
  if (kits) parts.push(decks ? plural(kits, 'brand kit', 'brand kits') : `Used in ${plural(kits, 'brand kit', 'brand kits')}`);
  const short = [decks ? plural(decks, 'deck', 'decks') : '', kits ? plural(kits, 'kit', 'kits') : ''].filter(Boolean).join(', ');
  return { word: parts.join(', '), short, used: true };
}

export function formatBytes(n) {
  const v = Number(n);
  if (!Number.isFinite(v) || v < 0) return '';
  if (v < 1024) return `${v} B`;
  if (v < 1024 * 1024) return `${Math.round(v / 1024)} KB`;
  if (v < 1024 * 1024 * 1024) return `${(v / 1024 / 1024).toFixed(v < 10 * 1024 * 1024 ? 1 : 0)} MB`;
  return `${(v / 1024 / 1024 / 1024).toFixed(1)} GB`;
}

export const dimensions = (a) => (a?.width && a?.height ? `${a.width} by ${a.height}` : '');

export function kindOf(asset) {
  const k = asset?.kind;
  if (k === 'video' || k === 'audio' || k === 'image') return k;
  const mime = String(asset?.mime || asset?.mime_type || '');
  if (mime.startsWith('video/')) return 'video';
  if (mime.startsWith('audio/')) return 'audio';
  return 'image';
}

export function parseDeleteError(err) {
  const status = err?.status;
  const data = err?.data || {};
  if (status === 409) {
    return {
      type: 'in_use',
      message: String(data.message || 'This file is still used.'),
      decks: Array.isArray(data.decks) ? data.decks : [],
      brandKits: Array.isArray(data.brand_kits) ? data.brand_kits : [],
    };
  }
  if (status === 403) return { type: 'forbidden', message: String(data.message || err?.message || 'You may not delete this file.'), decks: [], brandKits: [] };
  if (status === 404) return { type: 'missing', message: 'This file is already gone.', decks: [], brandKits: [] };
  return { type: 'other', message: String(err?.message || 'The file could not be deleted.'), decks: [], brandKits: [] };
}

export function createRefetchGate({ minGap = 20000, now = () => Date.now() } = {}) {
  let last = -Infinity;
  return {
    attempt() {
      const t = now();
      if (t - last < minGap) return false;
      last = t;
      return true;
    },
  };
}

export function replaceableKind(selected) {
  if (!Array.isArray(selected) || selected.length !== 1) return null;
  const t = selected[0]?.type;
  return t === 'image' || t === 'video' ? t : null;
}

export function assetPayload(asset) {
  return {
    id: asset.id,
    kind: kindOf(asset),
    width: asset.width ?? null,
    height: asset.height ?? null,
    alt: asset.alt || null,
    url: asset.web || asset.url,
    thumb: asset.thumb || null,
    mime: asset.mime || asset.mime_type || '',
    name: asset.name || '',
  };
}

/** A plain sentence for a failed library request (api errors carry .status and .network). */
export function describeApiError(err, what = 'the library') {
  const status = err?.status;
  if (status === 403) return `You do not have access to ${what}.`;
  if (status === 401 || status === 419) return 'Your session has ended. Reload the page and try again.';
  if (status === 404) return `Could not find ${what}. It may have been removed.`;
  if (status === 422) return String(err?.message || 'That change was not accepted.');
  if (status === 429) return 'Too many requests. Wait a moment and try again.';
  if (err?.network || !status) return `Could not reach ${what}. Check your connection and try again.`;
  return `Something went wrong with ${what}. Try again.`;
}

/** Clamp for a refetch that should bring back everything already on screen, up to one full page of 100. */
export const refetchLimit = (loaded) => Math.min(100, Math.max(30, Math.floor(loaded) || 30));
