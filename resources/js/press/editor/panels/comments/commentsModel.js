/**
 * Pure logic for the comments panel (no Vue, no DOM, node tested).
 *
 * Mentions travel in the body as `@[Name](id)`; the server checks the ids and rewrites the names. Here that token is
 * built, found while typing, split for display and flattened for announcements.
 *
 * Threads are what GET /api/decks/{deck}/comments returns: top level comments with `replies`, newest first.
 * A pin is {id, slide_id, x, y, count, resolved}: one per thread that carries a point on a slide.
 */

const MENTION = /@\[([^\]\r\n]{1,80})\]\((\d{1,12})\)/g;
const NAME_JUNK = /[[\]()\r\n]/g;

export const BODY_MAX = 4000;

/** The token the server parses. Brackets and parentheses are removed from the name the same way the server does. */
export function cleanName(name) {
  return String(name ?? '').replace(NAME_JUNK, '').trim().slice(0, 80) || 'Someone';
}

export function mentionToken(person) {
  return `@[${cleanName(person?.name)}](${Number(person.id)})`;
}

/** Body -> [{type: 'text', text} | {type: 'mention', name, id}] for rendering. */
export function parseSegments(body) {
  const text = String(body ?? '');
  const out = [];
  let last = 0;
  for (const m of text.matchAll(MENTION)) {
    if (m.index > last) out.push({ type: 'text', text: text.slice(last, m.index) });
    out.push({ type: 'mention', name: m[1], id: Number(m[2]) });
    last = m.index + m[0].length;
  }
  if (last < text.length) out.push({ type: 'text', text: text.slice(last) });
  return out;
}

/** Body with each mention reduced to `@Name`, for tooltips, live regions and the length of what a person typed. */
export function plainText(body) {
  return String(body ?? '').replace(MENTION, (_m, name) => `@${name}`);
}

/** Ids mentioned in a body, once each, in order. */
export function mentionedIds(body) {
  const ids = [];
  for (const m of String(body ?? '').matchAll(MENTION)) {
    const id = Number(m[2]);
    if (!ids.includes(id)) ids.push(id);
  }
  return ids;
}

/**
 * While typing: is the caret inside an `@name` being written? Returns {start, end, query} or null.
 * The `@` must start the text or follow whitespace or an opening bracket, and the query may not contain a line break,
 * a bracket or run longer than 32 characters, so an e-mail address or a finished token never opens the list.
 */
