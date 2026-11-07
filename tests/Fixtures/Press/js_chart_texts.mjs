// Prints the text lines the editor's chart engine (resources/js/press/charts) draws for each case given as JSON in argv[2].
// Used by ChartParityTest to check that the PHP chart renderer for PDF exports lays charts out the same way.
import { chartToSvg } from '../../../resources/js/press/charts/index.js';

const unescape = (s) => s.replace(/&amp;/g, '&').replace(/&#39;/g, "'").replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>');
const out = [];
for (const c of JSON.parse(process.argv[2])) {
    const svg = chartToSvg(c.chart, { w: c.w, h: c.h, theme: c.theme });
    const texts = [];
    const re = /<text x="([-\d.]+)" y="([-\d.]+)" font-size="([\d.]+)"([^>]*)>([^<]*)<\/text>/g;
    let m;
    while ((m = re.exec(svg))) {
        texts.push({ text: unescape(m[5]), x: +m[1], y: +m[2], size: +m[3] });
    }
    out.push(texts);
}
console.log(JSON.stringify(out));
