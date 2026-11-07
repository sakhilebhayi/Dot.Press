import { defineAsyncComponent } from 'vue';
// Loaded when the panel is first opened, not with the editor (R-40 performance).
import { registerPanel } from '../registry.js';

registerPanel({ id: 'history', label: 'History', icon: 'restore', scope: 'deck', order: 33, component: defineAsyncComponent(() => import('./HistoryPanel.vue')) });
