/**
 * Persistence of the unsent queue for sync.js. One record per tab (client id) plus an index of the
 * client ids that have a record, so a closed or crashed tab can be recovered by the next one.
 * All writes go through one promise chain so an async storage cannot reorder them; storage errors
 * are swallowed (saving to the server must never depend on storage) and counted in `failures`.
 *
 *   const p = createPersistence({storage, clientId, deckId})
 *   p.write(record | null)         store this tab's record, or remove it when null
 *   p.read(now, aliveMs, {self})   the newest recoverable record of another (dead) tab, or null. This tab's own record is the
 *                                  live queue in memory, never an "earlier session", so it is only read when `self` is true
 *   p.forget(clientId)             remove a recovered record
 *   p.settled()                    promise of all queued writes
 */
export function createPersistence({ storage, clientId, deckId }) {
  const recKey = (cid) => `press:sync:${deckId}:${cid}`;
  const idxKey = `press:sync:${deckId}:clients`;
  let chain = Promise.resolve();
  const api = { failures: 0 };
  const enqueue = (fn) => {
    chain = chain.then(fn).catch(() => { api.failures += 1; });
    return chain;
  };
  const readIndex = async () => {
    try { return JSON.parse((await storage.get(idxKey)) || '[]'); } catch { return []; }
  };

  api.write = (record) => enqueue(async () => {
    const idx = await readIndex();
    if (record) {
      await storage.set(recKey(clientId), JSON.stringify(record));
      if (!idx.includes(clientId)) await storage.set(idxKey, JSON.stringify([...idx, clientId]));
    } else {
      await storage.remove(recKey(clientId));
      if (idx.includes(clientId)) await storage.set(idxKey, JSON.stringify(idx.filter((c) => c !== clientId)));
    }
  });

  api.forget = (cid) => enqueue(async () => {
    await storage.remove(recKey(cid));
    const idx = await readIndex();
    await storage.set(idxKey, JSON.stringify(idx.filter((c) => c !== cid)));
  });

  api.read = async (now, aliveMs, { self = false } = {}) => {
    await chain;
    let best = null;
    for (const cid of await readIndex()) {
      let rec;
      try { rec = JSON.parse(await storage.get(recKey(cid))); } catch { continue; }
      if (!rec) continue;
      if (cid === clientId) { if (!self) continue; } // our own record: the queue we hold in memory, not an earlier session
      else if (now - rec.savedAt < aliveMs) continue; // another tab that is still alive
      if (!best || rec.savedAt > best.savedAt) best = rec;
    }
    return best;
  };

  api.settled = () => chain;
  return api;
}
