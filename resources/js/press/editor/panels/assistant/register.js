import { registerPanel } from '../panelRegistry.js';
import AssistantPanel from './AssistantPanel.vue';

registerPanel({ id: 'assistant', label: 'Assistant', icon: 'sparkle', scope: 'slide', order: 30, component: AssistantPanel });
