"""
Generates the PPTX fixtures used by the import tests. They are made by python-pptx, a writer that is independent of the
Dot.Press exporter, so the importer is tested against a file it did not produce.

    python3 -m venv /tmp/press-venv && /tmp/press-venv/bin/pip install python-pptx pillow
    /tmp/press-venv/bin/python tests/Fixtures/Press/make_fixtures.py

The generated files are committed; this script only documents how they were made.
"""
import io, os, copy
from pptx import Presentation
from pptx.util import Emu, Pt, Inches
from pptx.dml.color import RGBColor
from pptx.enum.shapes import MSO_SHAPE, MSO_CONNECTOR
from pptx.enum.text import PP_ALIGN, MSO_ANCHOR
from pptx.chart.data import CategoryChartData, XyChartData
from pptx.enum.chart import XL_CHART_TYPE, XL_LEGEND_POSITION
from pptx.enum.dml import MSO_THEME_COLOR
from PIL import Image, ImageDraw
from lxml import etree

HERE = os.path.dirname(os.path.abspath(__file__))
NS = {'p': 'http://schemas.openxmlformats.org/presentationml/2006/main', 'a': 'http://schemas.openxmlformats.org/drawingml/2006/main'}


def png(color, size=(400, 300)):
    im = Image.new('RGB', size, color)
    d = ImageDraw.Draw(im)
    d.ellipse((50, 50, size[0] - 50, size[1] - 50), fill=(255, 255, 255))
    b = io.BytesIO()
    im.save(b, 'PNG')
    b.seek(0)
    return b


def add_transition(slide, xml):
    sld = slide._element
    el = etree.fromstring(xml)
    # the transition goes after clrMapOvr and before timing
    ovr = sld.find('p:clrMapOvr', NS)
    if ovr is not None:
        ovr.addnext(el)
    else:
        sld.append(el)


prs = Presentation()  # 4:3 default template: placeholders inherit from layout and master
prs.slide_width = Emu(12192000)
prs.slide_height = Emu(6858000)

# 1. title slide through the layout placeholders
s = prs.slides.add_slide(prs.slide_layouts[0])
s.shapes.title.text = 'Placeholder inheritance'
s.placeholders[1].text = 'Position, size and font come from the layout'
s.notes_slide.notes_text_frame.text = 'Speaker note for slide one'
add_transition(s, '<p:transition xmlns:p="%s" spd="med"><p:fade/></p:transition>' % NS['p'])

# 2. title and content: bullets at two levels, bold and coloured runs, a hyperlink
s = prs.slides.add_slide(prs.slide_layouts[1])
s.shapes.title.text = 'Bullets'
body = s.placeholders[1].text_frame
body.text = 'First point'
p = body.add_paragraph()
r = p.add_run()
r.text = 'Second point with '
r2 = p.add_run()
r2.text = 'bold'
r2.font.bold = True
r3 = p.add_run()
r3.text = ' and red'
r3.font.color.rgb = RGBColor(0xC0, 0x00, 0x00)
p = body.add_paragraph()
p.text = 'A nested point'
p.level = 1
p = body.add_paragraph()
r = p.add_run()
r.text = 'Read more'
r.hyperlink.address = 'https://example.com/more'
add_transition(s, '<p:transition xmlns:p="%s" spd="slow"><p:push dir="u"/></p:transition>' % NS['p'])

