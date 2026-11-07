<script setup>
// PresentPlayer: full screen present for one deck on one screen. Used by Press/Player.vue and Press/Shared.vue,
// and (with offline = true) by the standalone present package. It owns input and chrome; PlayerStage owns the
// picture.
//
// Props
//   deck, slides, assets   the presentation document (spec sections 4, 5 and 9)
//   startIndex             slide to open on (index into slides); a #<n> hash in the URL (1 based) wins
//   mode                   'present' (the presenter's own screen: notes overlay available) | 'shared' (read only viewer)
//   exitUrl                where Esc / the close button leads. Omit to hide the exit button (offline package)
//   offline                no network at all: embeds are never loaded
//   title                  page title for the accessible name
// Events: position({slide, step}), exit
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import '../../../css/press-player.css';
import PlayerStage from './PlayerStage.vue';
import ChromeIcon from './ChromeIcon.vue';
import SlideView from '../render/SlideView.vue';
import { createPlan, samePos } from './state.js';
import { createNumberBuffer, isEditable, mapKey } from './keys.js';
import { ensureFonts } from '../fonts.js';

const props = defineProps({
    deck: { type: Object, required: true },
    slides: { type: Array, required: true },
    assets: { type: Object, default: () => ({}) },
    startIndex: { type: Number, default: 0 },
    mode: { type: String, default: 'present' },
    exitUrl: { type: String, default: null },
    offline: { type: Boolean, default: false },
    title: { type: String, default: '' },
});
const emit = defineEmits(['position', 'exit']);

const plan = computed(() => createPlan(props.slides, props.deck));
const root = ref(null);
function initialPos() {
    const hash = typeof location !== 'undefined' ? parseInt((location.hash || '').replace('#', ''), 10) : NaN;
    const start = Number.isFinite(hash) && hash >= 1 ? hash - 1 : props.startIndex;
    const first = plan.value.goto(Number.isFinite(+start) ? start : 0, 0);
    return plan.value.order.includes(first.slide) ? first : plan.value.jumpNumber(first.slide + 1);
}
const pos = ref(initialPos());
const cover = ref(null);
const gridOn = ref(false);
const notesOn = ref(false);
const uiOn = ref(true);
const idle = ref(false);
const fullscreen = ref(false);
const hintOn = ref(false);
const toast = ref('');
const toastOn = ref(false);
const jumpText = ref('');
const lasering = ref(false);
const laserEl = ref(null);
const announce = ref('');
const notesSize = ref(18);

const numbers = createNumberBuffer(4);
const canNotes = computed(() => props.mode === 'present');
const hasSlides = computed(() => props.slides.length > 0);
const current = computed(() => props.slides[pos.value.slide] || null);
const counter = computed(() => ({ n: pos.value.slide + 1, of: Math.max(1, props.slides.length) }));
const notes = computed(() => (canNotes.value && current.value && typeof current.value.notes === 'string' ? current.value.notes : ''));
const atEnd = computed(() => plan.value.isLast(pos.value));
const atStart = computed(() => plan.value.isFirst(pos.value));

// ---- position ----
function setPos(p, { quiet = false } = {}) {
    const next = plan.value.clamp(p);
    if (samePos(next, pos.value)) return false;
    pos.value = next;
    if (!quiet) cover.value = null;
    emit('position', { ...next });
    return true;
}
const go = {
    next() {
        const n = plan.value.next(pos.value);
        if (!setPos(n)) flash('End of presentation');
    },
    prev: () => setPos(plan.value.prev(pos.value)),
    first: () => setPos(plan.value.first()),
    last: () => setPos(plan.value.last()),
    number: (n) => setPos(plan.value.jumpNumber(n)),
    slide: (i) => setPos({ slide: i, step: 0 }),
};

function flash(text) {
    toast.value = text;
    toastOn.value = true;
    clearTimeout(flash.t);
    flash.t = setTimeout(() => (toastOn.value = false), 2200);
}

function onLink(id) {
    const i = props.slides.findIndex((s) => String(s.id) === String(id));
    if (i >= 0) go.slide(i);
}

