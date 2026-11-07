import { registerPanel } from '../registry.js';
import CommentsPanel from './CommentsPanel.vue';

registerPanel({ id: 'comments', label: 'Comments', icon: 'chat', scope: 'deck', order: 30, component: CommentsPanel });
