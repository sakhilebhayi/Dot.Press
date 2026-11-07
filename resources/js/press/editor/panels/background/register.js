import { defineAsyncComponent } from 'vue';
// Loaded when the panel is first opened, not with the editor (R-40 performance).
import { registerPanel } from '../registry.js';

registerPanel({
    id: 'background',
    label: 'Background',
    icon: 'image',
    scope: 'slide',
    order: 30,
    component: defineAsyncComponent(() => import('./BackgroundPanel.vue')),
});
