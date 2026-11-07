import { registerPanel } from '../registry.js';
import HistoryPanel from './HistoryPanel.vue';

registerPanel({ id: 'history', label: 'History', icon: 'restore', scope: 'deck', order: 33, component: HistoryPanel });
