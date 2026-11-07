/**
 * Pure logic for the share panel (no Vue, node tested). The words here mirror App\Services\Press\DeckAccess and
 * Sharing: change them together.
 *
 * The server stores three visibilities: `private`, `team` and `link`. The panel shows four steps because `private`
 * reads differently with and without people added: Private (nobody added) and Shared (people added).
 */

export const LEVELS = [
  { value: 'private', label: 'Private', short: 'Only you', long: 'Only you can open this deck. Add people below to share it with them.' },
  { value: 'shared', label: 'Shared', short: 'People you add', long: 'You and the people you add can open it, each with the role you give them. The team does not see it.' },
  { value: 'team', label: 'Team', short: 'Everyone in the team', long: 'Everyone in the team can open it, with a role that follows their team role. People you add keep their own role if it is higher.' },
  { value: 'link', label: 'Public link', short: 'Anyone with the link', long: 'Anyone with the link can view and present it, without an account. The team keeps its roles. Turning the link off or replacing it stops the old link at once.' },
];

/** Show the step the server's state amounts to. */
export function levelFor(visibility, peopleCount) {
  if (visibility === 'team') return 'team';
  if (visibility === 'link') return 'link';
  return peopleCount > 0 ? 'shared' : 'private';
}

/** What to save for a step: private and shared are the same server value. */
export function visibilityFor(level) {
  return level === 'team' || level === 'link' ? level : 'private';
}

/** Roles you can give a person (DeckAccess::SHARE_ROLES). */
export const ROLES = [
  { value: 'viewer', label: 'Viewer', hint: 'Open, present and export' },
  { value: 'commenter', label: 'Commenter', hint: 'Viewer, and comment' },
  { value: 'editor', label: 'Editor', hint: 'Commenter, and change slides' },
];

/** What each team role becomes on a team deck (DeckAccess::roleForTeamKey and DeckAccessGrant). */
export const TEAM_ROLES = [
  { team: 'Owner and administrators', gets: 'Owner', can: 'Everything an editor can do, plus sharing, review requests and deleting.' },
  { team: 'Editors', gets: 'Editor', can: 'Open, comment, change slides, save and restore versions.' },
  { team: 'Viewers', gets: 'Viewer who can comment', can: 'Open, present, export and comment. They cannot change slides.' },
];

export const COLLAB_HELP = [
  'Changes merge per element. If you move one box while a colleague edits another, both stay.',
  'They arrive within a few seconds, not as you type.',
  'If two people edit the very same text box at once, the last edit wins and the other is overwritten. There is no live co-typing here.',
];

export const LINK_LIMITS = 'People who open the public link can see the slides only. They cannot see comments, speaker notes, versions or who has access.';

export function roleLabel(role) {
  return ({ owner: 'Owner', editor: 'Editor', commenter: 'Commenter', viewer: 'Viewer' })[role] || role;
}

export function sourceLabel(source) {
  return ({ creator: 'created it', team: 'via the team', share: 'added by you' })[source] || source;
}

/** "1 owner, 2 editors, 3 viewers" for the access summary. */
export function accessSummary(people) {
  const counts = {};
  for (const p of people || []) counts[p.role] = (counts[p.role] || 0) + 1;
  const parts = ['owner', 'editor', 'commenter', 'viewer'].filter((r) => counts[r]).map((r) => `${counts[r]} ${r}${counts[r] === 1 ? '' : 's'}`);
  return parts.length ? parts.join(', ') : 'Nobody yet';
}

// ---- the person field -----------------------------------------------------------------------------------

/** "ana@example.com" -> {email}; "42" -> {user_id}; anything else -> null. */
export function parseIdentity(input) {
  const v = String(input ?? '').trim();
  if (v === '') return null;
  if (/^\d{1,12}$/.test(v)) return { user_id: Number(v) };
  if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return { email: v.toLowerCase() };
  return null;
}

export function identityProblem(input) {
  if (String(input ?? '').trim() === '') return 'Enter an e-mail address or a user id.';
  return parseIdentity(input) ? '' : 'That is not an e-mail address or a user id.';
}

// ---- link settings --------------------------------------------------------------------------------------

export function passwordProblem(pw) {
  const v = String(pw ?? '');
  if (v.length < 6) return 'Use at least 6 characters.';
  if (v.length > 200) return 'Use at most 200 characters.';
  return '';
}

const pad = (n) => String(n).padStart(2, '0');
/** YYYY-MM-DD in the local time zone. */
export function dateInputValue(d) {
  const x = d instanceof Date ? d : new Date(d);
  return Number.isNaN(x.getTime()) ? '' : `${x.getFullYear()}-${pad(x.getMonth() + 1)}-${pad(x.getDate())}`;
}

/** A link stays open through the whole of the chosen day: 23:59 local, as an ISO string. */
export function expiryIso(dateStr) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(dateStr ?? ''));
  if (!m) return null;
  const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), 23, 59, 0, 0);
  return Number.isNaN(d.getTime()) ? null : d.toISOString();
}

export function expiryProblem(dateStr, now = new Date()) {
  if (!dateStr) return '';
  const iso = expiryIso(dateStr);
  if (!iso) return 'Choose a date.';
  return Date.parse(iso) <= now.getTime() ? 'Choose a day that has not ended yet.' : '';
}

/** off | active | expired, with the facts the panel shows. */
export function linkState(link) {
  if (!link?.enabled) return { key: 'off', protected: false, expires: null };
  return { key: link.expired ? 'expired' : 'active', protected: !!link.has_password, expires: link.expires_at || null };
}

/** Plain-language message for a failed request. */
export function failureText(err) {
  if (!err) return 'That did not work. Try again.';
  if (err.network) return 'No connection. Nothing was changed.';
  if (err.status === 403) return 'Only an owner or an administrator can change sharing.';
  if (err.status === 429) return err.retryAfter ? `Too many changes in a row. Try again in ${err.retryAfter} seconds.` : 'Too many changes in a row. Try again in a moment.';
  return err.message || 'That did not work. Try again.';
}
