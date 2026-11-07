/**
 * The toast queue. One toast is on screen at a time (one line, bottom left); others wait behind it. The clock is
 * injected so the queue is testable.
 *
 *   const toasts = createToasts({ setTimeout, clearTimeout, defaultMs: 4200 })
 *   toasts.push('Slide deleted', { kind: 'info', action: { label: 'Undo', run }, ms })   -> id
 *   toasts.dismiss(id?)      remove one (default: the one on screen)
 *   toasts.current()         the toast on screen or null: {id, message, kind, action, ms}
 *   toasts.pending()         how many wait behind it
 *   toasts.subscribe(fn)     fn(current) on every change; returns unsubscribe
 *
 * Rules: kinds are info, ok, warn, error. An error stays until dismissed (ms 0 = sticky). A message identical to the
 * one on screen replaces it instead of queueing. A toast with an action lives longer (8 s). At most 6 wait.
 * A warning or an error takes the screen at once from a toast that is only information (an Undo offer, a Saved), so a refused
 * action is never explained eight seconds late. An error on screen is never pre-empted.
 */
const KINDS = new Set(['info', 'ok', 'warn', 'error']);
const MAX_WAITING = 6;

export function createToasts({ setTimeout: st = globalThis.setTimeout, clearTimeout: ct = globalThis.clearTimeout, defaultMs = 4200 } = {}) {
  let seq = 0;
  let queue = [];
  let timer = null;
  const subs = new Set();
  const emit = () => subs.forEach((f) => f(queue[0] || null));

  function arm() {
    if (timer) { ct(timer); timer = null; }
    const t = queue[0];
    if (t && t.ms > 0) timer = st(() => { timer = null; api.dismiss(t.id); }, t.ms);
  }

  const api = {
    push(message, { kind = 'info', action = null, ms } = {}) {
      const k = KINDS.has(kind) ? kind : 'info';
      const life = ms !== undefined ? ms : k === 'error' ? 0 : action ? Math.max(defaultMs, 8000) : defaultMs;
      const head = queue[0];
      if (head && head.message === message && head.kind === k) {
        queue[0] = { ...head, action, ms: life };
        arm();
        emit();
        return head.id;
      }
      const toast = { id: ++seq, message: String(message), kind: k, action, ms: life };
      if ((k === 'warn' || k === 'error') && head && head.kind !== 'error') {
        queue[0] = toast;
        arm();
        emit();
        return toast.id;
      }
      if (queue.length > MAX_WAITING) queue.splice(1, 1);
      queue.push(toast);
      if (queue.length === 1) arm();
      emit();
      return toast.id;
    },
    dismiss(id) {
      if (!queue.length) return;
      const wasHead = id === undefined || queue[0].id === id;
      queue = id === undefined ? queue.slice(1) : queue.filter((t) => t.id !== id);
      if (wasHead) arm();
      emit();
    },
    current: () => queue[0] || null,
    pending: () => Math.max(0, queue.length - 1),
    subscribe(fn) { subs.add(fn); return () => subs.delete(fn); },
    clear() { queue = []; arm(); emit(); },
  };
  return api;
}
