import { registerPanel } from '../registry.js';
import BackgroundPanel from './BackgroundPanel.vue';

registerPanel({
    id: 'background',
    label: 'Background',
    icon: 'image',
    scope: 'slide',
    order: 30,
    component: BackgroundPanel,
});
