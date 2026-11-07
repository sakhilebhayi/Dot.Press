import test from 'node:test';
import assert from 'node:assert/strict';
import { saveInfo } from '../saveState.js';

test('each sync status has a plain word', () => {
  assert.equal(saveInfo({ status: 'saved', pending: 0 }).label, 'Saved');
  assert.equal(saveInfo({ status: 'saving', pending: 2 }).label, 'Saving');
  assert.equal(saveInfo({ status: 'saved', pending: 1 }).label, 'Saving');
  assert.equal(saveInfo(null).label, 'Saved');
});

test('offline says where the changes are, depending on storage', () => {
  assert.equal(saveInfo({ status: 'offline', pending: 3 }).label, 'Offline: changes kept on this device');
  const blocked = saveInfo({ status: 'offline', pending: 3 }, { storageOk: false });
  assert.equal(blocked.label, 'Offline: changes kept in this tab only');
  assert.equal(blocked.action, 'retry');
});

test('errors offer the right button', () => {
  assert.deepEqual([saveInfo({ status: 'error', lastError: 'x' }).label, saveInfo({ status: 'error' }).action], ['Could not save', 'retry']);
  assert.equal(saveInfo({ status: 'error', fatal: true, lastError: 'bad op' }).action, 'discard');
  assert.match(saveInfo({ status: 'error', fatal: true, lastError: 'bad op' }).detail, /bad op/);
  assert.equal(saveInfo({ status: 'error', needsAuth: true }).action, 'reload');
});

test('a viewer sees View only whatever the sync says', () => {
  const v = saveInfo({ status: 'error' }, { readOnly: true });
  assert.equal(v.key, 'readonly');
  assert.equal(v.action, null);
});
