# Dot.Press wiki: where things are

Written for R-40 (2026-11-07). The contract for the document and its operations is `docs/programme/R40_DOCUMENT_SPEC.md`; read it before changing anything that stores or renders a deck.

## Backend (app/)

| Area | Where | Notes |
|---|---|---|
| Document and operations | `Services/Press/{DocumentSanitizer,OpApplier,DeckBatch,Resize,DeckDocument,DeckHash}.php`, `Http/Controllers/Api/DeckDocumentController.php` | Every change is an op batch applied in one transaction, logged in `deck_changes` (seq), idempotent by key |
| Versions | `Services/Press/VersionService.php` | Auto snapshot after 20 minutes of changes, named versions, restore keeps a safety version, 40 autos kept |
| Access | `Services/Press/DeckAccess*.php`, `Policies/`, `Models/Scopes/` | Roles owner, editor, commenter, viewer; private decks are invisible to everyone not named |
| Sharing, comments, reviews | `Services/Press/{Sharing,Comments,Reviews}*`, `Http/Controllers/Api/Deck{Share,Comment,Review,Activity,People}Controller.php`, `Http/Controllers/SharedDeckController.php` | Public links are 40 character capability tokens with optional password and expiry |
| Assets | `Services/Press/Assets/*`, `Http/Controllers/Api/AssetController.php` | Content sniffing, GD re-encode, SVG sanitiser, variants, Range streaming, relative signed URLs |
| Themes, layouts, templates | `Services/Press/{ThemeLibrary,LayoutLibrary,TemplateLibrary}.php`, `Services/Press/Templates/` | 12 themes, 14 layouts, 20 templates, all test-checked |
| Intelligence | `Services/Press/Intel/*`, `Services/Press/Data/*` | Local rules by default, model only with a key, always proposals |
| Export and import | `Services/Press/Export/*`, `Services/Press/Import/*`, `Jobs/RunDeckExport.php` | PDF (DomPDF), PPTX (OOXML), preflight, queue with a fast path for small decks |
| Presenting | `Services/Press/{PresentPayload,PresentPackage}.php`, `Http/Controllers/Api/PresentSessionController.php` | Live sessions poll once a second; notes never reach followers |
| Pages | `Http/Controllers/{DeckPageController,PrintController}.php` | Library, editor, print view |

## Frontend (resources/js/)

| Area | Where |
|---|---|
| Pages | `Pages/Press/{Library,Editor,Player,Presenter,Audience,Remote,Shared,Print}.vue`, `Pages/Welcome.vue` and the account pages |
| Renderer | `press/render/` (one renderer for editor, thumbnails, player, print) |
| Charts | `press/charts/` (SVG strings, no DOM) |
| Editor engine | `press/editor/core/` (pure: ops, history, selection, snapping, clipboard, layouts, sync, store; node tested) |
| Editor shell | `press/editor/ui/` (top bar, Spine, dock, inspector host, toasts), `press/ui/controls/` (the control kit) |
| Stage | `press/editor/stage/` (selection layer, text editing, crop, tables, context bar) |
| Panels | `press/editor/panels/<name>/register.js` (auto registered) |
| Player | `press/player/` (sequence, effects, transitions, state), `Pages/Press/*` chrome |
| Export client | `press/export/` (PNG and ZIP) |

## Honest limits

No websockets (short polling, last edit wins on the same text box), no headless browser (PDF is DomPDF plus a print view), no LibreOffice (PPTX output was checked by python-pptx and a schema validator, never opened in PowerPoint or Keynote), no AI model key (local rules mode), no data API on the other Dot platforms (file imports only). See `docs/programme/R40_READINESS.md`.
