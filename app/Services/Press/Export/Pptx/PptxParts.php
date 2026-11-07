<?php

namespace App\Services\Press\Export\Pptx;

use App\Services\Press\Export\Support\Theme;

/** The fixed parts of a package: theme, slide master, layout, notes master, presentation properties. */
final class PptxParts
{
    private const FMT_SCHEME = '<a:fmtScheme name="Dot.Press"><a:fillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:fillStyleLst><a:lnStyleLst><a:ln w="6350" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:prstDash val="solid"/><a:miter lim="800000"/></a:ln><a:ln w="12700" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:prstDash val="solid"/><a:miter lim="800000"/></a:ln><a:ln w="19050" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:prstDash val="solid"/><a:miter lim="800000"/></a:ln></a:lnStyleLst><a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst><a:bgFillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:bgFillStyleLst></a:fmtScheme>';

    public static function theme(Theme $theme, string $name): string
    {
        $hex = fn (string $token) => strtoupper(substr((string) $theme->hex($token), 1));
        $c = fn (string $slot, string $token) => '<a:'.$slot.'><a:srgbClr val="'.$hex($token).'"/></a:'.$slot.'>';
        $scheme = '<a:clrScheme name="'.Ooxml::esc($name).'">'
            .$c('dk1', '@ink').$c('lt1', '@bg').$c('dk2', '@muted').$c('lt2', '@surface')
            .$c('accent1', '@accent').$c('accent2', '@accent2').$c('accent3', '@chart3').$c('accent4', '@chart4').$c('accent5', '@chart5').$c('accent6', '@chart6')
            .$c('hlink', '@accent').$c('folHlink', '@muted').'</a:clrScheme>';
        $fonts = '<a:fontScheme name="'.Ooxml::esc($name).'"><a:majorFont><a:latin typeface="'.Ooxml::esc($theme->heading).'"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont><a:minorFont><a:latin typeface="'.Ooxml::esc($theme->body).'"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont></a:fontScheme>';

        return Ooxml::head().'<a:theme xmlns:a="'.Ooxml::NS_A.'" name="'.Ooxml::esc($name).'"><a:themeElements>'.$scheme.$fonts.self::FMT_SCHEME.'</a:themeElements><a:objectDefaults/><a:extraClrSchemeLst/></a:theme>';
    }

    private static function ph(int $id, string $name, string $phAttrs, float $x, float $y, float $w, float $h, string $prompt, bool $layout): string
    {
        $xfrm = Ooxml::xfrm($x, $y, $w, $h);
        $body = '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:rPr lang="en-US"/><a:t>'.Ooxml::esc($prompt).'</a:t></a:r></a:p></p:txBody>';

        return '<p:sp><p:nvSpPr><p:cNvPr id="'.$id.'" name="'.Ooxml::esc($name).'"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph '.$phAttrs.'/></p:nvPr></p:nvSpPr><p:spPr>'.$xfrm.'</p:spPr>'.$body.'</p:sp>';
    }

    public static function master(float $W, float $H): string
    {
        $title = self::ph(2, 'Title Placeholder 1', 'type="title"', $W * 0.06, $H * 0.06, $W * 0.88, $H * 0.16, 'Click to edit the title', false);
        $body = self::ph(3, 'Text Placeholder 2', 'type="body" idx="1"', $W * 0.06, $H * 0.26, $W * 0.88, $H * 0.62, 'Click to edit the text', false);
        $lvl = fn (string $tag, int $sz, string $font, bool $bold) => '<a:'.$tag.' marL="0" algn="l" defTabSz="914400"><a:lnSpc><a:spcPct val="100000"/></a:lnSpc><a:spcBef><a:spcPts val="0"/></a:spcBef><a:buNone/><a:defRPr sz="'.$sz.'" b="'.($bold ? 1 : 0).'" kern="1200"><a:solidFill><a:schemeClr val="tx1"/></a:solidFill><a:latin typeface="'.$font.'"/><a:ea typeface="+mn-ea"/><a:cs typeface="+mn-cs"/></a:defRPr></a:'.$tag.'>';

        return Ooxml::head().'<p:sldMaster xmlns:a="'.Ooxml::NS_A.'" xmlns:r="'.Ooxml::NS_R.'" xmlns:p="'.Ooxml::NS_P.'"><p:cSld><p:bg><p:bgRef idx="1001"><a:schemeClr val="bg1"/></p:bgRef></p:bg><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'.$title.$body.'</p:spTree></p:cSld>'
            .'<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
            .'<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>'
            .'<p:txStyles><p:titleStyle>'.$lvl('lvl1pPr', 4400, '+mj-lt', true).'</p:titleStyle><p:bodyStyle>'.$lvl('lvl1pPr', 2400, '+mn-lt', false).'</p:bodyStyle><p:otherStyle>'.$lvl('lvl1pPr', 1800, '+mn-lt', false).'</p:otherStyle></p:txStyles></p:sldMaster>';
    }

    public static function layout(float $W, float $H): string
    {
        $title = self::ph(2, 'Title 1', 'type="title"', $W * 0.06, $H * 0.06, $W * 0.88, $H * 0.16, 'Click to edit the title', true);
        $body = self::ph(3, 'Content Placeholder 2', 'idx="1"', $W * 0.06, $H * 0.26, $W * 0.88, $H * 0.62, 'Click to edit the text', true);

        return Ooxml::head().'<p:sldLayout xmlns:a="'.Ooxml::NS_A.'" xmlns:r="'.Ooxml::NS_R.'" xmlns:p="'.Ooxml::NS_P.'" type="obj" preserve="1"><p:cSld name="Title and content"><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'.$title.$body.'</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>';
    }