// ---- chrome visibility ----
let hideTimer = 0;
function wake() {
    uiOn.value = true;
    idle.value = false;
    clearTimeout(hideTimer);
    hideTimer = setTimeout(() => {
        if (gridOn.value || notesOn.value) return;
        uiOn.value = false;
        idle.value = true;
    }, 2000);
}

// ---- keyboard ----
function onKey(e) {
    if (isEditable(e.target)) return;
    if (gridOn.value && ['ArrowRight', 'ArrowLeft', 'ArrowUp', 'ArrowDown', 'Enter', ' '].includes(e.key) && e.target?.closest?.('.pp-grid')) {
        if (e.key.startsWith('Arrow')) {
            moveGridFocus(e);
        }
        return;
    }
    const m = mapKey(e, { typing: numbers.active });
    if (!m) return;
    if (m.action === 'laser') {
        if (!e.repeat) setLaser(true);
        e.preventDefault();
        return;
    }
    e.preventDefault();
    switch (m.action) {
        case 'digit':
            jumpText.value = numbers.push(m.digit);
            clearTimeout(onKey.t);
            onKey.t = setTimeout(clearJump, 2500);
            return;
        case 'digit-back':
            jumpText.value = numbers.back();
            return;
        case 'jump': {
            const n = numbers.commit();
            clearJump();
            if (n != null) go.number(n);
            return;
        }
        case 'next':
            if (gridOn.value) return;
            return go.next();
        case 'prev':
            if (gridOn.value) return;
            return go.prev();
        case 'first':
            return go.first();
        case 'last':
            return go.last();
        case 'grid':
            return toggleGrid();
        case 'blackout':
            return toggleCover('black');
        case 'whiteout':
            return toggleCover('white');
        case 'notes':
            return canNotes.value && toggleNotes();
        case 'fullscreen':
            return toggleFullscreen();
        case 'escape':
            return onEscape();
    }
}
function onKeyUp(e) {
    if (e.key && e.key.toLowerCase() === 'l') setLaser(false);
}
// A focused control inside the slide (a video's native controls, a link) disappears with the slide, and focus falls to
// <body>, where the root's own key handler never hears the arrows again. While nothing has focus the document hears them.
function onDocKey(e) {
    if (document.activeElement && document.activeElement !== document.body) return;
    onKey(e);
}
function onDocKeyUp(e) {
    if (document.activeElement && document.activeElement !== document.body) return;
    onKeyUp(e);
}
function clearJump() {
    numbers.clear();
    jumpText.value = '';
}
function onEscape() {
    if (numbers.active) return clearJump();
    if (gridOn.value) return (gridOn.value = false);
    if (notesOn.value) return (notesOn.value = false);
    if (cover.value) return (cover.value = null);
    if (document.fullscreenElement) return document.exitFullscreen?.();
    exit();
}
function exit() {
    emit('exit');
    if (props.exitUrl) window.location.assign(props.exitUrl);
}

function toggleCover(kind) {
    cover.value = cover.value === kind ? null : kind;
}
function toggleGrid() {
    gridOn.value = !gridOn.value;
    if (gridOn.value) {
        nextTick(() => root.value?.querySelector('.pp-thumb.is-now')?.focus({ preventScroll: false }));
    } else root.value?.focus();
}
function toggleNotes() {
    notesOn.value = !notesOn.value;
    if (notesOn.value) wake();
}
async function toggleFullscreen() {
    try {
        if (document.fullscreenElement) await document.exitFullscreen();
        else await (root.value?.requestFullscreen?.() ?? Promise.reject(new Error('unsupported')));
    } catch {
        flash('Fullscreen is not available here');
    }
}
function onFullscreenChange() {
    fullscreen.value = !!document.fullscreenElement;
}
function pickSlide(i) {
    gridOn.value = false;
    go.slide(i);
    root.value?.focus();
}
function moveGridFocus(e) {
    const items = [...root.value.querySelectorAll('.pp-thumb')];
    const i = items.indexOf(document.activeElement);
    if (i < 0) return;
    const cols = Math.max(1, getComputedStyle(root.value.querySelector('.pp-grid-list')).gridTemplateColumns.split(' ').length);
    const d = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: cols, ArrowUp: -cols }[e.key];
    const t = items[Math.min(items.length - 1, Math.max(0, i + d))];
    if (t) {
        e.preventDefault();
        t.focus();
    }
}

