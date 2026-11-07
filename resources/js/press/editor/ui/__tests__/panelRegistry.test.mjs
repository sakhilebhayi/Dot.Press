import test from 'node:test';
import assert from 'node:assert/strict';
import { registerPanel, unregisterPanel, getPanel, hasPanel, listPanels, activePanel, resetPanels } from '../../panels/panelRegistry.js';

const C = { name: 'component' };

test('register validates and fills defaults', () => {
  resetPanels();
  assert.throws(() => registerPanel({ scope: 'deck', component: C }), /id is required/);
  assert.throws(() => registerPanel({ id: 'a', scope: 'nope', component: C }), /scope/);
  assert.throws(() => registerPanel({ id: 'a', scope: 'deck' }), /component/);
  registerPanel({ id: 'theme', label: 'Theme', scope: 'deck', component: C });
  const p = getPanel('theme');
  assert.equal(p.order, 100);
  assert.equal(p.icon, null);
  assert.equal(hasPanel('theme'), true);
  assert.equal(unregisterPanel('theme'), true);
  assert.equal(hasPanel('theme'), false);
});

test('selection panels need a selection; order is scope, then order, then label', () => {
  resetPanels();
  registerPanel({ id: 'theme', label: 'Theme', scope: 'deck', order: 10, component: C });
  registerPanel({ id: 'assets', label: 'Assets', scope: 'deck', order: 20, component: C });
  registerPanel({ id: 'layout', label: 'Layout', scope: 'slide', order: 5, component: C });
  registerPanel({ id: 'text', label: 'Text', scope: 'selection', order: 1, component: C });
  registerPanel({ id: 'arrange', label: 'Arrange', scope: 'selection', order: 1, component: C });
  assert.deepEqual(listPanels({ selected: [] }).map((p) => p.id), ['layout', 'theme', 'assets']);
  assert.deepEqual(listPanels({ selected: [{ id: 'e' }] }).map((p) => p.id), ['arrange', 'text', 'layout', 'theme', 'assets']);
});

test('when() filters and a throwing predicate hides the panel instead of breaking the drawer', () => {
  resetPanels();
  registerPanel({ id: 'review', label: 'Review', scope: 'deck', component: C, when: (ctx) => ctx.can?.manage });
  registerPanel({ id: 'bad', label: 'Bad', scope: 'deck', component: C, when: () => { throw new Error('x'); } });
  assert.deepEqual(listPanels({ can: { manage: false } }).map((p) => p.id), []);
  assert.deepEqual(listPanels({ can: { manage: true } }).map((p) => p.id), ['review']);
});

test('activePanel prefers the wanted id and falls back to the first offered', () => {
  resetPanels();
  registerPanel({ id: 'a', label: 'A', scope: 'slide', component: C });
  registerPanel({ id: 'b', label: 'B', scope: 'deck', component: C });
  const list = listPanels({});
  assert.equal(activePanel(list, 'b').id, 'b');
  assert.equal(activePanel(list, 'zzz').id, 'a');
  assert.equal(activePanel(list, null).id, 'a');
  assert.equal(activePanel([], 'a'), null);
});

test('registering the same id again replaces the panel', () => {
  resetPanels();
  registerPanel({ id: 'a', label: 'One', scope: 'slide', component: C });
  registerPanel({ id: 'a', label: 'Two', scope: 'slide', component: C });
  assert.equal(listPanels({}).length, 1);
  assert.equal(getPanel('a').label, 'Two');
});
