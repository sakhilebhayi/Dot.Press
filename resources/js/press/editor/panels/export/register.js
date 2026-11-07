import { registerPanel } from '../registry.js';
import ExportPanel from './ExportPanel.vue';

// Deck scope: export is about the whole presentation, not the selection. Offered to anyone who may export.
registerPanel({
    id: 'export',
    label: 'Export',
    icon: 'export',
    scope: 'deck',
    order: 90,
    when: (ctx) => ctx.can?.export !== false,
    component: ExportPanel,
});
