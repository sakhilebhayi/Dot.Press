// The life of one PDF or PowerPoint export, as a small state machine plus a driver that talks to the server.
// No Vue and no DOM: the clock, sleeping and the api are injected, so the whole thing runs under node:test.
//
//   phases     idle -> checking -> ready -> starting -> working -> done | failed
//   reduce(state, event)    pure transitions. Events: {type: 'check'} {type: 'checked', preflight} {type: 'checkFailed', error}
//                           {type: 'start'} {type: 'started', job, at} {type: 'polled', job, at} {type: 'pollError', error, at}
//                           {type: 'startFailed', error} {type: 'reset'} {type: 'stale'}
//   jobOutcome(job)         what a job row means: {phase, message?}. "done" only counts when a download link exists.
//   backoff(attempt)        delay before poll number `attempt` in ms (fast at first, then 8 s)
//   runExport({api, deckId, format, options, sleep, now, onState, signal})   POST, then poll until the file exists or it fails
//
// Rules the tests hold in place
//   - success is claimed only when the job is "done" and has a download_url (the file exists and has not expired)
//   - a failed job shows the server's plain message and can be retried (a new POST)
//   - a network blip or a 5xx while polling is retried a few times, a 404 or 403 is not
//   - queued for too long and working for too long are said out loud, never silently waited on

export const POLL_STEPS = [700, 1000, 1500, 2200, 3200, 4500, 6000, 8000];
export const MAX_POLL_ERRORS = 4;
export const SLOW_AFTER_MS = 3 * 60 * 1000;
export const GIVE_UP_AFTER_MS = 20 * 60 * 1000;

export const initialState = () => ({ phase: 'idle', preflight: null, job: null, error: null, retryable: false, startedAt: null, polls: 0, pollErrors: 0, slow: false, lastRetryAfter: 0 });

export function backoff(attempt) {
    return POLL_STEPS[Math.min(Math.max(0, attempt), POLL_STEPS.length - 1)];
}

export function jobOutcome(job) {
    if (!job || typeof job !== 'object') return { phase: 'failed', message: 'The server sent back something unexpected.' };
    switch (job.status) {
        case 'done':
            return job.download_url
                ? { phase: 'done' }
                : { phase: 'failed', message: 'The file is not available any more. Make a new export.' };
        case 'failed':
            return { phase: 'failed', message: job.error || 'The export failed. Try again; if it keeps failing, the deck may hold something the export cannot read.' };
        case 'expired':
            return { phase: 'failed', message: 'This export has expired. Make a new one.' };
        case 'queued':
        case 'running':
            return { phase: 'working' };
        default:
            return { phase: 'failed', message: 'The server sent back something unexpected.' };
    }
}

export function reduce(state, event) {
    switch (event.type) {
        case 'check':
            return { ...state, phase: 'checking', error: null, preflight: null, job: null };
        case 'checked':
            return { ...state, phase: 'ready', preflight: event.preflight, error: null };
        case 'checkFailed':
            return { ...state, phase: 'idle', error: event.error, retryable: true };
        case 'stale':
            // options changed: the preflight no longer describes what would be exported
            return state.phase === 'ready' || state.phase === 'idle' ? { ...state, phase: 'idle', preflight: null, error: null } : state;
        case 'start':
            return { ...state, phase: 'starting', error: null, job: null, polls: 0, pollErrors: 0, slow: false };
        case 'startFailed':
            return { ...state, phase: 'failed', error: event.error, retryable: event.retryable !== false };
        case 'started':
        case 'polled': {
            const o = jobOutcome(event.job);
            const next = { ...state, job: event.job, pollErrors: 0, startedAt: state.startedAt ?? event.at ?? null, polls: state.polls + (event.type === 'polled' ? 1 : 0) };
            if (o.phase === 'done') return { ...next, phase: 'done', error: null, retryable: false, slow: false };
            if (o.phase === 'failed') return { ...next, phase: 'failed', error: o.message, retryable: true, slow: false };
            const waited = event.at != null && next.startedAt != null ? event.at - next.startedAt : 0;
            return { ...next, phase: 'working', slow: waited >= SLOW_AFTER_MS };
        }
        case 'pollError': {
            const pollErrors = state.pollErrors + 1;
            if (!event.fatal && pollErrors <= MAX_POLL_ERRORS) return { ...state, pollErrors };
            return { ...state, phase: 'failed', pollErrors, error: event.error, retryable: true };
        }
        case 'giveUp':
            return { ...state, phase: 'failed', error: 'This is taking much longer than usual. The file may still appear under Recent exports in a few minutes.', retryable: true, slow: true };
        case 'reset':
            return initialState();
        default:
            return state;
    }
}

const isFatalPollError = (e) => e && (e.status === 403 || e.status === 404 || e.status === 401 || e.status === 419);

/**
 * Starts an export and follows it. Resolves with the final state; never throws. `onState` is called on every change.
 * `signal` stops the following (the job itself keeps running on the server and shows under Recent exports).
 */
export async function runExport({ api, deckId, format, options = {}, sleep, now = () => Date.now(), onState = () => {}, signal = null, describe = (e) => e?.message || 'The export could not be started.' }) {
    let state = reduce(initialState(), { type: 'start' });
    const push = (event) => { state = reduce(state, event); onState(state); return state; };
    onState(state);
    let job;
    try {
        job = await api.post(`/api/decks/${deckId}/exports`, { format, options });
    } catch (e) {
        return push({ type: 'startFailed', error: describe(e), retryable: e?.status !== 403 });
    }
    push({ type: 'started', job, at: now() });
    let attempt = 0;
    let waitedTooLong = false;
    while (state.phase === 'working') {
        if (signal?.aborted) return state;
        if (now() - state.startedAt >= GIVE_UP_AFTER_MS) {
            waitedTooLong = true;
            break;
        }
        let delay = backoff(attempt++);
        if (state.pollErrors > 0 && state.lastRetryAfter) delay = Math.max(delay, state.lastRetryAfter * 1000);
        await sleep(delay);
        if (signal?.aborted) return state;
        try {
            const next = await api.get(`/api/exports/${job.id}`);
            push({ type: 'polled', job: next, at: now() });
        } catch (e) {
            if (e?.retryAfter) state = { ...state, lastRetryAfter: e.retryAfter };
            push({ type: 'pollError', error: describe(e), fatal: isFatalPollError(e), at: now() });
        }
    }
    if (waitedTooLong) push({ type: 'giveUp' });
    return state;
}

/** The status word and progress hint for the bead while a job is working. */
export function workingWord(state) {
    const status = state?.job?.status;
    if (state?.phase === 'starting') return 'starting';
    if (status === 'queued') return state.slow ? 'still queued' : 'queued';
    if (status === 'running') return 'building';
    return 'working';
}

export function workingMessage(state, formatLabel = 'file') {
    const status = state?.job?.status;
    if (state?.phase === 'starting') return `Starting your ${formatLabel}.`;
    if (status === 'queued') {
        return state.slow
            ? `Your ${formatLabel} is still waiting its turn on the server. Big decks can take a few minutes; you can keep editing.`
            : `Your ${formatLabel} is in the queue. The server picks it up within a minute; you can keep editing.`;
    }
    if (status === 'running') return state.slow ? `Still building your ${formatLabel}. Big decks take a while.` : `Building your ${formatLabel}.`;
    return `Working on your ${formatLabel}.`;
}
