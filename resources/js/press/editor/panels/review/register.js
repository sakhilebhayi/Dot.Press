import { registerPanel } from '../panelRegistry.js';
import ReviewPanel from './ReviewPanel.vue';

registerPanel({ id: 'review', label: 'Review', icon: 'review', scope: 'deck', order: 10, component: ReviewPanel });
