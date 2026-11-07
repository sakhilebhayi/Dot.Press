import test from 'node:test';
import assert from 'node:assert/strict';
import { escapeXml, stripInvalidXml, cdata, replaceCssUrls, clampScale, slideSvg, svgDataUrl, selectorClasses, ruleMayApply, staticSelector, MAX_CANVAS_SIDE } from '../svgSlide.js';
import { parseFontFaces, parseUnicodeRange, firstFamily, familiesFromStacks, codepointsOf, facesNeeded, fontFaceCss, bytesToBase64, toDataUri } from '../fontCss.js';

test('xml escaping and invalid characters', () => {
    assert.equal(escapeXml(`a & b < c > "d" 'e'`), 'a &amp; b &lt; c &gt; &quot;d&quot; &apos;e&apos;');
    assert.equal(stripInvalidXml('a\u0000b\u0008c\u000bd\ne\tf '), 'abcd\ne\tf ');
});

test('cdata keeps a closing marker from ending the section', () => {
    assert.equal(cdata('a{}'), '<![CDATA[a{}]]>');
    assert.equal(cdata('x]]>y'), '<![CDATA[x]]]]><![CDATA[>y]]>');
});

test('replaceCssUrls rewrites every url() and leaves others alone', () => {
    const css = `background:url(/a.png) , url("b.png"), url( 'c.png' ), url(data:image/png;base64,AAA=)`;
    const seen = [];
    const out = replaceCssUrls(css, (u) => { seen.push(u); return u.startsWith('data:') ? null : `data:x,${u}`; });
    assert.deepEqual(seen, ['/a.png', 'b.png', 'c.png', 'data:image/png;base64,AAA=']);
    assert.equal(out, `background:url("data:x,/a.png") , url("data:x,b.png"), url("data:x,c.png"), url(data:image/png;base64,AAA=)`);
});

test('clampScale keeps the canvas inside the browser limits and says when it did', () => {
    assert.deepEqual(clampScale(1920, 1080, 2), { scale: 2, reduced: false });
    assert.deepEqual(clampScale(1920, 1080, 0), { scale: 1, reduced: false });
    const big = clampScale(7680, 4320, 4);
    assert.equal(big.reduced, true);
    assert.ok(7680 * big.scale <= MAX_CANVAS_SIDE + 1);
    assert.ok(7680 * 4320 * big.scale * big.scale <= 100_000_001);
    const wide = clampScale(20000, 100, 2);
    assert.ok(20000 * wide.scale <= MAX_CANVAS_SIDE + 1 && wide.reduced);
});

test('slideSvg sets the picture size from the scale and the view box from the slide', () => {
    const svg = slideSvg({ xhtml: '<div xmlns="http://www.w3.org/1999/xhtml">Hi</div>', css: '.a{color:red}', w: 1920, h: 1080, scale: 2 });
    assert.match(svg, /^<svg xmlns="http:\/\/www\.w3\.org\/2000\/svg" width="3840" height="2160" viewBox="0 0 1920 1080">/);
    assert.match(svg, /<foreignObject x="0" y="0" width="1920" height="1080">/);
    assert.match(svg, /<style><!\[CDATA\[\.a\{color:red\}\]\]><\/style>/);
    assert.ok(svg.endsWith('</foreignObject></svg>'));
    const odd = slideSvg({ xhtml: '', css: '', w: 1280.4, h: 720.6, scale: 1.5 });
    assert.match(odd, /width="1920" height="1082"/);
    assert.match(odd, /viewBox="0 0 1280 721"/);
});

test('slideSvg output is well formed XML', async () => {
    const svg = slideSvg({ xhtml: '<div xmlns="http://www.w3.org/1999/xhtml"><p>a &amp; b\u0001</p><br/></div>', css: 'a>b{x:y}', w: 100, h: 50 });
    const { execFileSync } = await import('node:child_process');
    try {
        execFileSync('xmllint', ['--noout', '-'], { input: svg, stdio: ['pipe', 'pipe', 'pipe'] });
    } catch (e) {
        if (e.code === 'ENOENT') return; // xmllint not installed: the other tests still cover the shape
        assert.fail(String(e.stderr || e.message));
    }
});

test('svgDataUrl is a percent encoded data URL', () => {
    const url = svgDataUrl('<svg a="#"/>');
    assert.ok(url.startsWith('data:image/svg+xml;charset=utf-8,'));
    assert.ok(!url.includes('#') && !url.includes('<'));
});

