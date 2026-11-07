// Presenter <-> audience window messaging over BroadcastChannel, with strict message validation.
// The presenter owns the state; the audience window mirrors it. All messages are plain data.
//
//   { t: 'hello', from: 'audience' }                         an audience window opened and wants the state
//   { t: 'state', slide, step, cover, seq }                  the presenter's position and screen cover ('black', 'white' or null)
//   { t: 'laser', x, y, on }                                 laser pointer in slide fractions 0..1
//   { t: 'bye', from }                                       a window closed
//   { t: 'cmd', action }                                     the audience window asks the presenter to move (never used by default)
//
// A window never trusts a message: parseMessage() rebuilds it from known fields with clamped numbers.

export const COVERS = ['black', 'white'];
export const ACTIONS = ['next', 'prev', 'first', 'last', 'blackout', 'whiteout'];

const int = (v, max) => {
    const n = Math.floor(+v);
    return Number.isFinite(n) ? Math.min(max, Math.max(0, n)) : 0;
};
const frac = (v) => {
    const n = +v;
    return Number.isFinite(n) ? Math.min(1, Math.max(0, n)) : 0;
};

export function parseMessage(raw) {
    if (!raw || typeof raw !== 'object') return null;
    switch (raw.t) {
        case 'hello':
            return { t: 'hello', from: 'audience' };
        case 'bye':
            return { t: 'bye', from: raw.from === 'presenter' ? 'presenter' : 'audience' };
        case 'state':
            return { t: 'state', slide: int(raw.slide, 5000), step: int(raw.step, 5000), cover: COVERS.includes(raw.cover) ? raw.cover : null, seq: int(raw.seq, Number.MAX_SAFE_INTEGER) };
        case 'laser':
            return { t: 'laser', x: frac(raw.x), y: frac(raw.y), on: !!raw.on };
        case 'cmd':
            return ACTIONS.includes(raw.action) ? { t: 'cmd', action: raw.action } : null;
        default:
            return null;
    }
}

/** A short random key that ties a presenter and its audience window together. */
export function newChannelKey() {
    const bytes = new Uint8Array(9);
    (globalThis.crypto || { getRandomValues: (a) => a.map(() => Math.floor(Math.random() * 256)) }).getRandomValues(bytes);
    return Array.from(bytes, (b) => b.toString(36).padStart(2, '0')).join('').slice(0, 12);
}

export function channelName(deckId, key) {
    return `dot-press:${String(deckId).replace(/[^\w-]/g, '')}:${String(key).replace(/[^\w-]/g, '')}`;
}

export function supportsChannel() {
    return typeof BroadcastChannel !== 'undefined';
}

/** @returns {{ send: (m: object) => void, close: () => void }|null} */
export function openChannel(name, onMessage) {
    if (!supportsChannel()) return null;
    const bc = new BroadcastChannel(name);
    bc.onmessage = (e) => {
        const m = parseMessage(e.data);
        if (m) onMessage(m);
    };
    return {
        send: (m) => {
            try {
                bc.postMessage(m);
            } catch {
                /* a closed channel has no listeners */
            }
        },
        close: () => bc.close(),
    };
}
