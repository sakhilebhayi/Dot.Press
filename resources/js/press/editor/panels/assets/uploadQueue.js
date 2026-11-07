/**
 * The upload queue: a small state machine around any `upload` function. Pure (no DOM, no Vue, no axios), so it runs under node:test.
 *
 *   const q = createUploadQueue({ upload, validate, concurrency: 2, onDone })
 *     upload(file, {onProgress(0..1), signal}) -> Promise<{asset, deduplicated?}>
 *     validate(file) -> a plain sentence when the file cannot be sent (the item is then 'failed' without a request), else null
 *     onDone(item)  called once when an item reaches 'done' (item.asset, item.deduplicated)
 *
 *   q.add(files) -> ids       q.cancel(id)      q.retry(id)       q.remove(id)      q.clearFinished()
 *   q.items() -> frozen list  q.subscribe(fn) -> off      q.active() -> number of queued or uploading items
 *
 * States: queued -> uploading -> done | failed | cancelled.   failed and cancelled go back to queued with retry().
 * `done` with deduplicated true means the server already had the same file, so nothing new was stored.
 * Progress only moves forward and stays below 1 until the answer arrives.
 */
export const STATES = Object.freeze(['queued', 'uploading', 'done', 'failed', 'cancelled']);
export const isFinished = (item) => item.state === 'done' || item.state === 'failed' || item.state === 'cancelled';

/** A sentence for a failed upload. 422 shows the server's own words; the others say what to do. */
export function explainUploadError(err) {
  const status = err?.status ?? err?.response?.status;
  if (status === 422) {
    const data = err?.data ?? err?.response?.data;
    const first = data?.errors ? Object.values(data.errors).flat()[0] : null;
    return String(first || err?.message || data?.message || 'That file was not accepted.');
  }
  if (status === 413) return 'That file is too large for this server.';
  if (status === 403) return 'You can view this library but not add files to it.';
  if (status === 401 || status === 419) return 'Your session has ended. Reload the page and try again.';
  if (status === 429) return 'Too many uploads at once. Wait a moment and try again.';
  if (err?.network || err?.code === 'ERR_NETWORK' || !status) return 'The upload could not reach the server. Check your connection.';
  return 'The upload failed. Try again.';
}

export function createUploadQueue({
  upload, validate = () => null, concurrency = 2, onDone = () => {},
} = {}) {
  if (typeof upload !== 'function') throw new Error('createUploadQueue: upload is required');
  let seq = 0;
  let list = [];
  const aborts = new Map();
  const subs = new Set();

  const emit = () => { const snap = list.map((i) => Object.freeze({ ...i })); for (const fn of [...subs]) fn(snap); };
  const find = (id) => list.find((i) => i.id === id);
  const patch = (id, p) => { list = list.map((i) => (i.id === id ? { ...i, ...p } : i)); };

  function pump() {
    let running = list.filter((i) => i.state === 'uploading').length;
    for (const item of list) {
      if (running >= concurrency) break;
      if (item.state !== 'queued') continue;
      running += 1;
      run(item.id);
    }
  }

  async function run(id) {
    const item = find(id);
    if (!item) return;
    const ctl = new AbortController();
    aborts.set(id, ctl);
    patch(id, { state: 'uploading', progress: 0, error: null });
    emit();
    try {
      const res = await upload(item.file, {
        signal: ctl.signal,
        onProgress: (p) => {
          const cur = find(id);
          if (!cur || cur.state !== 'uploading') return;
          const next = Math.min(0.99, Math.max(cur.progress, Number.isFinite(p) ? p : 0));
          if (next !== cur.progress) { patch(id, { progress: next }); emit(); }
        },
      });
      if (find(id)?.state !== 'uploading') return; // cancelled while the answer was on its way
      patch(id, { state: 'done', progress: 1, asset: res?.asset || null, deduplicated: !!res?.deduplicated });
      emit();
      try { onDone(find(id)); } catch { /* the caller's problem must not stall the queue */ }
    } catch (err) {
      const cur = find(id);
      if (!cur) return;
      if (cur.state !== 'uploading') return;
      const aborted = ctl.signal.aborted || err?.name === 'CanceledError' || err?.code === 'ERR_CANCELED';
      patch(id, aborted ? { state: 'cancelled', error: null } : { state: 'failed', error: explainUploadError(err) });
      emit();
    } finally {
      aborts.delete(id);
      pump();
    }
  }

  return {
    add(files) {
      const ids = [];
      for (const file of [...files]) {
        const id = `u${++seq}`;
        const problem = validate(file);
        list = [...list, {
          id, file, name: String(file?.name || 'file'), size: file?.size ?? 0, state: problem ? 'failed' : 'queued', progress: 0, error: problem || null, asset: null, deduplicated: false,
        }];
        ids.push(id);
      }
      emit();
      pump();
      return ids;
    },
    cancel(id) {
      const it = find(id);
      if (!it || (it.state !== 'queued' && it.state !== 'uploading')) return false;
      patch(id, { state: 'cancelled', error: null });
      aborts.get(id)?.abort();
      emit();
      pump();
      return true;
    },
    retry(id) {
      const it = find(id);
      if (!it || (it.state !== 'failed' && it.state !== 'cancelled')) return false;
      const problem = validate(it.file);
      if (problem) { patch(id, { error: problem }); emit(); return false; }
      patch(id, { state: 'queued', progress: 0, error: null });
      emit();
      pump();
      return true;
    },
    remove(id) {
      const it = find(id);
      if (!it) return false;
      if (it.state === 'uploading' || it.state === 'queued') this.cancel(id);
      list = list.filter((i) => i.id !== id);
      emit();
      return true;
    },
    clearFinished() { list = list.filter((i) => !isFinished(i)); emit(); },
    items: () => list.map((i) => Object.freeze({ ...i })),
    active: () => list.filter((i) => i.state === 'queued' || i.state === 'uploading').length,
    subscribe(fn) { subs.add(fn); return () => subs.delete(fn); },
  };
}
