# R-40 Dot.Press: the presentation document (contract between backend, editor, player and exporters)

This file is the single contract. The editor, the player, the PHP exporters, the importer and the AI services all read and write exactly this shape. Changing it means changing every consumer in the same commit.

## 1. Units and coordinates

All geometry is in slide units, with the origin at the top left of the slide. A deck carries its own width and height (`deck.w`, `deck.h`): new decks are 1920 x 1080, legacy decks stay 1280 x 720 so their positions keep meaning. Font sizes use the same units. Rotation is in degrees, clockwise, around the element centre. z-order is array order, bottom first.

## 2. Colours

A colour is `"#rrggbb"`, `"#rrggbbaa"` or a theme token `"@bg" "@surface" "@ink" "@muted" "@line" "@accent" "@accent2" "@good" "@warn" "@bad" "@chart1".."@chart8"`. A fill may also be a gradient: `{"g":"linear","a":90,"stops":[[0,"#112233"],[1,"@accent"]]}` (angle in degrees, 0 = to the top). Elements that use tokens follow a theme change. Elements with hex values keep their own colour: that is how a theme applies without destroying custom formatting.

## 3. Theme tokens (`deck.theme`)

```
{ "id": "slate", "name": "Slate",
  "c": { "bg":"#fff", "surface":"#f4f5f7", "ink":"#111827", "muted":"#6b7280", "line":"#d1d5db",
         "accent":"#2563eb", "accent2":"#f59e0b", "good":"#15803d", "warn":"#b45309", "bad":"#b91c1c",
         "chart":["#2563eb","#f59e0b","#15803d","#7c3aed","#db2777","#0891b2","#65a30d","#475569"] },
  "t": { "heading":"Public Sans", "body":"Public Sans", "hw": 700, "scale": 1 },
  "s": { "radius": 12, "stroke": 2 },
  "bg": null }
```
`t.heading` and `t.body` are the targets of `"@heading"` and `"@body"` in an element's `font`. `bg` is an optional default slide background (a fill or an image background, see slides).

## 4. Deck

```
deck: { id, title, w, h, theme, rev, status: "draft"|"in_review"|"approved", visibility: "private"|"team"|"link",
        sections: [{ id, title }], slide_order: [slideId, ...] , updated_at }
```

## 5. Slide

```
slide: { id, title, notes, section_id|null, hidden: bool, layout: "title"|"section"|"bullets"|"two"|"image-left"|"image-right"|"image-full"|"compare"|"timeline"|"quote"|"data"|"kpi"|"closing"|"blank"|<custom:id>,
         bg: fill | {"img": {asset|url}, "fit":"cover", "dim":0..1} | null,
         transition: null | { type: "fade"|"push"|"wipe"|"reveal"|"zoom"|"morph", dir: "l"|"r"|"u"|"d", dur: ms, ease },
         rev: int, els: [Element] }
```

## 6. Elements

Common to all: `id` (1 to 40 chars of `A-Za-z0-9_-`, unique in the slide), `type`, `name?`, `x y w h`, `rot` (0), `op` opacity 0..1 (1), `lock` (false), `hide` (false), `group?` (a group id: elements sharing it select and move together), `role?` (a layout placeholder: `title subtitle body media caption footer number kpi`), `origin?` (`user` default, `ai`, `import`, `template`; AI content stays marked until accepted), `link?` (https URL or `#slide:<id>`), `alt?` (text alternative), `anim?` (list of animation steps), `morph?` (key for morph transitions, defaults to `id`), `shadow?` (`{x,y,blur,c}`).

### text
`doc` (a Tiptap/ProseMirror JSON document restricted to paragraph, bulletList, orderedList, listItem, hardBreak, text, with marks bold, italic, underline, strike, link and textStyle{color,fontSize,fontFamily}), `font` (`"@heading"`, `"@body"` or a family name from the font list), `size`, `weight`, `color`, `align` (left center right justify), `valign` (top middle bottom), `lh` line height multiplier, `ls` letter spacing in em, `ps` paragraph spacing in em, `fit` (`none` or `shrink`: the player scales type down so the text fits the box), `fill?` box fill, `stroke?`, `sw?`, `pad` (box padding), `radius`, `fx` (`none|outline|highlight`).

### shape
`kind` (`rect round ellipse triangle diamond pentagon hexagon star chevron arrow-r arrow-l callout cross heart line arrow`), `fill`, `stroke`, `sw`, `radius` (for rect), `dash` (`solid|dash|dot`), `ends` (`{s:"none|arrow|dot", e:"none|arrow|dot"}` for line and arrow). A shape may carry `doc` and the text properties above to hold text.

### image
`src` (`{asset: <assetId>}` or `{url: "https://..."}`), `crop` (`{x,y,w,h}` as fractions of the source, the visible window), `fit` (`cover contain fill`), `mask` (`none round circle ellipse triangle star hexagon`), `adj` (`{br,ct,sat,blur,gray,sepia,hue}` each a number, 0 meaning no change except blur in px and hue in degrees), `flipH`, `flipV`, `cap?` (caption text drawn under the image by the renderer), `fill?`.

### video
`src` (`{asset}` or `{url}`), `embed?` (`{provider:"youtube"|"vimeo", id}`), `poster?` (`{asset}`), `autoplay`, `loop`, `muted`, `controls`, `start` (seconds).

