import { registerPanel } from '../registry.js';
import AssetsPanel from './AssetsPanel.vue';

// The asset library: search, upload, insert, replace, rename, usage and delete.
registerPanel({
  id: 'assets',
  label: 'Library',
  icon: 'folder',
  scope: 'deck',
  order: 40,
  component: AssetsPanel,
});
