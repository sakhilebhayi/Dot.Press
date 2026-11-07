import { defineAsyncComponent } from 'vue';
// Loaded when the panel is first opened, not with the editor (R-40 performance).
import { registerPanel } from '../panelRegistry.js';

registerPanel({ id: 'assistant', label: 'Assistant', icon: 'sparkle', scope: 'slide', order: 30, component: defineAsyncComponent(() => import('./AssistantPanel.vue')) });
