# R-40 acceptance, part A: checks 1 to 25 (creation and editing)

Tested 2026-11-07 on branch `feat/r40-e2ea` (trunk `feat/r40-press` merged in, security branch included), by driving the real editor in Chrome with real pointer and keyboard events and reading the saved document back through the API. The scripts are in `tests/e2e/press/` (see its README). Result of the last full run: 01 creation 11/11, 02 editing 9/9, 03 arrange 6/6, 04 autosave 6/6, 05 collaboration 4/4, 06 roles 7/7, 07 responsive 11/11, node suite 1232 tests, PHPUnit 1361 tests (4 skipped as before).

A PASS means the step was seen to pass in that run. Where a check has parts that were not driven, the row says so and the status stays PASS only for what was seen.

| # | Check | Result | Evidence (script, step) | Not covered |
|---|---|---|---|---|
| 1 | Blank deck | PASS | `01-creation` steps 1 and 1b: created from the library with a title, 16:9, 4:3 and portrait; saved, one slide | custom size |
| 2 | Deck from a template | PASS after fix | `01-creation` step 2: 20 templates listed with category filter and live previews, "Mining production performance" made 11 slides with sections | the other 19 templates were not each opened |
| 3 | AI outline (local rules) | PASS after fix | `01-creation` step 3: chip says Local rules, outline drafted from a brief and source text | model mode (no key exists) |
| 4 | Review and edit the outline | PASS | `01-creation` step 4: rename a section, edit a claim, delete a slide, add a slide, move a section | |
| 5 | Generate | PASS | `01-creation` step 5: saved sections and claims equal the edited outline, every slide has content | |
| 6 | Add slides | PASS | `01-creation` step 6: New slide with a layout, Ctrl+M, the plus on a row | |
| 7 | Reorder | PASS | `01-creation` step 7: pointer drag of a row, and Alt+Arrow; saved order matches | dragging an element onto another slide in the list |
| 8 | Duplicate | PASS | `01-creation` step 8: Ctrl+D in the list, Ctrl+Shift+D on the canvas, context menu | |
| 9 | Delete | PASS after fix | `01-creation` step 9: Delete, undo from the toast, the last slide is protected and the refusal is told at once | |
| 10 | Sections | PASS | `01-creation` step 10: add, rename, collapse and expand, remove (slides stay) | moving slides between sections |
| 11 | Text | PASS | `02-editing` step 11: Text tool, drag, type, Escape; double click edits in place | |
| 12 | Typography | PASS | `02-editing` step 12: size, line height, letter spacing, alignment, bold, font family, colour token, shrink to fit | lists, links, effects, per word formatting |
| 13 | Images | PASS | `02-editing` step 13: a real 640 x 400 PNG uploaded from the Insert panel; stored as an asset, the picture loads at 640 x 400 | paste and drag and drop of files, replace |
| 14 | Crop and resize | PASS | `02-editing` step 14: crop with drag and wheel, corner handle resize, Alt+Shift+Arrow resize, circle mask | adjustments, flip |
| 15 | Shapes | PASS | `02-editing` step 15: shape from the dock menu, fill change, arrow | text inside a shape, other shape kinds |
| 16 | Tables | PASS | `02-editing` step 16: 3 x 3 table, typed cells, row and column added | merge cells |
| 17 | Charts | PASS | `02-editing` step 17: column chart added, switched to line (data kept), title and legend saved | the other 12 chart types |
| 18 | Chart data | PASS | `02-editing` step 18: grid edits saved and redrawn, a non number is flagged, add row, paste a CSV, import a CSV file recorded as `src.kind=file` with its name | XLSX file |
| 19 | Group | PASS | `03-arrange` step 19: Ctrl+G, group moves together, ungroup | |
| 20 | Layers | PASS after fix | `03-arrange` step 20: order shortcuts and panel buttons, lock stops dragging, unlock from the right click menu, hide | drag reorder in the layers list |
| 21 | Themes | PASS | `03-arrange` step 21: Night briefing applied, token colours follow, three hard coded colours are counted and kept | custom theme editor, brand kits |
| 22 | Change dimensions | PASS after fix | `03-arrange` step 22: 16:9 to 4:3 with "Scale to fit", positions scale, the stage shows 4:3 | the other two modes and the other presets were not driven in the browser (unit tested) |
| 23 | Undo and redo | PASS | `03-arrange` step 23: dock buttons name the action, Ctrl+Z, Ctrl+Shift+Z, one drag is one step, undo reaches back over a resize and a theme change | |
| 24 | Keyboard shortcuts | PASS | `03-arrange` step 24: select all, Escape, Tab and Shift+Tab, nudge 1 and 10, copy and paste, duplicate, Delete, Alt+A align, Ctrl+1 / minus / 0 zoom, Ctrl+B in text, `?` help sheet | Alt+letter alignments other than left |
| 25 | Autosave and recovery | PASS after fixes | `04-autosave` 25a to 25f: autosave with no save button; offline then reconnect; tab closed with unsent changes then Restore; server killed for about 25 seconds; three 500 answers; a refused change (422) | a real expired session (401) was not driven |

Also exercised, outside the 25: the viewer account (`06-roles`: read only badge, tools off, 12 keys and a drag change nothing, the server answers 403 to ops, rename, delete and create, comments allowed) and the editor account (edits and renames, 403 on delete, visibility unchanged); two browsers on one deck (`05-collab-sync`); phone, tablet and laptop in light and dark (`07-responsive`).

