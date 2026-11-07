import { registerPanel } from '../registry.js';
import MotionPanel from './MotionPanel.vue';

registerPanel({ id: 'motion', label: 'Motion', icon: 'present', scope: 'slide', order: 30, component: MotionPanel });
