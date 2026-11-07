// The review state of the open deck (GET /api/decks/{deck}/reviews), shared by the top bar chip and the approval panel.
//
// The deck status in the document only changes when the document is reloaded, but an approval is decided by other
// people and is lost by any edit. So this asks the server: now, every 45 s while the tab is visible and a review is
// open or the deck is approved, and a few seconds after an edit of an approved deck (the server then reports
// `changed_since_approval`).
import { computed, ref, shallowRef, watch } from 'vue';
import { deriveStatus } from './statusModel.js';

const stores = new WeakMap();
const SLOW_MS = 45000;

export function useDeckStatus(editor) {
  let s = stores.get(editor);
  if (!s) { s = create(editor); stores.set(editor, s); }
  return s;
}

function create(editor) {
  const info = shallowRef(null); // {status, stored_status, approved_at, approved_current, reviews}
  const loading = ref(false);
  const error = ref('');
  const status = computed(() => deriveStatus(info.value, editor.deck.value?.status));
  let timer = null;
  let editTimer = null;
  let started = false;
  let inflight = null;

  async function refresh() {
    if (inflight) return inflight;
    loading.value = true;
    inflight = editor.api.get(`/api/decks/${editor.deckId}/reviews`)
      .then((res) => { info.value = res; error.value = ''; })
      .catch((e) => { error.value = e?.message || 'Could not load the review state.'; })
      .finally(() => { loading.value = false; inflight = null; });
    return inflight;
  }
  function later() {
    clearTimeout(timer);
    if (!started || (typeof document !== 'undefined' && document.hidden)) return;
    const watching = status.value === 'in_review' || status.value === 'approved';
    if (!watching) return;
    timer = setTimeout(async () => { await refresh(); later(); }, SLOW_MS);
  }
  function onVisibility() {
    if (document.hidden) { clearTimeout(timer); return; }
    refresh().then(later);
  }
  function start() {
    if (started) return;
    started = true;
    document.addEventListener('visibilitychange', onVisibility);
    refresh().then(later);
    // An edit can end an approval; ask shortly after the last change.
    stopWatch = watch(() => editor.state.value.doc, () => {
      if (status.value !== 'approved') return;
      clearTimeout(editTimer);
      editTimer = setTimeout(() => { refresh().then(later); }, 3500);
    });
  }
  let stopWatch = null;
  function stop() {
    started = false;
    clearTimeout(timer);
    clearTimeout(editTimer);
    stopWatch?.();
    document.removeEventListener('visibilitychange', onVisibility);
  }
  watch(status, later);

  return { info, status, loading, error, refresh, start, stop, set: (v) => { info.value = v; } };
}
