// A trimmed copy of the editor's icon file format (resources/js/press/icons.js): helpers and the I(...) rows.
const f = (n) => +n.toFixed(2);
const circle = (cx, cy, r) => `M${f(cx - r)} ${cy}a${r} ${r} 0 1 0 ${f(2 * r)} 0a${r} ${r} 0 1 0 ${f(-2 * r)} 0Z`;
const box = (x, y, w, h, r = 0) =>
    r
        ? `M${x + r} ${y}h${w - 2 * r}a${r} ${r} 0 0 1 ${r} ${r}v${h - 2 * r}a${r} ${r} 0 0 1 ${-r} ${r}h${-(w - 2 * r)}a${r} ${r} 0 0 1 ${-r} ${-r}v${-(h - 2 * r)}a${r} ${r} 0 0 1 ${r} ${-r}Z`
        : `M${x} ${y}h${w}v${h}h${-w}Z`;
const I = (id, label, tags, ...d) => ({ id, label, tags, d });
export const ICONS = [
    I('check', 'Check', ['done', 'yes'], 'M4 12.5l5 5L20 6.5'),
    I('target', 'Target', ['goal', 'aim'], circle(12, 12, 9), circle(12, 12, 5), circle(12, 12, 1)),
    I('card', 'Payment card', ['credit', 'billing'], box(2.5, 5, 19, 14, 2), 'M2.5 10h19', 'M6 15h4'),
    I('grid', 'Grid', ['table'], box(4, 4, 7, 7), box(13, 4, 7, 7, 1)),
    I('arrow-right', 'Arrow right', ['next'], 'M4 12h16', 'M14 6l6 6-6 6'),
];
