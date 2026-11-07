"""Independent PPTX decks written by python-pptx (not by Dot.Press) for the import checks.
usage: make_pptx.py outdir   -> corporate.pptx (16:9, many kinds of content) and fourthree.pptx (4:3)"""
import io, os, sys
from pptx import Presentation
from pptx.util import Emu, Pt, Inches
from pptx.dml.color import RGBColor
from pptx.enum.shapes import MSO_SHAPE
from pptx.enum.text import PP_ALIGN
from pptx.chart.data import CategoryChartData
from pptx.enum.chart import XL_CHART_TYPE
from PIL import Image, ImageDraw

out = sys.argv[1]
os.makedirs(out, exist_ok=True)

def png(color, size=(600, 400)):
    im = Image.new('RGB', size, color); d = ImageDraw.Draw(im); d.rectangle((40, 40, size[0] - 40, size[1] - 40), outline=(255, 255, 255), width=8)
    b = io.BytesIO(); im.save(b, 'PNG'); b.seek(0); return b

prs = Presentation()
prs.slide_width = Emu(12192000); prs.slide_height = Emu(6858000)
s = prs.slides.add_slide(prs.slide_layouts[0])
s.shapes.title.text = 'Corporate review IMPORT-TITLE'
s.placeholders[1].text = 'Prepared by python-pptx'
s.notes_slide.notes_text_frame.text = 'IMPORT-NOTE-1 welcome'

s = prs.slides.add_slide(prs.slide_layouts[1])
s.shapes.title.text = 'Key messages'
tf = s.placeholders[1].text_frame
tf.text = 'Revenue grew'
p = tf.paragraphs[0]; r = p.add_run(); r.text = ' by twelve percent'; r.font.bold = True; r.font.color.rgb = RGBColor(0xC0, 0x39, 0x2B)
p2 = tf.add_paragraph(); p2.text = 'Costs fell'; p2.level = 1
p3 = tf.add_paragraph(); r = p3.add_run(); r.text = 'See the report'; r.hyperlink.address = 'https://example.com/report'
s.notes_slide.notes_text_frame.text = 'IMPORT-NOTE-2 bullets'

s = prs.slides.add_slide(prs.slide_layouts[5])
s.shapes.title.text = 'Regional table'
rows = [['Region', 'Q1', 'Q2'], ['North', '10', '12'], ['South', '8', '9']]
tbl = s.shapes.add_table(3, 3, Inches(1), Inches(1.8), Inches(8), Inches(2)).table
for i, row in enumerate(rows):
    for j, v in enumerate(row): tbl.cell(i, j).text = v

s = prs.slides.add_slide(prs.slide_layouts[5])
s.shapes.title.text = 'Native charts'
cd = CategoryChartData(); cd.categories = ['Jan', 'Feb', 'Mar']; cd.add_series('Sales', (5, 7, 6)); cd.add_series('Cost', (3, 4, 4))
s.shapes.add_chart(XL_CHART_TYPE.COLUMN_CLUSTERED, Inches(0.5), Inches(1.6), Inches(5), Inches(4), cd)
cd2 = CategoryChartData(); cd2.categories = ['A', 'B', 'C']; cd2.add_series('Share', (50, 30, 20))
s.shapes.add_chart(XL_CHART_TYPE.PIE, Inches(6), Inches(1.6), Inches(3.5), Inches(4), cd2)

s = prs.slides.add_slide(prs.slide_layouts[6])
s.shapes.add_picture(png((200, 60, 60)), Inches(0.5), Inches(0.5), Inches(4), Inches(2.667))
box = s.shapes.add_shape(MSO_SHAPE.ROUNDED_RECTANGLE, Inches(5), Inches(0.5), Inches(3), Inches(1.5))
box.fill.solid(); box.fill.fore_color.rgb = RGBColor(0x1D, 0x4E, 0xD8); box.text_frame.text = 'Rounded box'
box.rotation = 15
oval = s.shapes.add_shape(MSO_SHAPE.OVAL, Inches(5), Inches(3), Inches(2), Inches(2))
oval.fill.solid(); oval.fill.fore_color.rgb = RGBColor(0x15, 0x80, 0x3D)
g = s.shapes.add_group_shape()
g.shapes.add_shape(MSO_SHAPE.RECTANGLE, Inches(1), Inches(4), Inches(1), Inches(1))
g.shapes.add_shape(MSO_SHAPE.RECTANGLE, Inches(2.2), Inches(4), Inches(1), Inches(1))
tb = s.shapes.add_textbox(Inches(0.5), Inches(6), Inches(8), Inches(0.8)); tb.text_frame.text = 'A text box with Segoe UI'
tb.text_frame.paragraphs[0].runs[0].font.name = 'Segoe UI'; tb.text_frame.paragraphs[0].runs[0].font.size = Pt(24)
s.notes_slide.notes_text_frame.text = 'IMPORT-NOTE-5 shapes'

s = prs.slides.add_slide(prs.slide_layouts[5]); s.shapes.title.text = 'Hidden backup IMPORT-HIDDEN'
s._element.set('show', '0')
prs.save(os.path.join(out, 'corporate.pptx'))

p43 = Presentation()  # default python-pptx template is 4:3 (10in x 7.5in)
s = p43.slides.add_slide(p43.slide_layouts[0]); s.shapes.title.text = 'Four by three'; s.placeholders[1].text = 'Old format deck'
p43.save(os.path.join(out, 'fourthree.pptx'))
print('ok')
