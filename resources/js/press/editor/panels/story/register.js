import { registerPanel } from '../panelRegistry.js';
import StoryPanel from './StoryPanel.vue';

registerPanel({ id: 'story', label: 'Story', icon: 'spine', scope: 'deck', order: 20, component: StoryPanel });
