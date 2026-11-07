"""Prints a JSON description of a PDF as pypdf reads it: page count, page sizes in points, extracted text, font names."""
import json, sys
from pypdf import PdfReader

r = PdfReader(sys.argv[1])
pages = []
fonts = set()
for pg in r.pages:
    box = pg.mediabox
    pages.append({'w': float(box.width), 'h': float(box.height), 'text': pg.extract_text()})
    res = pg.get('/Resources')
    if res and '/Font' in res:
        for k, v in res['/Font'].items():
            fonts.add(str(v.get_object().get('/BaseFont')))
print(json.dumps({'pages': pages, 'fonts': sorted(fonts), 'size': len(open(sys.argv[1], 'rb').read())}))