test('rule filtering by class', () => {
    assert.deepEqual(selectorClasses('.pt-in ul.a-b > .c:hover'), ['pt-in', 'a-b', 'c']);
    const classes = new Set(['ps-stage', 'p-el', 'pt']);
    assert.equal(ruleMayApply('.ps-stage', classes), true);
    assert.equal(ruleMayApply('.ps-stage .missing', classes), false);
    assert.equal(ruleMayApply('.missing, .p-el', classes), true);
    assert.equal(ruleMayApply('*, ::after', classes), true);
    assert.equal(staticSelector('.a:hover .b::before'), '.a:not(*) .b');
});

const FONTS = `
@font-face{font-family:'Public Sans';font-style:normal;font-weight:100 900;font-display:swap;src:url(public-sans-normal-100-900-latin-ext.woff2) format('woff2');unicode-range:U+0100-02BA, U+1E00-1E9F}
@font-face{font-family:'Public Sans';font-style:normal;font-weight:100 900;font-display:swap;src:url(public-sans-normal-100-900-latin.woff2) format('woff2');unicode-range:U+0000-00FF, U+20AC}
@font-face{font-family:'Caveat';font-style:italic;font-weight:400 700;src:url("/fonts/caveat.woff2") format('woff2')}
`;

test('parseFontFaces reads family, weight, file and range', () => {
    const faces = parseFontFaces(FONTS, '/fonts/fonts.css');
    assert.equal(faces.length, 3);
    assert.equal(faces[1].family, 'Public Sans');
    assert.equal(faces[1].weight, '100 900');
    assert.equal(faces[1].url, '/fonts/public-sans-normal-100-900-latin.woff2');
    assert.deepEqual(faces[1].ranges, [[0, 255], [0x20ac, 0x20ac]]);
    assert.equal(faces[2].url, '/fonts/caveat.woff2');
    assert.equal(faces[2].ranges, null);
    assert.equal(faces[2].style, 'italic');
});

test('unicode ranges, including wildcard forms', () => {
    assert.deepEqual(parseUnicodeRange('U+0025-00FF, U+4??'), [[0x25, 0xff], [0x400, 0x4ff]]);
    assert.equal(parseUnicodeRange('nonsense'), null);
});

test('family names from font stacks', () => {
    assert.equal(firstFamily('"Public Sans", ui-sans-serif, system-ui'), 'Public Sans');
    assert.equal(firstFamily("'IBM Plex Mono', monospace"), 'IBM Plex Mono');
    assert.equal(firstFamily('system-ui, sans-serif'), null);
    assert.deepEqual([...familiesFromStacks(['"Inter", "Public Sans", sans-serif', "'Caveat', cursive"])].sort(), ['Caveat', 'Inter', 'Public Sans']);
});

test('only the faces that cover the text are chosen', () => {
    const faces = parseFontFaces(FONTS);
    const latin = facesNeeded(faces, new Set(['Public Sans']), codepointsOf('Hello €'));
    assert.equal(latin.length, 1);
    assert.match(latin[0].url, /latin\.woff2$/);
    const both = facesNeeded(faces, new Set(['Public Sans']), codepointsOf('Zażółć'));
    assert.equal(both.length, 2);
    assert.equal(facesNeeded(faces, new Set(['Unknown']), codepointsOf('a')).length, 0);
    assert.equal(facesNeeded(faces, new Set(['Caveat']), codepointsOf('a')).length, 1);
});

test('fontFaceCss inlines the file', () => {
    const [f] = parseFontFaces(FONTS);
    const css = fontFaceCss(f, 'data:font/woff2;base64,AAAA');
    assert.match(css, /^@font-face\{font-family:'Public Sans';font-style:normal;font-weight:100 900;src:url\(data:font\/woff2;base64,AAAA\) format\('woff2'\);unicode-range:U\+0100-02BA, U\+1E00-1E9F\}$/);
});

test('base64 helpers agree with Buffer, also for big arrays', () => {
    const big = new Uint8Array(200000).map((_, i) => (i * 7) % 256);
    assert.equal(bytesToBase64(big), Buffer.from(big).toString('base64'));
    assert.equal(toDataUri('font/woff2', new Uint8Array([1, 2, 3])), 'data:font/woff2;base64,AQID');
});