// ---- pointer: click to advance, tap zones, swipe, laser ----
let down = null;
let holdTimer = 0;
let suppressClick = false;
const INTERACTIVE = 'button, a, input, textarea, select, video, audio, iframe, [role="link"], .pp-bar, .pp-notes, .pp-grid, .pp-gate, .pp-jump';

function onPointerDown(e) {
    wake();
    if (e.button !== 0 && e.pointerType === 'mouse') return;
    if (e.target.closest?.(INTERACTIVE)) {
        down = null;
        return;
    }
    down = { x: e.clientX, y: e.clientY, t: Date.now(), type: e.pointerType, id: e.pointerId, moved: false };
    clearTimeout(holdTimer);
    holdTimer = setTimeout(() => {
        if (down && !down.moved) {
            setLaser(true);
            moveLaser(down.x, down.y);
            suppressClick = true;
        }
    }, 480);
}
function onPointerMove(e) {
    wake();
    if (lasering.value) moveLaser(e.clientX, e.clientY);
    if (down && Math.hypot(e.clientX - down.x, e.clientY - down.y) > 10) {
        down.moved = true;
        clearTimeout(holdTimer);
    }
}
function onPointerUp(e) {
    clearTimeout(holdTimer);
    const d = down;
    down = null;
    if (lasering.value && (d || e.pointerType !== 'mouse')) {
        setLaser(false);
        suppressClick = true;
        return;
    }
    if (!d || cover.value && false) return;
    const dx = e.clientX - d.x;
    const dy = e.clientY - d.y;
    if (Math.abs(dx) > 48 && Math.abs(dx) > Math.abs(dy) * 1.4) {
        suppressClick = true;
        dx < 0 ? go.next() : go.prev();
        return;
    }
    if (d.moved) return;
    // a tap or click
    suppressClick = true;
    if (cover.value) {
        cover.value = null;
        return;
    }
    if (d.type === 'touch' || d.type === 'pen') {
        const w = root.value.clientWidth;
        e.clientX < w * 0.3 ? go.prev() : go.next();
    } else go.next();
}
function onPointerCancel() {
    clearTimeout(holdTimer);
    down = null;
    if (lasering.value) setLaser(false);
}
function onClickCapture(e) {
    if (suppressClick) {
        suppressClick = false;
        if (!e.target.closest?.(INTERACTIVE)) e.stopPropagation();
    }
}
function onContext(e) {
    if (lasering.value || down) e.preventDefault();
}

function setLaser(on) {
    if (on && (gridOn.value || cover.value)) return;
    lasering.value = on;
}
let lastPointer = { x: -100, y: -100 };
function moveLaser(x, y) {
    lastPointer = { x, y };
    if (laserEl.value) laserEl.value.style.transform = `translate(${x}px, ${y}px)`;
}
function trackPointer(e) {
    lastPointer = { x: e.clientX, y: e.clientY };
}
watch(lasering, (on) => {
    if (on) nextTick(() => moveLaser(lastPointer.x, lastPointer.y));
});

// ---- persistence of small preferences ----
function pref(key, def) {
    try {
        const v = window.localStorage.getItem(key);
        return v == null ? def : v;
    } catch {
        return def;
    }
}
function setPref(key, v) {
    try {
        window.localStorage.setItem(key, String(v));
    } catch {
        /* private mode: the preference just does not stick */
    }
}
function bumpNotes(d) {
    notesSize.value = Math.min(40, Math.max(12, notesSize.value + d));
    setPref('press.notes.size', notesSize.value);
}

// ---- a11y live announcement and url hash ----
watch(pos, (p) => {
    const n = plan.value.number(p);
    const t = props.slides[p.slide]?.title;
    announce.value = `Slide ${n.n} of ${n.of}${t ? ': ' + t : ''}`;
    try {
        if (n.of > 1) history.replaceState(null, '', `${location.pathname}${location.search}#${p.slide + 1}`);
    } catch {
        /* sandboxed frames cannot touch history */
    }
});

