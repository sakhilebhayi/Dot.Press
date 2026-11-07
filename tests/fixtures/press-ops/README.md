# Shared op vectors

One JSON file per vector. The JS suite (`resources/js/press/editor/core/__tests__/vectors.test.mjs`) and the PHP `OpApplier` tests run the same files, so the client and the server stay on the same rules. The vectors are written by hand (the expected documents are never produced by running the implementation).

## Format (stable)

```json
{
  "name": "el-set-patch",
  "description": "one sentence",
  "normalize": ["n1"],
  "doc":  { "deck": { ... }, "slides": [ ... ] },
  "ops":  [ { "op": "el.set", "slide": "s1", "id": "a", "p": { "x": 10 } } ],
  "expected": {
    "doc": { "deck": { ... }, "slides": [ ... ] },
    "skipped": [ { "index": 0, "reason": "missing_element" } ]
  }
}
```

- The file name is `<name>.json`.
- `doc` is a complete document in the shape of spec section 9 (`deck` with `slide_order`, `slides` in order, every slide field present). It is used exactly as written. Slide ids are strings.
- `ops` is applied as one batch, in order, to `doc`. Skipped ops never stop the batch.
- `expected.doc` is the full document after the batch.
- `expected.skipped` lists the ops that were not applied: `index` is the position in `ops`, `reason` is one of the codes below. Ops that apply are not listed. An empty list means nothing was skipped.
- `normalize` (optional) is a list of slide ids whose element ids and group ids are not deterministic (`slide.dup` invents them). Before comparing, in those slides every element id is replaced by `e1`, `e2`, ... by position in `els`, and group ids by `g1`, `g2`, ... in order of first appearance. Apply the same renaming to the expected document.

## How to compare

Compare `deck` and `slides` as JSON values (object key order does not matter, array order does, numbers compare by value) after removing `deck.rev`, `deck.updated_at` and every `slide.rev`: the server owns revisions. Compare `skipped` as a list of `{index, reason}`.

A batch where every op is skipped changes nothing.

## Skip reasons

| reason | when |
|---|---|
| `unknown_op` | the `op` name is not in spec section 8 |
| `invalid` | malformed op: a missing or wrong typed field, a bad element id, an unknown element type, `deck.resize` outside 320..7680 or with an unknown mode, `slide.add` with a missing tid or a tid made only of digits, a `slide.set` / `deck.set` / `section.set` with no usable value |
| `forbidden` | `deck.reload` sent by a client, `deck.set` with status `approved` |
| `use_deck_resize` | `deck.set` that carries `w` or `h` |
| `missing_slide`, `missing_element`, `missing_section` | the target does not exist |
| `duplicate_id` | `slide.add` / `slide.dup` tid already used, `section.add` id already used, `el.add` id already in the slide, `el.move` id already in the target slide |
| `last_slide` | `slide.remove` of the only slide |
| `limit` | more than 600 slides or 400 elements in a slide |

The reason codes are the JS names. A server may use other strings internally; map them when running the vectors.

## Rules the vectors pin down

The full list is the header of `resources/js/press/editor/core/ops.js` and `resize.js`. In short:

- `after` on `slide.add`, `slide.move`, `section.add`, `slide.dup`: key absent means the end (for `slide.dup`: directly after the source), `null` means first, an unknown id means the end. `after` may name a slide added earlier in the same batch.
- `el.set`: `p` is a shallow patch, `null` deletes the key, `id` and `type` are never patched, nested values are replaced whole. `el.add` index is clamped to `0..length`.
- `el.order`: unknown and repeated ids are ignored, elements missing from the list stay on top in their old order.
- `el.move`: same slide means a z-order move, the index counts after the element is taken out.
- `slide.add` initial `els`: entries with a bad id, an unknown type or a repeated id are dropped; only the slide keys `title notes section_id hidden layout bg transition` are read, wrongly typed ones are ignored.
- `slide.dup` copies get fresh element and group ids, `rev` 0, and `morph` = the source element id when the source had no `morph`.
- `deck.resize` geometry: `scale` uses the larger ratio, `fit` the smaller, both scale style sizes, `anchor` shifts whole blocks (a group is one block) in proportion to their centre. Results round half away from zero to 2 decimals. The server follows a resize with `deck.reload`.
