import { defineAsyncComponent } from 'vue';
import { registerPanel } from '../registry.js';

registerPanel({
    id: 'image',
    label: 'Picture',
    icon: 'image',
    scope: 'selection',
    order: 20,
    when: (ctx) => ctx.selected.length > 0 && ctx.selected.every((e) => e.type === 'image'),
    component: defineAsyncComponent(() => import('./ImagePanel.vue')),
});
