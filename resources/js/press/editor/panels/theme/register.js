import { registerPanel } from '../registry.js';
import ThemePanel from './ThemePanel.vue';
import './design.css';

registerPanel({
    id: 'theme',
    label: 'Theme',
    icon: 'sparkle',
    scope: 'deck',
    order: 10,
    component: ThemePanel,
});
