import { defineAsyncComponent } from 'vue';
// Loaded when the panel is first opened, not with the editor (R-40 performance).
import { registerPanel } from '../registry.js';

registerPanel({ id: 'animate', label: 'Animate', icon: 'sparkle', scope: 'selection', order: 20, component: defineAsyncComponent(() => import('./AnimatePanel.vue')) });
