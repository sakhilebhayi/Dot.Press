// View models in the shape of Dot\Ui\PlatformSwitcher::for() (docs/SWITCHER.md).
const tile = (key, name, o = {}) => ({ key, name, href: `https://infodot.test/go/${key}?company=u`, current: false, plain: false, via_infodot: false, logo: `${key}.png`, ...o });

export const okModel = {
    state: 'ok', current: 'press', company: 'u', infodot_url: 'https://infodot.test', platforms_url: 'https://infodot.test/platforms',
    notice: null, message: null, count: 4,
    groups: [
        { code: 'WORK', tone: 'knowledge', name: 'Productivity and Collaboration', tiles: [tile('files', 'Dot.Files'), tile('press', 'Dot.Press', { href: null, current: true })] },
        { code: 'OPER', tone: 'files', name: 'Operations and Industry', tiles: [tile('mines', 'Dot.Mines', { plain: true }), tile('central', 'Dot.Central')] },
    ],
    more: [{ key: 'hr', name: 'Dot.HR', href: 'https://infodot.test/platforms/hr' }],
};
export const degradedModel = {
    state: 'degraded', current: 'press', company: 'u', infodot_url: 'https://infodot.test', platforms_url: 'https://infodot.test/platforms',
    notice: 'Could not check your platforms just now', message: null, count: 2,
    groups: [{ code: 'WORK', tone: 'knowledge', name: 'Productivity and Collaboration', tiles: [tile('files', 'Dot.Files', { via_infodot: true }), tile('docs', 'Dot.docs', { via_infodot: true })] }],
    more: [],
};
export const personalModel = {
    state: 'personal', current: 'press', company: null, infodot_url: 'https://infodot.test', platforms_url: 'https://infodot.test/platforms',
    notice: null, message: 'You are in your personal workspace, which has no company platforms to switch between. Switch to a company in InfoDot to see what it has.', count: 0, groups: [], more: [],
};
