// Test harness for the three SFCs, with no bundler: happy-dom provides the DOM, @vue/compiler-sfc compiles each
// component for the client (not SSR) into ./.compiled next to this file, and the test mounts it with Vue's own runtime.
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, basename } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { Window } from 'happy-dom';

const here = dirname(fileURLToPath(import.meta.url));

const win = new Window({ url: 'http://localhost/' });
for (const k of ['window', 'document', 'navigator', 'HTMLElement', 'Element', 'Node', 'Event', 'KeyboardEvent', 'MouseEvent', 'SVGElement', 'HTMLInputElement', 'HTMLFormElement', 'DocumentFragment', 'Text', 'Comment', 'MutationObserver', 'getComputedStyle', 'CustomEvent']) {
    const v = k === 'window' ? win : win[k];
    try { Object.defineProperty(globalThis, k, { value: v, configurable: true, writable: true }); } catch { /* read-only global */ }
}
globalThis.requestAnimationFrame = (f) => setTimeout(f, 0);

const { compileScript, parse } = await import('@vue/compiler-sfc');
export const Vue = await import('vue');

export async function loadSfc(file) {
    const src = readFileSync(file, 'utf8');
    const { descriptor, errors } = parse(src, { filename: file });
    if (errors.length) throw errors[0];
    const id = basename(file, '.vue');
    const script = compileScript(descriptor, { id, inlineTemplate: true });
    const out = join(here, '.compiled');
    mkdirSync(out, { recursive: true });
    const target = join(out, `${id}.mjs`);
    writeFileSync(target, script.content);
    return (await import(pathToFileURL(target).href)).default;
}

export async function mountComponent(component, props = {}, slots = {}) {
    const host = document.createElement('div');
    document.body.appendChild(host);
    const app = Vue.createApp({ render: () => Vue.h(component, props, slots) });
    const vm = app.mount(host);
    await Vue.nextTick();
    return { host, app, vm, unmount: () => { app.unmount(); host.remove(); } };
}

export const tick = () => new Promise((r) => setTimeout(r, 0));

export function key(target, k, init = {}) {
    const e = new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true, ...init });
    target.dispatchEvent(e);
    return e;
}

export function type(input, value) {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}
