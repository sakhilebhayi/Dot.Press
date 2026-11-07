# Press inspector control kit

Every inspector panel uses these controls so panels look and behave the same. Import from the folder:

```js
import { PanelSection, NumberField, Segmented, ColorField, usePanelChange } from '@/press/ui/controls';
```
The CSS (`resources/css/press/controls.css`) is imported by the index and by Editor.vue. Tokens only; 32 px controls on a pointer, 44 px on touch; amber focus ring; reduced motion and dark come from the tokens. Layout helpers: `.c-stack` (vertical gap), `.c-grid2`, `.c-grid3`.

## Reading and changing the selection: `usePanelChange(editor)`
```js
const editor = useEditorContext();
const pc = usePanelChange(editor);
pc.value('size')                    // common value | 'mixed' | undefined (nothing selected); dotted paths ok: 'shadow.blur'
pc.value('size', { fallback: 48 })  // a missing key counts as 48
pc.set('size', 56, { label: 'Font size' })   // store.patch on every selected element, one undo step per control
pc.set('size', (el) => el.size + 2)          // function: per element
pc.reset('shadow')                           // null deletes the key
pc.setMany({ fill: '#fff', stroke: null })   // several keys, one undo step (use each() for several nested keys of one parent)
pc.each((el) => ({ ...shallowPatch }))       // free form
pc.canEdit                                   // false for viewers: pass as :disabled
```
Undo key is `panel:<path>:<ids>`, so a scrub or slider drag within one second is one undo step. Nothing is sent when the value is unchanged. A control shows a mixed value when `modelValue === 'mixed'` (exported as `MIXED`).

Typical use: `<NumberField label="Size" unit="px" :model-value="pc.value('size')" :disabled="!pc.canEdit" @update:model-value="(v) => pc.set('size', v)" />`

## Controls (all use `v-model`, `label`, `hint`, `error`, `hideLabel`, `disabled`, and render their own Field)
| Component | Notes |
|---|---|
| `PanelSection` | `id` (remembers collapse per id in localStorage), `title`, `state` (Plex Mono word or count), `collapsible`, `defaultOpen`, `resettable` + `@reset`, slot `actions` |
| `Field` | `label hint error id inline hideLabel`; default slot gets `{id, describedby, invalid}` to wire your own input |
| `NumberField` | `unit min max step`, drag the label to scrub (shift x10, alt x0.1), arrows (shift x10), commit on blur and Enter, Escape reverts; emits `update:modelValue` on every change and `commit` at the end |
| `Slider` | `min max step unit format(v)`; native range (keyboard, touch); `update:modelValue` live, `commit` on release |
| `Segmented` | `options [{value,label?,icon?,title?}]`; radiogroup with arrow keys; icon only options need `title` |
| `Toggle` | switch; `true/false/'mixed'` |
| `SelectMenu` | `options [{value,label,hint?,group?,disabled?}]`, `searchable`, typeahead, slots `option` and `value` for previews |
| `ColorField` | `#rrggbb[aa]`, `@token`, gradient object, null or 'mixed'; theme swatches (deck theme from the editor context, or `:theme`), recent colours (localStorage), validated hex, opacity; `allowNone`, `allowGradient` |
| `GradientField` | `{g:'linear', a, stops:[[0..1, colour]]}`; drag stops, click the bar to add, Delete removes (min 2, max 8), angle |
| `FontPicker` | the 12 deck fonts previewed in their own face, plus `@heading` and `@body` (`:roles="false"` to hide them) |
| `IconButton` | `icon label pressed size danger`; `label` is name and tooltip |
| `Tabs` | `items [{id,label,icon?,count?}]`, `variant` underline or pill |
| `Popover` | `v-model:open :anchor="el" placement width`; flips, traps focus, Escape, outside press, returns focus; nested popovers work |
| `Dialog` | `v-model:open title size`; slots default, `footer` ({close}); `data-autofocus` picks the first focus |
| `ReorderList` / `ListRow` | `v-model:items`, `itemKey`, `selectedKey`; drag the handle or focus it and use ArrowUp and ArrowDown; emits `reorder {key, from, to}`; slots default and `actions` ({item, index}) |
| `EmptyState` | the spine with no beads; `title body compact`; default slot for buttons |
| `ProgressBead` | `value` 0..1 or null (indeterminate), `label` |
| `InlineNotice` | `kind` info, ok, warn, error; icon and word, errors are announced |

Pure helpers (node tested): `colors.js` (hex and alpha, recent colours, gradient edits), `numbers.js` (parse, snap, nudge, scrub), `mixed.js` (mixed resolution, patches, undo keys), `reorder.js`. Gallery: `__gallery__/` (throwaway harness, run `npx vite --port 8838` and open `/resources/js/press/ui/controls/__gallery__/index.html`; not part of the build).
