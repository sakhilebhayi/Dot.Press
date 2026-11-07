// The comment threads of the open deck, shared by the comments panel and the stage's pins.
//
// One store per editor (a WeakMap, so it dies with the page). The top bar starts it, so pins are on the stage before
// the panel was ever opened; the panel tells it when it is open, which speeds the polling up.
//
//   const c = useComments(editor)
//   c.threads            computed: server threads with unsent local comments laid back on top
//   c.status             'loading' | 'ready' | 'error'    c.error  plain language, or ''
//   c.load()             fetch now          c.start() / c.stop()      polling and visibility handling
//   c.post({body, target, parent})          optimistic; the comment shows at once with a `pending` flag
//   c.retry(tmp), c.discard(tmp)            for a comment whose request failed (`failed`, `error` on the comment)
//   c.edit(id, body), c.remove(comment), c.setResolved(thread, bool)
//   c.seenAt / c.markSeen()                 the unread baseline for this user, kept in localStorage
//
// It also sets `editor.commentPins` (a computed ref) which EditorStage draws.
import { computed, ref, shallowRef } from 'vue';
import { applyTemps, buildPins, failureMessage, postPayload, tempComment } from './commentsModel.js';

const stores = new WeakMap();
const OPEN_MS = 15000;
const IDLE_MS = 60000;

export function useComments(editor) {
  let s = stores.get(editor);
  if (!s) { s = create(editor); stores.set(editor, s); }
  return s;
}

function seenKey(editor) { return `press:comments:seen:${editor.deckId}:${editor.me?.id ?? 0}`; }
function readSeen(editor) {
  try { const v = Number(globalThis.localStorage?.getItem(seenKey(editor))); return Number.isFinite(v) && v > 0 ? v : 0; } catch { return 0; }
}

function create(editor) {
  const base = `/api/decks/${editor.deckId}/comments`;
  const server = shallowRef([]);
  const temps = shallowRef([]);
  const status = ref('loading');
  const error = ref('');
  const panelOpen = ref(false);
  const seenAt = ref(readSeen(editor));
  const threads = computed(() => applyTemps(server.value, temps.value));
  const pins = computed(() => buildPins(threads.value));
  let epoch = 0; // bumped by every local change, so an older fetch cannot undo it
  let loading = null;
  let timer = null;
  let started = false;
  let tmpN = 0;

  editor.commentPins = pins;

  async function load() {
    if (loading) return loading;
    const mine = epoch;
    loading = (async () => {
      try {
        const res = await editor.api.get(base, { filter: 'all' });
        if (mine !== epoch) { schedule(400); return; }
        server.value = Array.isArray(res?.threads) ? res.threads : [];
        status.value = 'ready';
        error.value = '';
      } catch (e) {
        if (status.value !== 'ready') status.value = 'error';
        error.value = e?.status === 429 ? 'Comments are being asked for too often. Waiting a moment.' : (e?.network ? 'No connection. Showing the last comments we had.' : (e?.message || 'Could not load comments.'));
      } finally {
        loading = null;
      }
    })();
    return loading;
  }

  function schedule(ms) {
    if (!started) return;
    clearTimeout(timer);
    if (typeof document !== 'undefined' && document.hidden) return; // resumes on visibilitychange
    timer = setTimeout(async () => { await load(); schedule(panelOpen.value ? OPEN_MS : IDLE_MS); }, ms ?? (panelOpen.value ? OPEN_MS : IDLE_MS));
  }
  function onVisibility() {
    if (typeof document === 'undefined') return;
    if (document.hidden) { clearTimeout(timer); return; }
    load().then(() => schedule());
  }
  function start() {
    if (started) return;
    started = true;
    document.addEventListener('visibilitychange', onVisibility);
    load().then(() => schedule());
  }
  function stop() {
    started = false;
    clearTimeout(timer);
    document.removeEventListener('visibilitychange', onVisibility);
  }
  function setPanelOpen(open) {
    const was = panelOpen.value;
    panelOpen.value = !!open;
    if (open && !was) { load().then(() => schedule()); }
    else schedule();
  }

  // ---- local edits to the lists -------------------------------------------------------------------
  const touch = () => { epoch += 1; };
  function mapComment(id, fn) {
    server.value = server.value.map((t) => {
      if (t.id === id) return fn({ ...t, replies: t.replies }) ?? t;
      if (!t.replies?.some((r) => r.id === id)) return t;
      return { ...t, replies: t.replies.map((r) => (r.id === id ? (fn({ ...r }) ?? r) : r)) };
    });
  }
  function setTemp(tmp, patch) {
    temps.value = temps.value.map((t) => (t.tmp === tmp ? { ...t, comment: { ...t.comment, ...patch } } : t));
  }

  // ---- posting ------------------------------------------------------------------------------------
  function post({ body, target = {}, parent = null }) {
    touch();
    const tmp = `tmp-${Date.now().toString(36)}-${++tmpN}`;
    const comment = tempComment({ tmp, body: body.trim(), me: editor.me, target, parent });
    temps.value = [...temps.value, { tmp, parent_id: parent ? parent.id : null, payload: postPayload({ body, target, parent }), comment }];
    return send(tmp);
  }
  async function send(tmp) {
    const entry = temps.value.find((t) => t.tmp === tmp);
    if (!entry) return null;
    setTemp(tmp, { pending: true, failed: false, error: '' });
    try {
      const res = await editor.api.post(base, entry.payload);
      touch();
      temps.value = temps.value.filter((t) => t.tmp !== tmp);
      if (entry.parent_id != null) {
        server.value = server.value.map((t) => (t.id === entry.parent_id && !t.replies.some((r) => r.id === res.id) ? { ...t, replies: [...t.replies, res] } : t));
      } else if (!server.value.some((t) => t.id === res.id)) {
        server.value = [{ ...res, replies: [] }, ...server.value];
      }
      editor.announce?.(entry.parent_id != null ? 'Reply sent' : 'Comment sent');
      return res;
    } catch (e) {
      setTemp(tmp, { pending: false, failed: true, error: failureMessage(e) });
      editor.announce?.('Comment not sent. Retry is available.');
      return null;
    }
  }
  const retry = (tmp) => send(tmp);
  function discard(tmp) { temps.value = temps.value.filter((t) => t.tmp !== tmp); }

  // ---- changes to existing comments ---------------------------------------------------------------
  async function edit(id, body) {
    const res = await editor.api.patch(`${base}/${id}`, { body });
    touch();
    mapComment(id, (c) => ({ ...c, ...res, replies: c.replies }));
    return res;
  }
  async function remove(comment) {
    await editor.api.delete(`${base}/${comment.id}`);
    touch();
    if (comment.parent_id == null) server.value = server.value.filter((t) => t.id !== comment.id);
    else server.value = server.value.map((t) => (t.id === comment.parent_id ? { ...t, replies: t.replies.filter((r) => r.id !== comment.id) } : t));
  }
  async function setResolved(thread, resolved) {
    const res = await editor.api.post(`${base}/${thread.id}/${resolved ? 'resolve' : 'reopen'}`);
    touch();
    mapComment(thread.id, (c) => ({ ...c, ...res, replies: c.replies }));
    return res;
  }

  function markSeen(at = Date.now()) {
    seenAt.value = at;
    try { globalThis.localStorage?.setItem(seenKey(editor), String(at)); } catch { /* private mode: the marker then resets on reload */ }
  }

  return { threads, pins, status, error, seenAt, panelOpen, load, start, stop, setPanelOpen, post, retry, discard, edit, remove, setResolved, markSeen };
}
