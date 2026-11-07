import { registerPanel } from '../registry.js';
import InsertPanel from './InsertPanel.vue';

// Opened by the dock with {kind}: image, chart, table, icon, video, audio, shape, text (or nothing for the whole list).
registerPanel({
  id: 'insert',
  label: 'Insert',
  icon: 'plus',
  scope: 'slide',
  order: 10,
  component: InsertPanel,
});
