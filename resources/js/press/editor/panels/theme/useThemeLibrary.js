// The team's themes and brand kits, fetched through editor.api with loading, error and write helpers.
// Not pure (uses Vue refs); the shaping lives in themeModel.js and brandKitModel.js.
import { computed, ref, shallowRef } from 'vue';
import { apiErrorText, splitThemes } from './themeModel.js';
import { KIT_LIMITS, kitThemeCard } from './brandKitModel.js';

export function useThemeLibrary(editor) {
  const api = editor.api;
  const status = ref('loading'); // loading | ready | error
  const error = ref('');
  const errorStatus = ref(0);
  const kitError = ref('');
  const themes = shallowRef([]);
  const kits = shallowRef([]);
  const defaultKit = shallowRef(null);
  const themeLimit = ref(100);
  const kitLimits = ref({ ...KIT_LIMITS });
  const busy = ref(false);

  const groups = computed(() => {
    const g = splitThemes(themes.value);
    const cards = kits.value.map(kitThemeCard);
    const known = new Set(cards.map((c) => c.id));
    return { builtin: g.builtin, custom: g.custom, brand: [...cards, ...g.brand.filter((t) => !known.has(t.id))] };
  });

  async function load() {
    status.value = 'loading';
    error.value = '';
    kitError.value = '';
    const [t, k] = await Promise.allSettled([api.get('/api/themes'), api.get('/api/brand-kits')]);
    if (t.status === 'rejected') {
      status.value = 'error';
      errorStatus.value = t.reason?.status || 0;
      error.value = apiErrorText(t.reason, 'themes');
      return;
    }
    themes.value = t.value.data || [];
    defaultKit.value = t.value.default_brand_kit || null;
    if (t.value.limits?.custom_themes) themeLimit.value = t.value.limits.custom_themes;
    if (k.status === 'fulfilled') {
      kits.value = k.value.data || [];
      kitLimits.value = { ...KIT_LIMITS, ...(k.value.limits || {}) };
    } else {
      kits.value = [];
      kitError.value = apiErrorText(k.reason, 'brand kits');
    }
    status.value = 'ready';
  }

  async function run(fn) {
    busy.value = true;
    try { return await fn(); } finally { busy.value = false; }
  }

  const createTheme = (body) => run(async () => {
    const res = await api.post('/api/themes', body);
    themes.value = [...themes.value, res.theme];
    return res.theme;
  });
  const updateTheme = (dbId, body) => run(async () => {
    const res = await api.put(`/api/themes/${dbId}`, body);
    themes.value = themes.value.map((t) => (t.db_id === dbId ? res.theme : t));
    return res.theme;
  });
  const deleteTheme = (dbId) => run(async () => {
    await api.delete(`/api/themes/${dbId}`);
    themes.value = themes.value.filter((t) => t.db_id !== dbId);
  });

  const createKit = (body) => run(async () => {
    const res = await api.post('/api/brand-kits', body);
    await reloadKits();
    return res.brand_kit;
  });
  const updateKit = (id, body) => run(async () => {
    const res = await api.put(`/api/brand-kits/${id}`, body);
    await reloadKits();
    return res.brand_kit;
  });
  const deleteKit = (id) => run(async () => {
    await api.delete(`/api/brand-kits/${id}`);
    await reloadKits();
  });
  async function reloadKits() {
    const [k, t] = await Promise.all([api.get('/api/brand-kits'), api.get('/api/themes')]);
    kits.value = k.data || [];
    defaultKit.value = t.default_brand_kit || null;
  }

  load();

  return {
    status, error, errorStatus, kitError, themes, kits, defaultKit, themeLimit, kitLimits, groups, busy,
    load, createTheme, updateTheme, deleteTheme, createKit, updateKit, deleteKit,
  };
}
