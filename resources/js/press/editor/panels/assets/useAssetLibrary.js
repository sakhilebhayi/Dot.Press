// The asset library's data: GET /api/assets with search, kind, sort and cursor paging (infinite scroll), plus the writes
// (PATCH rename and alt, DELETE with the 409 usage answer, POST poster) and the usage lookup. Signed URLs expire, so refetchLoaded()
// brings back the pages already on screen with fresh URLs.
import { computed, reactive, ref } from 'vue';
import {
  buildParams, mergePage, upsertAsset, describeApiError, refetchLimit, parseDeleteError,
} from './assetsModel.js';

export function useAssetLibrary(editor) {
  const filters = reactive({ q: '', kind: 'all', sort: 'newest' });
  const items = ref([]);
  const loading = ref(false);
  const loadingMore = ref(false);
  const loaded = ref(false);
  const hasMore = ref(false);
  const error = ref('');
  const moreError = ref('');
  let cursor = null;
  let token = 0;

  async function run({ reset, limit = 30 }) {
    const mine = ++token;
    if (reset) { loadingMore.value = false; moreError.value = ''; }
    try {
      const res = await editor.api.get('/api/assets', buildParams({ ...filters, limit, cursor: reset ? null : cursor }));
      if (mine !== token) return;
      items.value = mergePage(items.value, res.data, { replace: reset });
      cursor = res.next_cursor || null;
      hasMore.value = !!res.has_more && !!cursor;
      loaded.value = true;
      error.value = '';
      moreError.value = '';
    } catch (e) {
      if (mine !== token) return;
      const msg = describeApiError(e);
      if (reset) error.value = msg; else moreError.value = msg;
    } finally {
      if (mine === token) { loading.value = false; loadingMore.value = false; }
    }
  }

  /** A new search: clears the list and starts again. */
  function reload() {
    loading.value = true;
    items.value = [];
    hasMore.value = false;
    cursor = null;
    error.value = '';
    return run({ reset: true });
  }
  function loadMore() {
    if (loading.value || loadingMore.value || !hasMore.value) return Promise.resolve();
    loadingMore.value = true;
    moreError.value = '';
    return run({ reset: false });
  }
  /** Keeps what is on screen while it fetches, then swaps in the same items with new signed URLs. */
  function refetchLoaded() {
    return run({ reset: true, limit: refetchLimit(items.value.length) });
  }

  const prepend = (asset) => {
    const matches = (filters.kind === 'all' || filters.kind === asset.kind) && (!filters.q.trim() || String(asset.name).toLowerCase().includes(filters.q.trim().toLowerCase()));
    if (matches && filters.sort === 'newest' && !items.value.some((a) => a.id === asset.id)) items.value = [asset, ...items.value];
    else if (items.value.some((a) => a.id === asset.id)) items.value = upsertAsset(items.value, asset);
  };

  async function save(asset, { name, alt }) {
    const body = {};
    if (name !== undefined && name !== asset.name) body.name = name;
    if (alt !== undefined && (alt || '') !== (asset.alt || '')) body.alt = alt;
    if (!Object.keys(body).length) return asset;
    const res = await editor.api.patch(`/api/assets/${asset.id}`, body);
    items.value = upsertAsset(items.value, res.asset);
    if (editor.assets?.[asset.id]) editor.assets[asset.id] = { ...editor.assets[asset.id], name: res.asset.name, alt: res.asset.alt };
    return res.asset;
  }

  async function remove(asset, { force = false } = {}) {
    try {
      await editor.api.delete(`/api/assets/${asset.id}`, force ? { params: { force: 1 } } : {});
      items.value = items.value.filter((a) => a.id !== asset.id);
      return { ok: true };
    } catch (e) {
      if (e?.status === 404) { items.value = items.value.filter((a) => a.id !== asset.id); }
      return { ok: false, ...parseDeleteError(e) };
    }
  }

  const usage = (asset) => editor.api.get(`/api/assets/${asset.id}/usage`);

  async function setPoster(asset, blob) {
    const form = new FormData();
    form.append('file', blob, 'poster.jpg');
    const res = await editor.api.post(`/api/assets/${asset.id}/poster`, form, { timeout: 0 });
    items.value = upsertAsset(items.value, res.asset);
    return res.asset;
  }

  const filtered = computed(() => filters.q.trim() !== '' || filters.kind !== 'all');

  return {
    filters, items, loading, loadingMore, loaded, hasMore, error, moreError, filtered,
    reload, loadMore, refetchLoaded, prepend, save, remove, usage, setPoster,
  };
}