// ---- progress hairline ----
const segments = computed(() => {
    const p = plan.value;
    const now = pos.value;
    const list = p.slides.map((s, i) => ({ i, hidden: !!s.hidden }));
    const nowOrder = p.indexInOrder(now.slide);
    return list.map(({ i, hidden }) => {
        let f = 0;
        let state = '';
        const o = p.indexInOrder(i);
        if (i === now.slide) {
            const c = p.clicksOf(i);
            f = c > 0 ? (now.step + 1) / (c + 1) : 1;
            state = 'is-now';
        } else if (o >= 0 && nowOrder >= 0 && o < nowOrder) {
            f = 1;
            state = 'is-done';
        } else if (o < 0 && i < now.slide) {
            f = 1;
            state = 'is-done';
        }
        return { i, f, state, hidden };
    });
});
const solidProgress = computed(() => props.slides.length > 80);

onMounted(() => {
    if (!props.offline) ensureFonts();
    notesSize.value = Math.min(40, Math.max(12, +pref('press.notes.size', 18) || 18));
    emit('position', { ...pos.value });
    root.value?.focus({ preventScroll: true });
    wake();
    document.addEventListener('fullscreenchange', onFullscreenChange);
    document.addEventListener('keydown', onDocKey);
    document.addEventListener('keyup', onDocKeyUp);
    window.addEventListener('pointermove', trackPointer, { passive: true });
    if (window.matchMedia?.('(orientation: portrait) and (max-width: 640px)').matches && !pref('press.rotate.seen', null)) {
        flash('Rotate your phone for a larger slide');
        setPref('press.rotate.seen', '1');
    } else if (!pref('press.hint.seen', null)) {
        hintOn.value = true;
        setTimeout(() => {
            hintOn.value = false;
            setPref('press.hint.seen', '1');
        }, 4200);
    }
});
onBeforeUnmount(() => {
    clearTimeout(hideTimer);
    clearTimeout(holdTimer);
    document.removeEventListener('fullscreenchange', onFullscreenChange);
    document.removeEventListener('keydown', onDocKey);
    document.removeEventListener('keyup', onDocKeyUp);
    window.removeEventListener('pointermove', trackPointer);
});

defineExpose({ go, pos, toggleGrid, setPos });
</script>

