/**
 * The review panel's logic (pure, node tested): grouping and filtering the analyzer's issues, the per slide counts for
 * the Spine, and the plan for "Apply all safe fixes in this category".
 *
 * An issue (server): {id, severity: 'issue'|'warn'|'info', kind, category, slide, slide_index, element?, message, why,
 *                     fix?: {label, ops}, alternatives?: [{label, ops}], claims?, questions?}
 */
import { isSafeFix, normalizeOps, opLines } from '../intel/opsText.js';
import { isIgnored } from './ignoreStore.js';

export const CATEGORIES = [
  { id: 'consistency', label: 'Consistency' },
  { id: 'fit', label: 'Text fit' },
  { id: 'readability', label: 'Readability' },
  { id: 'density', label: 'Density' },
  { id: 'hierarchy', label: 'Hierarchy' },
  { id: 'terminology', label: 'Terminology' },
  { id: 'repetition', label: 'Repetition' },
  { id: 'claims', label: 'Claims' },
  { id: 'placeholders', label: 'Placeholders' },
  { id: 'accessibility', label: 'Accessibility' },
  { id: 'animation', label: 'Animation' },
];

export const SEVERITIES = {
  issue: { rank: 0, word: 'Issue', plural: 'Issues' },
  warn: { rank: 1, word: 'Warning', plural: 'Warnings' },
  info: { rank: 2, word: 'Note', plural: 'Notes' },
};

const CAT_INDEX = new Map(CATEGORIES.map((c, i) => [c.id, i]));

export function categoryLabel(id) {
  return CATEGORIES.find((c) => c.id === id)?.label || (id ? id.charAt(0).toUpperCase() + id.slice(1) : 'Other');
}

export const severityWord = (s) => SEVERITIES[s]?.word || 'Note';

const emptyCounts = () => ({ issue: 0, warn: 0, info: 0 });

/**
 * @param issues     the analyzer's issues
 * @param opts       {ignores, severity: 'all'|'issue'|'warn'|'info', category: 'all'|id, slide: null|slideId, showIgnored, applied: Set of issue ids applied or dismissed here}
 * @returns {groups: [{id, label, issues, counts, total}], shown, open, ignored, totals, bySlide, slidesWithIssues, categoryCounts}
 *   `open`, `totals`, `bySlide` and `categoryCounts` ignore the filters: they describe what is open in the deck.
 */
export function buildView(issues, opts = {}) {
  const { ignores = { ids: [], kinds: [] }, severity = 'all', category = 'all', slide = null, showIgnored = false, applied = new Set() } = opts;
  const totals = emptyCounts();
  const bySlide = {};
  const categoryCounts = {};
  const ignoredList = [];
  const buckets = new Map();
  let open = 0;
  for (const issue of issues || []) {
    if (applied.has(issue.id)) continue;
    const ign = isIgnored(ignores, issue);
    if (ign) ignoredList.push(issue);
    else {
      open += 1;
      totals[issue.severity] = (totals[issue.severity] || 0) + 1;
      categoryCounts[issue.category] = (categoryCounts[issue.category] || 0) + 1;
      if (issue.slide !== null && issue.slide !== undefined) {
        const k = String(issue.slide);
        bySlide[k] = (bySlide[k] || 0) + 1;
      }
    }
    if (ign && !showIgnored) continue;
    if (severity !== 'all' && issue.severity !== severity) continue;
    if (category !== 'all' && issue.category !== category) continue;
    if (slide !== null && String(issue.slide) !== String(slide)) continue;
    if (!buckets.has(issue.category)) buckets.set(issue.category, []);
    buckets.get(issue.category).push(issue);
  }
  const groups = [...buckets.entries()]
    .sort(([a], [b]) => (CAT_INDEX.get(a) ?? 99) - (CAT_INDEX.get(b) ?? 99))
    .map(([id, list]) => {
      const counts = emptyCounts();
      list.forEach((i) => { counts[i.severity] = (counts[i.severity] || 0) + 1; });
      return { id, label: categoryLabel(id), issues: list, counts, total: list.length };
    });
  return {
    groups,
    shown: groups.reduce((n, g) => n + g.total, 0),
    open,
    ignored: ignoredList.length,
    ignoredIssues: ignoredList,
    totals,
    bySlide,
    slidesWithIssues: Object.keys(bySlide).length,
    categoryCounts,
  };
}

/** Sentence for the header: "9 open: 2 issues, 5 warnings, 2 notes, on 4 of 14 slides". */
export function summaryLine(view, slideCount) {
  if (!view.open) return 'Nothing open.';
  const parts = ['issue', 'warn', 'info'].filter((s) => view.totals[s]).map((s) => `${view.totals[s]} ${view.totals[s] === 1 ? SEVERITIES[s].word.toLowerCase() : SEVERITIES[s].plural.toLowerCase()}`);
  const where = view.slidesWithIssues ? `, on ${view.slidesWithIssues} of ${slideCount} slides` : '';
  return `${view.open} open: ${parts.join(', ')}${where}`;
}

/** Truncation notices from the analyzer summary: "Showing the first 60 of 83 for long-lines". */
export function truncationNotes(summary) {
  const out = [];
  for (const [kind, n] of Object.entries(summary?.truncated || {})) {
    const shown = summary?.by_kind?.[kind] ? summary.by_kind[kind] - n : null;
    out.push(`${n} more "${kind.replace(/-/g, ' ')}" ${n === 1 ? 'finding is' : 'findings are'} not listed${shown ? ` (the first ${shown} are)` : ''}.`);
  }
  return out;
}

/**
 * "Apply all safe fixes in this category". A fix is safe when it only changes how things look (el.set without text,
 * source, data, link or animation) or the stacking order, never removes or rewrites content. Two issues that set the same
 * property of the same element would fight: the first wins and the other is reported as left out.
 *
 * @returns {entries: [{issue, lines}], ops, left: [{issue, reason}]}
 */
export function fixAllPlan(issues, doc) {
  const entries = [];
  const left = [];
  const ops = [];
  const claimed = new Set();
  for (const issue of issues) {
    if (!issue.fix) continue;
    if (!isSafeFix(issue.fix)) { left.push({ issue, reason: 'It rewrites or removes content, so it is applied one at a time.' }); continue; }
    const mine = normalizeOps(issue.fix.ops);
    const keys = mine.flatMap((op) => (op.op === 'el.set' ? Object.keys(op.p).map((k) => `${op.slide}|${op.id}|${k}`) : []));
    if (keys.some((k) => claimed.has(k))) { left.push({ issue, reason: 'Another fix in this list changes the same property.' }); continue; }
    keys.forEach((k) => claimed.add(k));
    ops.push(...mine);
    entries.push({ issue, lines: opLines(mine, doc, { max: 3 }) });
  }
  return { entries, ops, left };
}
