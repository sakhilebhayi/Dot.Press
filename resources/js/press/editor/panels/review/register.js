import { defineAsyncComponent } from 'vue';
// Loaded when the panel is first opened, not with the editor (R-40 performance).
import { registerPanel } from '../panelRegistry.js';

registerPanel({ id: 'review', label: 'Review', icon: 'review', scope: 'deck', order: 10, component: defineAsyncComponent(() => import('./ReviewPanel.vue')) });
