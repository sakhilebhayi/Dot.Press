<script setup>
// DotSwitcher: the "Dot Apps" platform switcher. Same markup and classes as blade/switcher.blade.php (.dsw-*).
// It prints a view model computed on the SERVER (Dot\Ui\PlatformSwitcher) and decides nothing: no access, no URLs of
// its own, no fetching. A tile is a plain link to InfoDot's /go (a full page navigation, never router-driven).
// Props mirror the Blade component: model (the view model), companyName, logos (public path of img/platforms), id, notice and
// noticeDetail (override the degraded line; default text unchanged).
// A logo's printed size comes from `tile.logo_size` ([w, h]) when the host adds it; otherwise the CSS box sizes it.
// Events: open, close, navigate (the tile the person chose: { key, name, href }).
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';

const BUSY_MS = 12000;

const props = defineProps({
    model: { type: Object, default: null },
    companyName: { type: String, default: null },
    logos: { type: String, default: '/dot-ui/img/platforms' },
    id: { type: String, default: 'dsw-panel' },
    mode: { type: String, default: 'header' },
    notice: { type: String, default: null },
    noticeDetail: { type: String, default: null },
});
const emit = defineEmits(['open', 'close', 'navigate']);

const root = ref(null);
const trigger = ref(null);
const panel = ref(null);
const filterEl = ref(null);
const open = ref(false);
const q = ref('');
const busyKey = ref(null);
const live = ref('');
let busyTimer = null;
const uid = useId();
const panelId = computed(() => props.id || `dsw-${uid}`);

const logoBase = computed(() => String(props.logos || '').replace(/\/+$/, ''));
const state = computed(() => props.model?.state ?? null);
const count = computed(() => props.model?.count ?? 0);
const needle = computed(() => q.value.trim().toLowerCase());
const matches = (name) => needle.value === '' || String(name).toLowerCase().includes(needle.value);

const groups = computed(() => (props.model?.groups || []).map((g) => ({
    ...g,
    tiles: g.tiles.map((t) => ({ ...t, shown: matches(t.name) })),
    shown: g.tiles.some((t) => matches(t.name)),
})));
const more = computed(() => (props.model?.more || []).map((m) => ({ ...m, shown: matches(m.name) })));
const moreShown = computed(() => more.value.some((m) => m.shown));
const shownCount = computed(() => groups.value.reduce((n, g) => n + g.tiles.filter((t) => t.shown).length, 0) + more.value.filter((m) => m.shown).length);
const moreOpen = ref(false);
watch(needle, (v) => { if (v !== '' && moreShown.value) moreOpen.value = true; });

const subtitle = computed(() => {
    if (state.value === 'ok') return `${count.value === 1 ? 'The one platform' : `The ${count.value} platforms`} ${props.companyName || 'your company'} has. Each opens already signed in.`;
    if (state.value === 'degraded') return 'The core Dot platforms, opened through InfoDot.';
    return 'Platforms are switched on per company.';
});

function size(t) {
    return Array.isArray(t.logo_size) && t.logo_size.length === 2 ? t.logo_size : null;
}
function tileTitle(t) {
    return t.via_infodot ? `Open ${t.name} through InfoDot` : `Open ${t.name}`;
}

function doOpen() {
    open.value = true;
    emit('open');
    nextTick(() => filterEl.value?.focus());
}
function doClose(returnFocus) {
    if (!open.value) return;
    open.value = false;
    emit('close');
    if (returnFocus) trigger.value?.focus();
}
function toggle() {
    if (open.value) doClose(false); else doOpen();
}

function visibleLinks() {
    return Array.from(panel.value?.querySelectorAll('a.dsw-bay[href]') || []).filter((a) => !a.closest('[hidden]'));
}
function onKeydown(e) {
    if (e.key === 'Escape' && open.value) {
        e.preventDefault();
        doClose(true);
        return;
    }
    if (!open.value || !['ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight'].includes(e.key)) return;
    if (e.target === filterEl.value && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) return; // the caret belongs to the field
    const targets = visibleLinks();
    if (!targets.length) return;
    e.preventDefault();
    const at = targets.indexOf(document.activeElement);
    const forward = e.key === 'ArrowDown' || e.key === 'ArrowRight';
    const next = at === -1 ? (forward ? 0 : targets.length - 1) : (at + (forward ? 1 : -1) + targets.length) % targets.length;
    targets[next].focus();
}
function onDocClick(e) {
    if (open.value && root.value && !root.value.contains(e.target)) doClose(false);
}

function clearBusy() {
    window.clearTimeout(busyTimer);
    busyKey.value = null;
    live.value = '';
}
function onGo(e, t) {
    if (busyKey.value === t.key) { e.preventDefault(); return; }
    // A new tab or window is not "this page leaving".
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    clearBusy();
    busyKey.value = t.key;
    live.value = `Opening ${t.name || 'the platform'}...`;
    busyTimer = window.setTimeout(clearBusy, BUSY_MS);
    emit('navigate', { key: t.key, name: t.name, href: t.href });
}
// A page restored from the back/forward cache comes back as it was left, "Opening..." included.
function onPageShow(e) {
    if (e.persisted) clearBusy();
}

onMounted(() => {
    document.addEventListener('click', onDocClick);
    window.addEventListener('pageshow', onPageShow);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onDocClick);
    window.removeEventListener('pageshow', onPageShow);
    window.clearTimeout(busyTimer);
});

defineExpose({ open: doOpen, close: doClose });
</script>

