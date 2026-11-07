import { defineAsyncComponent } from 'vue';
// Loaded when the panel is first opened, not with the editor (R-40 performance).
import { registerPanel } from '../panelRegistry.js';

registerPanel({ id: 'story', label: 'Story', icon: 'spine', scope: 'deck', order: 20, component: defineAsyncComponent(() => import('./StoryPanel.vue')) });
