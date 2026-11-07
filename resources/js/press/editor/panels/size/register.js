import { defineAsyncComponent } from 'vue';
// Loaded when the panel is first opened, not with the editor (R-40 performance).
import { registerPanel } from '../registry.js';

registerPanel({
    id: 'size',
    label: 'Size',
    icon: 'grid',
    scope: 'deck',
    order: 20,
    component: defineAsyncComponent(() => import('./SizePanel.vue')),
});