### audio
`src` (`{asset}`), `autoplay`, `loop`, `controls`. A speaker mark is drawn in the editor and hidden in presenting unless `controls`.

### icon
`name` (an id from the built-in icon set in `resources/js/press/icons.js`), `color`, `sw` (stroke width).

### chart
```
chart: { type: "bar"|"column"|"line"|"area"|"pie"|"doughnut"|"scatter"|"bubble"|"waterfall"|"funnel"|"radar"|"combo"|"kpi"|"map",
         data: { categories: [string], series: [{ name, values: [number|null], color?, kind?: "bar"|"line", points?: [{x,y,r?}] }], totals?: [index] , kpis?: [{label,value,delta?,unit?,note?}] , regions?: [{code, value}] },
         opt:  { title?, sub?, legend: "none"|"bottom"|"right"|"top", labels: "none"|"value"|"percent", stacked: bool, smooth: bool, grid: bool, fmt?: "num"|"pct"|"cur"|"compact", cur?: "R", dec?: 0..3,
                 ax: { x: {title?, show}, y: {title?, show, min?, max?} }, colors?: [colour], anim?: bool },
         src:  { kind: "manual"|"csv"|"sheet", ref?: string, at?: iso8601 } }
```
Charts draw from `data` only, never from decoration. `src.kind="sheet"` records where the numbers came from; the numbers themselves are still stored in `data`.

### table
`rows` (array of arrays of cell objects `{t: string, b?: bool, i?: bool, al?: "l|c|r", fill?: colour, color?: colour, span?: [rows, cols]}`), `colw` (array of fractions summing to 1), `rowh` (array of units), `header` (first row is a header), `band` (zebra rows), `size`, `font`, `lines` (`none|rows|grid`).

## 7. Animations

`anim` is an ordered list. Each step: `{ id, ph: "in"|"out"|"emph", fx: "fade"|"rise"|"drop"|"slide-l"|"slide-r"|"zoom"|"wipe"|"pop"|"pulse"|"spin"|"grow", on: "click"|"with"|"after", delay: ms, dur: ms, ease: "out"|"inout"|"spring" }`. The slide's playback sequence is the steps of its elements merged in z-order, grouped into clicks: `click` starts a new group, `with` runs with the previous step, `after` runs when the previous ends. Elements that have an `in` step start hidden.

## 8. Operations (the only way to change a deck)

The editor never sends a whole slide to the server. It sends operations. The server applies them in order, inside one transaction, to the latest stored document.

```
POST /api/decks/{deck}/ops
{ "client": "<uuid of this browser tab>", "key": "<uuid, unique per request>", "ops": [ ... ] }
-> 200 { "seq": n, "deck_rev": n, "slides": { "<id>": rev }, "ids": { "<tempId>": realId }, "skipped": [ {op, reason} ] }
```
`key` makes a retry harmless: a repeated key returns the stored result and applies nothing. Ops:

| op | fields | effect |
|---|---|---|
| `deck.set` | `title? w? h? theme? status?` | change deck properties (size changes go through the `deck.resize` op) |
| `deck.resize` | `w h mode` (`scale` `anchor` `fit`) | resize the slide size and transform every element: `scale` scales positions and sizes uniformly then centres, `anchor` keeps sizes and pins each element to its nearest edges proportionally, `fit` scales to fit and letterboxes |
| `section.add` `section.set` `section.remove` | `id title? after?` | sections group consecutive slides |
| `slide.add` | `tid` (temporary id) `after` (slide id or null) `slide` (initial fields incl. `els`) | create a slide |
| `slide.set` | `id` plus any of `title notes section_id hidden layout bg transition` | change slide properties |
| `slide.move` | `id after` | reorder |
| `slide.remove` | `id` | delete (the document keeps at least one slide) |
| `slide.dup` | `id tid after` | duplicate with new element ids |
| `el.add` | `slide el index?` | add an element (its `id` is client generated) |
| `el.set` | `slide id p` (`p` is a shallow patch: a key set to `null` deletes the key) | change fields of an element |
| `el.remove` | `slide id` | remove |
| `el.order` | `slide ids` (full id list, bottom to top) | set z-order. Ids the server holds that are missing from the list stay on top in their old order |
| `el.move` | `from id to index?` | move an element to another slide |

Rules: a change to an element that no longer exists is skipped and reported in `skipped`, never an error. Two people changing different elements, or different keys of one element, never conflict. The same key changed twice: the later operation wins. Every accepted batch gets a `seq` in the append-only `deck_changes` log. Others read it with `GET /api/decks/{deck}/changes?after=<seq>` (long poll friendly, returns `{seq, changes:[{seq, client, user, ops}]}`) and apply the same ops locally. If the log no longer reaches back to `after`, the answer is `{"stale": true}` and the client reloads the document.

## 9. Reading

`GET /api/decks/{deck}/document` returns `{ deck, slides: [slide...], seq, assets: {<id>: {url, thumb, w, h, mime, name}} }`. Asset URLs are signed and short lived; the client refreshes them when they expire.

## 10. Server limits

At most 600 slides per deck, 400 elements per slide, 2 MB per slide document, 8 MB per ops request, 200 ops per request. Strings are sanitised: element ids by pattern, URLs must be `https:` or `#slide:`, text docs are pruned to the allowed node and mark list, unknown element keys are dropped.
