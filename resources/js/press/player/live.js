// Live session client: short polling against the present session endpoints. Plain fetch, no websockets (the
// platform runs on shared hosting). All functions are small and take injectable fetch for tests.

export const POLL_MS = 1000;

/** Parse a state response body into a clean object, or null. */
export function parseLiveState(body) {
    if (!body || typeof body !== 'object') return null;
    const n = (v, max) => {
        const x = Math.floor(+v);
        return Number.isFinite(x) ? Math.min(max, Math.max(0, x)) : 0;
    };
    const text = (v, max) => (typeof v === 'string' ? v.slice(0, max) : '');
    const out = {
        slide: n(body.slide, 5000),
        step: n(body.step, 5000),
        cover: body.cover === 'black' || body.cover === 'white' ? body.cover : null,
        ended: !!body.ended,
        rev: n(body.rev, Number.MAX_SAFE_INTEGER),
        count: n(body.count, 5000),
    };
    // Only a remote that holds the key receives these.
    if (typeof body.title === 'string') out.title = text(body.title, 200);
    if (typeof body.notes === 'string') out.notes = text(body.notes, 20000);
    if (typeof body.next_title === 'string') out.nextTitle = text(body.next_title, 200);
    return out;
}

/**
 * Poll /live/{token}/state every second with ETag support. Calls onState with each changed state (the first one
 * always), onEnded once if the session ends, onError with a message when the network fails (it keeps trying).
 * Returns a stop function.
 */
export function followSession({ token, key = null, onState, onEnded, onError, fetchImpl = globalThis.fetch?.bind(globalThis), interval = POLL_MS }) {
    let etag = null;
    let stopped = false;
    let timer = null;
    let failures = 0;
    async function tick() {
        if (stopped) return;
        try {
            const headers = { Accept: 'application/json' };
            if (etag) headers['If-None-Match'] = etag;
            const qs = key ? `?k=${encodeURIComponent(key)}` : '';
            const res = await fetchImpl(`/live/${encodeURIComponent(token)}/state${qs}`, { headers, credentials: 'omit', cache: 'no-store' });
            if (res.status === 304) {
                failures = 0;
            } else if (res.status === 404 || res.status === 410) {
                onEnded?.();
                stopped = true;
                return;
            } else if (res.ok) {
                failures = 0;
                const tag = res.headers.get('ETag');
                if (tag) etag = tag;
                const state = parseLiveState(await res.json());
                if (state) {
                    onState?.(state);
                    if (state.ended) {
                        onEnded?.();
                        stopped = true;
                        return;
                    }
                }
            } else if (res.status === 429) {
                failures++;
                onError?.('Too many requests. Slowing down.');
            } else {
                failures++;
                onError?.('The live session could not be reached.');
            }
        } catch {
            failures++;
            onError?.('You are offline. Waiting for the connection.');
        }
        if (!stopped) timer = setTimeout(tick, Math.min(8000, interval * (1 + Math.min(failures, 6))));
    }
    tick();
    return () => {
        stopped = true;
        clearTimeout(timer);
    };
}

/** Send a remote command (next, prev, blackout...) to a session. */
export async function sendCommand({ token, key, action, fetchImpl = globalThis.fetch?.bind(globalThis) }) {
    const res = await fetchImpl(`/live/${encodeURIComponent(token)}/command`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        credentials: 'omit',
        body: JSON.stringify({ action, k: key }),
    });
    return res.ok;
}

/** Collapse a stream of positions into at most one request every `wait` ms with the latest value. */
export function createThrottle(fn, wait = 250) {
    let last = 0;
    let timer = null;
    let latest;
    return (value) => {
        latest = value;
        const now = Date.now();
        const run = () => {
            last = Date.now();
            timer = null;
            fn(latest);
        };
        if (timer) return;
        if (now - last >= wait) run();
        else timer = setTimeout(run, wait - (now - last));
    };
}