# 3. shapes: filled with theme colours, a rotated rectangle, an arrow connector, a group, a picture with crop
s = prs.slides.add_slide(prs.slide_layouts[6])
tb = s.shapes.add_textbox(Inches(0.5), Inches(0.4), Inches(6), Inches(1))
tb.text_frame.text = 'A plain text box'
tb.text_frame.paragraphs[0].runs[0].font.size = Pt(28)
tb.text_frame.paragraphs[0].runs[0].font.name = 'Inter'
box = s.shapes.add_shape(MSO_SHAPE.RECTANGLE, Inches(1), Inches(2), Inches(2), Inches(1))
box.fill.solid()
box.fill.fore_color.theme_color = MSO_THEME_COLOR.ACCENT_2
box.rotation = 15
box.text_frame.text = 'Rotated'
oval = s.shapes.add_shape(MSO_SHAPE.OVAL, Inches(4), Inches(2), Inches(1.5), Inches(1.5))
oval.fill.solid()
oval.fill.fore_color.rgb = RGBColor(0x22, 0x88, 0x44)
oval.line.color.rgb = RGBColor(0, 0, 0)
oval.line.width = Pt(3)
con = s.shapes.add_connector(MSO_CONNECTOR.STRAIGHT, Inches(1), Inches(5), Inches(5), Inches(4))
con.line.color.rgb = RGBColor(0x11, 0x11, 0x99)
con.line.width = Pt(2.5)
ln = con.line._get_or_add_ln()
tail = etree.SubElement(ln, '{%s}tailEnd' % NS['a'])
tail.set('type', 'triangle')
grp = s.shapes.add_group_shape()
g1 = grp.shapes.add_shape(MSO_SHAPE.RECTANGLE, Inches(7), Inches(1), Inches(1), Inches(1))
g2 = grp.shapes.add_shape(MSO_SHAPE.RECTANGLE, Inches(8.5), Inches(2), Inches(1), Inches(1))
oval.click_action.target_slide = prs.slides[0]
pic = s.shapes.add_picture(png((200, 80, 80)), Inches(6.5), Inches(3.5), Inches(3), Inches(2.25))
pic.crop_left = 0.1
pic.crop_right = 0.2
pic.crop_top = 0.05
pic.crop_bottom = 0.15

# 4. table with a merged cell, and charts
s = prs.slides.add_slide(prs.slide_layouts[5])
s.shapes.title.text = 'Data'
tbl = s.shapes.add_table(3, 3, Inches(0.5), Inches(1.6), Inches(5), Inches(2)).table
for r in range(3):
    for c in range(3):
        tbl.cell(r, c).text = 'R%dC%d' % (r, c)
tbl.cell(1, 0).merge(tbl.cell(2, 0))
tbl.cell(0, 1).text = 'Bold head'
tbl.cell(0, 1).text_frame.paragraphs[0].runs[0].font.bold = True
cd = CategoryChartData()
cd.categories = ['Q1', 'Q2', 'Q3']
cd.add_series('Revenue', (10, 20, 15))
cd.add_series('Cost', (5, 8, 9))
gf = s.shapes.add_chart(XL_CHART_TYPE.COLUMN_CLUSTERED, Inches(6), Inches(1.6), Inches(3.5), Inches(2.5), cd)
gf.chart.has_legend = True
gf.chart.legend.position = XL_LEGEND_POSITION.BOTTOM
gf.chart.has_title = True
gf.chart.chart_title.text_frame.text = 'Revenue vs cost'
cd2 = CategoryChartData()
cd2.categories = ['A', 'B', 'C']
cd2.add_series('Share', (50, 30, 20))
s.shapes.add_chart(XL_CHART_TYPE.PIE, Inches(6), Inches(4.2), Inches(3.5), Inches(2.5), cd2)
cd3 = XyChartData()
sr = cd3.add_series('Points')
for x, y in [(1, 2), (2, 4), (3, 3)]:
    sr.add_data_point(x, y)
s.shapes.add_chart(XL_CHART_TYPE.XY_SCATTER, Inches(0.5), Inches(4.2), Inches(5), Inches(2.5), cd3)

# 5. hidden slide
s = prs.slides.add_slide(prs.slide_layouts[6])
s.shapes.add_textbox(Inches(1), Inches(1), Inches(4), Inches(1)).text_frame.text = 'Hidden slide'
s._element.set('show', '0')

prs.save(os.path.join(HERE, 'python_pptx_basic.pptx'))
print('wrote python_pptx_basic.pptx')


