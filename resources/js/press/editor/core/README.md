# Editor core

Pure JavaScript (ESM, no DOM, no Vue, no dependencies) that holds all editor logic. The Vue shell wraps it. Run the tests with:

```
node --test "resources/js/press/editor/core/__tests__/*.test.mjs"
```

(Node 22 and later need the glob, a bare directory path is not accepted.) The shared op vectors live in `tests/fixtures/press-ops/`.

Import from `./index.js` or from a single module. Every module has its API in its header comment; this page is the map.

## The document

`createDoc(serverDocument)` returns `{deck, slides[], seq, assets}`. Slides are held in order and `deck.slide_order` is kept in sync; slide ids are strings. Documents are never mutated: every function returns new objects and keeps the identity of everything that did not change, so `===` means "unchanged" (use `shallowRef`).

| module | what it gives you |
|---|---|
| `doc.js` | `createDoc`, `docToServerShape`, element factories `newText newShape newImage newChart newTable newIcon newVideo newAudio` (sizes follow the deck width, colours are theme tokens), `newId`, `findEl`, `slideOf`, `elementsInGroup`, `bounds`, `textDoc`, `docText` |
| `ops.js` | `applyOps(doc, ops) -> {doc, applied, inverse, guards, skipped}`: every op of spec section 8, exact inverses, skipped never thrown. `dupToAdd`, `remapOps`, `remapDoc` |
| `resize.js` | the `deck.resize` geometry (scale, fit, anchor) |
| `theme.js` | `resolveColor`, `resolveFill`, `contrastRatio`, `nearestToken`, `resolveFont`, `mapColors`, `applyTheme`, `DEFAULT_THEME` |
| `geometry.js` | rotation aware bounds and hit tests, `resizeFromHandle` (8 handles, aspect, from centre), `rotationFromPointer` (shift snaps to 15), group transform, flip, `growVertically` |
| `snap.js` | `snapMove` (slide edges, centres, margins, other elements, equal spacing, grid; threshold in screen px / zoom) returning the snapped position, `guides` and `labels` to draw; `snapResize`; `measure` |
| `align.js` | `alignPatches`, `distributePatches`, `tidyPatches`, `matchSizePatches` (groups are one unit) |
| `margins.js` | `safeMargin`, `marginRect`, `marginLines`, `gutter` |
| `selection.js` | selection value `{slide, ids}`, `clickSelect`, `pickAt`, `marquee`, `selectAll`, `selectStep`, `selectionBounds`, `layers` |
| `arrange.js` | planners: `layerOps`, `groupOps`, `ungroupOps`, `toggleOps`, `removeOps`, `moveOps`, `patchOps` |
| `clipboard.js` | `serializeElements`, `parseClipboard`, `classifyText`, `pasteElements`, `pasteSlides`, `duplicateElements`, `conformPreview` (paste and conform) |
| `layouts.js` | `applyLayout(doc, slideId, layoutDef)` (never deletes content), `saveLayoutFromSlide`, definition format in the header |
| `history.js` | `createHistory`: undo/redo of op batches, coalescing by key, transactions, 200 step cap, guards so undo never overwrites or resurrects remote changes |
| `sync.js` | `createSync`: autosave, retry, offline queue, recovery, polling, merge of remote changes |
| `store.js` | `createStore`: everything above behind one object |
| `keys.js` | `KEYMAP`, `matchShortcut(event, scope, {mac})`, `formatCombo`, `helpGroups` for the help overlay |

## Using the store from Vue

```js
const sync = createSync({ transport, storage, clock: realClock, clientId: tabUuid, deckId });
sync.init(serverDocument);                       // GET /api/decks/{deck}/document
const rec = await sync.recover();                // offer "Restore unsent changes" when not null
if (rec && userAccepted) await sync.restore(rec); else if (rec) await sync.discardRecovery(rec);
sync.startPolling();
const store = createStore({ sync });
const state = shallowRef(store.getState());
store.subscribe((s) => { state.value = s; });
```

- `transport.postOps({client, key, ops})`, `getChanges(after)`, `getDocument()` wrap `fetch`; reject with an error that has `status` (HTTP code), `retryAfter` (seconds) or `network: true`. Optional `transport.beacon(payload)` for `sync.flush({unloading: true})`.
- `storage` is `{get, set, remove}` over localStorage or IndexedDB (sync or promise returning).
- Shell wiring: `window.onblur` and `visibilitychange` to `sync.blur()` / `sync.setFocused()`, `beforeunload` to `sync.flush({unloading: true})`, `online`/`offline` to `sync.setOnline()`.
- `state.sync.status` is `saved | saving | offline | error` (with counts, `lastError`, `fatal`, `needsAuth`). If `fatal`, offer `sync.discardInflight()`; if `needsAuth`, re-authenticate then `sync.retry()`.
- Keyboard: `store.handleKey(event, scope, {mac})` returns true when it handled the key (then call `preventDefault`). Scopes: `canvas`, `spine`, `text`, `present`.
- Canvas drags: call `store.patch(patches, {undoKey: 'drag:' + id})` on every pointer move; steps with the same key within one second become one undo step. Call `history.breakCoalescing()` is done for you by `undo`, `redo` and `setSlide`.
- Drawing: `snapMove` gives `guides` (lines and equal gap segments) and `labels` in slide units.

## Decisions and assumptions to know

- The server is authoritative for `deck.resize` (scale = larger ratio, fit = smaller, anchor = proportional shift of blocks, 320..7680, followed by a `deck.reload`). The JS geometry is the optimistic local view only.
- The change log is assumed to carry real slide ids (temporary ids already replaced), and a `slide.dup` in the log makes the client reload.
- The client never sends `slide.dup`: `dupToAdd` expands it to a `slide.add` with explicit elements so element ids agree with the server. Undoing a slide removal re-adds under a temporary id (the server refuses all digit tids); the store remaps history and selection through `sync.onIdMap`.
- Base documents follow the server by applying acknowledged ops locally. If the server sanitises a value differently (it prunes text documents and URLs) the difference heals on the next full reload (stale answer, `deck.reload`).
