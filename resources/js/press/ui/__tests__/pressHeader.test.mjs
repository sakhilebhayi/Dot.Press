// Mounts the real PressHeader (with the dot-ui components) in happy-dom: the markup the header prints, the search pill, the team
// switch rows, the phone drawer. Inertia and Ziggy are replaced by small stand-ins; nothing else is mocked.
import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { loadSfc, mountComponent, tick, type, Vue } from '../../../dot-ui/__tests__/harness.mjs';
import { compileScript, parse } from '@vue/compiler-sfc';

const here = dirname(fileURLToPath(import.meta.url));
const uiDir = join(here, '..', '..', '..', 'dot-ui');
const out = join(here, '.compiled');
mkdirSync(out, { recursive: true });

const visits = [];
const state = { props: {} };
writeFileSync(join(out, 'inertia.mjs'), `
import { reactive } from 'vue';
export const page = reactive({ props: {} });
export const visits = globalThis.__visits;
export const usePage = () => page;
export const router = { get: (...a) => globalThis.__visits.push(['get', ...a]), visit: (...a) => globalThis.__visits.push(['visit', ...a]), post: (...a) => globalThis.__visits.push(['post', ...a]), put: (...a) => globalThis.__visits.push(['put', ...a]) };
`);
globalThis.__visits = visits;
const routeApi = { has: () => true, current: (n) => n === 'dashboard' };
globalThis.route = (name) => (name === undefined ? routeApi : `/${name}`);

const header = await loadSfc(join(uiDir, 'DotHeader.vue'));
const search = await loadSfc(join(uiDir, 'DotSearch.vue'));
const switcher = await loadSfc(join(uiDir, 'DotSwitcher.vue'));
// PressHeader imports '@/dot-ui/X.vue' and '@inertiajs/vue3': point them at the compiled copies
const file = join(here, '..', 'PressHeader.vue');
const { descriptor } = parse(readFileSync(file, 'utf8'), { filename: file });
let code = compileScript(descriptor, { id: 'PressHeader', inlineTemplate: true }).content;
code = code.replace("'@/dot-ui/DotHeader.vue'", "'./DotHeader.mjs'").replace("'@/dot-ui/DotSearch.vue'", "'./DotSearch.mjs'").replace("'@/dot-ui/DotSwitcher.vue'", "'./DotSwitcher.mjs'").replace("'@inertiajs/vue3'", "'./inertia.mjs'");
void [header, search, switcher];
const uiOut = join(uiDir, '__tests__', '.compiled');
for (const n of ['DotHeader', 'DotSearch', 'DotSwitcher', 'DotDrawer', 'DotNavMenu', 'DotAccountMenu', 'DotMenuItem', 'DotGuest']) {
    writeFileSync(join(out, `${n}.mjs`), readFileSync(join(uiOut, `${n}.mjs`), 'utf8').replace(/from "\.\/(\w+)\.mjs"/g, 'from "./$1.mjs"'));
}
writeFileSync(join(out, 'PressHeader.mjs'), code);
const PressHeader = (await import(pathToFileURL(join(out, 'PressHeader.mjs')).href)).default;
const { page } = await import(pathToFileURL(join(out, 'inertia.mjs')).href);

const user = (teams) => ({ name: 'Naledi Mokoena', email: 'naledi@example.test', current_team: teams[0], all_teams: teams });
function setPage(teams = [{ id: 1, name: 'Mining Co' }]) {
    page.props = { auth: { user: user(teams) }, jetstream: { canCreateTeams: true, hasApiFeatures: true }, dotSwitcher: null };
}

test('the header prints the logo, nav, search, account and a phone drawer', async () => {
    setPage();
    const m = await mountComponent(PressHeader, {});
    const h = m.host;
    assert.ok(h.querySelector('header.dh'), 'the shared header');
    assert.equal(h.querySelector('a.dh-brand').getAttribute('aria-label'), 'Dot.Press library');
    assert.ok(h.querySelector('img.pr-hdr-logo--light') && h.querySelector('img.pr-hdr-logo--dark'), 'both original logo files');
    assert.equal(h.querySelectorAll('.dh-search .dsr-input').length, 1, 'one search pill');
    assert.equal(h.querySelector('.dsr-input').getAttribute('name'), 'q');
    assert.ok(h.querySelector('.dh-search-btn'), 'the phone search button');
    assert.ok(h.querySelector('.dh-menu-btn'), 'the phone menu button');
    assert.ok(h.querySelector('.dh-drawer .dh-dacct'), 'the drawer carries the account');
    const labels = [...h.querySelectorAll('.dh-nav__list > .dh-nav__item > .dh-nav__link')].map((e) => e.textContent.trim());
    assert.ok(labels.includes('Library') && labels.some((l) => l.startsWith('Team')));
    assert.ok(labels.length <= 7, 'at most seven primary items');
    assert.equal(h.querySelector('[data-dh-theme-set=dark]') !== null, true, 'theme buttons in the account menu');
    assert.equal(h.querySelectorAll('[style]').length, 0, 'no inline style attributes');
    m.unmount();
});

test('search outside the library opens the library with the query', async () => {
    setPage();
    visits.length = 0;
    const m = await mountComponent(PressHeader, {});
    const input = m.host.querySelector('.dsr-input');
    type(input, '  zebra  ');
    await tick();
    m.host.querySelector('form[role=search]').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await tick();
    assert.deepEqual(visits.at(-1), ['get', '/dashboard', { q: 'zebra' }]);
    m.unmount();
});

test('with several teams the account menu offers a switch row per team, and the search hint can be dropped', async () => {
    setPage([{ id: 1, name: 'Mining Co' }, { id: 2, name: 'Second Co' }]);
    const m = await mountComponent(PressHeader, { searchHint: null });
    const rows = [...m.host.querySelectorAll('.dh-account .dh-menu__item')].map((e) => e.textContent.trim());
    assert.ok(rows.includes('Switch to Second Co'));
    assert.equal(m.host.querySelector('.dh-search .dsr-kbd'), null, 'no Ctrl K hint in the editor');
    m.unmount();
});

test('a page may supply its own search (the library does)', async () => {
    setPage();
    const m = await mountComponent(PressHeader, {}, { search: () => Vue.h('div', { class: 'own-search' }, 'mine') });
    assert.ok(m.host.querySelector('.dh-search .own-search'));
    assert.equal(m.host.querySelector('.dsr-input'), null);
    m.unmount();
});
