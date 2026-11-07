import { registerPanel } from '../registry.js';
import SharePanel from './SharePanel.vue';

registerPanel({ id: 'share', label: 'Share', icon: 'share', scope: 'deck', order: 31, component: SharePanel });
