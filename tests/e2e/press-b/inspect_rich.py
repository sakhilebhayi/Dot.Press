"""Describe a .pptx in more detail than the unit test helper: run formatting, hyperlinks, bullets, charts, table spans, pictures, notes, hidden."""
import json, sys
from pptx import Presentation
from pptx.util import Emu
from pptx.enum.shapes import MSO_SHAPE_TYPE

p = Presentation(sys.argv[1])
out = {'w': p.slide_width, 'h': p.slide_height, 'slides': []}
A = '{http://schemas.openxmlformats.org/drawingml/2006/main}'
for s in p.slides:
    sl = {'hidden': s._element.get('show') == '0', 'notes': s.notes_slide.notes_text_frame.text if s.has_notes_slide else None, 'transition': None, 'shapes': []}
    tr = next(s._element.iter('{http://schemas.openxmlformats.org/presentationml/2006/main}transition'), None)
    if tr is not None:
        sl['transition'] = [c.tag.split('}')[1] for c in tr]
    for sh in s.shapes:
        d = {'name': sh.name, 'kind': str(sh.shape_type).split(' ')[0], 'x': sh.left, 'y': sh.top, 'w': sh.width, 'h': sh.height}
        if sh.has_text_frame:
            runs = []
            for para in sh.text_frame.paragraphs:
                ppr = para._p.find(A + 'pPr')
                bullet = None
                if ppr is not None:
                    if ppr.find(A + 'buChar') is not None: bullet = 'bullet'
                    if ppr.find(A + 'buAutoNum') is not None: bullet = 'number'
                for r in para.runs:
                    rp = r._r.find(A + 'rPr')
                    link = r.hyperlink.address if r.hyperlink and r.hyperlink.address else None
                    color = None
                    if r.font.color is not None and r.font.color.type is not None:
                        try: color = str(r.font.color.rgb)
                        except Exception: color = 'theme'
                    runs.append({'t': r.text, 'b': r.font.bold, 'i': r.font.italic, 'u': r.font.underline, 'sz': r.font.size.pt if r.font.size else None, 'color': color, 'link': link, 'strike': (rp is not None and rp.get('strike') not in (None, 'noStrike')), 'bullet': bullet})
            d['runs'] = runs
        if sh.shape_type == MSO_SHAPE_TYPE.PICTURE:
            d['image'] = {'ct': sh.image.content_type, 'px': list(sh.image.size)}
        if getattr(sh, 'has_chart', False) and sh.has_chart:
            ch = sh.chart
            d['chart'] = {'type': str(ch.chart_type).split(' ')[0], 'series': [{'name': se.name, 'values': list(se.values)} for pl in ch.plots for se in pl.series], 'cats': [str(c) for c in ch.plots[0].categories] if len(ch.plots) else []}
        if getattr(sh, 'has_table', False) and sh.has_table:
            t = sh.table
            cells = []
            spans = []
            for r in range(len(t.rows)):
                row = []
                for c in range(len(t.columns)):
                    cell = t.cell(r, c)
                    row.append(cell.text)
                    if cell.is_merge_origin: spans.append([r, c, cell.span_height, cell.span_width])
                cells.append(row)
            d['table'] = {'cells': cells, 'spans': spans}
        sl['shapes'].append(d)
    out['slides'].append(sl)
print(json.dumps(out))
