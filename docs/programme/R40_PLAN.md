# R-40 Dot.Press: implementation plan and decisions

Brief: `requirements/dot-press-transformation.md`. Audit: `R40_AUDIT.md`. Contract: `R40_DOCUMENT_SPEC.md`. Design: `DESIGN.md` ("The Spine"). Research: the landscape study, from which the product pillars below come. Written 2026-11-07.

## What the audit and the research decided

The audit found a small honest core (teams, policies, AI plumbing, backups) under an editor that cannot hold a deck: one slide per page, manual save, a PPTX export that writes only text, no charts, no presenter tools. The research found that the category's biggest complaints are export and import losing fidelity, fonts changing, generic AI output that is costly to edit, rigid auto-layout and drift in consistency. Dot.Press answers with five pillars.

1. **The Spine.** The deck is an argument. The left rail is a line of beads, one per slide, grouped into sections. A bead is hollow until its slide carries evidence, solid once it does, amber for the current slide. Dragging beads reorders the deck. The same line is the progress indicator while presenting.
2. **Honest by default.** Every export shows a preflight (native, approximated, flattened, dropped) before it runs. AI is labelled local rules or model. Collaboration is described as it is: changes merge per element and arrive within seconds, not live typing.
3. **Consistency as a live inspector.** The Review panel checks fonts, colours, alignment, margins, density, terminology, repeated content and unsupported claims, and proposes fixes that the user applies one at a time.
4. **Proposals, not rewrites.** Everything generated or suggested arrives as a proposal with a diff and a mark. Nothing changes the deck until the user accepts it.
5. **A deck that cannot fail on the day.** An offline present package, a presenter console synced by BroadcastChannel, remote clicker and audience following by short polling.

## Decisions

| # | Decision | Why |
|---|---|---|
| D1 | The deck is a document: slides hold their elements as JSON, every change is an operation in an append-only log | Autosave becomes small and idempotent, versions and collaboration become possible, import and export have one shape to meet. The old `elements` table is kept unused until the backfill has proved itself |
| D2 | The editor renders slides as DOM and inline SVG, not canvas. Konva is dropped | The same renderer serves the editor, thumbnails and the player, text editing is native, accessibility is possible, and there is no second layout engine to drift from |
| D3 | Changes are operations merged per element; transport is HTTP with short polling | The host has no websocket daemon. Different elements and different keys of one element never conflict. The same key edited twice: the later edit wins. Labelled plainly in the UI |
| D4 | Pending operations persist locally until the server acknowledges them | A closed tab, a dead network or a failed save never loses work. The editor offers to restore unsent changes |
| D5 | Charts are one JavaScript engine that produces SVG, with a PHP port for exports | One look everywhere. Native editable charts in PPTX |
| D6 | PPTX is written and read directly as OOXML | PhpPresentation could not place elements. Direct control gives positions, runs, tables, charts and notes. Unsupported content on import is reported, never dropped silently |
| D7 | PDF is exported on the server with DomPDF at slide size, with a browser print view for the highest fidelity | The host has no headless browser. The preflight says what DomPDF cannot do |
| D8 | AI runs in a local rules mode by default and in model mode when a key is set; both return proposals | No model key exists on the host. Rules can do real analysis (consistency, density, claims, structure) and extractive outlines, and say plainly that tone and rewriting need a model |
| D9 | Integrations are limited to real APIs: InfoDot sign-in and teams. Charts import from CSV and XLSX files, including Dot.Sheets exports | The audit of the other platforms found no data API in Dot.Sheet, Files, Tasks, Projects, HR or Brain, no send API in Notify, no text API in Docs, and no service auth in Analytics. Nothing is faked |
| D10 | Sharing has four levels: private, shared with named people, team, public link; approval is a status bound to a content hash | Brief section 17. An approved deck stops being approved when it changes |
| D11 | Design: "The Spine", ink with amber used only for "here", Bricolage, Instrument and Plex Mono, light and dark | See DESIGN.md |

## Phases and who builds them

| Phase | Work | Where |
|---|---|---|
| 1 | Audit, research, contract | this folder |
| 2 | Document store, ops, versions, backfill (core); access, sharing, comments, reviews (access); assets pipeline, themes, brand kits (assets); exporters and importer (export); chart engine (charts); renderer, player, presenter (player); analysis and outline services (intel); editor engine (ecore) | branches `feat/r40-*`, merged into `feat/r40-press` |
| 3 | Editor shell, canvas, Spine, inspector, panels, library, shell restyle | after merge |
| 4 | Layout, theme and template libraries, content and design review | after the editor renders |
| 5 | End to end tests (the 50 checks), security pass, performance, accessibility, readiness report | last |
| 6 | Merge, deploy, live verification | last |

## What cannot be finished by building alone

A model key and monthly cap for model mode. TURN is not relevant here. Real integrations with Sheets, Analytics and the rest need those platforms to ship APIs first. PPTX fidelity against real PowerPoint and Keynote needs a corpus of real decks and an Office install: the exports are verified by python-pptx and by round trip only.