export function mentionQuery(text, caret) {
  const s = String(text ?? '');
  const at = Math.max(0, Math.min(Number.isInteger(caret) ? caret : s.length, s.length));
  let i = at - 1;
  while (i >= 0 && at - i <= 33) {
    const ch = s[i];
    if (ch === '@') {
      const before = i === 0 ? ' ' : s[i - 1];
      if (!/[\s([{"'“]/.test(before)) return null;
      return { start: i, end: at, query: s.slice(i + 1, at) };
    }
    if (/[\r\n[\]()]/.test(ch)) return null;
    i -= 1;
  }
  return null;
}

/**
 * Replace the `@query` being typed with `@Name ` (what the box shows). Returns {text, caret, pick}; the pick remembers
 * the id so toBody() can write the token when the comment is sent.
 */
export function insertPick(text, query, person) {
  const s = String(text ?? '');
  const pick = { id: Number(person.id), name: cleanName(person.name) };
  const shown = `@${pick.name} `;
  return { text: s.slice(0, query.start) + shown + s.slice(query.end), caret: query.start + shown.length, pick };
}

/** People for the suggestion list: name prefix first, then word prefix, then contains. Never yourself, at most `limit`. */
export function suggestPeople(people, query, { exclude = [], limit = 6 } = {}) {
  const q = String(query ?? '').trim().toLowerCase();
  const skip = new Set(exclude.map(Number));
  const scored = [];
  for (const p of people || []) {
    if (!p || skip.has(Number(p.id)) || p.can_comment === false) continue;
    const name = String(p.name ?? '').toLowerCase();
    let score;
    if (q === '') score = 3;
    else if (name.startsWith(q)) score = 0;
    else if (name.split(/\s+/).some((w) => w.startsWith(q))) score = 1;
    else if (name.includes(q)) score = 2;
    else continue;
    scored.push({ p, score });
  }
  return scored.sort((a, b) => a.score - b.score || String(a.p.name).localeCompare(String(b.p.name))).slice(0, limit).map((x) => x.p);
}

// ---- time ----------------------------------------------------------------------------------------------

/** "just now", "4 min ago", "3 h ago", "yesterday", "5 d ago", then a date. `now` is injected for tests. */
export function timeAgo(iso, now = Date.now()) {
  const t = typeof iso === 'number' ? iso : Date.parse(iso);
  if (!Number.isFinite(t)) return '';
  const s = Math.round((now - t) / 1000);
  if (s < 45) return 'just now';
  const m = Math.round(s / 60);
  if (m < 60) return `${m} min ago`;
  const h = Math.round(m / 60);
  if (h < 24) return `${h} h ago`;
  const d = Math.round(h / 24);
  if (d === 1) return 'yesterday';
  if (d < 14) return `${d} d ago`;
  return new Date(t).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: new Date(t).getFullYear() === new Date(now).getFullYear() ? undefined : 'numeric' });
}

// ---- threads -------------------------------------------------------------------------------------------

/** Server slide ids are integers, editor slide ids are strings, and a not yet saved slide has a temporary id. */
export function serverSlideId(id) {
  if (id === null || id === undefined || id === '') return null;
  return /^\d+$/.test(String(id)) ? Number(id) : null;
}

/** open | resolved | all, optionally limited to one slide (editor slide id). */
export function filterThreads(threads, { state = 'all', slideId = null } = {}) {
  const sid = slideId === null ? null : String(slideId);
  return (threads || []).filter((t) => {
    if (state === 'open' && t.resolved) return false;
    if (state === 'resolved' && !t.resolved) return false;
    if (sid !== null && String(t.slide_id ?? '') !== sid) return false;
    return true;
  });
}

export function countThreads(threads, slideId = null) {
  const all = threads || [];
  const open = all.filter((t) => !t.resolved).length;
  const onSlide = slideId === null ? 0 : all.filter((t) => String(t.slide_id ?? '') === String(slideId)).length;
  return { open, resolved: all.length - open, all: all.length, onSlide };
}

/** Readable name of an element: its name, the start of its text, or its type. */
export function elementLabel(el) {
  if (!el) return 'Element';
  if (el.name) return String(el.name).slice(0, 40);
  const text = docText(el.doc) || (typeof el.text === 'string' ? el.text : '');
  if (text) return `"${text.length > 28 ? `${text.slice(0, 27)}…` : text}"`;
  return ({ text: 'Text box', image: 'Picture', shape: 'Shape', line: 'Line', chart: 'Chart', table: 'Table', icon: 'Icon', video: 'Video', audio: 'Audio' })[el.type] || 'Element';
}

function docText(node, acc = []) {
  if (!node || acc.join('').length > 60) return acc.join(' ').replace(/\s+/g, ' ').trim();
  if (typeof node.text === 'string') acc.push(node.text);
  if (Array.isArray(node.content)) for (const c of node.content) docText(c, acc);
  return acc.join(' ').replace(/\s+/g, ' ').trim();
}

/** Where a thread points, in words: "Whole deck", "Slide 3", "Slide 3, "Revenue"", "Slide 3, point". */
export function scopeLabel(thread, slides) {
  if (thread.slide_id == null) return 'Whole deck';
  const list = slides || [];
  const index = list.findIndex((s) => String(s.id) === String(thread.slide_id));
  if (index < 0) return 'A deleted slide';
  const base = `Slide ${index + 1}`;
  if (thread.element_id) {
    const el = (list[index].els || []).find((e) => e.id === thread.element_id);
    return el ? `${base}, ${elementLabel(el)}` : `${base}, a removed element`;
  }
  return thread.anchor ? `${base}, point` : base;
}

// ---- pins ----------------------------------------------------------------------------------------------

/**
 * One pin per thread that sits on a slide at a point. `count` is the number of replies (the stage draws it in the pin).
 * Failed or pending optimistic threads are pinned too, so a comment placed on the stage shows at once.
 */
export function buildPins(threads) {
  const pins = [];
  for (const t of threads || []) {
    const a = t.anchor;
    if (t.slide_id == null || !a || !Number.isFinite(Number(a.x)) || !Number.isFinite(Number(a.y))) continue;
    pins.push({
      id: t.id,
      slide_id: String(t.slide_id),
      x: Number(a.x),
      y: Number(a.y),
      count: (t.replies || []).length,
      resolved: !!t.resolved,
    });
  }
  return pins;
}

// ---- unread --------------------------------------------------------------------------------------------

/** Everything by someone else in the thread (root or reply) that is newer than `seenAt` (ms). */
export function isUnread(thread, seenAt, meId) {
  const newer = (c) => c.author?.id !== meId && Date.parse(c.updated_at || c.created_at) > seenAt;
  return newer(thread) || (thread.replies || []).some(newer);
}

export function unreadIds(threads, seenAt, meId) {
  return (threads || []).filter((t) => !t.pending && isUnread(t, seenAt, meId)).map((t) => t.id);
}

// ---- optimistic posting --------------------------------------------------------------------------------

/**
 * Put locally written comments (not yet confirmed) back on top of the server's answer, so a poll never makes a
 * comment vanish. temps: [{tmp, parent_id|null, comment}]; a reply whose parent is gone is dropped with it.
 */
export function applyTemps(serverThreads, temps) {
  if (!temps?.length) return serverThreads;
  const threads = serverThreads.map((t) => ({ ...t, replies: [...(t.replies || [])] }));
  const roots = [];
  for (const t of temps) {
    if (t.parent_id == null) { roots.push({ ...t.comment, replies: [] }); continue; }
    const parent = threads.find((x) => x.id === t.parent_id) || roots.find((x) => x.id === t.parent_id);
    if (parent) parent.replies.push(t.comment);
  }
  return [...roots, ...threads];
}

/** A comment as the list shows it before the server has answered. */
export function tempComment({ tmp, body, me, target = {}, parent = null, now = Date.now() }) {
  const iso = new Date(now).toISOString();
  return {
    id: tmp,
    parent_id: parent ? parent.id : null,
    slide_id: parent ? parent.slide_id : (target.slide_id ?? null),
    element_id: parent ? parent.element_id : (target.element_id ?? null),
    anchor: parent ? parent.anchor : (target.anchor ?? null),
    body,
    author: { id: me.id, name: me.name },
    mentions: [],
    resolved: false,
    resolved_at: null,
    resolved_by: null,
    created_at: iso,
    updated_at: iso,
    edited_at: null,
    can_edit: false,
    can_delete: false,
    pending: true,
    failed: false,
  };
}

/** The request body for a new comment. Only fields the server accepts. */
export function postPayload({ body, target = {}, parent = null }) {
  const out = { body: body.trim() };
  if (parent) { out.parent_id = parent.id; return out; }
  if (target.slide_id != null) out.slide_id = target.slide_id;
  if (target.element_id) out.element_id = target.element_id;
  if (target.anchor && Number.isFinite(target.anchor.x) && Number.isFinite(target.anchor.y)) out.anchor = { x: target.anchor.x, y: target.anchor.y };
  return out;
}

/**
 * Where a new comment goes. kind: 'deck' | 'slide' | 'element' | 'point'. Needs the current editor slide (string id),
 * the selected element (optional) and the point from the stage menu (optional).
 */
export function resolveTarget(kind, { slide, element = null, anchor = null }) {
  if (kind === 'deck') return { slide_id: null, element_id: null, anchor: null };
  const slide_id = serverSlideId(slide?.id);
  if (slide_id === null) return null; // the slide is not saved yet
  if (kind === 'slide') return { slide_id, element_id: null, anchor: null };
  if (kind === 'element') return element ? { slide_id, element_id: element.id, anchor: anchor ? { x: anchor.x, y: anchor.y } : cornerOf(element) } : null;
  if (kind === 'point') return anchor ? { slide_id, element_id: anchor.element || null, anchor: { x: anchor.x, y: anchor.y } } : null;
  return null;
}

function cornerOf(el) {
  const x = Number(el.x), y = Number(el.y), w = Number(el.w), h = Number(el.h);
  if (![x, y, w, h].every(Number.isFinite)) return null;
  return { x: Math.round(x + w), y: Math.round(y) }; // top right corner, where a pin does not hide the element
}

/** Plain language for a failed request. */
export function failureMessage(err) {
  if (!err) return 'Could not send the comment.';
  if (err.network) return 'No connection. Your comment is kept here until you retry.';
  if (err.status === 403) return 'You are not allowed to comment on this deck.';
  if (err.status === 429) return err.retryAfter ? `Too many comments in a row. Try again in ${err.retryAfter} seconds.` : 'Too many comments in a row. Try again in a moment.';
  if (err.status === 404) return 'That comment or slide no longer exists.';
  return err.message || 'Could not send the comment.';
}

// ---- typing with names, sending with tokens -----------------------------------------------------------

/** A stored body as the text box shows it: `@Name` for every mention, plus the picks that remember the ids. */
export function toDisplay(body) {
  const picks = [];
  const text = String(body ?? '').replace(MENTION, (_m, name, id) => {
    picks.push({ id: Number(id), name });
    return `@${name}`;
  });
  return { text, picks };
}

/**
 * The text box content back into a body with tokens. Every pick claims the first `@Name` occurrence nobody else claimed
 * (a name must end at a word boundary, so `@Ann` never claims the start of `@Anna`). A pick whose `@Name` was typed
 * over or deleted is simply dropped.
 */
export function toBody(text, picks) {
  const s = String(text ?? '');
  const claims = [];
  for (const pick of picks || []) {
    const needle = `@${pick.name}`;
    let from = 0;
    for (;;) {
      const i = s.indexOf(needle, from);
      if (i < 0) break;
      const end = i + needle.length;
      const boundary = end >= s.length || !/[\p{L}\p{N}_]/u.test(s[end]);
      const free = !claims.some((c) => i < c.end && end > c.start);
      const startOk = i === 0 || !/[\p{L}\p{N}_]/u.test(s[i - 1]);
      if (boundary && free && startOk) { claims.push({ start: i, end, pick }); break; }
      from = i + 1;
    }
  }
  claims.sort((a, b) => a.start - b.start);
  let out = '';
  let at = 0;
  for (const c of claims) {
    out += s.slice(at, c.start) + mentionToken(c.pick);
    at = c.end;
  }
  return out + s.slice(at);
}

/** Length the server will count: the body with tokens. Used for the counter. */
export function bodyLength(text, picks) {
  return toBody(text, picks).length;
}
