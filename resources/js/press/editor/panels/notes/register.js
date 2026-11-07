import { defineAsyncComponent } from 'vue';
// Loaded when the panel is first opened, not with the editor (R-40 performance).
import { registerPanel } from '../registry.js';

registerPanel({ id: 'notes', label: 'Notes', icon: 'pen', scope: 'slide', order: 40, component: defineAsyncComponent(() => import('./NotesPanel.vue')) });
