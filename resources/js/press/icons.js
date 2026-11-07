// Built-in icon set for icon elements (spec section 6, `icon`).
// 24 x 24 grid, drawn with a stroke only (no fills), round caps and joins. The renderer sets the stroke colour and
// width (`color`, `sw`), so every icon below is a list of path data strings.
//
// Exports
//   ICONS            array of { id, label, tags, d }  (d: array of SVG path strings; ids are unique)
//   ICON_IDS         array of ids
//   getIcon(id)      entry or null
//   iconSvg(id, { color, sw, size })  a complete <svg> string, for the offline package and exports
//   DEFAULT_ICON_SW  stroke width used when an element has none (1.75)

export const DEFAULT_ICON_SW = 1.75;

const f = (n) => +n.toFixed(2);
const circle = (cx, cy, r) => `M${f(cx - r)} ${cy}a${r} ${r} 0 1 0 ${f(2 * r)} 0a${r} ${r} 0 1 0 ${f(-2 * r)} 0Z`;
const box = (x, y, w, h, r = 0) =>
    r
        ? `M${x + r} ${y}h${w - 2 * r}a${r} ${r} 0 0 1 ${r} ${r}v${h - 2 * r}a${r} ${r} 0 0 1 ${-r} ${r}h${-(w - 2 * r)}a${r} ${r} 0 0 1 ${-r} ${-r}v${-(h - 2 * r)}a${r} ${r} 0 0 1 ${r} ${-r}Z`
        : `M${x} ${y}h${w}v${h}h${-w}Z`;

const I = (id, label, tags, ...d) => ({ id, label, tags, d });

