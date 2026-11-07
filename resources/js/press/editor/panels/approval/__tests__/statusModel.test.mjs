import test from 'node:test';
import assert from 'node:assert/strict';
import {
  currentRound, decisionPayload, decisionProblem, deriveStatus, myPendingReview, requestPayload, reviewState, reviewerChoices, roundSummary, statusMeta, statusSentence,
} from '../statusModel.js';

const r = (id, reviewer, status, extra = {}) => ({ id, status, round: 1, current: true, reviewer: { id: reviewer, name: `U${reviewer}` }, requested_by: { id: 1, name: 'Owner' }, ...extra });

test('the shown status is the server one, else the document one, else draft', () => {
  assert.equal(deriveStatus({ status: 'approved' }, 'draft'), 'approved');
  assert.equal(deriveStatus(null, 'in_review'), 'in_review');
  assert.equal(deriveStatus({ status: 'changed_since_approval' }, 'approved'), 'changed_since_approval');
  assert.equal(deriveStatus({ status: 'bogus' }, 'approved'), 'draft');
  assert.equal(deriveStatus(null, undefined), 'draft');
});

test('each status has a label, a state word and a bead that is not only colour', () => {
  assert.deepEqual(['draft', 'in_review', 'approved', 'changed_since_approval'].map((s) => statusMeta(s).label), ['Draft', 'In review', 'Approved', 'Changed since approval']);
  assert.equal(statusMeta('draft').bead, 'hollow');
  assert.equal(statusMeta('approved').bead, 'solid');
  assert.equal(statusMeta('in_review').bead, 'current');
  assert.equal(statusMeta('changed_since_approval').bead, 'issue');
  assert.equal(statusMeta('nonsense').label, 'Draft');
});

test('review state words: waiting, approved, out of date, changes', () => {
  assert.equal(reviewState(r(1, 2, 'pending')).key, 'waiting');
  assert.equal(reviewState(r(1, 2, 'approved')).key, 'approved');
  assert.equal(reviewState(r(1, 2, 'approved', { current: false })).key, 'stale');
  assert.equal(reviewState(r(1, 2, 'changes_requested')).key, 'changes');
  assert.equal(reviewState(r(1, 2, 'changes_requested', { current: false })).key, 'changes-old');
});

test('only the newest round counts, one row per reviewer, newest first', () => {
  const list = [r(1, 2, 'approved', { round: 1 }), r(2, 3, 'pending', { round: 2 }), r(3, 2, 'pending', { round: 2 }), r(4, 2, 'changes_requested', { round: 2 })];
  assert.deepEqual(currentRound(list).map((x) => x.id), [4, 2]);
  assert.deepEqual(currentRound([]), []);
});

test('round summary and the sentence under the status', () => {
  const list = [r(1, 2, 'approved'), r(2, 3, 'pending'), r(3, 4, 'approved', { current: false }), r(4, 5, 'changes_requested')];
  assert.deepEqual(roundSummary(list), { total: 4, waiting: 1, approved: 1, changes: 1, stale: 1 });
  assert.equal(statusSentence('in_review', roundSummary(list)), 'Waiting for 1 reviewer.');
  assert.equal(statusSentence('in_review', { total: 3, waiting: 2 }), 'Waiting for 2 reviewers.');
  assert.equal(statusSentence('approved', { total: 2 }), '2 reviewers approved this exact version.');
  assert.match(statusSentence('changed_since_approval', { total: 1 }), /approved, then edited/);
  assert.equal(statusSentence('draft', { total: 0 }), 'Nobody has been asked to review this version.');
  assert.equal(statusSentence('draft', { total: 2, changes: 1 }), '1 reviewer asked for changes.');
});

test('the reviewer sees their own pending review only', () => {
  const list = [r(1, 2, 'pending'), r(2, 3, 'pending'), r(3, 3, 'approved')];
  assert.equal(myPendingReview(list, 3).id, 2);
  assert.equal(myPendingReview(list, 9), null);
  assert.equal(myPendingReview(null, 3), null);
});

test('reviewer choices: people who can comment and are not already pending', () => {
  const people = [{ id: 1, name: 'Me', role: 'owner' }, { id: 2, name: 'Ann', role: 'editor' }, { id: 3, name: 'Viewer', role: 'viewer', can_comment: false }, { id: 4, name: 'Bob', role: 'commenter', can_comment: true }];
  const out = reviewerChoices(people, [r(1, 4, 'pending')], 1);
  assert.deepEqual(out.map((c) => c.value), [1, 2]);
  assert.equal(out[0].label, 'Me (you)');
});

test('request and decision payloads', () => {
  assert.deepEqual(requestPayload([2, '2', 3], '  look at slide 4  '), { reviewer_ids: [2, 3], note: 'look at slide 4' });
  assert.deepEqual(requestPayload([2], '   '), { reviewer_ids: [2] });
  assert.deepEqual(decisionPayload('approved', ''), { decision: 'approved' });
  assert.deepEqual(decisionPayload('changes_requested', ' fix the chart '), { decision: 'changes_requested', note: 'fix the chart' });
  assert.equal(decisionProblem('changes_requested', ' '), 'Say what should change, so the author knows where to start.');
  assert.equal(decisionProblem('approved', ''), '');
});
