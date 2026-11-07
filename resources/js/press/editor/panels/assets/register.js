import { defineAsyncComponent } from 'vue';
// Loaded when the panel is first opened, not with the editor (R-40 performance).
import { registerPanel } from '../registry.js';

// The asset library: search, upload, insert, replace, rename, usage and delete.
registerPanel({
  id: 'assets',
  label: 'Library',
  icon: 'folder',
  scope: 'deck',
  order: 40,
  component: defineAsyncComponent(() => import('./AssetsPanel.vue')),
});