<template>
    <div ref="root" class="pp-root" :class="{ 'pp-idle': idle && !gridOn, 'pp-ui-on': uiOn, 'pp-lasering': lasering }" tabindex="0" role="application" :aria-label="title || deck.title || 'Presentation'" @keydown="onKey" @keyup="onKeyUp" @pointerdown="onPointerDown" @pointermove="onPointerMove" @pointerup="onPointerUp" @pointercancel="onPointerCancel" @click.capture="onClickCapture" @contextmenu="onContext">
        <PlayerStage v-if="hasSlides" :deck="deck" :slides="slides" :assets="assets" :position="pos" :media="true" @link="onLink" />
        <div v-else class="pp-empty">This presentation has no slides.</div>

        <div class="pp-cover" :class="{ 'is-black': cover === 'black', 'is-white': cover === 'white' }" aria-hidden="true"></div>
        <div ref="laserEl" class="pp-laser" :class="{ 'is-on': lasering }" aria-hidden="true"></div>

        <div class="pp-progress" :class="{ 'pp-progress-solid': solidProgress }" aria-hidden="true">
            <template v-if="!solidProgress">
                <span v-for="s in segments" :key="s.i" class="pp-seg" :class="[s.state, { 'is-hidden': s.hidden }]" :style="{ '--f': s.f }"><i></i></span>
            </template>
            <span v-else class="pp-seg is-now" :style="{ '--f': plan.progress(pos) }"><i></i></span>
        </div>

        <div class="pp-bar pp-ui" role="toolbar" aria-label="Presentation controls">
            <button class="pp-btn" type="button" :disabled="atStart" aria-label="Previous" title="Previous (Left arrow)" @click="go.prev()"><ChromeIcon name="prev" /></button>
            <span class="pp-count" aria-hidden="true">{{ counter.n }} / {{ counter.of }}</span>
            <button class="pp-btn" type="button" :disabled="atEnd" aria-label="Next" title="Next (Right arrow)" @click="go.next()"><ChromeIcon name="next" /></button>
            <span class="pp-sep" aria-hidden="true"></span>
            <button class="pp-btn" type="button" :aria-pressed="gridOn" aria-label="All slides" title="All slides (G)" @click="toggleGrid"><ChromeIcon name="grid" /></button>
            <button v-if="canNotes" class="pp-btn" type="button" :aria-pressed="notesOn" aria-label="Speaker notes" title="Speaker notes (N)" @click="toggleNotes"><ChromeIcon name="notes" /></button>
            <button class="pp-btn pp-hide-sm" type="button" :aria-pressed="cover === 'black'" aria-label="Black screen" title="Black screen (B)" @click="toggleCover('black')"><ChromeIcon name="black" /></button>
            <button class="pp-btn" type="button" :aria-pressed="fullscreen" :aria-label="fullscreen ? 'Leave full screen' : 'Full screen'" title="Full screen (F)" @click="toggleFullscreen"><ChromeIcon :name="fullscreen ? 'fullscreen-exit' : 'fullscreen'" /></button>
            <slot name="actions" />
            <template v-if="exitUrl">
                <span class="pp-sep" aria-hidden="true"></span>
                <button class="pp-btn" type="button" aria-label="Close" title="Close (Esc)" @click="exit"><ChromeIcon name="close" /></button>
            </template>
        </div>

        <div v-if="canNotes" class="pp-notes pp-ui" :class="{ 'is-on': notesOn }" role="region" aria-label="Speaker notes" :aria-hidden="notesOn ? undefined : 'true'" :inert="!notesOn || undefined" :style="{ '--pp-notes-size': notesSize + 'px' }">
            <div class="pp-notes-head"><span>Notes for slide {{ counter.n }}</span><span><button class="pp-chip" type="button" aria-label="Smaller text" @click="bumpNotes(-2)">A-</button> <button class="pp-chip" type="button" aria-label="Larger text" @click="bumpNotes(2)">A+</button></span></div>
            <div class="pp-notes-body" :class="{ 'is-empty': !notes }" tabindex="0">{{ notes || 'No notes on this slide.' }}</div>
        </div>

        <div class="pp-jump" :class="{ 'is-on': jumpText }" aria-hidden="true">{{ jumpText }}</div>
        <div class="pp-toast" :class="{ 'is-on': toastOn }" role="status">{{ toastOn ? toast : '' }}</div>
        <div class="pp-hint" :class="{ 'is-on': hintOn }" aria-hidden="true"><kbd>Space</kbd> next &middot; <kbd>G</kbd> all slides &middot; <kbd>N</kbd> notes &middot; <kbd>B</kbd> black &middot; <kbd>F</kbd> full screen</div>

        <div class="pp-grid pp-ui" :class="{ 'is-on': gridOn }" role="dialog" aria-label="All slides" :aria-hidden="gridOn ? undefined : 'true'" :inert="!gridOn || undefined">
            <div class="pp-grid-head"><span>{{ deck.title }}</span><button class="pp-btn" type="button" aria-label="Close overview" @click="gridOn = false"><ChromeIcon name="close" /></button></div>
            <ol v-if="gridOn" class="pp-grid-list">
                <li v-for="(s, i) in slides" :key="s.id ?? i">
                    <button class="pp-thumb" type="button" :class="{ 'is-now': i === pos.slide, 'is-hidden': s.hidden }" :aria-current="i === pos.slide ? 'true' : undefined" @click="pickSlide(i)">
                        <span class="pp-thumb-frame"><SlideView :slide="s" :deck="deck" :assets="assets" mode="thumb" /></span>
                        <span class="pp-thumb-meta"><b>{{ i + 1 }}</b><span>{{ s.title || 'Untitled' }}</span><em v-if="s.hidden">hidden</em></span>
                    </button>
                </li>
            </ol>
        </div>

        <div class="pp-sr" role="status" aria-live="polite">{{ announce }}</div>
        <div v-if="$slots.default" class="pp-gate"><slot /></div>
    </div>
</template>
