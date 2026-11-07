import { registerPanel } from '../registry.js';
import ShapePanel from './ShapePanel.vue';

// Order 2: after Text (0) and Arrange (1). Offered when the selection holds a shape or a line.
registerPanel({
    id: 'shape',
    label: 'Shape',
    icon: 'shape',
    scope: 'selection',
    order: 2,
    when: (ctx) => (ctx.selected || []).some((e) => e.type === 'shape'),
    component: ShapePanel,
});
