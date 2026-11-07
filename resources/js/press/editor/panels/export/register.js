import { defineAsyncComponent } from 'vue';
// Loaded when the panel is first opened, not with the editor (R-40 performance).
import { registerPanel } from '../registry.js';

// Deck scope: export is about the whole presentation, not the selection. Offered to anyone who may export.
registerPanel({
    id: 'export',
    label: 'Export',
    icon: 'export',
    scope: 'deck',
    order: 90,
    when: (ctx) => ctx.can?.export !== false,
    component: defineAsyncComponent(() => import('./ExportPanel.vue')),
});
