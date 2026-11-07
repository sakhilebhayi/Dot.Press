// localStorage with every access guarded. Failures fall back to an in-memory map for the page's life.
const mem = new Map();
export function lsGet(key, fallback = null) {
  try { const v = globalThis.localStorage?.getItem(key); if (v != null) return JSON.parse(v); } catch { /* blocked or corrupt */ }
  return mem.has(key) ? mem.get(key) : fallback;
}
export function lsSet(key, value) {
  mem.set(key, value);
  try { globalThis.localStorage?.setItem(key, JSON.stringify(value)); } catch { /* quota or blocked */ }
}
