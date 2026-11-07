/**
 * Runs an analysis only when the deck has settled (pure, node tested). The editor autosaves in the background and the
 * analysis reads the SAVED deck on the server, so a check must wait for two things: the person to stop changing the
 * deck for `delay` ms, and the save state to read "saved".
 *
 *   const s = createSettler({ run, delay, isSettled, timers })
 *   s.touch()            the deck changed: mark stale and (re)start the quiet period
 *   s.settledChanged()   the save state changed: if a run was waiting for the save, start the quiet period again (shorter)
 *   s.now()              run immediately (first open, "Check again"), unless a run is already going
 *   s.stop()             cancel timers, ignore results that arrive later
 *   s.state              {stale, waiting, running}
 *   s.onState(fn)        called with the state after every change
 */
export function createSettler({ run, delay = 2500, isSettled = () => true, timers = { setTimeout: (f, ms) => globalThis.setTimeout(f, ms), clearTimeout: (h) => globalThis.clearTimeout(h) }, onState = null }) {
  let timer = null;
  let stopped = false;
  const state = { stale: false, waiting: false, running: false };
  const emit = () => { if (onState) onState({ ...state }); };
  const clear = () => { if (timer !== null) { timers.clearTimeout(timer); timer = null; } };

  async function go() {
    if (stopped) return;
    if (state.running) { state.stale = true; emit(); return; }
    if (!isSettled()) { state.waiting = true; emit(); return; }
    state.waiting = false;
    state.running = true;
    state.stale = false;
    emit();
    try { await run(); } finally {
      state.running = false;
      emit();
    }
    // The deck changed while the check was running: check again once it is quiet.
    if (state.stale && !stopped) schedule(delay);
  }
  function schedule(ms) {
    clear();
    timer = timers.setTimeout(() => { timer = null; go(); }, ms);
  }
  return {
    get state() { return { ...state }; },
    touch() { if (stopped) return; state.stale = true; emit(); schedule(delay); },
    settledChanged() {
      if (stopped || !state.waiting) return;
      if (isSettled()) schedule(Math.min(delay, 600));
    },
    now() { clear(); return go(); },
    stop() { stopped = true; clear(); },
  };
}
