"""Render every page of a PDF to PNG (pymupdf) so it can be looked at. usage: render_pdf.py in.pdf outdir [dpi]"""
import sys, os, json
import pymupdf
doc = pymupdf.open(sys.argv[1])
out = sys.argv[2]
os.makedirs(out, exist_ok=True)
dpi = int(sys.argv[3]) if len(sys.argv) > 3 else 60
res = []
for i, page in enumerate(doc):
    pix = page.get_pixmap(dpi=dpi)
    f = os.path.join(out, f'page-{i + 1:02d}.png')
    pix.save(f)
    res.append({'page': i + 1, 'w': page.rect.width, 'h': page.rect.height, 'png': f, 'text_len': len(page.get_text()), 'images': len(page.get_images()), 'fonts': [f[3] for f in page.get_fonts()]})
print(json.dumps(res))
