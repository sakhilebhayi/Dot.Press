// Throwaway harness for looking at the control kit. Serve with: npx vite --port 8838, open
// /resources/js/press/ui/controls/__gallery__/index.html. Not part of the app build.
import '../../../../../css/press/tokens.css';
import '../../../../../css/press/fonts-ui.css';
import '../../../../../css/press/base.css';
import { createApp } from 'vue';
import Gallery from './Gallery.vue';

createApp(Gallery).mount('#app');
