// Builds the acceptance deck through the real API (asset uploads, deck create, operations). Used by every script.
import crypto from 'node:crypto';
import { api, uploadAsset, makeMedia, assert } from './lib.mjs';

export const uid = () => crypto.randomUUID();

export function textEl(id, text, box, p = {}) {
    const paras = text.split('\n').map((line) => ({ type: 'paragraph', content: [{ type: 'text', text: line }] }));
    return { id, type: 'text', x: box[0], y: box[1], w: box[2], h: box[3], doc: { type: 'doc', content: paras }, font: '@body', size: 44, weight: 400, color: '@ink', align: 'left', valign: 'top', lh: 1.3, ...p };
}
export const step = (id, ph, fx, on = 'click', dur = 400, delay = 0) => ({ id, ph, fx, on, delay, dur, ease: 'out' });

/** Create a deck and apply operations to it. Returns {id, slides: {name: slideId}, assets}. */
export async function buildAcceptanceDeck(page, { title = 'Acceptance deck ' + Date.now(), withMedia = true } = {}) {
    let assets = {};
    if (withMedia) {
        const m = makeMedia();
        const v = await uploadAsset(page, m.video, 'video/mp4');
        const a = await uploadAsset(page, m.audio, 'audio/mpeg');
        const i = await uploadAsset(page, m.image, 'image/png');
        assert([v, a, i].every((r) => r.status === 201 || r.status === 200), 'media uploaded (' + [v, a, i].map((r) => r.status).join(',') + ')');
        const idOf = (r) => r.json.asset.id;
        assets = { video: idOf(v), audio: idOf(a), image: idOf(i) };
    }
    const created = await api(page, 'POST', '/api/decks', { title, size: '16:9' });
    assert(created.status === 201, 'deck created ' + created.status);
    const deck = created.json.data || created.json;
    const doc = await api(page, 'GET', `/api/decks/${deck.id}/document`);
    const first = doc.json.slides[0].id;

    const T = (type, dir = 'r', dur = 600) => ({ type, dir, dur, ease: 'inout' });
    const defs = [
        ['title', { title: 'Q3 production beat plan', layout: 'title', notes: 'SPEAKERNOTE-ONE open with the headline.', transition: T('fade', 'r', 500), els: [
            textEl('t1', 'Q3 production beat plan', [140, 300, 1500, 220], { font: '@heading', size: 100, weight: 700, role: 'title', anim: [step('a1', 'in', 'fade', 'click')] }),
            textEl('t2', 'Mining operations review', [140, 600, 1200, 80], { size: 44, color: '@muted', role: 'subtitle', anim: [step('a2', 'in', 'rise', 'click')] }),
            { id: 's1', type: 'shape', kind: 'rect', x: 140, y: 250, w: 160, h: 12, fill: '@accent', stroke: null, sw: 0, anim: [step('a3', 'in', 'wipe', 'after', 400)] },
        ] }],
        ['bullets', { title: 'Push slide', layout: 'bullets', notes: 'SPEAKERNOTE-TWO the drivers.', transition: T('push', 'l'), els: [
            textEl('h', 'Three drivers', [140, 100, 1600, 140], { font: '@heading', size: 76, weight: 700, role: 'title' }),
            textEl('b1', 'Availability up', [140, 320, 1500, 90], { size: 52, anim: [step('b1a', 'in', 'slide-l', 'click')] }),
            textEl('b2', 'Grade held', [140, 440, 1500, 90], { size: 52, anim: [step('b2a', 'in', 'slide-r', 'click')] }),
            textEl('b3', 'Cycle time down', [140, 560, 1500, 90], { size: 52, anim: [step('b3a', 'in', 'drop', 'click'), step('b3b', 'out', 'fade', 'click')] }),
        ] }],
        ['wipe', { title: 'Wipe slide', layout: 'data', notes: 'SPEAKERNOTE-THREE chart.', transition: T('wipe', 'd'), els: [
            textEl('h', 'Output by week', [140, 90, 1600, 120], { font: '@heading', size: 64, weight: 700 }),
            { id: 'c1', type: 'chart', x: 140, y: 240, w: 1100, h: 700, chart: { type: 'column', data: { categories: ['W1', 'W2', 'W3', 'W4'], series: [{ name: 'Actual', values: [118, 124, 121, 133] }] }, opt: { legend: 'none', labels: 'value', grid: true }, src: { kind: 'manual' } }, anim: [step('c1a', 'in', 'zoom', 'click', 600)] },
        ] }],
        ['media', { title: 'Reveal slide with media', layout: 'blank', notes: 'SPEAKERNOTE-FOUR media.', transition: T('reveal', 'u'), els: [
            textEl('h', 'Media', [140, 60, 1600, 100], { font: '@heading', size: 60, weight: 700 }),
            ...(withMedia ? [
                { id: 'img1', type: 'image', x: 140, y: 200, w: 640, h: 360, src: { asset: assets.image }, fit: 'cover', alt: 'Test pattern' },
                { id: 'vid1', type: 'video', x: 860, y: 200, w: 800, h: 450, src: { asset: assets.video }, autoplay: false, loop: false, muted: false, controls: true, start: 0 },
                { id: 'aud1', type: 'audio', x: 140, y: 700, w: 400, h: 120, src: { asset: assets.audio }, autoplay: false, loop: false, controls: true },
            ] : []),
        ] }],
        ['zoom', { title: 'Zoom slide', layout: 'blank', notes: 'SPEAKERNOTE-FIVE emphasis.', transition: T('zoom', 'r'), els: [
            textEl('h', 'Emphasis', [140, 100, 1600, 140], { font: '@heading', size: 76, weight: 700 }),
            { id: 'sh1', type: 'shape', kind: 'ellipse', x: 700, y: 400, w: 400, h: 400, fill: '@accent', stroke: null, sw: 0, anim: [step('sh1a', 'emph', 'pulse', 'click', 600), step('sh1b', 'emph', 'spin', 'click', 800), step('sh1c', 'out', 'pop', 'click', 400)] },
        ] }],
        ['morphA', { title: 'Morph A', layout: 'blank', notes: 'SPEAKERNOTE-SIX morph from.', transition: T('fade', 'r', 400), els: [
            { id: 'm1', type: 'shape', kind: 'rect', x: 200, y: 200, w: 300, h: 300, fill: '@accent', stroke: null, sw: 0, morph: 'box' },
            textEl('mt', 'Before', [200, 600, 800, 100], { size: 60 }),
        ] }],
        ['morphB', { title: 'Morph B', layout: 'blank', notes: 'SPEAKERNOTE-SEVEN morph to.', transition: T('morph', 'r', 800), els: [
            { id: 'm1b', type: 'shape', kind: 'rect', x: 1200, y: 500, w: 500, h: 400, fill: '@accent2', stroke: null, sw: 0, morph: 'box' },
            textEl('mt2', 'After', [200, 600, 800, 100], { size: 60 }),
        ] }],
        ['hidden', { title: 'Hidden backup slide', layout: 'blank', hidden: true, notes: 'HIDDENSECRET backup numbers.', els: [textEl('hh', 'Backup: HIDDENCONTENT', [140, 300, 1500, 200], { size: 70 })] }],
        ['closing', { title: 'Next steps', layout: 'closing', notes: 'SPEAKERNOTE-NINE close with the ask.', transition: T('fade', 'r', 500), els: [
            textEl('h', 'Decisions we need', [140, 200, 1600, 140], { font: '@heading', size: 84, weight: 700 }),
            textEl('b', 'Approve the ventilation upgrade', [140, 420, 1500, 100], { size: 52 }),
        ] }],
    ];
    const ops = [];
    const names = Object.fromEntries(defs.map(([n]) => [n, 't_' + n]));
    let after = first;
    // The new deck starts with one slide: turn it into the first slide and add the others after it.
    const [firstName, firstDef] = defs[0];
    ops.push({ op: 'slide.set', id: first, title: firstDef.title, notes: firstDef.notes, layout: firstDef.layout, transition: firstDef.transition });
    for (const el of firstDef.els) ops.push({ op: 'el.add', slide: first, el });
    for (const [n, d] of defs.slice(1)) {
        ops.push({ op: 'slide.add', tid: names[n], after, slide: d });
        after = names[n];
    }
    const res = await api(page, 'POST', `/api/decks/${deck.id}/ops`, { client: uid(), key: uid(), ops });
    assert(res.status === 200, 'ops applied ' + res.status + ' ' + res.text.slice(0, 300));
    const ids = { title: first };
    for (const [n] of defs.slice(1)) ids[n] = res.json.ids[names[n]];
    return { id: deck.id, title: deck.title, slides: ids, assets, skipped: res.json.skipped || [] };
}
