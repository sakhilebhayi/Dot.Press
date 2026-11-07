import { defineAsyncComponent } from 'vue';
// Loaded when the panel is first opened, not with the editor (R-40 performance).
import { registerPanel } from '../registry.js';

registerPanel({ id: 'share', label: 'Share', icon: 'share', scope: 'deck', order: 31, component: defineAsyncComponent(() => import('./SharePanel.vue')) });
