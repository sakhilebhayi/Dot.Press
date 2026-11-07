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

const richText = (id, paras, box, p = {}) => ({ id, type: 'text', x: box[0], y: box[1], w: box[2], h: box[3], doc: { type: 'doc', content: paras }, font: '@body', size: 40, weight: 400, color: '@ink', align: 'left', valign: 'top', lh: 1.3, ...p });
const para = (...runs) => ({ type: 'paragraph', content: runs });
const run = (text, marks) => (marks ? { type: 'text', text, marks } : { type: 'text', text });

/** A deck that uses most element kinds, for export and import checks. Returns {id, slides:{name:id}}. */
export async function buildRichDeck(page, { title = 'Rich deck ' + Date.now(), media = null } = {}) {
    const created = await api(page, 'POST', '/api/decks', { title, size: '16:9' });
    const deck = created.json.data || created.json;
    const doc = await api(page, 'GET', `/api/decks/${deck.id}/document`);
    const first = doc.json.slides[0].id;
    const T = (type, dir = 'r', dur = 600) => ({ type, dir, dur, ease: 'inout' });
    const chart = (id, type, x, y, w, h, data, opt = {}) => ({ id, type: 'chart', x, y, w, h, chart: { type, data, opt: { legend: 'bottom', labels: 'none', grid: true, ...opt }, src: { kind: 'manual' } } });
    const cats = ['Q1', 'Q2', 'Q3', 'Q4'];
    const ser = [{ name: 'North', values: [12, 19, 14, 22] }, { name: 'South', values: [8, 11, 17, 13] }];
    const defs = [
        ['type', { title: 'Typography RICH-TITLE', layout: 'bullets', notes: 'RICHNOTE-1 speak slowly', transition: T('fade', 'r', 400), els: [
            richText('h', [para(run('Typography ', [{ type: 'bold' }]), run('and ', [{ type: 'italic' }]), run('lists', [{ type: 'underline' }]))], [140, 80, 1600, 140], { font: '@heading', size: 80, weight: 700 }),
            richText('bl', [{ type: 'bulletList', content: [{ type: 'listItem', content: [para(run('First point'))] }, { type: 'listItem', content: [para(run('Second point with a '), run('link', [{ type: 'link', attrs: { href: 'https://example.com/docs' } }]))] }] }, { type: 'orderedList', content: [{ type: 'listItem', content: [para(run('Step one'))] }, { type: 'listItem', content: [para(run('Step two'))] }] }], [140, 300, 900, 500], { size: 44 }),
            richText('co', [para(run('Red ', [{ type: 'textStyle', attrs: { color: '#b91c1c' } }]), run('Blue 30', [{ type: 'textStyle', attrs: { color: '#1d4ed8', fontSize: 30 } }]), run(' strike', [{ type: 'strike' }]))], [1100, 300, 700, 120], { size: 44 }),
            richText('fx', [para(run('Centered text, big'))], [1100, 480, 700, 200], { size: 56, align: 'center', valign: 'middle', fill: '#fde68a', radius: 24, pad: 24 }),
        ] }],
        ['shapes', { title: 'Shapes and lines', layout: 'blank', notes: 'RICHNOTE-2', transition: T('push', 'l'), els: [
            { id: 'r1', type: 'shape', kind: 'rect', x: 100, y: 120, w: 300, h: 200, fill: '#2563eb', stroke: '#111827', sw: 4, radius: 0 },
            { id: 'r2', type: 'shape', kind: 'round', x: 460, y: 120, w: 300, h: 200, fill: '#16a34a', stroke: null, sw: 0, radius: 40 },
            { id: 'e1', type: 'shape', kind: 'ellipse', x: 820, y: 120, w: 300, h: 200, fill: { g: 'linear', a: 90, stops: [[0, '#f59e0b'], [1, '#b91c1c']] }, stroke: null, sw: 0 },
            { id: 'st', type: 'shape', kind: 'star', x: 1180, y: 120, w: 240, h: 240, fill: '#7c3aed', stroke: null, sw: 0 },
            { id: 'ar', type: 'shape', kind: 'arrow-r', x: 100, y: 420, w: 400, h: 160, fill: '#0891b2', stroke: null, sw: 0 },
            { id: 'rt', type: 'shape', kind: 'rect', x: 600, y: 440, w: 300, h: 160, fill: '#db2777', stroke: null, sw: 0, rot: 20, shadow: { x: 8, y: 8, blur: 16, c: '#00000066' } },
            { id: 'ln', type: 'shape', kind: 'line', x: 1000, y: 460, w: 500, h: 120, stroke: '#111827', sw: 6, dash: 'dash', ends: { s: 'none', e: 'arrow' } },
            { id: 'ic', type: 'icon', name: 'target', x: 1560, y: 120, w: 200, h: 200, color: '#b45309', sw: 3 },
            richText('tin', [para(run('Text inside a shape'))], [100, 700, 600, 140], { size: 40, fill: '#e0f2fe', stroke: '#0369a1', sw: 3, pad: 20 }),
        ] }],
        ['picture', { title: 'Pictures', layout: 'blank', notes: 'RICHNOTE-3', transition: T('wipe', 'd'), els: [
            ...(media ? [
                { id: 'p1', type: 'image', x: 100, y: 100, w: 640, h: 360, src: { asset: media.image }, fit: 'cover', alt: 'Plain test card' },
                { id: 'p2', type: 'image', x: 800, y: 100, w: 360, h: 360, src: { asset: media.image }, fit: 'cover', mask: 'circle', crop: { x: 0.2, y: 0, w: 0.6, h: 1 }, alt: 'Cropped circle' },
                { id: 'p3', type: 'image', x: 1220, y: 100, w: 560, h: 360, src: { asset: media.image }, fit: 'cover', adj: { gray: 100, br: 10 }, flipH: true, cap: 'Grey and mirrored', alt: 'Grey card' },
            ] : []),
            richText('pc', [para(run('Pictures keep their crop and mask where the format allows it.'))], [100, 620, 1500, 120], { size: 40 }),
        ] }],
        ['table', { title: 'Table and KPIs', layout: 'data', notes: 'RICHNOTE-4', transition: T('reveal', 'u'), els: [
            { id: 'tb', type: 'table', x: 100, y: 100, w: 1100, h: 360, header: true, band: true, size: 30, lines: 'rows', colw: [0.4, 0.2, 0.2, 0.2], rowh: [80, 70, 70, 70],
                rows: [[{ t: 'Metric', b: true }, { t: 'Q2', b: true, al: 'r' }, { t: 'Q3', b: true, al: 'r' }, { t: 'Delta', b: true, al: 'r' }], [{ t: 'Tonnes' }, { t: '1,012', al: 'r' }, { t: '1,053', al: 'r' }, { t: '+4.1%', al: 'r', color: '#15803d' }], [{ t: 'Grade' }, { t: '3.4', al: 'r' }, { t: '3.4', al: 'r' }, { t: '0.0%', al: 'r' }], [{ t: 'Spanning cell', span: [1, 2] }, { t: '' }, { t: 'x', al: 'r', fill: '#fee2e2' }, { t: 'y', al: 'r' }]] },
            chart('k1', 'kpi', 100, 540, 1700, 260, { kpis: [{ label: 'LTIFR', value: '0.8', delta: -12 }, { label: 'Cost per tonne', value: 'R412', delta: -3.1 }, { label: 'Availability', value: '91%', delta: 4.2 }] }),
        ] }],
        ['charts1', { title: 'Charts one', layout: 'data', notes: 'RICHNOTE-5', transition: T('zoom'), els: [
            chart('c1', 'column', 60, 60, 600, 400, { categories: cats, series: ser }, { stacked: false, labels: 'value' }),
            chart('c2', 'line', 680, 60, 600, 400, { categories: cats, series: ser }, { smooth: true }),
            chart('c3', 'pie', 1300, 60, 560, 400, { categories: cats, series: [ser[0]] }, { labels: 'percent' }),
            chart('c4', 'doughnut', 60, 520, 600, 400, { categories: cats, series: [ser[1]] }),
            chart('c5', 'area', 680, 520, 600, 400, { categories: cats, series: ser }, { stacked: true }),
            chart('c6', 'bar', 1300, 520, 560, 400, { categories: cats, series: ser }),
        ] }],
        ['charts2', { title: 'Charts two', layout: 'data', notes: 'RICHNOTE-6', transition: T('fade'), els: [
            chart('d1', 'scatter', 60, 60, 600, 400, { categories: [], series: [{ name: 'Pts', values: [], points: [{ x: 1, y: 2 }, { x: 2, y: 4 }, { x: 3, y: 3 }, { x: 4, y: 7 }] }] }),
            chart('d2', 'bubble', 680, 60, 600, 400, { categories: [], series: [{ name: 'B', values: [], points: [{ x: 1, y: 2, r: 5 }, { x: 2, y: 4, r: 9 }, { x: 3, y: 3, r: 4 }] }] }),
            chart('d3', 'waterfall', 1300, 60, 560, 400, { categories: ['Start', 'Up', 'Down', 'End'], series: [{ name: 'W', values: [100, 30, -20, 110] }], totals: [0, 3] }),
            chart('d4', 'funnel', 60, 520, 600, 400, { categories: ['Visits', 'Leads', 'Deals'], series: [{ name: 'F', values: [1000, 300, 60] }] }),
            chart('d5', 'radar', 680, 520, 600, 400, { categories: ['A', 'B', 'C', 'D', 'E'], series: [{ name: 'R', values: [4, 3, 5, 2, 4] }] }),
            chart('d6', 'combo', 1300, 520, 560, 400, { categories: cats, series: [{ name: 'Bars', values: [5, 7, 6, 9], kind: 'bar' }, { name: 'Line', values: [4, 6, 7, 8], kind: 'line' }] }),
        ] }],
        ['media', { title: 'Media slide', layout: 'blank', notes: 'RICHNOTE-7', transition: T('fade'), els: [
            richText('mh', [para(run('Video and audio'))], [100, 60, 1500, 100], { size: 60, font: '@heading', weight: 700 }),
            ...(media ? [
                { id: 'v1', type: 'video', x: 100, y: 200, w: 800, h: 450, src: { asset: media.video }, autoplay: false, loop: false, muted: false, controls: true, start: 0 },
                { id: 'a1', type: 'audio', x: 1000, y: 300, w: 500, h: 120, src: { asset: media.audio }, autoplay: false, loop: false, controls: true },
            ] : []),
        ] }],
        ['anim', { title: 'Animated and hidden', layout: 'blank', hidden: true, notes: 'RICHNOTE-8 hidden backup', transition: T('morph'), els: [
            richText('an', [para(run('Backup RICH-HIDDEN'))], [140, 300, 1500, 200], { size: 70, anim: [step('x1', 'in', 'fade', 'click')] }),
        ] }],
    ];
    const ops = [];
    const names = {};
    let after = first;
    const [, firstDef] = defs[0];
    ops.push({ op: 'slide.set', id: first, title: firstDef.title, notes: firstDef.notes, layout: firstDef.layout, transition: firstDef.transition });
    for (const el of firstDef.els) ops.push({ op: 'el.add', slide: first, el });
    for (const [n, d] of defs.slice(1)) { names[n] = 't_' + n; ops.push({ op: 'slide.add', tid: names[n], after, slide: d }); after = names[n]; }
    const res = await api(page, 'POST', `/api/decks/${deck.id}/ops`, { client: uid(), key: uid(), ops });
    assert(res.status === 200 && (res.json.skipped || []).length === 0, 'rich deck ops applied ' + res.status + ' skipped=' + JSON.stringify(res.json?.skipped || []).slice(0, 300));
    const ids = { type: first };
    for (const [n] of defs.slice(1)) ids[n] = res.json.ids[names[n]];
    return { id: deck.id, title: deck.title, slides: ids };
}
