import test from 'node:test';
import assert from 'node:assert/strict';
import { docToHtml, docToPlain, safeHref, escapeHtml, docKey, docIsEmpty } from '../doc.js';

const p = (...content) => ({ type: 'paragraph', content });
const t = (text, marks) => ({ type: 'text', text, ...(marks ? { marks } : {}) });
const doc = (...content) => ({ type: 'doc', content });

test('paragraphs, marks and line breaks', () => {
    const html = docToHtml(doc(p(t('Hello ', [{ type: 'bold' }]), t('world', [{ type: 'italic' }, { type: 'underline' }, { type: 'strike' }]), { type: 'hardBreak' }, t('next'))));
    assert.equal(html, '<p><strong>Hello </strong><s><u><em>world</em></u></s><br>next</p>');
});

test('empty paragraph keeps its height with a break', () => {
    assert.equal(docToHtml(doc({ type: 'paragraph' })), '<p><br></p>');
});

test('lists nest', () => {
    const html = docToHtml(doc({ type: 'bulletList', content: [{ type: 'listItem', content: [p(t('a')), { type: 'orderedList', attrs: { start: 3 }, content: [{ type: 'listItem', content: [p(t('b'))] }] }] }] }));
    assert.equal(html, '<ul><li><p>a</p><ol start="3"><li><p>b</p></li></ol></li></ul>');
});

test('text is escaped, including quotes', () => {
    const html = docToHtml(doc(p(t('<img src=x onerror=alert(1)> "q" & \'s\''))));
    assert.ok(!html.includes('<img'));
    assert.match(html, /&lt;img src=x onerror=alert\(1\)&gt; &quot;q&quot; &amp; &#39;s&#39;/);
});

test('hostile: unknown nodes and marks are dropped, script nodes never survive', () => {
    const html = docToHtml(doc(
        { type: 'script', content: [t('alert(1)')] },
        { type: 'heading', attrs: { level: 1 }, content: [t('H')] },
        p(t('x', [{ type: 'onclick', attrs: { v: 'alert(1)' } }, { type: 'bold', attrs: { style: 'x' } }])),
        { type: 'image', attrs: { src: 'javascript:alert(1)' } },
    ));
    assert.equal(html, '<p><strong>x</strong></p>');
});

test('hostile: link marks only allow https and slide links', () => {
    const mk = (href, links) => docToHtml(doc(p(t('l', [{ type: 'link', attrs: { href } }]))), { links });
    assert.match(mk('https://example.com/a?b=1', true), /<a class="p-link" href="https:\/\/example\.com\/a\?b=1" target="_blank" rel="noopener noreferrer">l<\/a>/);
    assert.match(mk('#slide:abc_12', true), /data-slide="abc_12"/);
    for (const bad of ['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,x', 'http://example.com', '//evil.com', 'https://a.com/" onmouseover="x', '  javascript:alert(1)', 'https://a.com/\u0000x', '#slide:', '#slide:a b', 'https:///']) {
        assert.ok(!/href=/.test(mk(bad, true)), `should reject ${JSON.stringify(bad)}`);
    }
    assert.equal(mk('https://example.com', false), '<p><span class="p-link">l</span></p>');
});

test('hostile: textStyle values cannot break out of the style attribute', () => {
    const html = docToHtml(doc(p(t('s', [{ type: 'textStyle', attrs: { color: 'red;background:url(x)', fontSize: '20px', fontFamily: 'x;}body{display:none' } }]))));
    assert.ok(!html.includes('url('));
    assert.ok(!html.includes('display:none'));
    assert.match(html, /font-size:calc\(20px \* var\(--p-fit, 1\)\)/);
    const ok = docToHtml(doc(p(t('s', [{ type: 'textStyle', attrs: { color: '@accent', fontFamily: 'Caveat' } }]))));
    assert.match(ok, /color:var\(--p-accent\)/);
    assert.match(ok, /font-family:&#39;Caveat&#39;/);
    const bad = docToHtml(doc(p(t('s', [{ type: 'textStyle', attrs: { fontSize: '99999px' } }]))));
    assert.ok(!bad.includes('99999'));
});

test('hostile: deep nesting and huge documents terminate quickly', () => {
    let node = p(t('deep'));
    for (let i = 0; i < 5000; i++) node = { type: 'bulletList', content: [{ type: 'listItem', content: [node] }] };
    const t0 = Date.now();
    const html = docToHtml(doc(node));
    assert.ok(Date.now() - t0 < 500);
    assert.ok(!html.includes('deep'));
    const many = doc(...Array.from({ length: 50000 }, () => p(t('x'))));
    assert.ok(docToHtml(many).length < 50000 * 20);
});

test('hostile: non-object input', () => {
    for (const v of [null, undefined, 3, 'x', [], { type: 'doc' }, { type: 'doc', content: 'x' }, { type: 'paragraph' }]) assert.equal(docToHtml(v), '');
    assert.equal(docToHtml(doc(null, 5, 'x', p(null, 7, { type: 'text', text: 5 }))), '<p><br></p>');
});

test('highlight wraps text in a marker span', () => {
    assert.match(docToHtml(doc(p(t('hi'))), { highlight: 'var(--p-accent2)' }), /<span class="p-hl" style="--p-hl:var\(--p-accent2\)">hi<\/span>/);
});

test('paragraph alignment is whitelisted', () => {
    assert.equal(docToHtml(doc({ type: 'paragraph', attrs: { textAlign: 'center' }, content: [t('a')] })), '<p style="text-align:center">a</p>');
    assert.equal(docToHtml(doc({ type: 'paragraph', attrs: { textAlign: 'center;x:y' }, content: [t('a')] })), '<p>a</p>');
});

test('docToPlain, docIsEmpty, docKey', () => {
    const d = doc(p(t('one')), p(t('two'), { type: 'hardBreak' }, t('three')));
    assert.equal(docToPlain(d), 'one\ntwo\nthree');
    assert.equal(docIsEmpty(doc(p())), true);
    assert.equal(docIsEmpty(d), false);
    assert.equal(docKey(d), docKey(JSON.parse(JSON.stringify(d))));
    assert.notEqual(docKey(d), docKey(doc(p(t('one')))));
});

test('escapeHtml and safeHref basics', () => {
    assert.equal(escapeHtml('<&>"\''), '&lt;&amp;&gt;&quot;&#39;');
    assert.equal(safeHref('https://example.com'), 'https://example.com/');
    assert.equal(safeHref(null), null);
});
