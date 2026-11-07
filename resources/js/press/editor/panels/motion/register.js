import { defineAsyncComponent } from 'vue';
// Loaded when the panel is first opened, not with the editor (R-40 performance).
import { registerPanel } from '../registry.js';

registerPanel({ id: 'motion', label: 'Motion', icon: 'present', scope: 'slide', order: 30, component: defineAsyncComponent(() => import('./MotionPanel.vue')) });
