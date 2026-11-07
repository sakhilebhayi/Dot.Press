import { registerPanel } from '../registry.js';
import AnimatePanel from './AnimatePanel.vue';

registerPanel({ id: 'animate', label: 'Animate', icon: 'sparkle', scope: 'selection', order: 20, component: AnimatePanel });
