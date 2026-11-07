import { registerPanel } from '../registry.js';
import ArrangePanel from './ArrangePanel.vue';

// Order 1: the first tab for anything that is not text (text sits at order 0). Offered for any selection.
registerPanel({
    id: 'arrange',
    label: 'Arrange',
    icon: 'layout',
    scope: 'selection',
    order: 1,
    component: ArrangePanel,
});
