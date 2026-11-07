import { registerPanel } from '../registry.js';
import SizePanel from './SizePanel.vue';

registerPanel({
    id: 'size',
    label: 'Size',
    icon: 'grid',
    scope: 'deck',
    order: 20,
    component: SizePanel,
});
