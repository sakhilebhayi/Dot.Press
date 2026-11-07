// Table model for table elements (spec section 6, `table`). Pure, no DOM.
//
// `rows` is a grid: every row lists a cell object for every column. A cell with `span: [rows, cols]` covers the
// cells to its right and below it, and those covered positions are skipped (whatever they hold).

const num = (v, d) => (Number.isFinite(+v) && v !== null && v !== '' ? +v : d);

/** Normalised column fractions that always sum to 1 and match the column count. */
export function columnFractions(colw, cols) {
    const n = Math.max(1, cols);
    const raw = Array.isArray(colw) ? colw.slice(0, n).map((v) => Math.max(0, num(v, 0))) : [];
    while (raw.length < n) raw.push(0);
    const given = raw.reduce((a, b) => a + b, 0);
    const empty = raw.filter((v) => v === 0).length;
    if (given <= 0) return raw.map(() => 1 / n);
    if (empty > 0 && given < 1) {
        const share = (1 - given) / empty;
        return raw.map((v) => (v === 0 ? share : v));
    }
    return raw.map((v) => v / given);
}

/**
 * @returns {{ cols: number, rows: { height: number|null, header: boolean, band: boolean, cells: object[] }[], colw: number[] }}
 */
export function tableModel(el) {
    const src = Array.isArray(el?.rows) ? el.rows.slice(0, 200) : [];
    const cols = Math.min(60, Math.max(1, ...src.map((r) => (Array.isArray(r) ? r.length : 0))));
    const covered = new Set();
    const rows = [];
    src.forEach((row, r) => {
        const cells = [];
        const list = Array.isArray(row) ? row.slice(0, cols) : [];
        list.forEach((cell, c) => {
            if (covered.has(`${r}:${c}`)) return;
            const o = cell && typeof cell === 'object' ? cell : { t: cell == null ? '' : String(cell) };
            const span = Array.isArray(o.span) ? o.span : [1, 1];
            const rs = Math.max(1, Math.min(src.length - r, Math.floor(num(span[0], 1))));
            const cs = Math.max(1, Math.min(cols - c, Math.floor(num(span[1], 1))));
            for (let i = 0; i < rs; i++) for (let j = 0; j < cs; j++) if (i || j) covered.add(`${r + i}:${c + j}`);
            cells.push({
                t: typeof o.t === 'string' ? o.t.slice(0, 2000) : o.t == null ? '' : String(o.t).slice(0, 2000),
                b: !!o.b,
                i: !!o.i,
                al: ['l', 'c', 'r'].includes(o.al) ? o.al : null,
                fill: typeof o.fill === 'string' ? o.fill : null,
                color: typeof o.color === 'string' ? o.color : null,
                rowspan: rs,
                colspan: cs,
            });
        });
        const rh = Array.isArray(el.rowh) ? num(el.rowh[r], null) : null;
        rows.push({ height: rh && rh > 0 ? rh : null, header: !!el.header && r === 0, band: !!el.band && r - (el.header ? 1 : 0) >= 0 && (r - (el.header ? 1 : 0)) % 2 === 1, cells });
    });
    return { cols, rows, colw: columnFractions(el?.colw, cols) };
}
