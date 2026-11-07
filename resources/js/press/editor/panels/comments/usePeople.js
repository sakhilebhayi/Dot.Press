// Everyone who can open the deck (GET /api/decks/{deck}/people), shared by the mention list, the share panel and the
// review picker. One cache per editor; `refresh()` asks again (after sharing changes, for example).
import { ref, shallowRef } from 'vue';

const caches = new WeakMap();

export function usePeople(editor) {
  let c = caches.get(editor);
  if (!c) {
    const people = shallowRef([]);
    const status = ref('idle'); // idle | loading | ready | error
    const error = ref('');
    let inflight = null;
    const refresh = () => {
      if (inflight) return inflight;
      status.value = status.value === 'ready' ? 'ready' : 'loading';
      inflight = editor.api.get(`/api/decks/${editor.deckId}/people`)
        .then((res) => { people.value = Array.isArray(res?.people) ? res.people : []; status.value = 'ready'; error.value = ''; })
        .catch((e) => { if (status.value !== 'ready') status.value = 'error'; error.value = e?.message || 'Could not load the people on this deck.'; })
        .finally(() => { inflight = null; });
      return inflight;
    };
    const ensure = () => (status.value === 'idle' ? refresh() : Promise.resolve());
    c = { people, status, error, refresh, ensure };
    caches.set(editor, c);
  }
  return c;
}
