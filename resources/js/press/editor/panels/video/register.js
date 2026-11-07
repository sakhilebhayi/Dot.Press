import { defineAsyncComponent } from 'vue';
import { registerPanel } from '../registry.js';

registerPanel({
    id: 'video',
    label: 'Video',
    icon: 'video',
    scope: 'selection',
    order: 20,
    when: (ctx) => ctx.selected.length > 0 && ctx.selected.every((e) => e.type === 'video'),
    component: defineAsyncComponent(() => import('./VideoPanel.vue')),
});
