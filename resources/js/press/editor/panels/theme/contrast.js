/**
 * Live contrast checks for a theme draft, with plain warnings (core contrastRatio, WCAG 2).
 *
 *   contrastChecks(theme) -> [{id, label, fg, bg, ratio, level: 'ok' | 'warn' | 'issue', text}]
 *   worstLevel(checks)
 *
 * Rules (the owner brief): ink on bg under 7:1 is a warning, under 4.5:1 an issue. The same holds for ink on surface.
 * Muted text under 4.5:1 is a warning and under 3:1 an issue. An accent on the page under 3:1 is a warning (accents
 * carry large text, lines and chart marks). The page colour on an accent ground (section slides) follows the muted rule.
 */
import { contrastRatio } from '../../core/theme.js';

const fmt = (r) => `${r.toFixed(1)}:1`;

function grade(ratio, warnBelow, issueBelow) {
  if (ratio == null) return 'issue';
  if (ratio < issueBelow) return 'issue';
  if (ratio < warnBelow) return 'warn';
  return 'ok';
}

const RULES = [
  { id: 'ink-bg', label: 'Text on background', fg: 'ink', bg: 'bg', warn: 7, issue: 4.5, name: 'Body text' },
  { id: 'ink-surface', label: 'Text on surface', fg: 'ink', bg: 'surface', warn: 7, issue: 4.5, name: 'Text on panels' },
  { id: 'muted-bg', label: 'Muted text on background', fg: 'muted', bg: 'bg', warn: 4.5, issue: 3, name: 'Muted text' },
  { id: 'accent-bg', label: 'Accent on background', fg: 'accent', bg: 'bg', warn: 3, issue: 1.5, name: 'The accent colour' },
  { id: 'accent2-bg', label: 'Accent 2 on background', fg: 'accent2', bg: 'bg', warn: 3, issue: 1.5, name: 'The second accent' },
  { id: 'bg-accent', label: 'Background on accent', fg: 'bg', bg: 'accent', warn: 4.5, issue: 3, name: 'Text on an accent ground' },
];

export function contrastChecks(theme) {
  return RULES.map((r) => {
    const ratio = contrastRatio(`@${r.fg}`, `@${r.bg}`, theme);
    const level = grade(ratio, r.warn, r.issue);
    let text;
    if (ratio == null) text = `${r.name} cannot be measured: one of the two colours is not a valid hex value.`;
    else if (level === 'ok') text = `${r.name} reads well (${fmt(ratio)}).`;
    else if (level === 'warn') text = `${r.name} is ${fmt(ratio)}. Readable, but below the ${r.warn}:1 we recommend.`;
    else text = `${r.name} is only ${fmt(ratio)}. Many people will struggle to read it. Aim for at least ${r.issue}:1, ideally ${r.warn}:1.`;
    return { id: r.id, label: r.label, fg: r.fg, bg: r.bg, ratio, level, text };
  });
}

export function worstLevel(checks) {
  if (checks.some((c) => c.level === 'issue')) return 'issue';
  if (checks.some((c) => c.level === 'warn')) return 'warn';
  return 'ok';
}

export const LEVEL_WORD = { ok: 'ok', warn: 'warning', issue: 'issue' };
