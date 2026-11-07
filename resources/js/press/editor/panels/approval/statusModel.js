/**
 * Pure logic for the approval panel and the top bar chip (no Vue, node tested).
 *
 * The server owns the truth (App\Services\Press\Reviews): a decision is bound to a hash of the exact content, so an
 * edit after approval makes the deck read `changed_since_approval`. These helpers only turn the server's description
 * (GET /api/decks/{deck}/reviews) into words, bead states and the few decisions the screen needs.
 */

export const STATUSES = ['draft', 'in_review', 'approved', 'changed_since_approval'];

/** word is the Plex Mono state word, bead the data-state of the bead (see DESIGN.md: hollow, solid, current, issue). */
const META = {
  draft: { label: 'Draft', word: 'hollow', bead: 'hollow', evidence: 'hollow', tone: 'quiet', line: 'Nobody has been asked to review this version.' },
  in_review: { label: 'In review', word: 'here', bead: 'current', evidence: 'hollow', tone: 'busy', line: 'Waiting for the people asked to review.' },
  approved: { label: 'Approved', word: 'solid', bead: 'solid', evidence: 'solid', tone: 'ok', line: 'Every reviewer approved this exact version.' },
  changed_since_approval: { label: 'Changed since approval', word: 'issue', bead: 'issue', evidence: 'hollow', tone: 'warn', line: 'This deck was approved, then edited. The approval no longer applies.' },
};

export function statusMeta(status) {
  return META[status] || META.draft;
}

/** The status to show: the server's, else the one in the document, else draft. Unknown words fall back to draft. */
export function deriveStatus(info, docStatus) {
  const s = info?.status ?? docStatus;
  return STATUSES.includes(s) ? s : 'draft';
}

/** The reviews of the newest round (the ones that count), one row per reviewer, newest first. */
export function currentRound(reviews) {
  const list = reviews || [];
  if (!list.length) return [];
  const round = Math.max(...list.map((r) => r.round || 0));
  const seen = new Set();
  const out = [];
  for (const r of [...list].filter((x) => (x.round || 0) === round).sort((a, b) => b.id - a.id)) {
    if (seen.has(r.reviewer.id)) continue;
    seen.add(r.reviewer.id);
    out.push(r);
  }
  return out;
}

/** How one review reads: a state word, a longer phrase and a bead. */
export function reviewState(review) {
  if (review.status === 'pending') return { key: 'waiting', word: 'waiting', phrase: 'Has not decided yet', bead: 'hollow', evidence: 'hollow' };
  if (review.status === 'approved') {
    return review.current
      ? { key: 'approved', word: 'approved', phrase: 'Approved this version', bead: 'solid', evidence: 'solid' }
      : { key: 'stale', word: 'out of date', phrase: 'Approved an older version, before the deck changed', bead: 'issue', evidence: 'hollow' };
  }
  return review.current
    ? { key: 'changes', word: 'changes', phrase: 'Asked for changes', bead: 'issue', evidence: 'hollow' }
    : { key: 'changes-old', word: 'changes', phrase: 'Asked for changes on an older version', bead: 'issue', evidence: 'hollow' };
}

/** Counts for the newest round. `stale` reviews are decided but no longer match the content. */
export function roundSummary(reviews) {
  const round = currentRound(reviews);
  const out = { total: round.length, waiting: 0, approved: 0, changes: 0, stale: 0 };
  for (const r of round) {
    const k = reviewState(r).key;
    if (k === 'waiting') out.waiting += 1;
    else if (k === 'approved') out.approved += 1;
    else if (k === 'stale') out.stale += 1;
    else out.changes += 1;
  }
  return out;
}

/** One sentence for the top of the panel. */
export function statusSentence(status, summary) {
  const n = summary?.total || 0;
  if (status === 'in_review') {
    const left = summary.waiting;
    return left === 1 ? 'Waiting for 1 reviewer.' : `Waiting for ${left} reviewers.`;
  }
  if (status === 'approved') return n === 1 ? '1 reviewer approved this exact version.' : `${n} reviewers approved this exact version.`;
  if (status === 'changed_since_approval') return 'This deck was approved, then edited. Ask for a new review to approve it again.';
  if (n && summary.changes) return summary.changes === 1 ? '1 reviewer asked for changes.' : `${summary.changes} reviewers asked for changes.`;
  return 'Nobody has been asked to review this version.';
}

/** The pending review the signed in person may decide, if any. */
export function myPendingReview(reviews, meId) {
  return (reviews || []).find((r) => r.status === 'pending' && r.reviewer?.id === meId) || null;
}

/** Reviewers that can be asked: they can comment, are not already pending in the open round. */
export function reviewerChoices(people, reviews, meId) {
  const pending = new Set(currentRound(reviews).filter((r) => r.status === 'pending').map((r) => r.reviewer.id));
  return (people || [])
    .filter((p) => p.can_comment !== false && !pending.has(p.id))
    .map((p) => ({ value: p.id, label: p.id === meId ? `${p.name} (you)` : p.name, hint: p.role }));
}

/** The request body for POST /reviews. */
export function requestPayload(ids, note) {
  const out = { reviewer_ids: [...new Set(ids.map(Number))] };
  const n = String(note ?? '').trim();
  if (n) out.note = n.slice(0, 2000);
  return out;
}

/** The decision body for POST /reviews/{id}/decision. Asking for changes should say what to change. */
export function decisionPayload(decision, note) {
  const out = { decision };
  const n = String(note ?? '').trim();
  if (n) out.note = n.slice(0, 2000);
  return out;
}

export function decisionProblem(decision, note) {
  if (decision === 'changes_requested' && String(note ?? '').trim() === '') return 'Say what should change, so the author knows where to start.';
  return '';
}
