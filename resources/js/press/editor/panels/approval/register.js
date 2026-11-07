import { defineAsyncComponent } from 'vue';
// Loaded when the panel is first opened, not with the editor (R-40 performance).
import { registerPanel } from '../registry.js';

registerPanel({ id: 'approval', label: 'Approval', icon: 'review', scope: 'deck', order: 32, component: defineAsyncComponent(() => import('./ApprovalPanel.vue')) });
