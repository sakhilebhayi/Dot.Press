import test from 'node:test';
import assert from 'node:assert/strict';
import { tableModel, columnFractions } from '../table.js';

const c = (t, extra = {}) => ({ t, ...extra });

test('columnFractions always sum to 1 and match the column count', () => {
    const sum = (a) => a.reduce((x, y) => x + y, 0);
    assert.ok(Math.abs(sum(columnFractions([0.5, 0.25, 0.25], 3)) - 1) < 1e-9);
    assert.ok(Math.abs(sum(columnFractions(undefined, 4)) - 1) < 1e-9);
    assert.equal(columnFractions(undefined, 4)[0], 0.25);
    assert.ok(Math.abs(sum(columnFractions([2, 2], 2)) - 1) < 1e-9, 'unnormalised input is normalised');
    assert.equal(columnFractions([0.5], 3).length, 3);
    assert.ok(Math.abs(sum(columnFractions([0.6], 3)) - 1) < 1e-9, 'missing columns share what is left');
    assert.ok(Math.abs(sum(columnFractions(['x', -1, null], 3)) - 1) < 1e-9);
});

test('header and banding', () => {
    const m = tableModel({ header: true, band: true, rows: [[c('h')], [c('a')], [c('b')], [c('c')]] });
    assert.deepEqual(m.rows.map((r) => [r.header, r.band]), [[true, false], [false, false], [false, true], [false, false]]);
    const n = tableModel({ band: true, rows: [[c('a')], [c('b')], [c('c')]] });
    assert.deepEqual(n.rows.map((r) => r.band), [false, true, false]);
});

test('spans skip the cells they cover', () => {
    const m = tableModel({ rows: [[c('a', { span: [2, 2] }), c('x'), c('b')], [c('y'), c('z'), c('d')], [c('e'), c('f'), c('g')]] });
    assert.equal(m.cols, 3);
    assert.deepEqual(m.rows[0].cells.map((x) => [x.t, x.rowspan, x.colspan]), [['a', 2, 2], ['b', 1, 1]]);
    assert.deepEqual(m.rows[1].cells.map((x) => x.t), ['d']);
    assert.deepEqual(m.rows[2].cells.map((x) => x.t), ['e', 'f', 'g']);
});

test('spans are clamped to the table', () => {
    const m = tableModel({ rows: [[c('a', { span: [9, 9] }), c('b')], [c('c'), c('d')]] });
    assert.equal(m.rows[0].cells[0].rowspan, 2);
    assert.equal(m.rows[0].cells[0].colspan, 2);
});

test('cell fields are cleaned', () => {
    const m = tableModel({ rowh: [60, 'x'], rows: [[{ t: 'a', b: 1, al: 'r', fill: '@surface', color: 5 }, 'plain'], [{ t: 7 }]] });
    const [a, p] = m.rows[0].cells;
    assert.equal(a.b, true);
    assert.equal(a.al, 'r');
    assert.equal(a.fill, '@surface');
    assert.equal(a.color, null);
    assert.equal(p.t, 'plain');
    assert.equal(m.rows[0].height, 60);
    assert.equal(m.rows[1].height, null);
    assert.equal(m.rows[1].cells[0].t, '7');
});

test('hostile input does not throw and stays bounded', () => {
    for (const v of [null, {}, { rows: 'x' }, { rows: [null, 3, 'x', [null]] }]) assert.doesNotThrow(() => tableModel(v));
    const big = tableModel({ rows: Array.from({ length: 500 }, () => Array.from({ length: 200 }, () => c('x'))) });
    assert.ok(big.rows.length <= 200 && big.cols <= 60);
});
