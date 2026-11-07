import { registerPanel } from '../registry.js';
import NotesPanel from './NotesPanel.vue';

registerPanel({ id: 'notes', label: 'Notes', icon: 'pen', scope: 'slide', order: 40, component: NotesPanel });
