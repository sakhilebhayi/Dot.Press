import { defineAsyncComponent } from 'vue';
// Loaded when the panel is first opened, not with the editor (R-40 performance).
import { registerPanel } from '../registry.js';

registerPanel({
    id: 'layout',
    label: 'Layout',
    icon: 'layout',
    scope: 'slide',
    order: 20,
    component: defineAsyncComponent(() => import('./LayoutPanel.vue')),
});
