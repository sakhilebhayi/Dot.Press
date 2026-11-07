import { defineAsyncComponent } from 'vue';
import { registerPanel } from '../registry.js';

registerPanel({
    id: 'icon',
    label: 'Icon',
    icon: 'icon',
    scope: 'selection',
    order: 20,
    when: (ctx) => ctx.selected.length > 0 && ctx.selected.every((e) => e.type === 'icon'),
    component: defineAsyncComponent(() => import('./IconPanel.vue')),
});
