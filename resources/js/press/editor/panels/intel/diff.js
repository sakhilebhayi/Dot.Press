/**
 * Plain text diffs for proposal cards (pure, node tested).
 *
 *   parseServerDiff(str)   the server's inline word diff ("[-removed-] {+added+}") or a line outline ("+ New slide: ...")
 *                          -> {kind: 'words', segments: [{t: 'same'|'del'|'add', text}]} | {kind: 'lines', lines: [{sign, text}]}
 *   wordDiff(before, after) the same segments built here (longest common subsequence over words, newlines kept)
 *   diffFor(proposal)      the server's diff when it has one, else wordDiff of before and after
 *   changed(segments)      true when anything was added or removed
 */
const TOKEN = /(\[-[\s\S]*?-\]|\{\+[\s\S]*?\+\})/;

export function parseServerDiff(diff) {
  const text = String(diff ?? '');
  if (/^[+-] /m.test(text) && !/\[-[\s\S]*?-\]|\{\+[\s\S]*?\+\}/.test(text)) {
    return { kind: 'lines', lines: text.split('\n').filter((l) => l !== '').map((l) => (/^[+-] /.test(l) ? { sign: l[0], text: l.slice(2) } : { sign: ' ', text: l })) };
  }
  const segments = [];
  for (const part of text.split(TOKEN)) {
    if (part === '') continue;
    if (part.startsWith('[-') && part.endsWith('-]')) segments.push({ t: 'del', text: part.slice(2, -2) });
    else if (part.startsWith('{+') && part.endsWith('+}')) segments.push({ t: 'add', text: part.slice(2, -2) });
    else {
      const t = part.trim();
      if (t) segments.push({ t: 'same', text: t });
    }
  }
  return { kind: 'words', segments };
}

function tokens(s) {
  return String(s ?? '').match(/\n|[^\s]+/g) || [];
}

const MAX = 700;

export function wordDiff(before, after) {
  const a = tokens(before);
  const b = tokens(after);
  if (a.length > MAX || b.length > MAX) {
    const out = [];
    if (String(before ?? '').trim()) out.push({ t: 'del', text: String(before).trim() });
    if (String(after ?? '').trim()) out.push({ t: 'add', text: String(after).trim() });
    return out;
  }
  const n = a.length;
  const m = b.length;
  const lcs = Array.from({ length: n + 1 }, () => new Uint16Array(m + 1));
  for (let i = n - 1; i >= 0; i--) {
    for (let j = m - 1; j >= 0; j--) {
      lcs[i][j] = a[i] === b[j] ? lcs[i + 1][j + 1] + 1 : Math.max(lcs[i + 1][j], lcs[i][j + 1]);
    }
  }
  const raw = [];
  let i = 0;
  let j = 0;
  while (i < n && j < m) {
    if (a[i] === b[j]) { raw.push(['same', a[i]]); i++; j++; } else if (lcs[i + 1][j] >= lcs[i][j + 1]) raw.push(['del', a[i++]]); else raw.push(['add', b[j++]]);
  }
  while (i < n) raw.push(['del', a[i++]]);
  while (j < m) raw.push(['add', b[j++]]);
  const out = [];
  for (const [t, w] of raw) {
    const last = out[out.length - 1];
    if (last && last.t === t) last.text += last.text.endsWith('\n') || w === '\n' ? w : ` ${w}`;
    else out.push({ t, text: w });
  }
  return out.map((s) => ({ ...s, text: s.text.replace(/ *\n */g, '\n').trim() })).filter((s) => s.text !== '');
}

export function diffFor(p) {
  if (p?.diff) return parseServerDiff(p.diff);
  return { kind: 'words', segments: wordDiff(p?.before, p?.after) };
}

export const changed = (segments) => segments.some((s) => s.t !== 'same');
