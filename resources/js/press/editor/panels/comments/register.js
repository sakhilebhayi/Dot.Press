import { defineAsyncComponent } from 'vue';
// Loaded when the panel is first opened, not with the editor (R-40 performance).
import { registerPanel } from '../registry.js';

registerPanel({ id: 'comments', label: 'Comments', icon: 'chat', scope: 'deck', order: 30, component: defineAsyncComponent(() => import('./CommentsPanel.vue')) });
