// The built-in layouts and templates come from PHP (App\Services\Press\LayoutLibrary, TemplateLibrary). This test asks
// PHP for them and checks they work with the editor's applyLayout(): placeholders match by role, nothing is deleted, and
// every placement stays on the slide. It is skipped when no php binary is available.
import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { applyLayout } from '../editor/core/layouts.js';
import { applyOps } from '../editor/core/ops.js';
import { mkDoc, S, T } from '../editor/core/__tests__/helpers.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../../..');

function php(code) {
  try {
    return JSON.parse(execFileSync('php', ['-r', `require 'vendor/autoload.php'; ${code}`], { cwd: root, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }));
  } catch {
    return null;
  }
}

const sizes = [[1920, 1080], [1440, 1080], [1080, 1920], [1280, 720]];
const probe = php('echo json_encode(App\\Services\\Press\\LayoutLibrary::ids());');
const skip = probe === null ? 'php is not available' : false;

test('the library names the layouts of the spec', { skip }, () => {
  assert.deepEqual(probe, ['title', 'section', 'bullets', 'two', 'image-left', 'image-right', 'image-full', 'compare', 'timeline', 'quote', 'data', 'kpi', 'closing', 'blank']);
});

for (const [w, h] of sizes) {
  test(`every layout applies to a slide at ${w}x${h}: roles match, nothing is removed, placements stay on the slide`, { skip }, () => {
    const layouts = php(`echo json_encode(App\\Services\\Press\\LayoutLibrary::forSize(${w}, ${h}));`);
    for (const layout of layouts) {
      const deck = { w, h, id: 1 };
      const doc = mkDoc([S('s1', [T('a', 10, 10, 300, 80, { role: 'title' }), T('b', 10, 200, 300, 200, { role: 'body' })], { layout: 'custom:x' })], deck);
      doc.deck = { ...doc.deck, w, h };
      const { ops, report } = applyLayout(doc, 's1', layout);
      assert.ok(!ops.some((o) => o.op === 'el.remove'), `${layout.id} never deletes content`);
      assert.ok(ops.some((o) => o.op === 'slide.set' && o.layout === layout.id), `${layout.id} sets the slide layout`);
      const out = applyOps(doc, ops).doc.slides[0];
      assert.ok(out.els.length >= 2, 'the original elements survive');
      for (const el of out.els) {
        assert.ok(el.x >= -0.5 && el.y >= -0.5 && el.x + el.w <= w + 0.5 && el.y + el.h <= h + 0.5, `${layout.id}: ${el.id} is on the slide`);
      }
      const wanted = layout.ph.filter((p) => p.role === 'title').length;
      if (wanted) assert.ok(report.matched.some((m) => m.id === 'a' && m.role === 'title'), `${layout.id}: the title element takes the title placeholder`);
      for (const p of layout.ph) {
        assert.ok(p.x >= 0 && p.y >= 0 && p.x + p.w <= 1.0001 && p.y + p.h <= 1.0001);
        if (p.type === 'text') assert.ok(p.sizeF > 0 && p.style.font && p.text);
      }
    }
  });
}

test('a template slide re-flows when its layout changes: the content is kept and re-placed by role', { skip }, () => {
  const built = php('echo json_encode(App\\Services\\Press\\TemplateLibrary::build("quarterly-business-review"));');
  const bullets = php('echo json_encode(App\\Services\\Press\\LayoutLibrary::layout("bullets"));');
  const compare = php('echo json_encode(App\\Services\\Press\\LayoutLibrary::layout("compare"));');
  const slide = built.slides.find((s) => s.layout === 'bullets');
  const doc = mkDoc([{ ...S('s1', slide.els.map((e) => ({ ...e }))), layout: 'bullets' }]);
  doc.deck = { ...doc.deck, w: 1920, h: 1080 };

  const before = slide.els.length;
  const { ops } = applyLayout(doc, 's1', compare);
  const out = applyOps(doc, ops).doc.slides[0];
  assert.equal(out.layout, 'compare');
  assert.ok(out.els.length >= before, 'no element was lost');
  const title = out.els.find((e) => e.role === 'title');
  assert.equal(title.y, 90, 'the title moved to the top band');
  assert.ok(out.els.some((e) => e.role === 'subtitle'), 'the column headings were added');

  const again = applyLayout(doc, 's1', bullets);
  assert.equal(again.ops.filter((o) => o.op === 'el.add').length, 0, 'applying the layout the slide already has adds nothing');
});

test('template animations play as written: one click reveals a group, and nothing is hidden for good', { skip }, async () => {
  const { buildSequence } = await import('../player/sequence.js');
  const keys = php('echo json_encode(App\\Services\\Press\\TemplateLibrary::keys());');
  let animated = 0;
  for (const key of keys) {
    const built = php(`echo json_encode(App\\Services\\Press\\TemplateLibrary::build('${key}'));`);
    for (const slide of built.slides) {
      const hasAnim = slide.els.some((e) => Array.isArray(e.anim));
      if (!hasAnim) continue;
      animated++;
      const seq = buildSequence(slide);
      const last = seq.stateAt(Infinity);
      for (const el of slide.els) {
        if (!Array.isArray(el.anim)) continue;
        assert.ok(last.get(el.id).visible, `${key}: ${el.id} is visible after the last click`);
        assert.ok(!seq.stateAt(-1).get(el.id).visible, `${key}: ${el.id} starts hidden`);
      }
    }
  }
  assert.ok(animated >= 10, 'the templates use a few reveals');
});
