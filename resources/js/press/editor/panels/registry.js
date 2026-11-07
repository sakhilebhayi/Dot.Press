/**
 * Panel registry entry point. Other modules import `registerPanel` from here:
 *
 *   import { registerPanel } from '../registry.js';
 *   registerPanel({ id: 'text', label: 'Text', icon: 'edit', scope: 'selection', order: 10, component: TextPanel });
 *
 * Every `panels/<name>/register.js` is loaded automatically (import.meta.glob, eager). The logic is in
 * panelRegistry.js so it runs under node:test.
 */
export * from './panelRegistry.js';

// Loaded for their side effect: each file calls registerPanel().
// eslint-disable-next-line no-unused-vars
const autoRegistered = import.meta.glob('./*/register.js', { eager: true });