    public static function notesMaster(): string
    {
        $img = '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Slide Image Placeholder 1"/><p:cNvSpPr><a:spLocks noGrp="1" noRot="1" noChangeAspect="1"/></p:cNvSpPr><p:nvPr><p:ph type="sldImg" idx="2"/></p:nvPr></p:nvSpPr><p:spPr><a:xfrm><a:off x="685800" y="1143000"/><a:ext cx="5486400" cy="3086100"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/><a:ln w="12700"><a:solidFill><a:prstClr val="black"/></a:solidFill></a:ln></p:spPr></p:sp>';
        $body = '<p:sp><p:nvSpPr><p:cNvPr id="3" name="Notes Placeholder 2"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="body" sz="quarter" idx="3"/></p:nvPr></p:nvSpPr><p:spPr><a:xfrm><a:off x="685800" y="4400550"/><a:ext cx="5486400" cy="3600450"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr><p:txBody><a:bodyPr vert="horz" lIns="91440" tIns="45720" rIns="91440" bIns="45720" rtlCol="0"/><a:lstStyle/><a:p><a:pPr lvl="0"/><a:r><a:rPr lang="en-US"/><a:t>Click to edit the notes</a:t></a:r></a:p></p:txBody></p:sp>';

        return Ooxml::head().'<p:notesMaster xmlns:a="'.Ooxml::NS_A.'" xmlns:r="'.Ooxml::NS_R.'" xmlns:p="'.Ooxml::NS_P.'"><p:cSld><p:bg><p:bgRef idx="1001"><a:schemeClr val="bg1"/></p:bgRef></p:bg><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'.$img.$body.'</p:spTree></p:cSld><p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/><p:notesStyle><a:lvl1pPr marL="0" algn="l" defTabSz="914400"><a:defRPr sz="1200" kern="1200"><a:solidFill><a:schemeClr val="tx1"/></a:solidFill><a:latin typeface="+mn-lt"/></a:defRPr></a:lvl1pPr></p:notesStyle></p:notesMaster>';
    }

    public static function notesSlide(string $text): string
    {
        $paras = '';
        foreach (preg_split('/\R/u', $text) ?: [''] as $line) {
            $paras .= '<a:p><a:r><a:rPr lang="en-US" dirty="0"/><a:t>'.Ooxml::esc($line).'</a:t></a:r></a:p>';
        }
        $img = '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Slide Image Placeholder 1"/><p:cNvSpPr><a:spLocks noGrp="1" noRot="1" noChangeAspect="1"/></p:cNvSpPr><p:nvPr><p:ph type="sldImg"/></p:nvPr></p:nvSpPr><p:spPr/></p:sp>';
        $body = '<p:sp><p:nvSpPr><p:cNvPr id="3" name="Notes Placeholder 2"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="body" idx="1"/></p:nvPr></p:nvSpPr><p:spPr/><p:txBody><a:bodyPr/><a:lstStyle/>'.$paras.'</p:txBody></p:sp>';

        return Ooxml::head().'<p:notes xmlns:a="'.Ooxml::NS_A.'" xmlns:r="'.Ooxml::NS_R.'" xmlns:p="'.Ooxml::NS_P.'"><p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'.$img.$body.'</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:notes>';
    }

    public static function presProps(): string
    {
        return Ooxml::head().'<p:presentationPr xmlns:a="'.Ooxml::NS_A.'" xmlns:r="'.Ooxml::NS_R.'" xmlns:p="'.Ooxml::NS_P.'"/>';
    }

    public static function viewProps(): string
    {
        return Ooxml::head().'<p:viewPr xmlns:a="'.Ooxml::NS_A.'" xmlns:r="'.Ooxml::NS_R.'" xmlns:p="'.Ooxml::NS_P.'"><p:normalViewPr><p:restoredLeft sz="15620"/><p:restoredTop sz="94660"/></p:normalViewPr><p:slideViewPr><p:cSldViewPr><p:cViewPr varScale="1"><p:scale><a:sx n="100" d="100"/><a:sy n="100" d="100"/></p:scale><p:origin x="0" y="0"/></p:cViewPr><p:guideLst/></p:cSldViewPr></p:slideViewPr><p:notesTextViewPr><p:cViewPr><p:scale><a:sx n="1" d="1"/><a:sy n="1" d="1"/></p:scale><p:origin x="0" y="0"/></p:cViewPr></p:notesTextViewPr><p:gridSpacing cx="76200" cy="76200"/></p:viewPr>';
    }

    public static function tableStyles(): string
    {
        return Ooxml::head().'<a:tblStyleLst xmlns:a="'.Ooxml::NS_A.'" def="{5C22544A-7EE6-4342-B048-85BDC9FD1C3A}"/>';
    }

    public static function core(string $title): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        return Ooxml::head().'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>'.Ooxml::esc($title).'</dc:title><dc:creator>Dot.Press</dc:creator><cp:lastModifiedBy>Dot.Press</cp:lastModifiedBy><cp:revision>1</cp:revision><dcterms:created xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:modified></cp:coreProperties>';
    }

    public static function app(int $slides): string
    {
        return Ooxml::head().'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><TotalTime>0</TotalTime><Application>Dot.Press</Application><PresentationFormat>Custom</PresentationFormat><Slides>'.$slides.'</Slides><Notes>0</Notes><HiddenSlides>0</HiddenSlides></Properties>';
    }
}
