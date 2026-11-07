import { defineAsyncComponent } from 'vue';
import { registerPanel } from '../registry.js';

registerPanel({
    id: 'table',
    label: 'Table',
    icon: 'table',
    scope: 'selection',
    order: 20,
    when: (ctx) => ctx.selected.length > 0 && ctx.selected.every((e) => e.type === 'table'),
    component: defineAsyncComponent(() => import('./TablePanel.vue')),
});
