/**
 * The intelligence endpoints behind one small object, and the plain words for their failures (no Vue, node tested).
 *
 *   const intel = createIntel(editor.api, deckId)
 *   intel.analyze()                       POST /api/decks/{deck}/intel/analyze
 *   intel.story(framework)                POST /api/decks/{deck}/intel/story   ('auto' or null = detect)
 *   intel.spine()                         GET  /api/decks/{deck}/intel/spine
 *   intel.spineEdit({type, beat, ...})    POST /api/decks/{deck}/intel/spine   (a proposal; nothing is applied)
 *   intel.suggest({kind, slide, element, text, tone})   POST /api/decks/{deck}/intel/suggest
 *   intel.outline({brief, source, framework, audience, length, tone})   POST /api/ai/outline
 *   intel.frameworks()                    GET  /api/intel/frameworks (cached for the page)
 *   intel.layouts(w, h)                   GET  /api/layout-library (cached per size)
 *
 * describeError(err) -> {kind: 'forbidden'|'throttled'|'validation'|'network'|'missing'|'server', message, retryAfter}
 */
export function describeError(err) {
  const status = err?.status;
  const retryAfter = Number.isFinite(err?.retryAfter) ? err.retryAfter : null;
  if (err?.network || (!status && !err?.data)) return { kind: 'network', message: 'The network is not reachable. Check your connection and try again.', retryAfter };
  if (status === 403 || status === 401) return { kind: 'forbidden', message: status === 401 ? 'Your session ended. Reload the page and sign in again.' : 'You do not have permission to do this on this deck.', retryAfter };
  if (status === 429) return { kind: 'throttled', message: `Too many requests just now. ${retryAfter ? `Try again in ${retryAfter} second${retryAfter === 1 ? '' : 's'}.` : 'Wait a moment and try again.'}`, retryAfter };
  if (status === 422) return { kind: 'validation', message: err.message || 'That request was not accepted.', retryAfter };
  if (status === 404) return { kind: 'missing', message: 'This deck or slide could not be found. It may have been deleted.', retryAfter };
  return { kind: 'server', message: 'Something went wrong on the server. Try again in a moment.', retryAfter };
}

export function createIntel(api, deckId) {
  const base = `/api/decks/${deckId}/intel`;
  let frameworksPromise = null;
  const layoutCache = new Map();
  return {
    analyze: () => api.post(`${base}/analyze`, {}),
    story: (framework) => api.post(`${base}/story`, framework && framework !== 'auto' ? { framework } : {}),
    spine: () => api.get(`${base}/spine`),
    spineEdit: (edit) => api.post(`${base}/spine`, edit),
    suggest: (req) => api.post(`${base}/suggest`, req),
    outline: (req) => api.post('/api/ai/outline', req),
    frameworks() {
      if (!frameworksPromise) {
        frameworksPromise = api.get('/api/intel/frameworks').then((r) => r.frameworks || []).catch((e) => { frameworksPromise = null; throw e; });
      }
      return frameworksPromise;
    },
    layouts(w, h) {
      const key = `${w}x${h}`;
      if (!layoutCache.has(key)) {
        layoutCache.set(key, api.get('/api/layout-library', { w, h }).then((r) => r.data || []).catch((e) => { layoutCache.delete(key); throw e; }));
      }
      return layoutCache.get(key);
    },
  };
}

/** Framework options for a SelectMenu: detect, the server's list in its order, then the free structure last. */
export function frameworkOptions(list, { auto = 'Detect from my slides' } = {}) {
  const real = (list || []).filter((f) => f.id !== 'free');
  const free = (list || []).find((f) => f.id === 'free');
  return [
    { value: 'auto', label: auto },
    ...real.map((f) => ({ value: f.id, label: f.name, hint: f.summary })),
    { value: 'free', label: free?.name || 'Free structure', hint: free?.summary || 'No fixed shape' },
  ];
}
