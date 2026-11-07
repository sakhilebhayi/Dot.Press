/**
 * Browser plumbing for the sync engine and the editor's `api` helper (no Vue). Everything that touches the network or
 * localStorage is injected or guarded so it can be tested under node.
 *
 *   toApiError(err)            axios error -> Error with .status, .retryAfter (seconds), .network, .data
 *   createApi(http)            {get, post, put, patch, delete} returning response data, throwing toApiError errors
 *   createTransport(http, deckId, {beacon})   the transport object createSync() expects
 *   safeStorage(win?)          {get, set, remove, ok()} over localStorage; any failure falls back to memory so autosave
 *                              keeps working (the unsent copy then lives only in this tab)
 */
export function toApiError(err) {
  const res = err?.response;
  if (!res) {
    const e = new Error(err?.code === 'ECONNABORTED' ? 'The request timed out' : 'The network is not reachable');
    e.network = true;
    e.api = true;
    return e;
  }
  const data = res.data;
  const first = data?.errors ? Object.values(data.errors).flat()[0] : null;
  const e = new Error(first || data?.message || `The server answered ${res.status}`);
  e.status = res.status;
  e.api = true;
  e.data = data;
  const retry = Number(res.headers?.['retry-after']);
  if (Number.isFinite(retry) && retry > 0) e.retryAfter = retry;
  return e;
}

export function createApi(http) {
  const run = async (fn) => {
    try {
      const res = await fn();
      return res.data;
    } catch (err) {
      throw err?.api ? err : toApiError(err);
    }
  };
  return {
    get: (url, params, config = {}) => run(() => http.get(url, { params, ...config })),
    post: (url, data, config = {}) => run(() => http.post(url, data, config)),
    put: (url, data, config = {}) => run(() => http.put(url, data, config)),
    patch: (url, data, config = {}) => run(() => http.patch(url, data, config)),
    delete: (url, config = {}) => run(() => http.delete(url, config)),
  };
}

export function createTransport(http, deckId, { beacon = null } = {}) {
  const base = `/api/decks/${deckId}`;
  const api = createApi(http);
  return {
    postOps: (payload) => api.post(`${base}/ops`, payload, { timeout: 30000 }),
    getChanges: (after) => api.get(`${base}/changes`, { after }, { timeout: 30000 }),
    getDocument: () => api.get(`${base}/document`, undefined, { timeout: 30000 }),
    ...(beacon ? { beacon } : {}),
  };
}

/** sendBeacon cannot carry the CSRF header, so the unload flush uses a keepalive fetch (64 KB limit). */
export function createKeepaliveBeacon(deckId, { fetchFn = globalThis.fetch, cookie = () => globalThis.document?.cookie || '' } = {}) {
  return (payload) => {
    if (typeof fetchFn !== 'function') return false;
    const body = JSON.stringify(payload);
    if (body.length > 60000) return false;
    const m = /(?:^|;\s*)XSRF-TOKEN=([^;]+)/.exec(cookie());
    const headers = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (m) headers['X-XSRF-TOKEN'] = decodeURIComponent(m[1]);
    try {
      fetchFn(`/api/decks/${deckId}/ops`, { method: 'POST', body, headers, keepalive: true, credentials: 'same-origin' }).catch(() => {});
      return true;
    } catch {
      return false;
    }
  };
}

export function safeStorage(win = globalThis) {
  const memory = new Map();
  let works = true;
  let area = null;
  try {
    area = win.localStorage;
    const probe = '__press_probe__';
    area.setItem(probe, '1');
    area.removeItem(probe);
  } catch {
    area = null;
    works = false;
  }
  return {
    get(key) {
      if (memory.has(key)) return memory.get(key);
      if (area) { try { return area.getItem(key); } catch { works = false; } }
      return null;
    },
    set(key, value) {
      if (area) {
        try { area.setItem(key, value); memory.delete(key); return; } catch { works = false; }
      }
      memory.set(key, String(value));
    },
    remove(key) {
      if (area) { try { area.removeItem(key); } catch { works = false; } }
      memory.delete(key);
    },
    /** false once localStorage has thrown: unsent changes then survive only while this tab stays open. */
    ok: () => works,
  };
}
