import test from 'node:test';
import assert from 'node:assert/strict';
import {
  COLLAB_HELP, LEVELS, LINK_LIMITS, TEAM_ROLES, accessSummary, dateInputValue, expiryIso, expiryProblem, failureText, identityProblem, levelFor, linkState, parseIdentity,
  passwordProblem, visibilityFor,
} from '../sharingModel.js';

test('four steps map onto three server visibilities', () => {
  assert.deepEqual(LEVELS.map((l) => l.value), ['private', 'shared', 'team', 'link']);
  assert.equal(levelFor('private', 0), 'private');
  assert.equal(levelFor('private', 2), 'shared');
  assert.equal(levelFor('team', 0), 'team');
  assert.equal(levelFor('link', 5), 'link');
  assert.equal(visibilityFor('private'), 'private');
  assert.equal(visibilityFor('shared'), 'private');
  assert.equal(visibilityFor('team'), 'team');
  assert.equal(visibilityFor('link'), 'link');
});

test('the person field accepts an e-mail address or a user id and nothing else', () => {
  assert.deepEqual(parseIdentity(' Ana@Example.COM '), { email: 'ana@example.com' });
  assert.deepEqual(parseIdentity('42'), { user_id: 42 });
  assert.equal(parseIdentity('ana'), null);
  assert.equal(parseIdentity('a@b'), null);
  assert.equal(parseIdentity(''), null);
  assert.equal(identityProblem(''), 'Enter an e-mail address or a user id.');
  assert.equal(identityProblem('nope'), 'That is not an e-mail address or a user id.');
  assert.equal(identityProblem('ana@example.com'), '');
});

test('link password and expiry rules match the server', () => {
  assert.equal(passwordProblem('12345'), 'Use at least 6 characters.');
  assert.equal(passwordProblem('123456'), '');
  assert.equal(passwordProblem('x'.repeat(201)), 'Use at most 200 characters.');
  const now = new Date(2026, 10, 7, 12, 0, 0);
  assert.equal(expiryProblem('', now), '');
  assert.equal(expiryProblem('2026-11-06', now), 'Choose a day that has not ended yet.');
  assert.equal(expiryProblem('2026-11-07', now), ''); // today runs until 23:59
  assert.equal(expiryProblem('not a date', now), 'Choose a date.');
  const iso = expiryIso('2026-11-07');
  const back = new Date(iso);
  assert.equal(back.getHours(), 23);
  assert.equal(back.getMinutes(), 59);
  assert.equal(dateInputValue(new Date(2026, 0, 5)), '2026-01-05');
  assert.equal(expiryIso('2026-13-45') === null || typeof expiryIso('2026-13-45') === 'string', true);
});

test('link state', () => {
  assert.deepEqual(linkState(null), { key: 'off', protected: false, expires: null });
  assert.deepEqual(linkState({ enabled: false }), { key: 'off', protected: false, expires: null });
  assert.equal(linkState({ enabled: true, expired: true }).key, 'expired');
  assert.deepEqual(linkState({ enabled: true, has_password: true, expires_at: 'x' }), { key: 'active', protected: true, expires: 'x' });
});

test('access summary counts roles', () => {
  assert.equal(accessSummary([{ role: 'owner' }, { role: 'editor' }, { role: 'editor' }, { role: 'viewer' }]), '1 owner, 2 editors, 1 viewer');
  assert.equal(accessSummary([]), 'Nobody yet');
});

test('the fixed explanations stay honest', () => {
  assert.equal(TEAM_ROLES.length, 3);
  assert.match(TEAM_ROLES[2].can, /cannot change slides/);
  assert.match(LINK_LIMITS, /comments, speaker notes, versions or who has access/);
  assert.ok(COLLAB_HELP.some((l) => /last edit wins/.test(l)));
  assert.ok(COLLAB_HELP.some((l) => /no live co-typing/.test(l)));
  for (const text of [...COLLAB_HELP, LINK_LIMITS, ...LEVELS.map((l) => l.long)]) assert.doesNotMatch(text, new RegExp('[\u2013\u2014]'));
});

test('failure text', () => {
  assert.match(failureText({ status: 403 }), /owner or an administrator/);
  assert.match(failureText({ status: 429, retryAfter: 9 }), /9 seconds/);
  assert.equal(failureText({ status: 422, message: 'No account matches that person.' }), 'No account matches that person.');
});
