/**
 * The words for changes the server refused (pure). The save engine drops a skipped operation from the document it
 * shows, so without a notice the person would see a change quietly undo itself. `list` is the `skipped` array of an
 * ops answer: [{op, index, reason}].
 *
 *   skippedNotice(list) -> null | { message, kind: 'warn', count }
 */
const MAX_REASONS = 2;

const KNOWN = [
  [/^element not found$/, 'an element was deleted by someone else first'],
  [/^unknown slide$/, 'a slide was deleted by someone else first'],
  [/^unknown section$/, 'a section was deleted by someone else first'],
  [/^unknown asset$/, 'a picture or video that is not in this team\'s library'],
  [/too large|text too large/, 'some content is too long or too big to store'],
  [/is full$/, 'a slide or the deck is at its size limit'],
  [/must be https|invalid .*(href|link|url)/, 'a web address that is not a secure https link'],
  [/^duplicate/, 'an item that already exists'],
  [/^a deck keeps at least one slide$/, 'the last slide of a deck cannot be removed'],
  [/^a deck is approved by a reviewer/, 'a deck is approved by a reviewer, not by an edit'],
];

function plain(reason) {
  const r = String(reason ?? '').trim();
  for (const [re, words] of KNOWN) if (re.test(r)) return words;
  // Anything else: show it as short plain text, without markup characters.
  const clean = r.replace(/[<>&"'`]/g, '').replace(/\s+/g, ' ').slice(0, 60);
  return clean || 'the server refused it';
}

export function skippedNotice(list) {
  if (!Array.isArray(list) || list.length === 0) return null;
  const count = list.length;
  const counts = new Map();
  for (const s of list) {
    const w = plain(s?.reason);
    counts.set(w, (counts.get(w) || 0) + 1);
  }
  const ranked = [...counts.entries()].sort((a, b) => b[1] - a[1]);
  const shown = ranked.slice(0, MAX_REASONS).map(([w]) => w);
  const more = ranked.length - shown.length;
  const head = count === 1 ? '1 change was not saved' : `${count} changes were not saved`;
  let message = `${head}: ${more > 0 ? shown.join(', ') : shown.join(' and ')}`;
  if (more > 0) message += `, and ${more} more ${more === 1 ? 'reason' : 'reasons'}`;
  return { message: `${message}.`, kind: 'warn', count };
}
