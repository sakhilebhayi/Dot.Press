import test from 'node:test';
import assert from 'node:assert/strict';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadSfc, mountComponent, tick, key, type, Vue } from './harness.mjs';
import { okModel, degradedModel, personalModel } from './fixtures.mjs';

const dir = join(dirname(fileURLToPath(import.meta.url)), '..');
const DotSearch = await loadSfc(join(dir, 'DotSearch.vue'));
const DotFilterSearch = await loadSfc(join(dir, 'DotFilterSearch.vue'));
const DotSwitcher = await loadSfc(join(dir, 'DotSwitcher.vue'));

// ------------------------------------------------------------------------------------------------ DotSearch

test('search: renders the Blade markup (classes, label, search role, arrow button, no combobox without results)', async () => {
    const m = await mountComponent(DotSearch, { modelValue: 'abc', placeholder: 'Search presentations', label: 'Search presentations', id: 's1' });
    const root = m.host.querySelector('.dsr');
    assert.ok(root && root.hasAttribute('data-dsr'));
    assert.equal(root.querySelector('label.dsr-sr').getAttribute('for'), 's1');
    assert.equal(root.querySelector('form').getAttribute('role'), 'search');
    const input = root.querySelector('input.dsr-input');
    assert.equal(input.type, 'search');
    assert.equal(input.value, 'abc');
    assert.equal(input.placeholder, 'Search presentations');
    assert.equal(input.getAttribute('autocomplete'), 'off');
    assert.equal(input.getAttribute('role'), null, 'no combobox when the host gives no results');
    assert.equal(root.querySelector('button.dsr-go .dsr-arrow').parentElement.getAttribute('aria-label'), 'Search presentations');
    assert.equal(root.querySelector('.dsr-kbd'), null);
    assert.equal(root.querySelector('.dsr-panel'), null);
    assert.equal(root.hasAttribute('data-dsr-shortcut'), false);
    assert.equal(m.host.querySelector('[style]'), null, 'no inline style attribute (CSP)');
    m.unmount();
});

test('search: hint adds the kbd and the shortcut marker; results slot adds the listbox and combobox wiring', async () => {
    const m = await mountComponent(DotSearch, { hint: 'Ctrl K', id: 's2' }, { results: () => Vue.h('a', { class: 'dsr-item', href: '/x', role: 'option' }, 'One') });
    assert.equal(m.host.querySelector('.dsr-kbd').textContent, 'Ctrl K');
    assert.ok(m.host.querySelector('.dsr').hasAttribute('data-dsr-shortcut'));
    const input = m.host.querySelector('input');
    assert.equal(input.getAttribute('role'), 'combobox');
    assert.equal(input.getAttribute('aria-controls'), 's2-list');
    assert.equal(input.getAttribute('aria-expanded'), 'false');
    const list = m.host.querySelector('#s2-list');
    assert.equal(list.getAttribute('role'), 'listbox');
    assert.equal(list.querySelectorAll('.dsr-item').length, 1);
    m.unmount();
});

test('search: typing emits update:modelValue with the new text (v-model) and nothing else happens', async () => {
    const seen = [];
    const m = await mountComponent(DotSearch, { modelValue: '', 'onUpdate:modelValue': (v) => seen.push(v) });
    type(m.host.querySelector('input'), 'mining');
    assert.deepEqual(seen, ['mining']);
    m.unmount();
});

test('search: focus opens, Escape closes and blurs and marks data-closed, focus reopens, outside click closes', async () => {
    const m = await mountComponent(DotSearch, { id: 's3' }, { results: () => Vue.h('span', 'r') });
    const root = m.host.querySelector('.dsr');
    const input = root.querySelector('input');
    input.dispatchEvent(new Event('focus'));
    await tick();
    assert.ok(root.classList.contains('is-open'));
    assert.equal(input.getAttribute('aria-expanded'), 'true');
    key(input, 'Escape');
    await tick();
    assert.equal(root.classList.contains('is-open'), false);
    assert.ok(root.hasAttribute('data-closed'));
    input.dispatchEvent(new Event('focus'));
    await tick();
    assert.ok(root.classList.contains('is-open') && !root.hasAttribute('data-closed'));
    document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    await tick();
    assert.equal(root.classList.contains('is-open'), false);
    m.unmount();
});

test('search: Ctrl/Cmd+K focuses the input only when a hint is shown', async () => {
    const withHint = await mountComponent(DotSearch, { hint: 'Ctrl K' });
    const e = key(window, 'k', { ctrlKey: true });
    assert.equal(e.defaultPrevented, true);
    assert.equal(document.activeElement, withHint.host.querySelector('input'));
    key(window, 'K', { metaKey: true });
    withHint.unmount();
    const noHint = await mountComponent(DotSearch, {});
    assert.equal(key(window, 'k', { ctrlKey: true }).defaultPrevented, false);
    noHint.unmount();
});