<template>
    <div v-if="model" ref="root" class="dsw is-ready" :class="`dsw--${mode}`" data-dsw :data-state="state" :data-platform="model.current" :aria-busy="busyKey ? 'true' : null" @keydown="onKeydown">
        <button ref="trigger" type="button" class="dsw-btn" data-dsw-trigger :aria-expanded="String(open)" :aria-controls="panelId" title="Switch Dot platform" @click="toggle">
            <svg class="dsw-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="4" y="4" width="4" height="4" rx="1.2" fill="currentColor" stroke="none" /><rect x="10" y="4" width="4" height="4" rx="1.2" fill="currentColor" stroke="none" /><rect x="16" y="4" width="4" height="4" rx="1.2" fill="currentColor" stroke="none" /><rect x="4" y="10" width="4" height="4" rx="1.2" fill="currentColor" stroke="none" /><rect x="10" y="10" width="4" height="4" rx="1.2" fill="currentColor" stroke="none" /><rect x="16" y="10" width="4" height="4" rx="1.2" fill="currentColor" stroke="none" /><rect x="4" y="16" width="4" height="4" rx="1.2" fill="currentColor" stroke="none" /><rect x="10" y="16" width="4" height="4" rx="1.2" fill="currentColor" stroke="none" /><rect x="16" y="16" width="4" height="4" rx="1.2" fill="currentColor" stroke="none" /></svg>
            <span class="dsw-btn__label">Dot Apps</span>
        </button>

        <div :id="panelId" ref="panel" class="dsw-panel" data-dsw-panel role="region" aria-label="Dot platforms" :hidden="!open" :data-open="open ? '' : null">
            <div class="dsw-in">
                <div class="dsw-head">
                    <div>
                        <p class="dsw-title">Dot Apps</p>
                        <p class="dsw-sub">{{ subtitle }} <a :href="model.platforms_url" class="dsw-all">See all platforms</a></p>
                    </div>
                    <label v-if="count > 0" class="dsw-filter">
                        <span class="dsw-sr">Filter platforms</span>
                        <svg class="dsw-filter__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="6.5" /><path d="M16 16l4.5 4.5" /></svg>
                        <input ref="filterEl" v-model="q" type="search" data-dsw-filter placeholder="Filter platforms" autocomplete="off" />
                    </label>
                </div>

                <p v-if="state === 'degraded'" class="dsw-notice" role="status"><strong>{{ notice || model.notice }}.</strong> <span>{{ noticeDetail || 'These open through InfoDot, which checks your access.' }}</span></p>

                <p v-if="state === 'personal'" class="dsw-personal">{{ model.message }}</p>
                <template v-else>
                    <p class="dsw-empty" data-dsw-empty role="status" :hidden="shownCount > 0">No platform matches &ldquo;<span data-dsw-empty-q>{{ q.trim() }}</span>&rdquo;.</p>

                    <div class="dsw-groups">
                        <section v-for="group in groups" :key="group.code" class="dsw-bays" :data-tone="group.tone" data-dsw-group :hidden="!group.shown">
                            <h3 class="dsw-bays__head"><span class="dsw-bays__code">{{ group.code }}</span><span class="dsw-bays__name">{{ group.name }}</span></h3>
                            <ul class="dsw-bays__grid">
                                <li v-for="tile in group.tiles" :key="tile.key" :data-dsw-name="tile.name.toLowerCase()" :hidden="!tile.shown">
                                    <div v-if="tile.href === null" class="dsw-bay is-current" aria-current="page">
                                        <span class="dsw-bay__logo"><img v-if="tile.logo" :src="`${logoBase}/${tile.logo}`" :alt="tile.name" :width="size(tile)?.[0]" :height="size(tile)?.[1]" decoding="async" /></span>
                                        <span class="dsw-bay__name">{{ tile.name }}</span>
                                        <span class="dsw-bay__tag dsw-bay__tag--here">You are here</span>
                                    </div>
                                    <a v-else class="dsw-bay" :class="{ 'is-busy': busyKey === tile.key }" :href="tile.href" data-dsw-go :data-dsw-label="tile.name" :title="tileTitle(tile)" :aria-busy="busyKey === tile.key ? 'true' : null" @click="onGo($event, tile)">
                                        <span class="dsw-bay__logo"><img v-if="tile.logo" :src="`${logoBase}/${tile.logo}`" :alt="tile.name" :width="size(tile)?.[0]" :height="size(tile)?.[1]" loading="lazy" decoding="async" /></span>
                                        <span class="dsw-bay__name">{{ tile.name }}</span>
                                        <span v-if="tile.plain" class="dsw-bay__tag dsw-bay__tag--own" title="Has its own sign-in">Own sign-in</span>
                                        <span class="dsw-bay__tag dsw-bay__tag--busy" aria-hidden="true">Opening&hellip;</span>
                                    </a>
                                </li>
                            </ul>
                        </section>
                    </div>

                    <details v-if="more.length > 0" class="dsw-more" data-dsw-more :open="moreOpen" :hidden="!moreShown" @toggle="moreOpen = $event.target.open">
                        <summary>Not switched on ({{ more.length }})</summary>
                        <p class="dsw-more__note">Your company has not switched these on. Each link shows what the platform does and how to add it.</p>
                        <ul>
                            <li v-for="other in more" :key="other.key" :data-dsw-name="other.name.toLowerCase()" :hidden="!other.shown"><a :href="other.href">{{ other.name }}</a></li>
                        </ul>
                    </details>
                </template>

                <p class="dsw-sr" data-dsw-live role="status" aria-live="polite">{{ live }}</p>
            </div>
        </div>
    </div>
</template>
