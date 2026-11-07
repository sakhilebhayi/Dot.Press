import { defineAsyncComponent } from 'vue';
import { registerPanel } from '../registry.js';

registerPanel({
    id: 'audio',
    label: 'Audio',
    icon: 'audio',
    scope: 'selection',
    order: 20,
    when: (ctx) => ctx.selected.length > 0 && ctx.selected.every((e) => e.type === 'audio'),
    component: defineAsyncComponent(() => import('./AudioPanel.vue')),
});
