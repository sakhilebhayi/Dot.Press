// Theme helpers for the colour and font controls: the deck theme from the editor context when there is one, token names.
import { inject } from 'vue';
import { EDITOR_KEY } from '../../editor/context.js';
import { DEFAULT_THEME } from '../../editor/core/theme.js';

export const useDeckTheme = (explicit) => {
  const editor = inject(EDITOR_KEY, null);
  return () => explicit?.value ?? explicit ?? editor?.deck?.value?.theme ?? DEFAULT_THEME;
};

export const TOKEN_GROUPS = [
  { name: 'Theme', tokens: ['@bg', '@surface', '@ink', '@muted', '@line', '@accent', '@accent2', '@good', '@warn', '@bad'] },
  { name: 'Chart', tokens: Array.from({ length: 8 }, (_, i) => `@chart${i + 1}`) },
];
const NAMES = { '@bg': 'Background', '@surface': 'Surface', '@ink': 'Text', '@muted': 'Muted text', '@line': 'Line', '@accent': 'Accent', '@accent2': 'Accent 2', '@good': 'Good', '@warn': 'Warning', '@bad': 'Bad' };
export const tokenName = (t) => NAMES[t] || (/^@chart(\d)$/.test(t) ? `Chart ${t.slice(6)}` : t);
