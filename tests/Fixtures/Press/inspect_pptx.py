"""Prints a JSON description of a .pptx as python-pptx reads it. Used by the export tests (skipped when python-pptx is absent)."""
import json, sys
from pptx import Presentation
from pptx.util import Emu

p = Presentation(sys.argv[1])
out = {'slide_width': p.slide_width, 'slide_height': p.slide_height, 'slides': []}
for s in p.slides:
    slide = {'layout': s.slide_layout.name, 'hidden': s._element.get('show') == '0', 'notes': s.notes_slide.notes_text_frame.text if s.has_notes_slide else None, 'shapes': []}
    for sh in s.shapes:
        info = {'id': sh.shape_id, 'name': sh.name, 'type': str(sh.shape_type), 'left': sh.left, 'top': sh.top, 'width': sh.width, 'height': sh.height, 'rotation': getattr(sh, 'rotation', 0), 'text': None}
        if sh.has_text_frame:
            info['text'] = sh.text_frame.text
            info['runs'] = [{'text': r.text, 'bold': r.font.bold, 'size': r.font.size.pt if r.font.size else None} for para in sh.text_frame.paragraphs for r in para.runs]
        if getattr(sh, 'has_chart', False) and sh.has_chart:
            ch = sh.chart
            info['chart'] = {'type': str(ch.chart_type), 'series': [{'name': se.name, 'values': list(se.values)} for pl in ch.plots for se in pl.series], 'categories': [str(c) for c in ch.plots[0].categories] if len(ch.plots) else []}
        if getattr(sh, 'has_table', False) and sh.has_table:
            t = sh.table
            info['table'] = {'rows': len(t.rows), 'cols': len(t.columns), 'cells': [[t.cell(r, c).text for c in range(len(t.columns))] for r in range(len(t.rows))]}
        slide['shapes'].append(info)
    out['slides'].append(slide)
print(json.dumps(out))
