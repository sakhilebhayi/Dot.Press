// The built in layout library only changes with a release, so it is kept for the life of the page, per slide size.
// Reopening the layout panel then costs nothing against the read rate limit. Failed requests are never kept.
const cache = new Map();

export function cachedLibrary(w, h, fetcher) {
  const key = `${w}x${h}`;
  if (cache.has(key)) return Promise.resolve(cache.get(key));
  return fetcher().then((res) => { cache.set(key, res); return res; });
}

export const clearLibraryCache = () => cache.clear();
