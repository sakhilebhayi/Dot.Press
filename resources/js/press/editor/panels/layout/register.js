import { registerPanel } from '../registry.js';
import LayoutPanel from './LayoutPanel.vue';

registerPanel({
    id: 'layout',
    label: 'Layout',
    icon: 'layout',
    scope: 'slide',
    order: 20,
    component: LayoutPanel,
});