test('search: submit emits with the text; without an action the browser submit is cancelled, with one it is left alone', async () => {
    const calls = [];
    const a = await mountComponent(DotSearch, { modelValue: 'q1', onSubmit: (v) => calls.push(v) });
    const ev = new Event('submit', { bubbles: true, cancelable: true });
    a.host.querySelector('form').dispatchEvent(ev);
    assert.deepEqual(calls, ['q1']);
    assert.equal(ev.defaultPrevented, true);
    a.unmount();
    const b = await mountComponent(DotSearch, { modelValue: 'q2', action: '/search' });
    assert.equal(b.host.querySelector('form').getAttribute('action'), '/search');
    const ev2 = new Event('submit', { bubbles: true, cancelable: true });
    b.host.querySelector('form').dispatchEvent(ev2);
    assert.equal(ev2.defaultPrevented, false);
    b.unmount();
});

test('search: exposes focus() for hosts with their own shortcut', async () => {
    const r = Vue.ref(null);
    const host = document.createElement('div');
    document.body.appendChild(host);
    const app = Vue.createApp({ render: () => Vue.h(DotSearch, { ref: r }) });
    app.mount(host);
    await tick();
    r.value.focus();
    assert.equal(document.activeElement, host.querySelector('input'));
    app.unmount();
    host.remove();
});

// ------------------------------------------------------------------------------------------ DotFilterSearch

test('filter: the compact input, v-model, aria-label, attributes fall through', async () => {
    const seen = [];
    const m = await mountComponent(DotFilterSearch, { modelValue: 'x', placeholder: 'Filter these', label: 'Filter these', id: 'f1', 'onUpdate:modelValue': (v) => seen.push(v) });
    const input = m.host.querySelector('input.dsr-filter');
    assert.ok(input && input.type === 'search' && input.id === 'f1');
    assert.equal(input.getAttribute('aria-label'), 'Filter these');
    assert.equal(input.value, 'x');
    assert.equal(m.host.querySelector('.dsr'), null, 'it is not the pill');
    type(input, 'abc');
    assert.deepEqual(seen, ['abc']);
    m.unmount();
});

// ---------------------------------------------------------------------------------------------- DotSwitcher

const links = (host) => [...host.querySelectorAll('a.dsw-bay')];

test('switcher: ok state prints groups, links through /go, marks the current tile, lists "not switched on"', async () => {
    const m = await mountComponent(DotSwitcher, { model: okModel, companyName: 'Acme', logos: '/dot-ui/img/platforms' });
    const root = m.host.querySelector('.dsw[data-dsw]');
    assert.equal(root.getAttribute('data-state'), 'ok');
    assert.equal(root.getAttribute('data-platform'), 'press');
    assert.match(root.querySelector('.dsw-sub').textContent, /The 4 platforms Acme has\./);
    assert.deepEqual([...root.querySelectorAll('.dsw-bays__code')].map((e) => e.textContent), ['WORK', 'OPER']);
    assert.deepEqual(links(m.host).map((a) => a.getAttribute('href')), [
        'https://infodot.test/go/files?company=u', 'https://infodot.test/go/mines?company=u', 'https://infodot.test/go/central?company=u',
    ]);
    const current = root.querySelector('.dsw-bay.is-current');
    assert.equal(current.getAttribute('aria-current'), 'page');
    assert.equal(current.tagName, 'DIV');
    assert.match(current.textContent, /You are here/);
    assert.match(root.querySelector('.dsw-bay__tag--own').textContent, /Own sign-in/);
    assert.equal(root.querySelector('img').getAttribute('src'), '/dot-ui/img/platforms/files.png');
    assert.match(root.querySelector('.dsw-more summary').textContent, /Not switched on \(1\)/);
    assert.equal(root.querySelector('.dsw-more a').getAttribute('href'), 'https://infodot.test/platforms/hr');
    assert.equal(root.querySelector('[style]'), null);
    assert.equal(root.querySelector('.dsw-notice'), null);
    m.unmount();
});

test('switcher: degraded state shows the visible "could not check" line and never the word Active', async () => {
    const m = await mountComponent(DotSwitcher, { model: degradedModel });
    const notice = m.host.querySelector('.dsw-notice');
    assert.match(notice.textContent, /Could not check your platforms just now\./);
    assert.match(m.host.querySelector('.dsw-sub').textContent, /The core Dot platforms, opened through InfoDot\./);
    assert.ok(links(m.host).every((a) => a.title.endsWith('through InfoDot')));
    assert.doesNotMatch(m.host.textContent, /Active/);
    m.unmount();
});

test('switcher: personal state shows one sentence, no tiles, no filter', async () => {
    const m = await mountComponent(DotSwitcher, { model: personalModel });
    assert.equal(m.host.querySelector('.dsw-personal').textContent, personalModel.message);
    assert.equal(links(m.host).length, 0);
    assert.equal(m.host.querySelector('[data-dsw-filter]'), null);
    assert.equal(m.host.querySelector('.dsw-groups'), null);
    m.unmount();
});

test('switcher: renders nothing without a model', async () => {
    const m = await mountComponent(DotSwitcher, { model: null });
    assert.equal(m.host.querySelector('.dsw'), null);
    m.unmount();
});

