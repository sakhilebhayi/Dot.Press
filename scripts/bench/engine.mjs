// Editor engine benchmark (pure logic, no browser): document normalisation, op application, undo stack and heap growth,
// and the request rate of the sync engine. Run: node --expose-gc scripts/bench/engine.mjs [--doc storage/app/bench/doc-300.json]
//   php artisan press:bench --seed-only --use-current-db --sizes=10,100,300     writes the doc-N.json files
import { readFileSync, existsSync } from 'node:fs';
import { createDoc } from '../../resources/js/press/editor/core/doc.js';
import { applyOps } from '../../resources/js/press/editor/core/ops.js';
import { createStore } from '../../resources/js/press/editor/core/store.js';
import { createSync } from '../../resources/js/press/editor/core/sync.js';
import { fakeClock, fakeServer, fakeStorage } from '../../resources/js/press/editor/core/__tests__/fake.mjs';
import { args, recorder, time, median } from './lib.mjs';

const a = args();
const sizes = String(a.sizes || '10,100,300').split(',').map(Number);
const rec = recorder('Dot.Press editor engine benchmark');
const heap = () => { globalThis.gc?.(); return process.memoryUsage().heapUsed / 1048576; };
if (!globalThis.gc) console.warn('Run with --expose-gc for heap numbers.');

for (const size of sizes) {
    const file = a.doc && sizes.length === 1 ? a.doc : `storage/app/bench/doc-${size}.json`;
    if (!existsSync(file)) { console.warn(`missing ${file}; run php artisan press:bench --seed-only --use-current-db --sizes=${size}`); continue; }
    const server = JSON.parse(readFileSync(file, 'utf8'));
    const key = `${size}`;
    rec.put(key, 'document_json_mb', readFileSync(file).length / 1048576);

    const h0 = heap();
    let doc;
    rec.put(key, 'createDoc.ms', time(() => { doc = createDoc(server); }, 5));
    rec.put(key, 'heap_after_createDoc_mb', heap() - h0);

    const slide = doc.slides[0];
    const els = slide.els.map((e) => e.id);
    const drag = (n, dx) => els.slice(0, n).map((id, i) => ({ op: 'el.set', slide: slide.id, id, p: { x: 100 + i + dx, y: 100 + dx } }));
    rec.put(key, 'applyOps.single_el_set.ms', time(() => applyOps(doc, drag(1, 5)), 50));
    rec.put(key, 'applyOps.drag_30.ms', time(() => applyOps(doc, drag(30, 5)), 30));
    const last = doc.slides[doc.slides.length - 1];
    const addOps = Array.from({ length: 200 }, (_, i) => ({ op: 'el.add', slide: last.id, el: { id: `x${i}`, type: 'shape', kind: 'rect', x: i, y: i, w: 10, h: 10, fill: '#fff' } }));
    rec.put(key, 'applyOps.add_200_elements.ms', time(() => applyOps(doc, addOps), 10));
    rec.put(key, 'applyOps.slide_add.ms', time(() => applyOps(doc, [{ op: 'slide.add', tid: 'tmp-1', after: doc.slides[0].id, slide: { title: 'New', els: [] } }]), 30));
    const dupOne = () => applyOps(doc, [{ op: 'slide.move', id: doc.slides[doc.slides.length - 1].id, after: doc.slides[0].id }]);
    rec.put(key, 'applyOps.slide_move.ms', time(dupOne, 30));

    // The undo stack and the heap over 500 operations, each its own undo step (distinct keys, so nothing coalesces).
    const store = createStore({ doc: createDoc(server) });
    store.setSlide(slide.id);
    store.select([els[0], els[1], els[2]]);
    const before = heap();
    const early = [];
    const late = [];
    for (let i = 0; i < 500; i++) {
        const t = performance.now();
        store.moveSelection(1, 1, { undoKey: `k${i}` });
        const dt = performance.now() - t;
        if (i < 50) early.push(dt); else if (i >= 450) late.push(dt);
    }
    rec.put(key, '500_ops.early_ms_per_op', median(early));
    rec.put(key, '500_ops.late_ms_per_op', median(late));
    rec.put(key, '500_ops.undo_stack_size', store.getState().canUndo ? 200 : 0);
    rec.put(key, '500_ops.heap_growth_mb', heap() - before);
    const t = performance.now();
    let undone = 0;
    while (store.getState().canUndo && undone < 1000) { store.undo(); undone++; }
    rec.put(key, '500_ops.undo_all.ms', performance.now() - t);
    rec.put(key, '500_ops.undo_steps_available', undone);
    store.destroy();
}

// Request rate of the sync engine: minutes of simulated time per state, counting the polls it sends.
async function pollRate(label, setup, minutes = 10) {
    const clock = fakeClock();
    const srv = fakeServer({ deck: { id: 'd', title: 'D', w: 1920, h: 1080, theme: null, sections: [], slide_order: ['1'], status: 'draft', visibility: 'team' }, slides: [{ id: '1', title: 'a', notes: '', section_id: null, hidden: false, layout: 'blank', bg: null, transition: null, rev: 0, els: [] }], seq: 0 });
    const sync = createSync({ transport: srv.transport, storage: fakeStorage(), clock, clientId: 'bench', deckId: 'd', uuid: (() => { let n = 0; return () => `k${++n}`; })() });
    sync.init(srv.snapshot());
    setup(sync);
    sync.startPolling();
    const before = srv.pollCalls;
    await clock.advance(minutes * 60_000);
    rec.put('sync_polling', `${label}.requests_per_minute`, (srv.pollCalls - before) / minutes);
    sync.destroy();
}
await pollRate('focused_tab', (s) => s.setFocused(true));
await pollRate('hidden_tab', (s) => s.setFocused(false));

rec.print();
rec.save(a.json);
