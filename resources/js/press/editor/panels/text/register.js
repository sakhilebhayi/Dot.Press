import { registerPanel } from '../registry.js';
import TextPanel from './TextPanel.vue';
import { isTextual } from './textModel.js';

// Order 0: the first tab whenever the selection holds text (Arrange is order 1).
registerPanel({
    id: 'text',
    label: 'Text',
    icon: 'text',
    scope: 'selection',
    order: 0,
    when: (ctx) => (ctx.selected || []).some(isTextual),
    component: TextPanel,
});
