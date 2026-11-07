/**
 * What a person chose to ignore in the review, kept per deck in localStorage (pure; the storage is injected and every
 * access is guarded, so a blocked or full storage only means the choice is not remembered).
 *
 *   storage = {get(key) -> string|null, set(key, string)}
 *   loadIgnores(storage, deckId)        -> {ids: [issue id], kinds: [issue kind]}
 *   saveIgnores(storage, deckId, ig)    -> boolean (false when it could not be saved)
 *   isIgnored(ig, issue)                -> true when the issue itself or its kind is ignored
 *   ignoreIssue(ig, issue) / ignoreKind(ig, issue) / restoreIssue(ig, issue) / restoreKind(ig, kind) / restoreAll()
 *
 * Issue ids are stable hashes of kind, slide, element and message, so an ignored issue stays ignored until its
 * wording changes, which happens when the problem itself changes.
 */
const KEY = (deckId) => `press:review:ignore:${deckId}`;
const MAX_IDS = 400;
const MAX_KINDS = 60;

export const emptyIgnores = () => ({ ids: [], kinds: [] });

export function loadIgnores(storage, deckId) {
  try {
    const raw = storage.get(KEY(deckId));
    if (!raw) return emptyIgnores();
    const v = JSON.parse(raw);
    return {
      ids: Array.isArray(v.ids) ? v.ids.filter((x) => typeof x === 'string').slice(-MAX_IDS) : [],
      kinds: Array.isArray(v.kinds) ? v.kinds.filter((x) => typeof x === 'string').slice(-MAX_KINDS) : [],
    };
  } catch {
    return emptyIgnores();
  }
}

export function saveIgnores(storage, deckId, ig) {
  try {
    storage.set(KEY(deckId), JSON.stringify({ ids: ig.ids.slice(-MAX_IDS), kinds: ig.kinds.slice(-MAX_KINDS) }));
    return true;
  } catch {
    return false;
  }
}

export const isIgnored = (ig, issue) => ig.kinds.includes(issue.kind) || ig.ids.includes(issue.id);
export const ignoreIssue = (ig, issue) => (ig.ids.includes(issue.id) ? ig : { ...ig, ids: [...ig.ids, issue.id].slice(-MAX_IDS) });
export const ignoreKind = (ig, issue) => (ig.kinds.includes(issue.kind) ? ig : { ...ig, kinds: [...ig.kinds, issue.kind].slice(-MAX_KINDS) });
export const restoreIssue = (ig, issue) => ({ ...ig, ids: ig.ids.filter((x) => x !== issue.id), kinds: ig.kinds.filter((k) => k !== issue.kind) });
export const restoreKind = (ig, kind) => ({ ...ig, kinds: ig.kinds.filter((k) => k !== kind) });
export const restoreAll = () => emptyIgnores();