test('switcher: button toggles the panel, aria-expanded follows, opening focuses the filter, Escape closes and returns focus', async () => {
    const events = [];
    const m = await mountComponent(DotSwitcher, { model: okModel, onOpen: () => events.push('open'), onClose: () => events.push('close') });
    const btn = m.host.querySelector('[data-dsw-trigger]');
    const panel = m.host.querySelector('[data-dsw-panel]');
    assert.equal(panel.hidden, true);
    assert.equal(btn.getAttribute('aria-expanded'), 'false');
    btn.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    await tick();
    assert.equal(panel.hidden, false);
    assert.equal(btn.getAttribute('aria-expanded'), 'true');
    assert.equal(document.activeElement, m.host.querySelector('[data-dsw-filter]'));
    key(document.activeElement, 'Escape');
    await tick();
    assert.equal(panel.hidden, true);
    assert.equal(document.activeElement, btn);
    assert.deepEqual(events, ['open', 'close']);
    m.unmount();
});

test('switcher: a click outside closes it', async () => {
    const m = await mountComponent(DotSwitcher, { model: okModel });
    m.host.querySelector('[data-dsw-trigger]').dispatchEvent(new MouseEvent('click', { bubbles: true }));
    await tick();
    document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    await tick();
    assert.equal(m.host.querySelector('[data-dsw-panel]').hidden, true);
    m.unmount();
});

test('switcher: arrow keys move between visible tiles and wrap; left/right stay in the filter field', async () => {
    const m = await mountComponent(DotSwitcher, { model: okModel });
    m.host.querySelector('[data-dsw-trigger]').dispatchEvent(new MouseEvent('click', { bubbles: true }));
    await tick();
    const filter = m.host.querySelector('[data-dsw-filter]');
    assert.equal(key(filter, 'ArrowRight').defaultPrevented, false);
    const tiles = links(m.host);
    key(filter, 'ArrowDown');
    assert.equal(document.activeElement, tiles[0]);
    key(tiles[0], 'ArrowRight');
    assert.equal(document.activeElement, tiles[1]);
    key(tiles[1], 'ArrowUp');
    key(tiles[0], 'ArrowUp');
    assert.equal(document.activeElement, tiles[2], 'wraps to the last tile');
    m.unmount();
});

test('switcher: the filter hides tiles and groups, shows "nothing matches", and opens "not switched on" when it matches there', async () => {
    const m = await mountComponent(DotSwitcher, { model: okModel });
    m.host.querySelector('[data-dsw-trigger]').dispatchEvent(new MouseEvent('click', { bubbles: true }));
    await tick();
    const filter = m.host.querySelector('[data-dsw-filter]');
    type(filter, 'mines');
    await tick();
    const groups = [...m.host.querySelectorAll('[data-dsw-group]')];
    assert.deepEqual(groups.map((g) => g.hidden), [true, false]);
    assert.equal(m.host.querySelector('[data-dsw-empty]').hidden, true);
    type(filter, 'zzz');
    await tick();
    assert.equal(m.host.querySelector('[data-dsw-empty]').hidden, false);
    assert.equal(m.host.querySelector('[data-dsw-empty-q]').textContent, 'zzz');
    type(filter, 'hr');
    await tick();
    assert.equal(m.host.querySelector('[data-dsw-more]').hidden, false);
    assert.equal(m.host.querySelector('[data-dsw-more]').open, true);
    m.unmount();
});

test('switcher: choosing a tile shows "Opening...", announces it, ignores a second click, ignores new-tab clicks, emits navigate', async () => {
    const nav = [];
    const m = await mountComponent(DotSwitcher, { model: okModel, onNavigate: (p) => nav.push(p) });
    const [files, mines] = links(m.host);
    const ctrl = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ctrlKey: true });
    files.dispatchEvent(ctrl);
    await tick();
    assert.equal(files.classList.contains('is-busy'), false, 'a new tab is not leaving this page');
    const first = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
    files.dispatchEvent(first);
    await tick();
    assert.ok(files.classList.contains('is-busy'));
    assert.equal(files.getAttribute('aria-busy'), 'true');
    assert.equal(m.host.querySelector('.dsw').getAttribute('aria-busy'), 'true');
    assert.equal(m.host.querySelector('[data-dsw-live]').textContent, 'Opening Dot.Files...');
    assert.deepEqual(nav, [{ key: 'files', name: 'Dot.Files', href: 'https://infodot.test/go/files?company=u' }]);
    const second = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
    files.dispatchEvent(second);
    assert.equal(second.defaultPrevented, true, 'double click ignored');
    mines.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
    await tick();
    assert.equal(files.classList.contains('is-busy'), false, 'only one tile is busy at a time');
    assert.ok(mines.classList.contains('is-busy'));
    // a page restored from the back/forward cache clears it
    const show = new Event('pageshow'); show.persisted = true;
    window.dispatchEvent(show);
    await tick();
    assert.equal(mines.classList.contains('is-busy'), false);
    m.unmount();
});
