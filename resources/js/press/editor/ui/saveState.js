/**
 * The words for the save bead (pure). `syncState` is sync.state: {status, pending, lastError, fatal, needsAuth, retryAt}.
 *
 *   saveInfo(syncState, {storageOk, readOnly}) -> { key, label, detail, action, tone }
 *     key     'saved' | 'saving' | 'offline' | 'error' | 'readonly'  (what the bead shape follows)
 *     label   the plain word shown next to the bead ("Saved", "Saving", "Offline: changes kept on this device", ...)
 *     action  null | 'retry' | 'discard' | 'reload'   which button belongs with it
 *     tone    'ok' | 'busy' | 'warn' | 'bad' | 'quiet'
 */
export function saveInfo(s, { storageOk = true, readOnly = false } = {}) {
  if (readOnly) return { key: 'readonly', label: 'View only', detail: 'You can read and comment on this deck, not change it.', action: null, tone: 'quiet' };
  if (!s) return { key: 'saved', label: 'Saved', detail: '', action: null, tone: 'ok' };
  if (s.needsAuth) {
    return { key: 'error', label: 'Could not save', detail: 'Your session ended. Reload and sign in again. Your changes are kept and will be offered back.', action: 'reload', tone: 'bad' };
  }
  if (s.fatal) {
    return { key: 'error', label: 'Could not save', detail: `The server refused a change${s.lastError ? `: ${s.lastError}` : ''}. Retry, or discard that change.`, action: 'discard', tone: 'bad' };
  }
  if (s.status === 'offline') {
    return {
      key: 'offline',
      label: storageOk ? 'Offline: changes kept on this device' : 'Offline: changes kept in this tab only',
      detail: storageOk ? 'They are sent as soon as the connection is back.' : 'This browser blocks local storage. Keep this tab open until you are back online.',
      action: 'retry',
      tone: 'warn',
    };
  }
  if (s.status === 'error') return { key: 'error', label: 'Could not save', detail: s.lastError || 'Something went wrong while saving.', action: 'retry', tone: 'bad' };
  if (s.status === 'saving' || (s.pending || 0) > 0) return { key: 'saving', label: 'Saving', detail: '', action: null, tone: 'busy' };
  return { key: 'saved', label: 'Saved', detail: '', action: null, tone: 'ok' };
}