## What was broken, and what was fixed

Each fix has a test (node test, PHPUnit, or the e2e step named).

1. **New deck, Templates tab never appeared.** The sheet read `id` but `GET /api/templates` sends `key`. Fixed in `NewDeckSheet.vue` with `normalizeTemplates` and categories (unit tested), a lazy preview per template (`TemplateThumb.vue`), blurb and slide count.
2. **AI outline: "The outline writer answered with something that is not an outline."** The sheet read `outline[].beat` but the route sends `beats[].id` with `slots.bullets` and `needs`. `normalizeOutline` reads the real shape (unit tested). Placeholders that ask for your evidence are labelled in the review. In local mode the "write again" buttons are gone because the rules give the same draft every time (a "Reset my edits" button replaces them); one section can no longer be replaced by the first beat of a new draft.
3. **Inspector had no way to open on a narrow screen.** Added an Inspector button in the top bar (768 and up, pressed state, aria-controls) and in the dock (under 1180), plus an entry in the phone overflow menu. Under 1180 the stage now makes room beside the drawer; under 768 the inspector is a sheet in the layout, below the slide and dock, instead of covering the slide. The drawer sat 52 px too low (its `top` was added to the grid row's own offset).
4. **Inspector panels were icon only and the label truncated.** A panel picker (button naming the active panel with a chevron) opens the list of every panel, grouped Selection, Slide, Deck, with roving keyboard focus. The icon strip stays as a shortcut with `aria-label` and a tooltip on each tab. Decision: the active label lives in the picker, not on the tab, so it can never truncate.
5. **CSS cascade bug across the editor and library.** `base.css` loads after the page stylesheet, so `.pr-btn { display: inline-flex }` beat `.tb-menu`, `.tb-overflow`, `.tb-act`, `.lh-team` and others. The hamburger and the overflow button showed at every width, the actions never collapsed on a phone, and on a 390 px library the search shrank to 8 px. The base rule is now `:where(.pr-btn)` (no specificity) and the top bar rules are more specific. On a phone the library New button is reachable and keeps its accessible name.
6. **Recovery banner shown to a live tab.** About 16 s after load the editor offered its own pending queue back as "unsent changes from an earlier session" while the person was editing. Own record is never offered, and it can no longer hide an older crashed tab's record (also fixed on trunk by another agent; merged, my extra unit test kept).
7. **A remote client's chain of added slides got temporary ids.** The change log carries `id` (stored) and `tid` (temporary); readers used `tid`, so later ops for those slides (titles, moves) never found them and the editors drifted until a reload. `fromChangeLog` in `ops.js`, used by the feed in `sync.js`; the fake server in the tests now logs like the real one. Reproduced with `05-collab-sync` (failed before, passes after) and a node test.
8. **Whole editor scrolled sideways by 11 px** when a panel opened (a focus or scroll into view while the drawer slid in). `.ed` is `overflow: clip` and the inspector tab strip scrolls itself. The e2e runner now checks the shell is never scrolled after any step.
9. **Toast queue.** A "Slide deleted, Undo" toast made the next message wait 8 seconds; a refusal such as "A deck needs at least one slide" came far too late. Warnings and errors now take the screen at once (an error is never pre-empted). Unit tested.
10. **A locked element could not be unlocked on the stage.** It cannot be picked, the hint told the person to use the context menu, and the context menu offered nothing. Right click now selects it and the menu offers Unlock (`contextPick`, unit tested).
11. **Size panel default.** It started on "Scale to fill", which cut the left and right of a 16:9 slide when changed to 4:3. It now starts on "Scale to fit, with bars" (`DEFAULT_MODE`, unit tested); Scale to fill is a choice made after seeing the preview.
12. **Top bar lost the title.** A long save status ("Offline: changes kept on this device") squeezed the deck title to one letter. Saved never truncates, long statuses may, the title keeps a minimum width, and at tablet width the wordmark, avatars and the word Saved give way (still read out by the status role).
13. **Presence ghost on reload.** A reloaded tab met its own earlier self as another collaborator for 20 seconds. The page remembers its previous client id in sessionStorage and sends `replaces` once; the server deletes only the caller's own row (PHPUnit `test_a_reloaded_tab_replaces_its_own_earlier_row_and_never_someone_elses`).

## Rough edges seen and left (decisions needed or too large)

- Clicking a slide row collapses the previous current row's thumbnail, so rows below shift up by about 120 px under the pointer. A double click to rename a row that was not current therefore misses; F2 works. Fixing it needs a different thumbnail layout in the Spine.
- Section headers in the Spine are `aria-hidden` with `tabindex -1` controls: no keyboard path to rename or collapse a section except the slide context menu (Shift+F10, "Start a section here"). A real fix is a listbox item for each section.
- Insert panel: after a shape or text is drawn from the dock, the inspector stays on the panel the person chose (Insert) instead of switching to Shape or Text. Kept on purpose so repeated insertion is not interrupted; the context bar carries the basic controls.
- Upload of an image that already exists says so twice (a line and a card).
- Accessible names of dock and spine buttons include their tooltip text (CSS `content: attr(data-tip)`), so a screen reader hears "Section Start a section at this slide".
- The size panel keeps its "Resized 1 slide" notice after an undo reverts the resize.
- Touch gestures (drag, pinch) were not driven; layout at 390 and 768 was.
