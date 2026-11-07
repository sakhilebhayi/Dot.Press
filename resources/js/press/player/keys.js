// Keyboard mapping for every present surface. Pure: takes a KeyboardEvent-like object, returns an action.
//
//   next      ArrowRight ArrowDown PageDown Space Enter(no number typed) N-key is not used for next
//   prev      ArrowLeft ArrowUp PageUp Backspace  (Shift+Space too)
//   first     Home        last  End
//   digit     0-9, collected by the caller into a slide number; Enter jumps, Escape cancels
//   grid      G           blackout B or .      whiteout W      notes N      fullscreen F
//   laser     L (hold)    escape   Escape
//
// Modified keys (Ctrl, Meta, Alt) are never ours.

export function mapKey(e, { typing = false } = {}) {
    if (!e || e.ctrlKey || e.metaKey || e.altKey) return null;
    const k = e.key;
    if (typeof k !== 'string') return null;
    if (/^[0-9]$/.test(k)) return { action: 'digit', digit: k };
    switch (k) {
        case 'ArrowRight':
        case 'ArrowDown':
        case 'PageDown':
            return { action: 'next' };
        case 'ArrowLeft':
        case 'ArrowUp':
        case 'PageUp':
        case 'Backspace':
            return { action: typing && k === 'Backspace' ? 'digit-back' : 'prev' };
        case ' ':
            return { action: e.shiftKey ? 'prev' : 'next' };
        case 'Enter':
            return { action: typing ? 'jump' : 'next' };
        case 'Home':
            return { action: 'first' };
        case 'End':
            return { action: 'last' };
        case 'Escape':
            return { action: 'escape' };
        case '.':
            return { action: 'blackout' };
        default:
    }
    switch (k.toLowerCase()) {
        case 'g':
            return { action: 'grid' };
        case 'b':
            return { action: 'blackout' };
        case 'w':
            return { action: 'whiteout' };
        case 'n':
            return { action: 'notes' };
        case 'f':
            return { action: 'fullscreen' };
        case 'l':
            return { action: 'laser' };
        default:
            return null;
    }
}

/** True when the key event came from a control that needs its own keys. */
export function isEditable(target) {
    if (!target || !target.tagName) return false;
    const tag = target.tagName.toLowerCase();
    return tag === 'input' || tag === 'textarea' || tag === 'select' || target.isContentEditable === true;
}

/** A tiny buffer for typing a slide number: digits, Backspace, Enter. */
export function createNumberBuffer(limit = 4) {
    let value = '';
    return {
        push(d) {
            value = (value + d).slice(-limit);
            return value;
        },
        back() {
            value = value.slice(0, -1);
            return value;
        },
        clear() {
            value = '';
        },
        get value() {
            return value;
        },
        get active() {
            return value !== '';
        },
        commit() {
            const n = value === '' ? null : parseInt(value, 10);
            value = '';
            return n;
        },
    };
}
