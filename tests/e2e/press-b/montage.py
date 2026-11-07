"""Tile PNG pages into one contact sheet so a whole export can be looked at in one image. usage: montage.py out.png cols in1.png in2.png ..."""
import sys
from PIL import Image
out, cols, files = sys.argv[1], int(sys.argv[2]), sys.argv[3:]
ims = [Image.open(f).convert('RGB') for f in files]
w, h = max(i.width for i in ims), max(i.height for i in ims)
rows = (len(ims) + cols - 1) // cols
sheet = Image.new('RGB', (cols * (w + 8) + 8, rows * (h + 8) + 8), (120, 120, 120))
for n, im in enumerate(ims):
    sheet.paste(im, (8 + (n % cols) * (w + 8), 8 + (n // cols) * (h + 8)))
sheet.save(out)
