import { registerPanel } from '../registry.js';
import ApprovalPanel from './ApprovalPanel.vue';

registerPanel({ id: 'approval', label: 'Approval', icon: 'review', scope: 'deck', order: 32, component: ApprovalPanel });
