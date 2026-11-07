/**
 * How often an editing tab talks to the server when it is not saving anything. Pure functions (no DOM, no timers),
 * so the numbers can be tested and the benchmark (scripts/bench/http.mjs) can use the very same policy.
 *
 * The host has no websocket daemon, so everything is short polling and every poll is a PHP request. The budget this
 * file keeps (requests per minute for one open tab):
 *
 *   state                                              presence        changes feed
 *   active, other people in the deck                   20 (every 3 s)  when presence says the log moved
 *   active, alone                                      10 (every 6 s)  when presence says the log moved
 *   idle for a minute (no key, pointer or touch)       4 (every 15 s)  when presence says the log moved
 *   idle for five minutes                              2 (every 30 s)  when presence says the log moved
 *   hidden tab or window without focus                 none            1 safety poll every 30 s
 *
 * Presence answers with the current log position (`seq`), so "has anything changed" costs nothing extra: the changes
 * feed is read when that position is ahead of the tab's own, and otherwise once every `SAFETY_MS` (4 requests a minute
 * while focused, 2 while hidden) in case a presence answer was lost. Before this the tab did both on a 3 s clock:
 * 40 requests a minute whatever happened.
 *
 *   presenceMs({focused, hidden, idleMs, others})  -> ms until the next presence beat, or null for none
 *   changesSafetyMs({focused, hidden})             -> ms between safety polls of the changes feed
 *   needsChanges({presenceSeq, baseSeq, sinceLastPollMs}) -> true when the feed should be read now
 *   requestsPerMinute(state)                       -> expected requests per minute for a state (documentation and tests)
 */

export const ACTIVE_WITH_OTHERS_MS = 3000;
export const ACTIVE_ALONE_MS = 6000;
export const IDLE_MS = 15_000;
export const LONG_IDLE_MS = 30_000;
export const IDLE_AFTER_MS = 60_000;
export const LONG_IDLE_AFTER_MS = 300_000;
export const SAFETY_MS = { focused: 15_000, hidden: 30_000 };

export function presenceMs({ focused = true, hidden = false, idleMs = 0, others = 0 } = {}) {
  if (hidden || !focused) return null;
  if (idleMs >= LONG_IDLE_AFTER_MS) return LONG_IDLE_MS;
  if (idleMs >= IDLE_AFTER_MS) return IDLE_MS;
  return others > 0 ? ACTIVE_WITH_OTHERS_MS : ACTIVE_ALONE_MS;
}

export function changesSafetyMs({ focused = true, hidden = false } = {}) {
  return hidden || !focused ? SAFETY_MS.hidden : SAFETY_MS.focused;
}

export function needsChanges({ presenceSeq, baseSeq, sinceLastPollMs = 0, safetyMs = SAFETY_MS.focused } = {}) {
  if (Number.isFinite(presenceSeq) && Number.isFinite(baseSeq) && presenceSeq > baseSeq) return true;
  return sinceLastPollMs >= safetyMs;
}

export function requestsPerMinute(state) {
  const p = presenceMs(state);
  const presence = p === null ? 0 : 60_000 / p;
  const safety = 60_000 / changesSafetyMs(state);
  return { presence, safetyPolls: safety, total: presence + safety };
}

export const cadence = { presenceMs, changesSafetyMs, needsChanges, requestsPerMinute, IDLE_AFTER_MS, LONG_IDLE_AFTER_MS };
