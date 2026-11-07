// The Chart panel: type, data, format and the accessible description of the selected chart element.
// editor.openPanel('chart') opens it; panelProps: { id } edits the chart element with that id, { dialog: true } opens the large Edit data dialog.
import { defineAsyncComponent } from 'vue';
import { registerPanel } from '../registry.js';

registerPanel({
    id: 'chart',
    label: 'Chart',
    icon: 'chart',
    scope: 'selection',
    order: 20,
    when: (ctx) => (ctx.selected || []).some((e) => e.type === 'chart'),
    component: defineAsyncComponent(() => import('./ChartPanel.vue')),
});
