// Entry of the offline present package (built by vite.standalone.config.js into one IIFE file).
// The package page holds the deck as JSON in <script id="press-data">; nothing is fetched.
import { createApp, h } from 'vue';
import PresentPlayer from './PresentPlayer.vue';

const el = document.getElementById('press-data');
const data = el ? JSON.parse(el.textContent || '{}') : {};
const deck = data.deck || { title: 'Presentation', w: 1920, h: 1080, theme: null };

createApp({
    render: () => h(PresentPlayer, { deck, slides: data.slides || [], assets: data.assets || {}, startIndex: 0, mode: 'present', offline: true, title: deck.title }),
}).mount('#press-app');