export const ICONS = [
    // arrows and marks
    I('arrow-right', 'Arrow right', ['next', 'forward'], 'M4 12h16', 'M14 6l6 6-6 6'),
    I('arrow-left', 'Arrow left', ['back', 'previous'], 'M20 12H4', 'M10 6l-6 6 6 6'),
    I('arrow-up', 'Arrow up', ['increase'], 'M12 20V4', 'M6 10l6-6 6 6'),
    I('arrow-down', 'Arrow down', ['decrease'], 'M12 4v16', 'M6 14l6 6 6-6'),
    I('arrow-up-right', 'Arrow up right', ['external', 'growth'], 'M7 17L17 7', 'M8 7h9v9'),
    I('chevron-right', 'Chevron right', ['next'], 'M9 5l7 7-7 7'),
    I('chevron-down', 'Chevron down', ['expand'], 'M5 9l7 7 7-7'),
    I('check', 'Check', ['done', 'yes'], 'M4 12.5l5 5L20 6.5'),
    I('check-circle', 'Check in circle', ['done', 'success'], circle(12, 12, 9), 'M8 12.5l3 3 5-6'),
    I('cross', 'Cross', ['close', 'no'], 'M6 6l12 12', 'M18 6L6 18'),
    I('cross-circle', 'Cross in circle', ['error', 'fail'], circle(12, 12, 9), 'M9 9l6 6', 'M15 9l-6 6'),
    I('plus', 'Plus', ['add'], 'M12 5v14', 'M5 12h14'),
    I('minus', 'Minus', ['remove', 'subtract'], 'M5 12h14'),
    I('refresh', 'Refresh', ['cycle', 'repeat', 'loop'], 'M20 11a8 8 0 0 0-14.3-4', 'M5 3v4h4', 'M4 13a8 8 0 0 0 14.3 4', 'M19 21v-4h-4'),
    // people and places
    I('user', 'Person', ['profile', 'customer'], circle(12, 8, 4), 'M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7'),
    I('users', 'People', ['team', 'group'], circle(9, 8, 3.5), 'M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6', 'M16 4.6a3.5 3.5 0 0 1 0 6.8', 'M18 14.3c2.2.7 3.5 2.6 3.5 5.7'),
    I('building', 'Building', ['office', 'company'], box(5, 3, 14, 18, 1), 'M9 8h2', 'M13 8h2', 'M9 12h2', 'M13 12h2', 'M10 21v-4h4v4'),
    I('home', 'Home', ['house'], 'M3 11l9-8 9 8', 'M5 10v10h14V10', 'M10 20v-6h4v6'),
    I('factory', 'Factory', ['plant', 'industry', 'manufacturing'], 'M3 21V10l6 4V10l6 4V6h3l3 0v15Z', 'M7 17h2', 'M12 17h2', 'M17 17h2'),
    I('hard-hat', 'Hard hat', ['safety', 'site', 'mine'], 'M4 17h16', 'M5 17a7 7 0 0 1 14 0', 'M12 6v5', 'M9 8.5l-1 3', 'M15 8.5l1 3', 'M3 17v2h18v-2'),
    I('pickaxe', 'Pickaxe', ['mine', 'mining', 'dig'], 'M3 10.5C6 5 15 4 21 10C17 7.5 8 7.5 3 10.5Z', 'M12 8.3V21'),
    I('cone', 'Safety cone', ['hazard', 'road', 'safety'], 'M12 3l6 15H6Z', 'M9.2 11h5.6', 'M3 21h18', 'M5 18l-1 3', 'M19 18l1 3'),
    I('truck', 'Truck', ['delivery', 'logistics', 'transport'], 'M2 6h11v10H2Z', 'M13 9h4l4 4v3h-8', circle(7, 17.5, 2), circle(17, 17.5, 2)),
    I('globe', 'Globe', ['world', 'international', 'web'], circle(12, 12, 9), 'M3 12h18', 'M12 3c3 3 4.5 6 4.5 9S15 18 12 21c-3-3-4.5-6-4.5-9S9 6 12 3Z'),
    I('pin', 'Location pin', ['map', 'place', 'location'], 'M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21Z', circle(12, 9.5, 2.5)),
    I('compass', 'Compass', ['direction', 'navigate'], circle(12, 12, 9), 'M15.5 8.5l-2 5-5 2 2-5Z'),
    // data and finance
    I('chart-bar', 'Bar chart', ['analytics', 'report'], 'M4 20h16', 'M6 20v-7h3v7', 'M10.5 20V6h3v14', 'M15 20v-10h3v10'),
    I('chart-line', 'Line chart', ['trend', 'analytics'], 'M3 3v18h18', 'M7 15l4-5 3 3 6-7'),
    I('chart-pie', 'Pie chart', ['share', 'split'], 'M12 3v9h9', 'M20.5 15A9 9 0 1 1 9 3.6'),
    I('trending-up', 'Trending up', ['growth', 'increase'], 'M3 17l6-6 4 4 8-8', 'M15 7h6v6'),
    I('trending-down', 'Trending down', ['decline', 'decrease'], 'M3 7l6 6 4-4 8 8', 'M15 17h6v-6'),
    I('target', 'Target', ['goal', 'aim'], circle(12, 12, 9), circle(12, 12, 5), circle(12, 12, 1)),
    I('money', 'Money', ['cash', 'revenue', 'price'], box(2.5, 6, 19, 12, 1.5), circle(12, 12, 2.8), 'M6 9.5v.01', 'M18 14.5v.01'),
    I('percent', 'Percent', ['discount', 'rate'], 'M19 5L5 19', circle(7, 7, 2.5), circle(17, 17, 2.5)),
    I('scale', 'Scales', ['balance', 'legal', 'compare'], 'M12 3v18', 'M7 21h10', 'M5 6h14', 'M5 6l-3 7a3 3 0 0 0 6 0Z', 'M19 6l-3 7a3 3 0 0 0 6 0Z'),
    I('card', 'Payment card', ['credit', 'billing'], box(2.5, 5, 19, 14, 2), 'M2.5 10h19', 'M6 15h4'),
    I('cart', 'Cart', ['shop', 'order', 'purchase'], 'M3 4h2.5l2 11h10.5l2-8H6.5', circle(9, 19.5, 1.4), circle(17, 19.5, 1.4)),
    I('briefcase', 'Briefcase', ['work', 'business'], box(3, 7, 18, 13, 2), 'M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2', 'M3 13h18'),
    I('layers', 'Layers', ['stack', 'levels'], 'M12 3l9 5-9 5-9-5Z', 'M3 12.5l9 5 9-5', 'M3 16.5l9 5 9-5'),
    I('grid', 'Grid', ['table', 'tiles', 'dashboard'], box(4, 4, 7, 7, 1), box(13, 4, 7, 7, 1), box(4, 13, 7, 7, 1), box(13, 13, 7, 7, 1)),
    I('list', 'List', ['bullets', 'agenda'], 'M9 6h11', 'M9 12h11', 'M9 18h11', 'M4.5 6v.01', 'M4.5 12v.01', 'M4.5 18v.01'),
    I('database', 'Database', ['data', 'storage'], 'M4 6c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3Z', 'M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6', 'M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3'),
    I('server', 'Server', ['hosting', 'infrastructure'], box(3, 4, 18, 6, 1.5), box(3, 14, 18, 6, 1.5), 'M7 7v.01', 'M7 17v.01'),
    I('cloud', 'Cloud', ['online', 'hosting'], 'M7 18a4.5 4.5 0 0 1-.6-8.96A6 6 0 0 1 18 9a4.5 4.5 0 0 1 0 9Z'),
    I('code', 'Code', ['developer', 'software'], 'M8 7l-5 5 5 5', 'M16 7l5 5-5 5', 'M14 4l-4 16'),
    // communication
    I('mail', 'Mail', ['email', 'message'], box(3, 5, 18, 14, 2), 'M3.5 7l8.5 6.5L20.5 7'),
    I('phone', 'Phone', ['call', 'contact'], 'M6.5 3h3l1.5 4.5-2 1.5a11 11 0 0 0 5 5l1.5-2L20 13.5v3a2.5 2.5 0 0 1-2.7 2.5C10 18.5 5.5 14 5 6.2A2.5 2.5 0 0 1 6.5 3Z'),
    I('chat', 'Chat bubble', ['comment', 'message', 'discuss'], 'M4 5h16v11H11l-5 4v-4H4Z'),
    I('quote', 'Quote', ['testimonial', 'citation'], 'M10 7H6a1 1 0 0 0-1 1v4a1 1 0 0 0 1 1h3v1.5a3 3 0 0 1-3 3', 'M19 7h-4a1 1 0 0 0-1 1v4a1 1 0 0 0 1 1h3v1.5a3 3 0 0 1-3 3'),
    I('bell', 'Bell', ['notification', 'alert'], 'M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15Z', 'M10 21h4'),
    I('share', 'Share', ['send', 'distribute'], circle(6, 12, 2.5), circle(18, 6, 2.5), circle(18, 18, 2.5), 'M8.2 10.8l7.6-3.6', 'M8.2 13.2l7.6 3.6'),
    // files and media
    I('document', 'Document', ['file', 'page', 'report'], 'M6 3h8l5 5v13H6Z', 'M14 3v5h5', 'M9 13h7', 'M9 17h7'),
    I('folder', 'Folder', ['files', 'directory'], 'M3 6a1 1 0 0 1 1-1h5l2 2.5h9a1 1 0 0 1 1 1V19a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1Z'),
    I('link', 'Link', ['url', 'connect', 'chain'], 'M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1', 'M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1'),
    I('download', 'Download', ['save', 'import'], 'M12 4v11', 'M7 11l5 5 5-5', 'M5 20h14'),
    I('upload', 'Upload', ['export', 'publish'], 'M12 16V5', 'M7 9l5-5 5 5', 'M5 20h14'),
    I('image', 'Image', ['photo', 'picture'], box(3, 4.5, 18, 15, 2), circle(8.5, 9.5, 1.5), 'M21 16l-5-5-8 8.5'),
    I('camera', 'Camera', ['photo', 'capture'], 'M4 7h3l1.5-2.5h7L17 7h3a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1Z', circle(12, 13, 3.5)),
    I('mic', 'Microphone', ['audio', 'voice', 'podcast'], box(9, 3, 6, 11, 3), 'M5.5 11a6.5 6.5 0 0 0 13 0', 'M12 17.5V21', 'M8.5 21h7'),
    I('play', 'Play', ['video', 'start'], 'M8 5.5v13l11-6.5Z'),
    I('pause', 'Pause', ['hold'], 'M8 5v14', 'M16 5v14'),
    I('search', 'Search', ['find', 'magnify'], circle(10.5, 10.5, 6.5), 'M15.5 15.5L21 21'),
    I('eye', 'Eye', ['view', 'visible', 'watch'], 'M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z', circle(12, 12, 3)),
    I('edit', 'Pencil', ['write', 'change'], 'M4 20l1-4L16.5 4.5a2.1 2.1 0 0 1 3 3L8 19Z', 'M14.5 6.5l3 3'),
    I('trash', 'Bin', ['delete', 'remove'], 'M4 7h16', 'M9 7V4h6v3', 'M6 7l1 13h10l1-13', 'M10 11v6', 'M14 11v6'),
    I('tag', 'Tag', ['label', 'price'], 'M3 12V4h8l10 10-8 8Z', 'M7.5 8v.01'),
    I('bookmark', 'Bookmark', ['save', 'favourite'], 'M6 3h12v18l-6-4.5L6 21Z'),
    I('calendar', 'Calendar', ['date', 'schedule', 'event'], box(3.5, 5, 17, 15, 2), 'M3.5 10h17', 'M8 3v4', 'M16 3v4'),
    I('clock', 'Clock', ['time', 'deadline'], circle(12, 12, 9), 'M12 7v5l3.5 2'),
    // security and status
    I('lock', 'Lock', ['secure', 'private'], box(5, 10.5, 14, 10, 2), 'M8 10.5V7.5a4 4 0 0 1 8 0v3'),
    I('unlock', 'Unlocked', ['open', 'access'], box(5, 10.5, 14, 10, 2), 'M8 10.5V7.5a4 4 0 0 1 7.5-1.9'),
    I('key', 'Key', ['access', 'password'], circle(8, 15, 4), 'M11 12l9-9', 'M17 6l3 3', 'M14.5 8.5l2 2'),
    I('shield', 'Shield', ['protect', 'safety', 'security'], 'M12 3l8 3v6c0 4.7-3.2 8.2-8 9.5C7.2 20.2 4 16.7 4 12V6Z'),
    I('shield-check', 'Shield with check', ['verified', 'compliant'], 'M12 3l8 3v6c0 4.7-3.2 8.2-8 9.5C7.2 20.2 4 16.7 4 12V6Z', 'M8.5 12l2.5 2.5L15.5 10'),
    I('flag', 'Flag', ['milestone', 'goal'], 'M5 21V4', 'M5 4h12l-2 4 2 4H5'),
    I('info', 'Information', ['about', 'note'], circle(12, 12, 9), 'M12 11v6', 'M12 7.5v.01'),
    I('warning', 'Warning', ['risk', 'caution', 'alert'], 'M12 3.5l10 17.5H2Z', 'M12 10v5', 'M12 18v.01'),
    I('question', 'Question', ['help', 'faq'], circle(12, 12, 9), 'M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 1-1 1.7', 'M12 17v.01'),
    // ideas, tools, symbols
    I('star', 'Star', ['favourite', 'rating'], 'M12 3l2.7 5.8 6.3.8-4.6 4.4 1.2 6.3L12 17.2l-5.6 3.1 1.2-6.3L3 9.6l6.3-.8Z'),
    I('heart', 'Heart', ['like', 'care', 'love'], 'M12 20S4 14.6 4 9a4.5 4.5 0 0 1 8-2.5A4.5 4.5 0 0 1 20 9c0 5.6-8 11-8 11Z'),
    I('bolt', 'Lightning', ['energy', 'power', 'fast'], 'M13 2L4 14h7l-1 8 9-12h-7Z'),
    I('lightbulb', 'Light bulb', ['idea', 'insight'], 'M9 18h6', 'M10 21h4', 'M12 3a6 6 0 0 0-3.5 10.9c.6.5 1 1.2 1 2.1h5c0-.9.4-1.6 1-2.1A6 6 0 0 0 12 3Z'),
    I('gear', 'Gear', ['settings', 'process', 'configure'], circle(12, 12, 3), 'M12 2.5l1.3 2.4 2.7-.6.9 2.6 2.6.9-.6 2.7L21.3 12l-2.4 1.3.6 2.7-2.6.9-.9 2.6-2.7-.6L12 21.5l-1.3-2.4-2.7.6-.9-2.6-2.6-.9.6-2.7L2.7 12l2.4-1.3-.6-2.7 2.6-.9.9-2.6 2.7.6Z'),
    I('wrench', 'Wrench', ['tool', 'maintenance', 'repair'], 'M14.5 6.5a4 4 0 0 0 5 5L21 13l-8 8a2.1 2.1 0 0 1-3-3l8-8-1.5-1.5Z', 'M14.5 6.5a4 4 0 0 1 4-4l-2.5 2.5 1.5 1.5L20 4.5a4 4 0 0 1-5.5 2'),
    I('rocket', 'Rocket', ['launch', 'startup'], 'M12 2c3.5 2 5.5 6 5.5 10L15 17H9l-2.5-5C6.5 8 8.5 4 12 2Z', circle(12, 10, 1.8), 'M9 17l-2 4', 'M15 17l2 4', 'M6.5 13l-2.5 3 3 .5', 'M17.5 13l2.5 3-3 .5'),
    I('trophy', 'Trophy', ['win', 'award', 'achievement'], 'M8 4h8v5a4 4 0 0 1-8 0Z', 'M8 6H4v1a3.5 3.5 0 0 0 4 3.5', 'M16 6h4v1a3.5 3.5 0 0 1-4 3.5', 'M12 13v4', 'M8.5 21h7', 'M9.5 21l.5-4h4l.5 4'),
    I('award', 'Award ribbon', ['badge', 'certificate', 'quality'], circle(12, 9, 6), 'M8.5 14l-1.5 7 5-3 5 3-1.5-7'),
    I('handshake', 'Handshake', ['deal', 'partnership', 'agreement'], 'M2 12l4-4 4 1 3-1.5L18 8l4 4', 'M2 12l3 3', 'M6 15l2.5 2.5a1.4 1.4 0 0 0 2-2', 'M9.5 14.5l2 2a1.4 1.4 0 0 0 2-2', 'M13 14l1.5 1.5a1.4 1.4 0 0 0 2-2L13.5 10.5', 'M22 12l-3 3'),
    I('puzzle', 'Puzzle piece', ['fit', 'integration', 'solution'], 'M10 4a2 2 0 1 1 4 0v1h4v4h1a2 2 0 1 1 0 4h-1v5H13v-1a2 2 0 1 0-4 0v1H4v-5h1a2 2 0 1 0 0-4H4V5h6Z'),
    I('magnet', 'Magnet', ['attract', 'pull'], 'M5 3v8a7 7 0 0 0 14 0V3', 'M5 3h4v8a3 3 0 0 0 6 0V3h4', 'M5 7h4', 'M15 7h4'),
    I('leaf', 'Leaf', ['environment', 'sustainability', 'green'], 'M5 19C5 10 10 5 20 4c0 10-5 15-14 15', 'M5 19c3-5 6-8 11-10'),
    I('sun', 'Sun', ['day', 'energy', 'solar'], circle(12, 12, 4), 'M12 2.5v2', 'M12 19.5v2', 'M2.5 12h2', 'M19.5 12h2', 'M5.3 5.3l1.4 1.4', 'M17.3 17.3l1.4 1.4', 'M18.7 5.3l-1.4 1.4', 'M6.7 17.3l-1.4 1.4'),
    I('droplet', 'Droplet', ['water', 'liquid'], 'M12 3s6 6.2 6 11a6 6 0 0 1-12 0c0-4.8 6-11 6-11Z'),
    I('battery', 'Battery', ['power', 'charge'], box(2.5, 7.5, 16, 9, 2), 'M21.5 10.5v3', 'M6 10.5v3', 'M9 10.5v3'),
    I('wifi', 'Signal', ['network', 'connect'], 'M2.5 9a14 14 0 0 1 19 0', 'M5.5 12.5a9.5 9.5 0 0 1 13 0', 'M8.6 16a5 5 0 0 1 6.8 0', 'M12 19.5v.01'),
    I('mountain', 'Mountain', ['terrain', 'peak', 'summit'], 'M2 20l7-12 4 6 2.5-4L22 20Z', 'M7 11.5l2 1.5 1.5-1'),
];

export const ICON_IDS = ICONS.map((i) => i.id);

const BY_ID = new Map(ICONS.map((i) => [i.id, i]));

export function getIcon(id) {
    return BY_ID.get(id) || null;
}

const attr = (s) => String(s).replace(/[^\w#(),.%\- ]/g, '');

export function iconSvg(id, { color = 'currentColor', sw = DEFAULT_ICON_SW, size = 24 } = {}) {
    const icon = getIcon(id);
    if (!icon) return '';
    const strokeW = Math.min(6, Math.max(0.5, +sw || DEFAULT_ICON_SW));
    const paths = icon.d.map((d) => `<path d="${d}"/>`).join('');
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="${+size || 24}" height="${+size || 24}" fill="none" stroke="${attr(color)}" stroke-width="${strokeW}" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths}</svg>`;
}