# ---------------------------------------------------------------------------------------------------------------
# A second file: features the document model cannot hold, injected as raw XML so the import report can be tested.
# ---------------------------------------------------------------------------------------------------------------
prs = Presentation()
prs.slide_width = Emu(12192000)
prs.slide_height = Emu(6858000)
s = prs.slides.add_slide(prs.slide_layouts[6])
box = s.shapes.add_shape(MSO_SHAPE.PENTAGON if hasattr(MSO_SHAPE, 'PENTAGON') else MSO_SHAPE.REGULAR_PENTAGON, Inches(1), Inches(1), Inches(2), Inches(1))
tb = s.shapes.add_textbox(Inches(4), Inches(1), Inches(4), Inches(1))
tb.text_frame.text = 'WordArt text'
# a shape with an unsupported preset, 3D formatting and WordArt warp
star = s.shapes.add_shape(MSO_SHAPE.LIGHTNING_BOLT, Inches(1), Inches(3), Inches(2), Inches(2))
sppr = star._element.spPr
sp3d = etree.SubElement(sppr, '{%s}sp3d' % NS['a'])
sp3d.set('extrusionH', '76200')
etree.SubElement(sp3d, '{%s}bevelT' % NS['a'])
bodyPr = tb._element.txBody.find('a:bodyPr', NS)
warp = etree.SubElement(bodyPr, '{%s}prstTxWarp' % NS['a'])
warp.set('prst', 'textWave1')
# a SmartArt frame, an OLE frame, an equation paragraph, an animation tree and a comment relationship
tree = s.shapes._spTree
gf_dgm = etree.fromstring("""<p:graphicFrame xmlns:p="%(p)s" xmlns:a="%(a)s"><p:nvGraphicFramePr><p:cNvPr id="50" name="SmartArt 1"/><p:cNvGraphicFramePr/><p:nvPr/></p:nvGraphicFramePr><p:xfrm><a:off x="100000" y="100000"/><a:ext cx="2000000" cy="1000000"/></p:xfrm><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/diagram"/></a:graphic></p:graphicFrame>""" % NS)
gf_ole = etree.fromstring("""<p:graphicFrame xmlns:p="%(p)s" xmlns:a="%(a)s"><p:nvGraphicFramePr><p:cNvPr id="51" name="Worksheet 1"/><p:cNvGraphicFramePr/><p:nvPr/></p:nvGraphicFramePr><p:xfrm><a:off x="100000" y="100000"/><a:ext cx="2000000" cy="1000000"/></p:xfrm><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/presentationml/2006/ole"/></a:graphic></p:graphicFrame>""" % NS)
tree.append(gf_dgm)
tree.append(gf_ole)
eq = tb._element.txBody
p2 = etree.fromstring("""<a:p xmlns:a="%(a)s" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math"><mc:AlternateContent><mc:Choice Requires="a14"><a14:m xmlns:a14="http://schemas.microsoft.com/office/drawing/2010/main"><m:oMathPara><m:oMath><m:r><m:t>x=1</m:t></m:r></m:oMath></m:oMathPara></a14:m></mc:Choice><mc:Fallback><a:r><a:t>x=1</a:t></a:r></mc:Fallback></mc:AlternateContent></a:p>""" % NS)
eq.append(p2)
timing = etree.fromstring("""<p:timing xmlns:p="%(p)s"><p:tnLst><p:par><p:cTn id="1" dur="indefinite" restart="never" nodeType="tmRoot"/></p:par></p:tnLst></p:timing>""" % NS)
s._element.append(timing)
# a slide with a transition the model does not have
s2 = prs.slides.add_slide(prs.slide_layouts[6])
add_transition(s2, '<p:transition xmlns:p="%s" spd="med"><p:checker dir="horz"/></p:transition>' % NS['p'])
prs.save(os.path.join(HERE, 'python_pptx_unsupported.pptx'))
print('wrote python_pptx_unsupported.pptx')

# ---------------------------------------------------------------------------------------------------------------
# A third file: a freeform (custom geometry) shape with text, a red triangle outline with a blue stroke.
# ---------------------------------------------------------------------------------------------------------------
prs = Presentation()
prs.slide_width = Emu(12192000)
prs.slide_height = Emu(6858000)
s = prs.slides.add_slide(prs.slide_layouts[6])
fb = s.shapes.build_freeform(0, 0, scale=1.0)
fb.add_line_segments([(Inches(2), 0), (Inches(1), Inches(1.5))], close=True)
tri = fb.convert_to_shape(Inches(1), Inches(1))
tri.fill.solid()
tri.fill.fore_color.rgb = RGBColor(0xFF, 0x00, 0x00)
tri.line.color.rgb = RGBColor(0x00, 0x00, 0xFF)
tri.line.width = Pt(4)
tri.text_frame.text = 'Inside the freeform'
prs.save(os.path.join(HERE, 'python_pptx_freeform.pptx'))
print('wrote python_pptx_freeform.pptx')
