<?php

namespace App\Services\Press\Export\Pptx;

use App\Services\Press\Export\AssetResolver;
use App\Services\Press\Export\Support\ChartData;
use App\Services\Press\Export\Support\Els;
use App\Services\Press\Export\Support\IconLibrary;
use App\Services\Press\Export\Support\ImageComposer;
use App\Services\Press\Export\Support\Placeholder;
use App\Services\Press\Export\Support\Raster;
use App\Services\Press\Export\Support\ShapeGeometry;
use App\Services\Press\Export\Support\SvgPath;
use App\Services\Press\Export\Support\TableGrid;
use App\Services\Press\Export\Support\TextDoc;
use App\Services\Press\Export\Support\TextStyle;
use App\Services\Press\Export\Support\Theme;
use RuntimeException;

/**
 * Writes a presentation document as an editable .pptx (OOXML) with ZipArchive: real slide master, layout, theme,
 * notes, sections, transitions, text runs, preset shapes, pictures, tables and native charts with embedded workbooks.
 */
final class PptxExporter
{
    private const T = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

    /** @var list<array{slide: int, element: ?string, level: string, note: string}> what could not be carried over exactly */
    public array $notes = [];

    private Package $pkg;

    private Theme $theme;

    private PptxText $text;

    private float $W = 1920;

    private float $H = 1080;

    /** @var array<string, string> sha1 => media path */
    private array $media = [];

    private int $chartCount = 0;

    private int $mediaCount = 0;

    /** @var array<string, int> slide id => 1 based slide number in the file */
    private array $slideNo = [];

    /** @var array{n: int, part: string, nextId: int, title: bool, body: bool} */
    private array $cur = ['n' => 0, 'part' => '', 'nextId' => 2, 'title' => false, 'body' => false];

    private IconLibrary $icons;

    public function __construct(private AssetResolver $assets, ?IconLibrary $icons = null)
    {
        $this->icons = $icons ?? new IconLibrary;
    }

    /**
     * @param  array{deck: array<string, mixed>, slides: list<array<string, mixed>>}  $doc
     * @param  array{include_hidden?: bool}  $options
     */
    public function render(array $doc, string $path, array $options = []): void
    {
        $deck = (array) ($doc['deck'] ?? []);
        $this->theme = new Theme(is_array($deck['theme'] ?? null) ? $deck['theme'] : null);
        $this->W = Els::num($deck['w'] ?? 1920, 1920, 100, 5000);
        $this->H = Els::num($deck['h'] ?? 1080, 1080, 100, 5000);
        $slides = [];
        foreach ((array) ($doc['slides'] ?? []) as $s) {
            if (is_array($s) && (! ($options['include_hidden'] ?? true) ? empty($s['hidden']) : true)) {
                $slides[] = $s;
            }
        }
        if ($slides === []) {
            throw new RuntimeException('There are no slides to export.');
        }
        $this->pkg = new Package;
        $this->notes = [];
        $this->media = [];
        $this->chartCount = 0;
        $this->mediaCount = 0;
        $this->slideNo = [];
        foreach ($slides as $i => $s) {
            $this->slideNo[Els::str($s['id'] ?? $i)] = $i + 1;
        }
        $pkg = $this->pkg;
        $name = Els::str($deck['title'] ?? null, 'Presentation');

        $ct = 'application/vnd.openxmlformats-officedocument.';
        $pkg->defaultType('png', 'image/png');
        $pkg->defaultType('jpeg', 'image/jpeg');
        $pkg->defaultType('jpg', 'image/jpeg');
        $pkg->defaultType('gif', 'image/gif');
        $pkg->defaultType('svg', 'image/svg+xml');
        $pkg->defaultType('xlsx', $ct.'spreadsheetml.sheet');

        $pkg->add('docProps/core.xml', PptxParts::core($name), 'application/vnd.openxmlformats-package.core-properties+xml');
        $pkg->add('docProps/app.xml', PptxParts::app(count($slides)), $ct.'extended-properties+xml');
        $pkg->rel('', self::T.'officeDocument', 'ppt/presentation.xml');
        $pkg->rel('', 'http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties', 'docProps/core.xml');
        $pkg->rel('', self::T.'extended-properties', 'docProps/app.xml');

        $pkg->add('ppt/theme/theme1.xml', PptxParts::theme($this->theme, $this->theme->name), $ct.'theme+xml');
        $pkg->add('ppt/theme/theme2.xml', PptxParts::theme($this->theme, $this->theme->name), $ct.'theme+xml');
        $pkg->add('ppt/slideMasters/slideMaster1.xml', PptxParts::master($this->emuW(), $this->emuH()), $ct.'presentationml.slideMaster+xml');
        $pkg->rel('ppt/slideMasters/slideMaster1.xml', self::T.'slideLayout', '../slideLayouts/slideLayout1.xml');
        $pkg->rel('ppt/slideMasters/slideMaster1.xml', self::T.'theme', '../theme/theme1.xml');
        $pkg->add('ppt/slideLayouts/slideLayout1.xml', PptxParts::layout($this->emuW(), $this->emuH()), $ct.'presentationml.slideLayout+xml');
        $pkg->rel('ppt/slideLayouts/slideLayout1.xml', self::T.'slideMaster', '../slideMasters/slideMaster1.xml');
        $pkg->add('ppt/notesMasters/notesMaster1.xml', PptxParts::notesMaster(), $ct.'presentationml.notesMaster+xml');
        $pkg->rel('ppt/notesMasters/notesMaster1.xml', self::T.'theme', '../theme/theme2.xml');
        $pkg->add('ppt/presProps.xml', PptxParts::presProps(), $ct.'presentationml.presProps+xml');
        $pkg->add('ppt/viewProps.xml', PptxParts::viewProps(), $ct.'presentationml.viewProps+xml');
        $pkg->add('ppt/tableStyles.xml', PptxParts::tableStyles(), $ct.'presentationml.tableStyles+xml');

        $presRels = [];
        $presRels['master'] = $pkg->rel('ppt/presentation.xml', self::T.'slideMaster', 'slideMasters/slideMaster1.xml');
        $presRels['notesMaster'] = $pkg->rel('ppt/presentation.xml', self::T.'notesMaster', 'notesMasters/notesMaster1.xml');
        $pkg->rel('ppt/presentation.xml', self::T.'presProps', 'presProps.xml');
        $pkg->rel('ppt/presentation.xml', self::T.'viewProps', 'viewProps.xml');
        $pkg->rel('ppt/presentation.xml', self::T.'theme', 'theme/theme1.xml');
        $pkg->rel('ppt/presentation.xml', self::T.'tableStyles', 'tableStyles.xml');

        $sldIds = '';
        $sectionRows = [];
        foreach ($slides as $i => $slide) {
            $n = $i + 1;
            $this->slide($slide, $n);
            $rid = $pkg->rel('ppt/presentation.xml', self::T.'slide', 'slides/slide'.$n.'.xml');
            $sldIds .= '<p:sldId id="'.(256 + $i).'" r:id="'.$rid.'"/>';
            $sectionRows[] = [256 + $i, $slide['section_id'] ?? null];
        }

        $pres = Ooxml::head().'<p:presentation xmlns:a="'.Ooxml::NS_A.'" xmlns:r="'.Ooxml::NS_R.'" xmlns:p="'.Ooxml::NS_P.'" saveSubsetFonts="1">'
            .'<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="'.$presRels['master'].'"/></p:sldMasterIdLst>'
            .'<p:notesMasterIdLst><p:notesMasterId r:id="'.$presRels['notesMaster'].'"/></p:notesMasterIdLst>'
            .'<p:sldIdLst>'.$sldIds.'</p:sldIdLst>'
            .'<p:sldSz cx="'.min(Ooxml::MAX_EMU, Ooxml::emu($this->W)).'" cy="'.min(Ooxml::MAX_EMU, Ooxml::emu($this->H)).'"/><p:notesSz cx="6858000" cy="9144000"/>'
            .'<p:defaultTextStyle><a:defPPr><a:defRPr lang="en-US"/></a:defPPr><a:lvl1pPr marL="0" algn="l" defTabSz="914400"><a:defRPr sz="1800" kern="1200"><a:solidFill><a:schemeClr val="tx1"/></a:solidFill><a:latin typeface="+mn-lt"/><a:ea typeface="+mn-ea"/><a:cs typeface="+mn-cs"/></a:defRPr></a:lvl1pPr></p:defaultTextStyle>'
            .$this->sectionsXml((array) ($deck['sections'] ?? []), $sectionRows).'</p:presentation>';
        $pkg->add('ppt/presentation.xml', $pres, $ct.'presentationml.presentation.main+xml');

        $tmp = $path.'.part';
        @unlink($tmp);
        $pkg->save($tmp);
        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot store the PPTX file.');
        }
    }

    private function emuW(): float
    {
        return $this->W;
    }

    private function emuH(): float
    {
        return $this->H;
    }

    private function note(?string $el, string $level, string $note): void
    {
        $this->notes[] = ['slide' => $this->cur['n'], 'element' => $el, 'level' => $level, 'note' => $note];
    }

    /** @param array<int, array{0: int, 1: ?string}> $rows */
    private function sectionsXml(array $sections, array $rows): string
    {
        $sections = array_values(array_filter($sections, fn ($s) => is_array($s) && isset($s['id'])));
        if ($sections === []) {
            return '';
        }
        $byId = [];
        foreach ($sections as $s) {
            $byId[Els::str($s['id'])] = Els::str($s['title'] ?? null, 'Section');
        }
        // Slides without a section follow the previous one; slides before the first section go to a default one.
        $groups = [];
        $currentKey = null;
        foreach ($rows as [$sldId, $sid]) {
            $sid = $sid !== null && isset($byId[$sid]) ? $sid : $currentKey;
            if ($sid === null) {
                $sid = '__default';
                $byId[$sid] = 'Default Section';
            }
            $currentKey = $sid;
            $groups[] = [$sid, $sldId];
        }
        $merged = [];
        foreach ($groups as [$sid, $sldId]) {
            if ($merged && $merged[count($merged) - 1][0] === $sid) {
                $merged[count($merged) - 1][1][] = $sldId;
            } else {
                $merged[] = [$sid, [$sldId]];
            }
        }
        $x = '<p:extLst><p:ext uri="{521415D9-36F7-43E2-AB2F-B90AF26B5E84}"><p14:sectionLst xmlns:p14="http://schemas.microsoft.com/office/powerpoint/2010/main">';
        foreach ($merged as [$sid, $ids]) {
            $h = strtoupper(md5('press-section-'.$sid));
            $guid = '{'.substr($h, 0, 8).'-'.substr($h, 8, 4).'-'.substr($h, 12, 4).'-'.substr($h, 16, 4).'-'.substr($h, 20, 12).'}';
            $x .= '<p14:section name="'.Ooxml::esc($byId[$sid]).'" id="'.$guid.'"><p14:sldIdLst>';
            foreach ($ids as $id) {
                $x .= '<p14:sldId id="'.$id.'"/>';
            }
            $x .= '</p14:sldIdLst></p14:section>';
        }

        return $x.'</p14:sectionLst></p:ext></p:extLst>';
    }

    // ================================================================== slides

    /** @param array<string, mixed> $slide */
    private function slide(array $slide, int $n): void
    {
        $part = 'ppt/slides/slide'.$n.'.xml';
        $this->cur = ['n' => $n, 'part' => $part, 'nextId' => 2, 'title' => false, 'body' => false];
        $pkg = $this->pkg;
        $pkg->rel($part, self::T.'slideLayout', '../slideLayouts/slideLayout1.xml');
        $this->text = new PptxText($this->theme, fn (string $link) => $this->linkRel($link));

        $bg = $this->background($slide['bg'] ?? null);
        $tree = '';
        $els = Els::visible($slide);
        $emittedGroups = [];
        foreach ($els as $i => $el) {
            $g = is_string($el['group'] ?? null) ? $el['group'] : null;
            if ($g !== null) {
                if (isset($emittedGroups[$g])) {
                    continue;
                }
                $members = array_values(array_filter($els, fn ($e) => ($e['group'] ?? null) === $g));
                if (count($members) > 1) {
                    $emittedGroups[$g] = true;
                    $tree .= $this->group($g, $members);

                    continue;
                }
            }
            $tree .= $this->element($el);
        }
        if ($bg['overlay'] !== '') {
            $tree = $bg['overlay'].$tree;
        }
        $hidden = ! empty($slide['hidden']) ? ' show="0"' : '';
        $xml = Ooxml::head().'<p:sld xmlns:a="'.Ooxml::NS_A.'" xmlns:r="'.Ooxml::NS_R.'" xmlns:p="'.Ooxml::NS_P.'"'.$hidden.'><p:cSld>'.$bg['xml'].'<p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'.$tree.'</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>'.$this->transition($slide['transition'] ?? null, $slide['id'] ?? null).'</p:sld>';
        $pkg->add($part, $xml, 'application/vnd.openxmlformats-officedocument.presentationml.slide+xml');

        $notes = trim(Els::str($slide['notes'] ?? null));
        if ($notes !== '') {
            $np = 'ppt/notesSlides/notesSlide'.$n.'.xml';
            $pkg->add($np, PptxParts::notesSlide($notes), 'application/vnd.openxmlformats-officedocument.presentationml.notesSlide+xml');
            $pkg->rel($np, self::T.'notesMaster', '../notesMasters/notesMaster1.xml');
            $pkg->rel($np, self::T.'slide', '../slides/slide'.$n.'.xml');
            $pkg->rel($part, self::T.'notesSlide', '../notesSlides/notesSlide'.$n.'.xml');
        }
        foreach ($els as $el) {
            if (! empty($el['anim']) && is_array($el['anim'])) {
                $this->note($el['id'] ?? null, 'dropped', 'Element animations are not exported.');
            }
        }
    }

    /** @return array{xml: string, overlay: string} */
    private function background(mixed $bg): array
    {
        $bg ??= $this->theme->bg ?? '@bg';
        $fill = null;
        $overlay = '';
        if (is_string($bg) || (is_array($bg) && Theme::isGradient($bg))) {
            $fill = Ooxml::fill($this->theme, $bg);
        } elseif (is_array($bg) && isset($bg['img']) && is_array($bg['img'])) {
            $asset = Els::shortAssetId($bg['img']);
            $img = $asset !== null ? $this->imagePart($asset, [], $this->W, $this->H) : null;
            if ($img !== null) {
                $rid = $img['rid'];
                $win = $img['win'];
                $fill = '<a:blipFill dpi="0" rotWithShape="1"><a:blip r:embed="'.$rid.'"/><a:srcRect'.$this->srcRectAttrs($win).'/><a:stretch><a:fillRect/></a:stretch></a:blipFill>';
                $dim = Els::num($bg['dim'] ?? 0, 0, 0, 1);
                if ($dim > 0) {
                    $overlay = $this->rawRect(0, 0, $this->W, $this->H, '<a:solidFill><a:srgbClr val="000000"><a:alpha val="'.(int) round($dim * 100000).'"/></a:srgbClr></a:solidFill>', 'Background dim');
                }
            } else {
                $this->note(null, 'approximated', 'The slide background image could not be read, so the theme background is used.');
            }
        }
        $fill ??= Ooxml::fill($this->theme, '@bg');

        return ['xml' => '<p:bg><p:bgPr>'.$fill.'<a:effectLst/></p:bgPr></p:bg>', 'overlay' => $overlay];
    }

    private function rawRect(float $x, float $y, float $w, float $h, string $fillXml, string $name): string
    {
        $id = $this->cur['nextId']++;

        return '<p:sp><p:nvSpPr><p:cNvPr id="'.$id.'" name="'.Ooxml::esc($name).'"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:spPr>'.Ooxml::xfrm($x, $y, $w, $h).'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'.$fillXml.'<a:ln><a:noFill/></a:ln></p:spPr></p:sp>';
    }

    private function transition(mixed $t, mixed $slideId): string
    {
        if (! is_array($t) || empty($t['type'])) {
            return '';
        }
        $type = Els::str($t['type']);
        $dir = in_array($t['dir'] ?? null, ['l', 'r', 'u', 'd'], true) ? $t['dir'] : 'l';
        $dur = (int) Els::num($t['dur'] ?? 600, 600, 100, 5000);
        $inner = match ($type) {
            'fade' => '<p:fade/>',
            'push' => '<p:push dir="'.$dir.'"/>',
            'wipe' => '<p:wipe dir="'.$dir.'"/>',
            'reveal' => '<p:pull dir="'.$dir.'"/>',
            default => '<p:fade/>',
        };
        if ($type === 'zoom' || $type === 'morph') {
            $this->note(null, 'approximated', ucfirst($type).' transition is exported as a fade.');
        }
        $spd = $dur <= 400 ? 'fast' : ($dur <= 900 ? 'med' : 'slow');

        return '<mc:AlternateContent xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"><mc:Choice xmlns:p14="http://schemas.microsoft.com/office/powerpoint/2010/main" Requires="p14"><p:transition spd="'.$spd.'" p14:dur="'.$dur.'">'.$inner.'</p:transition></mc:Choice><mc:Fallback><p:transition spd="'.$spd.'">'.$inner.'</p:transition></mc:Fallback></mc:AlternateContent>';
    }

    // ================================================================== relationships and media

    private function linkRel(string $link): ?string
    {
        if (str_starts_with($link, '#slide:')) {
            $id = substr($link, 7);
            if (! isset($this->slideNo[$id])) {
                return null;
            }

            return $this->pkg->rel($this->cur['part'], self::T.'slide', 'slide'.$this->slideNo[$id].'.xml');
        }
        if (Els::link($link) === null) {
            return null;
        }

        return $this->pkg->rel($this->cur['part'], self::T.'hyperlink', $link, true);
    }

    private function hlink(?string $link): string
    {
        if ($link === null || ($link = Els::link($link)) === null) {
            return '';
        }
        $rid = $this->linkRel($link);
        if ($rid === null) {
            return '';
        }

        return '<a:hlinkClick r:id="'.$rid.'"'.(str_starts_with($link, '#slide:') ? ' action="ppaction://hlinksldjump"' : '').'/>';
    }

    private function addMedia(string $bytes, string $ext): string
    {
        $key = sha1($bytes).$ext;
        if (! isset($this->media[$key])) {
            $this->mediaCount++;
            $path = 'ppt/media/image'.$this->mediaCount.'.'.$ext;
            $this->pkg->add($path, $bytes);
            $this->media[$key] = $path;
        }

        return $this->pkg->rel($this->cur['part'], self::T.'image', '../media/'.basename($this->media[$key]));
    }

    /**
     * Picture bytes for PowerPoint (png, jpeg, gif or svg), with the crop window and placement for an element.
     *
     * @param  array<string, mixed>  $el
     * @return array{rid: string, win: array{0: float, 1: float, 2: float, 3: float}, dest: array{0: float, 1: float, 2: float, 3: float}, svg: bool, baked: bool}|null
     */
    private function imagePart(int $assetId, array $el, float $w, float $h): ?array
    {
        $asset = $this->assets->find($assetId);
        if ($asset === null) {
            return null;
        }
        $bytes = $asset['bytes'];
        $isSvg = $asset['mime'] === 'image/svg+xml' || str_contains(substr($bytes, 0, 2048), '<svg');
        if ($isSvg) {
            if (preg_match('/<\s*(script|foreignObject)\b/i', $bytes) || preg_match('/(href|src)\s*=\s*["\']\s*(https?:|file:)/i', $bytes)) {
                return null;
            }
            $dim = ImageComposer::svgDimensions($bytes) ?? [(int) $w, (int) $h];
            $plan = ImageComposer::plan($dim[0], $dim[1], array_merge($el, ['fit' => ($el['fit'] ?? 'contain') === 'cover' ? 'contain' : ($el['fit'] ?? 'contain')]), $w, $h);
            $rid = $this->addMedia(Raster::blankPng(), 'png');
            $svgRid = $this->addMedia($bytes, 'svg');

            return ['rid' => $rid, 'svgRid' => $svgRid, 'win' => [0.0, 0.0, 1.0, 1.0], 'dest' => $plan['dest'], 'svg' => true, 'baked' => false];
        }
        $dim = ImageComposer::dimensions($bytes);
        if ($dim === null) {
            return null;
        }
        [$sw, $sh] = $dim;
        $adj = is_array($el['adj'] ?? null) ? $el['adj'] : null;
        $bake = ImageComposer::hasAdj($adj) && ! ImageComposer::adjIsNativeOnly($adj);
        $mime = $asset['mime'];
        $native = in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true) && (str_starts_with($bytes, "\x89PNG") || str_starts_with($bytes, "\xFF\xD8") || str_starts_with($bytes, 'GIF8'));
        $ext = str_starts_with($bytes, "\x89PNG") ? 'png' : (str_starts_with($bytes, "\xFF\xD8") ? 'jpg' : (str_starts_with($bytes, 'GIF8') ? 'gif' : 'png'));
        if ($bake) {
            $b = ImageComposer::bakeAdjustments($bytes, $adj);
            if ($b === null) {
                return null;
            }
            $bytes = $b['bytes'];
            $ext = $b['ext'];
            $dim = ImageComposer::dimensions($bytes) ?? $dim;
            [$sw, $sh] = $dim;
        } elseif (! $native) {
            // webp, avif and bmp are not read by PowerPoint: re-encode.
            $im = ImageComposer::decode($bytes);
            if ($im === null) {
                return null;
            }
            $sw = imagesx($im);
            $sh = imagesy($im);
            $max = 2400;
            if (max($sw, $sh) > $max) {
                $s = $max / max($sw, $sh);
                $im = imagescale($im, max(1, (int) round($sw * $s)), max(1, (int) round($sh * $s)), IMG_BILINEAR_FIXED) ?: $im;
            }
            imagealphablending($im, false);
            imagesavealpha($im, true);
            $bytes = Raster::png($im);
            $ext = 'png';
        }
        $plan = ImageComposer::plan($sw, $sh, $el, $w, $h);

        return ['rid' => $this->addMedia($bytes, $ext), 'win' => $plan['win'], 'dest' => $plan['dest'], 'svg' => false, 'baked' => $bake];
    }

    /** @param array{0: float, 1: float, 2: float, 3: float} $win */
    private function srcRectAttrs(array $win): string
    {
        $l = (int) round(max(0, $win[0]) * 100000);
        $t = (int) round(max(0, $win[1]) * 100000);
        $r = (int) round(max(0, 1 - $win[0] - $win[2]) * 100000);
        $b = (int) round(max(0, 1 - $win[1] - $win[3]) * 100000);
        $s = '';
        foreach (['l' => $l, 't' => $t, 'r' => $r, 'b' => $b] as $k => $v) {
            if ($v > 0) {
                $s .= ' '.$k.'="'.$v.'"';
            }
        }

        return $s;
    }

    // ================================================================== elements

    /** @param array<string, mixed> $el */
    private function element(array $el): string
    {
        return match ($el['type']) {
            'text' => $this->textEl($el),
            'shape' => $this->shapeEl($el),
            'image' => $this->imageEl($el),
            'video' => $this->mediaEl($el, 'video'),
            'audio' => $this->mediaEl($el, 'audio'),
            'icon' => $this->iconEl($el),
            'chart' => $this->chartEl($el),
            'table' => $this->tableEl($el),
            default => '',
        };
    }

    /** @param list<array<string, mixed>> $members */
    private function group(string $gid, array $members): string
    {
        $minX = $minY = INF;
        $maxX = $maxY = -INF;
        foreach ($members as $m) {
            $b = Els::box($m);
            $minX = min($minX, $b['x']);
            $minY = min($minY, $b['y']);
            $maxX = max($maxX, $b['x'] + $b['w']);
            $maxY = max($maxY, $b['y'] + $b['h']);
        }
        $id = $this->cur['nextId']++;
        $inner = '';
        foreach ($members as $m) {
            $inner .= $this->element($m);
        }
        $off = '<a:off x="'.Ooxml::emu($minX).'" y="'.Ooxml::emu($minY).'"/><a:ext cx="'.Ooxml::emu($maxX - $minX).'" cy="'.Ooxml::emu($maxY - $minY).'"/><a:chOff x="'.Ooxml::emu($minX).'" y="'.Ooxml::emu($minY).'"/><a:chExt cx="'.Ooxml::emu($maxX - $minX).'" cy="'.Ooxml::emu($maxY - $minY).'"/>';

        return '<p:grpSp><p:nvGrpSpPr><p:cNvPr id="'.$id.'" name="Group '.Ooxml::esc($gid).'"/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm>'.$off.'</a:xfrm></p:grpSpPr>'.$inner.'</p:grpSp>';
    }

    /** @param array<string, mixed> $el */
    private function cNvPr(int $id, array $el, string $default, bool $link = true): string
    {
        $name = Ooxml::esc(mb_substr(Els::str($el['name'] ?? null, $default.' '.Els::str($el['id'] ?? null, (string) $id)), 0, 120));
        $alt = isset($el['alt']) && is_string($el['alt']) && $el['alt'] !== '' ? ' descr="'.Ooxml::esc(mb_substr($el['alt'], 0, 1000)).'"' : '';
        $h = $link ? $this->hlink(is_string($el['link'] ?? null) ? $el['link'] : null) : '';

        return '<p:cNvPr id="'.$id.'" name="'.$name.'"'.$alt.'>'.$h.'</p:cNvPr>';
    }

    /** @param array<string, mixed> $el */
    private function textEl(array $el): string
    {
        $b = Els::box($el);
        $style = TextStyle::of($el, $this->theme);
        $paras = TextDoc::paragraphs(is_array($el['doc'] ?? null) ? $el['doc'] : null, is_string($el['text'] ?? null) ? $el['text'] : null);
        $id = $this->cur['nextId']++;
        $role = $el['role'] ?? null;
        $ph = '';
        $cSp = '<p:cNvSpPr txBox="1"/>';
        if ($role === 'title' && ! $this->cur['title']) {
            $this->cur['title'] = true;
            $ph = '<p:ph type="title"/>';
            $cSp = '<p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr>';
        } elseif ($role === 'body' && ! $this->cur['body']) {
            $this->cur['body'] = true;
            $ph = '<p:ph idx="1"/>';
            $cSp = '<p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr>';
        }
        $radius = $style['radius'];
        $geom = PptxShapes::geometry($radius > 0 ? 'round' : 'rect', $b['w'], $b['h'], $radius);
        $alpha = $b['op'];
        $sp = Ooxml::xfrm($b['x'], $b['y'], $b['w'], $b['h'], $b['rot']).'<a:prstGeom prst="'.$geom['prst'].'">'.$geom['av'].'</a:prstGeom>';
        $sp .= isset($el['fill']) ? Ooxml::fill($this->theme, $el['fill'], $alpha) : '<a:noFill/>';
        $sw = Els::num($el['sw'] ?? 0, 0, 0, 200);
        $sp .= isset($el['stroke']) ? Ooxml::line($this->theme, $el['stroke'], $sw > 0 ? $sw : $this->theme->stroke, $el['dash'] ?? 'solid', $alpha) : '<a:ln><a:noFill/></a:ln>';
        if (isset($el['fill']) || isset($el['stroke'])) {
            $sp .= Ooxml::shadow($this->theme, is_array($el['shadow'] ?? null) ? $el['shadow'] : null, $alpha);
        }
        $body = $this->text->txBody($paras, $style, ['w' => $b['w'], 'h' => $b['h'], 'pad' => $style['pad'], 'alphaMul' => $alpha]);

        return '<p:sp><p:nvSpPr>'.$this->cNvPr($id, $el, 'Text').$cSp.'<p:nvPr>'.$ph.'</p:nvPr></p:nvSpPr><p:spPr>'.$sp.'</p:spPr>'.$body.'</p:sp>';
    }

    /** @param array<string, mixed> $el */
    private function shapeEl(array $el): string
    {
        $b = Els::box($el);
        $kind = in_array($el['kind'] ?? null, ShapeGeometry::KINDS, true) ? $el['kind'] : 'rect';
        $id = $this->cur['nextId']++;
        $alpha = $b['op'];
        $sw = Els::num($el['sw'] ?? 0, 0, 0, 200);
        if ($kind === 'line' || $kind === 'arrow') {
            $ends = is_array($el['ends'] ?? null) ? $el['ends'] : [];
            $e = ['s' => $ends['s'] ?? 'none', 'e' => $ends['e'] ?? ($kind === 'arrow' ? 'arrow' : 'none')];
            $stroke = $el['stroke'] ?? ($el['fill'] ?? '@ink');
            $ln = Ooxml::line($this->theme, $stroke, $sw > 0 ? $sw : $this->theme->stroke, $el['dash'] ?? 'solid', $alpha, $e, true);

            return '<p:cxnSp><p:nvCxnSpPr>'.$this->cNvPr($id, $el, 'Line').'<p:cNvCxnSpPr/><p:nvPr/></p:nvCxnSpPr><p:spPr>'.Ooxml::xfrm($b['x'], $b['y'], $b['w'], $b['h'], $b['rot']).'<a:prstGeom prst="line"><a:avLst/></a:prstGeom>'.$ln.'</p:spPr></p:cxnSp>';
        }
        $radius = Els::num($el['radius'] ?? ($kind === 'round' ? $this->theme->radius : 0), 0, 0, 5000);
        $geom = PptxShapes::geometry($kind, $b['w'], $b['h'], $radius);
        $h = $geom['h'] ?? $b['h'];
        $sp = Ooxml::xfrm($b['x'], $b['y'], $b['w'], $h, $b['rot']).'<a:prstGeom prst="'.$geom['prst'].'">'.$geom['av'].'</a:prstGeom>';
        $sp .= isset($el['fill']) ? Ooxml::fill($this->theme, $el['fill'], $alpha) : '<a:noFill/>';
        $sp .= isset($el['stroke']) ? Ooxml::line($this->theme, $el['stroke'], $sw > 0 ? $sw : $this->theme->stroke, $el['dash'] ?? 'solid', $alpha) : '<a:ln><a:noFill/></a:ln>';
        $sp .= Ooxml::shadow($this->theme, is_array($el['shadow'] ?? null) ? $el['shadow'] : null, $alpha);
        $body = '';
        if (is_array($el['doc'] ?? null)) {
            $style = TextStyle::of($el, $this->theme, ['align' => 'center', 'valign' => 'middle', 'pad' => 10]);
            $paras = TextDoc::paragraphs($el['doc']);
            $body = $this->text->txBody($paras, $style, ['w' => $b['w'], 'h' => $h, 'pad' => $style['pad'], 'alphaMul' => $alpha]);
        }

        return '<p:sp><p:nvSpPr>'.$this->cNvPr($id, $el, 'Shape').'<p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:spPr>'.$sp.'</p:spPr>'.$body.'</p:sp>';
    }

    /** @param array<string, mixed> $el */
    private function imageEl(array $el): string
    {
        $b = Els::box($el);
        $w = max(1.0, $b['w']);
        $h = max(1.0, $b['h']);
        $assetId = Els::shortAssetId($el['src'] ?? null);
        $img = $assetId !== null ? $this->imagePart($assetId, $el, $w, $h) : null;
        if ($img === null) {
            $this->note($el['id'] ?? null, 'dropped', $assetId === null ? 'The picture points at an external address, which the export does not download.' : 'The picture file could not be read.');

            return $this->placeholderPic($el, 'Image not available', 'image');
        }

        return $this->pic($el, $img, $b, $w, $h);
    }

    /**
     * @param  array<string, mixed>  $el
     * @param  array<string, mixed>  $img
     * @param  array{x: float, y: float, w: float, h: float, rot: float, op: float}  $b
     */
    private function pic(array $el, array $img, array $b, float $w, float $h, ?string $link = null): string
    {
        $out = '';
        $mask = in_array($el['mask'] ?? null, ShapeGeometry::MASKS, true) ? $el['mask'] : 'none';
        [$dx, $dy, $dw, $dh] = $img['dest'];
        if (($el['fit'] ?? 'cover') === 'contain' && isset($el['fill']) && ! $img['svg']) {
            $out .= $this->rawRectRot($b['x'], $b['y'], $w, $h, $b['rot'], Ooxml::fill($this->theme, $el['fill'], $b['op']), 'Picture background');
        }
        $id = $this->cur['nextId']++;
        $geom = PptxShapes::maskGeometry($mask, $dw, $dh);
        // A rotated element rotates about its own centre: move the (possibly smaller) picture with it.
        $cx = $b['x'] + $w / 2;
        $cy = $b['y'] + $h / 2;
        $px = $b['x'] + $dx;
        $py = $b['y'] + $dy;
        if (abs($b['rot']) > 0.001 && ($dx > 0.01 || $dy > 0.01)) {
            $rad = deg2rad($b['rot']);
            $ox = $px + $dw / 2 - $cx;
            $oy = $py + $dh / 2 - $cy;
            $nx = $cx + $ox * cos($rad) - $oy * sin($rad);
            $ny = $cy + $ox * sin($rad) + $oy * cos($rad);
            $px = $nx - $dw / 2;
            $py = $ny - $dh / 2;
        }
        $adj = is_array($el['adj'] ?? null) ? $el['adj'] : null;
        $blipKids = '';
        if ($b['op'] < 0.999) {
            $blipKids .= '<a:alphaModFix amt="'.(int) round($b['op'] * 100000).'"/>';
        }
        if (! $img['baked'] && ImageComposer::hasAdj($adj)) {
            if ((float) ($adj['gray'] ?? 0) >= 100.0) {
                $blipKids .= '<a:grayscl/>';
            }
            $br = (int) round(Els::num($adj['br'] ?? 0, 0, -100, 100) * 1000);
            $ct = (int) round(Els::num($adj['ct'] ?? 0, 0, -100, 100) * 1000);
            if ($br !== 0 || $ct !== 0) {
                $blipKids .= '<a:lum'.($br !== 0 ? ' bright="'.$br.'"' : '').($ct !== 0 ? ' contrast="'.$ct.'"' : '').'/>';
            }
        }
        $ext = '';
        if ($img['svg']) {
            $ext = '<a:extLst><a:ext uri="{96DAC541-7B7A-43D3-8B79-37D633B846F1}"><asvg:svgBlip xmlns:asvg="http://schemas.microsoft.com/office/drawing/2016/SVG/main" r:embed="'.$img['svgRid'].'"/></a:ext></a:extLst>';
        }
        $blip = '<a:blip r:embed="'.$img['rid'].'">'.$blipKids.$ext.'</a:blip>';
        $sp = Ooxml::xfrm($px, $py, $dw, $dh, $b['rot'], ! empty($el['flipH']), ! empty($el['flipV'])).'<a:prstGeom prst="'.$geom['prst'].'">'.$geom['av'].'</a:prstGeom>'.Ooxml::shadow($this->theme, is_array($el['shadow'] ?? null) ? $el['shadow'] : null, $b['op']);
        $out .= '<p:pic><p:nvPicPr>'.$this->cNvPr($id, $el, 'Picture', $link === null).'<p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr><p:nvPr/></p:nvPicPr><p:blipFill>'.$blip.'<a:srcRect'.$this->srcRectAttrs($img['win']).'/><a:stretch><a:fillRect/></a:stretch></p:blipFill><p:spPr>'.$sp.'</p:spPr></p:pic>';
        if ($link !== null) {
            $out = str_replace('</p:cNvPr>', $this->hlink($link).'</p:cNvPr>', $out);
        }
        if (isset($el['cap']) && is_string($el['cap']) && trim($el['cap']) !== '') {
            $size = max(14.0, min(28.0, $w * 0.04));
            $style = TextStyle::of(['size' => $size, 'color' => '@muted', 'lh' => 1.3], $this->theme);
            $capId = $this->cur['nextId']++;
            $out .= '<p:sp><p:nvSpPr><p:cNvPr id="'.$capId.'" name="Caption"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr><p:spPr>'.Ooxml::xfrm($b['x'], $b['y'] + $h + 8, $w, $size * 2).'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/></p:spPr>'.$this->text->txBody(TextDoc::paragraphs(null, mb_substr($el['cap'], 0, 300)), $style, ['w' => $w, 'h' => $size * 2, 'pad' => 0]).'</p:sp>';
        }

        return $out;
    }

    private function rawRectRot(float $x, float $y, float $w, float $h, float $rot, string $fillXml, string $name): string
    {
        $id = $this->cur['nextId']++;

        return '<p:sp><p:nvSpPr><p:cNvPr id="'.$id.'" name="'.Ooxml::esc($name).'"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:spPr>'.Ooxml::xfrm($x, $y, $w, $h, $rot).'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'.$fillXml.'<a:ln><a:noFill/></a:ln></p:spPr></p:sp>';
    }

    /** A labelled stand-in picture for media a presentation file cannot carry. */
    private function placeholderPic(array $el, string $label, string $kind, ?string $link = null): string
    {
        $b = Els::box($el);
        $w = max(1.0, $b['w']);
        $h = max(1.0, $b['h']);
        $png = Placeholder::png($label, $w, $h, $kind, $this->theme);
        $rid = $this->addMedia($png, 'png');
        $img = ['rid' => $rid, 'win' => [0.0, 0.0, 1.0, 1.0], 'dest' => [0.0, 0.0, $w, $h], 'svg' => false, 'baked' => true];
        $el2 = ['id' => $el['id'] ?? null, 'name' => $el['name'] ?? null, 'alt' => $el['alt'] ?? $label, 'rot' => $el['rot'] ?? 0, 'op' => $el['op'] ?? 1, 'link' => $link ?? ($el['link'] ?? null)];

        return $this->pic(array_filter($el2, fn ($v) => $v !== null), $img, $b, $w, $h, $link);
    }

    /** @param array<string, mixed> $el */
    private function mediaEl(array $el, string $kind): string
    {
        $b = Els::box($el);
        $w = max(1.0, $b['w']);
        $h = max(1.0, $b['h']);
        $link = null;
        $label = $kind === 'video' ? 'Video' : 'Audio clip';
        if ($kind === 'video') {
            if (is_array($el['embed'] ?? null) && isset($el['embed']['provider'], $el['embed']['id'])) {
                $id = preg_replace('/[^A-Za-z0-9_-]/', '', Els::str($el['embed']['id']));
                $link = match ($el['embed']['provider']) {
                    'youtube' => 'https://www.youtube.com/watch?v='.$id,
                    'vimeo' => 'https://vimeo.com/'.$id,
                    default => null,
                };
                $label = 'Video on '.ucfirst(Els::str($el['embed']['provider'])).' (click to open)';
            } elseif (($u = Els::url($el['src'] ?? null)) !== null) {
                $link = $u;
                $label = 'Video (click to open)';
            } else {
                $label = 'Video (plays in the presentation)';
            }
            $poster = Els::shortAssetId($el['poster'] ?? null);
            if ($poster !== null) {
                $img = $this->imagePart($poster, ['fit' => 'cover'], $w, $h);
                if ($img !== null && ! $img['svg']) {
                    $this->note($el['id'] ?? null, 'approximated', 'Video is exported as its poster picture'.($link ? ' with a link to the video.' : '.'));

                    return $this->pic(['id' => $el['id'] ?? null, 'alt' => $el['alt'] ?? 'Video poster', 'rot' => $el['rot'] ?? 0, 'op' => $el['op'] ?? 1], $img, $b, $w, $h, $link);
                }
            }
        } else {
            $label = 'Audio clip (plays in the presentation)';
        }
        $this->note($el['id'] ?? null, 'approximated', ucfirst($kind).' files cannot be embedded, so a labelled picture stands in'.($link ? ' with a link.' : '.'));

        return $this->placeholderPic($el, $label, $kind, $link);
    }

    /** @param array<string, mixed> $el */
    private function iconEl(array $el): string
    {
        $b = Els::box($el);
        $id = $this->cur['nextId']++;
        $name = is_string($el['name'] ?? null) ? $el['name'] : '';
        $paths = $this->icons->paths($name);
        $sw = Els::num($el['sw'] ?? 2, 2, 0.25, 8);
        $colorSpec = $el['color'] ?? '@ink';
        if ($paths === null) {
            $this->note($el['id'] ?? null, 'approximated', 'The icon "'.mb_substr($name, 0, 40).'" is not in the icon set, so a dashed square stands in.');
            $geom = '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>';
            $ln = Ooxml::line($this->theme, $colorSpec, max(1.0, $sw), 'dash', $b['op']);

            return '<p:sp><p:nvSpPr>'.$this->cNvPr($id, $el + ['alt' => 'Icon '.$name], 'Icon').'<p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:spPr>'.Ooxml::xfrm($b['x'] + $b['w'] * 0.15, $b['y'] + $b['h'] * 0.15, $b['w'] * 0.7, $b['h'] * 0.7, $b['rot']).$geom.'<a:noFill/>'.$ln.'</p:spPr></p:sp>';
        }
        $pathXml = '';
        foreach ($paths as $d) {
            $segs = SvgPath::parse($d);
            if ($segs === []) {
                continue;
            }
            $pathXml .= '<a:path w="24000" h="24000" fill="none">';
            $pt = fn (float $x, float $y) => '<a:pt x="'.(int) round($x * 1000).'" y="'.(int) round($y * 1000).'"/>';
            foreach ($segs as $s) {
                $pathXml .= match ($s[0]) {
                    'M' => '<a:moveTo>'.$pt($s[1], $s[2]).'</a:moveTo>',
                    'L' => '<a:lnTo>'.$pt($s[1], $s[2]).'</a:lnTo>',
                    'C' => '<a:cubicBezTo>'.$pt($s[1], $s[2]).$pt($s[3], $s[4]).$pt($s[5], $s[6]).'</a:cubicBezTo>',
                    default => '<a:close/>',
                };
            }
            $pathXml .= '</a:path>';
        }
        $geom = '<a:custGeom><a:avLst/><a:gdLst/><a:ahLst/><a:cxnLst/><a:rect l="0" t="0" r="r" b="b"/><a:pathLst>'.$pathXml.'</a:pathLst></a:custGeom>';
        $ln = Ooxml::line($this->theme, $colorSpec, $sw * max(0.01, min($b['w'], $b['h'])) / 24, 'solid', $b['op'], null, true);

        return '<p:sp><p:nvSpPr>'.$this->cNvPr($id, $el + ['alt' => 'Icon '.$name], 'Icon').'<p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:spPr>'.Ooxml::xfrm($b['x'], $b['y'], $b['w'], $b['h'], $b['rot']).$geom.'<a:noFill/>'.$ln.'</p:spPr></p:sp>';
    }

    // ------------------------------------------------------------------ charts

    /** @param array<string, mixed> $el */
    private function chartEl(array $el): string
    {
        $b = Els::box($el);
        $chart = ChartData::normalize($el['chart'] ?? null);
        $type = $chart['type'];
        if ($type === 'kpi') {
            return $this->kpiEl($el, $chart, $b);
        }
        if ($type === 'map') {
            $this->note($el['id'] ?? null, 'approximated', 'A map chart is exported as a table of region values.');
            $rows = [[['t' => 'Region', 'b' => true], ['t' => 'Value', 'b' => true, 'al' => 'r']]];
            foreach (array_slice($chart['data']['regions'] ?? [], 0, 40) as $r) {
                $rows[] = [['t' => $r['code']], ['t' => Els::str($r['value']), 'al' => 'r']];
            }

            return $this->tableEl(['id' => $el['id'] ?? null, 'type' => 'table', 'x' => $b['x'], 'y' => $b['y'], 'w' => $b['w'], 'h' => $b['h'], 'rows' => $rows, 'colw' => [0.6, 0.4], 'header' => true, 'band' => true, 'lines' => 'rows', 'size' => max(12, min(22, $b['h'] / max(1, count($rows)) * 0.5)), 'rot' => $b['rot'], 'alt' => $el['alt'] ?? 'Map values']);
        }
        if (! in_array($type, PptxChart::NATIVE, true)) {
            return '';
        }
        $builder = new PptxChart($this->theme, $b['w'], $b['h']);
        $built = $builder->build($chart);
        $this->chartCount++;
        $n = $this->chartCount;
        $chartPath = 'ppt/charts/chart'.$n.'.xml';
        $xlsxPath = 'ppt/embeddings/Microsoft_Excel_Sheet'.$n.'.xlsx';
        $this->pkg->add($chartPath, $built['xml'], 'application/vnd.openxmlformats-officedocument.drawingml.chart+xml');
        $this->pkg->add($xlsxPath, $built['xlsx']);
        $this->pkg->rel($chartPath, self::T.'package', '../embeddings/Microsoft_Excel_Sheet'.$n.'.xlsx');
        $rid = $this->pkg->rel($this->cur['part'], self::T.'chart', '../charts/chart'.$n.'.xml');
        $id = $this->cur['nextId']++;
        if (abs($b['rot']) > 0.001) {
            $this->note($el['id'] ?? null, 'approximated', 'Charts cannot be rotated in PowerPoint, so the chart is exported upright.');
        }
        if ($type === 'waterfall') {
            $this->note($el['id'] ?? null, 'approximated', 'The waterfall is a stacked column chart with a hidden base series.');
        } elseif ($type === 'funnel') {
            $this->note($el['id'] ?? null, 'approximated', 'The funnel is a stacked bar chart with a hidden spacer series.');
        }

        return '<p:graphicFrame><p:nvGraphicFramePr>'.$this->cNvPr($id, $el + ['alt' => ChartData::text($chart['opt']['title'] ?? 'Chart')], 'Chart').'<p:cNvGraphicFramePr><a:graphicFrameLocks noGrp="1"/></p:cNvGraphicFramePr><p:nvPr/></p:nvGraphicFramePr><p:xfrm><a:off x="'.Ooxml::emu($b['x']).'" y="'.Ooxml::emu($b['y']).'"/><a:ext cx="'.Ooxml::emu($b['w']).'" cy="'.Ooxml::emu($b['h']).'"/></p:xfrm><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart"><c:chart xmlns:c="'.Ooxml::NS_C.'" r:id="'.$rid.'"/></a:graphicData></a:graphic></p:graphicFrame>';
    }

    /**
     * KPI tiles as native rounded rectangles holding text.
     *
     * @param  array<string, mixed>  $chart
     * @param  array<string, float>  $b
     */
    private function kpiEl(array $el, array $chart, array $b): string
    {
        $items = ChartData::kpiItems($chart);
        $fs = max(10.0, min(36.0, min($b['h'] * 0.045, $b['w'] * 0.04)));
        $titleH = 0.0;
        $out = '';
        $pad = max(8.0, $fs * 0.8);
        if (isset($chart['opt']['title'])) {
            $titleH = $fs * 1.3 * 1.4 + $fs * 0.5;
            $style = TextStyle::of(['size' => $fs * 1.3, 'weight' => 700, 'lh' => 1.2], $this->theme);
            $id = $this->cur['nextId']++;
            $out .= '<p:sp><p:nvSpPr><p:cNvPr id="'.$id.'" name="KPI title"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr><p:spPr>'.Ooxml::xfrm($b['x'] + $pad, $b['y'] + $pad, $b['w'] - 2 * $pad, $titleH).'<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/></p:spPr>'.$this->text->txBody(TextDoc::paragraphs(null, $chart['opt']['title']), $style, ['w' => $b['w'], 'h' => $titleH, 'pad' => 0]).'</p:sp>';
            $titleH += $pad;
        }
        $n = count($items);
        if ($n === 0) {
            return $out;
        }
        $area = ['x' => $b['x'] + $pad, 'y' => $b['y'] + $pad + $titleH, 'w' => $b['w'] - 2 * $pad, 'h' => $b['h'] - 2 * $pad - $titleH];
        $aspect = $area['w'] / max(1.0, $area['h']);
        $cols = $n <= 3 ? $n : ($n === 4 ? ($aspect > 2.4 ? 4 : 2) : (int) ceil(sqrt($n * $aspect)));
        $cols = max(1, min(6, $cols));
        while ($cols > 1 && ($area['w'] - ($cols - 1) * $fs) / $cols < $fs * 7) {
            $cols--;
        }
        $rows = (int) ceil($n / $cols);
        $gap = $fs * 0.9;
        $tw = ($area['w'] - $gap * ($cols - 1)) / $cols;
        $th = min(($area['h'] - $gap * ($rows - 1)) / $rows, $fs * 11);
        $gridH = $rows * $th + ($rows - 1) * $gap;
        $gy = $area['y'] + max(0, ($area['h'] - $gridH) / 2);
        $fmt = ChartData::formatter($chart['opt']);
        foreach ($items as $i => $it) {
            $r = intdiv($i, $cols);
            $c = $i % $cols;
            $x = $area['x'] + $c * ($tw + $gap);
            $y = $gy + $r * ($th + $gap);
            $vs = max($fs * 1.3, min($fs * 3.4, $th * 0.3));
            $value = is_float($it['value'] ?? null) || is_int($it['value'] ?? null) ? $fmt((float) $it['value']) : Els::str($it['value'] ?? null);
            $unit = Els::str($it['unit'] ?? null);
            $paras = [];
            $mk = fn (string $t, array $runStyle) => ['list' => null, 'level' => 0, 'n' => 0, 'runs' => [['t' => $t, 'b' => false, 'i' => false, 'u' => false, 's' => false, 'color' => $runStyle['color'], 'size' => $runStyle['size'], 'font' => null, 'link' => null]]];
            $paras[] = $mk(Els::str($it['label'] ?? null), ['color' => '@muted', 'size' => $fs * 0.95]);
            $paras[] = ['list' => null, 'level' => 0, 'n' => 0, 'runs' => array_filter([
                ['t' => $value, 'b' => true, 'i' => false, 'u' => false, 's' => false, 'color' => '@ink', 'size' => $vs, 'font' => null, 'link' => null],
                $unit !== '' ? ['t' => ' '.$unit, 'b' => false, 'i' => false, 'u' => false, 's' => false, 'color' => '@muted', 'size' => $vs * 0.5, 'font' => null, 'link' => null] : null,
            ])];
            if (array_key_exists('delta', $it)) {
                $dir = ChartData::deltaDirection($it['delta']);
                $good = $dir === 0 ? null : (($dir > 0) === (($it['good'] ?? 'up') !== 'down'));
                $col = $dir === 0 ? '@muted' : ($good ? '@good' : '@bad');
                $arrow = $dir > 0 ? "\u{25B2} " : ($dir < 0 ? "\u{25BC} " : '');
                $paras[] = $mk($arrow.ChartData::deltaText($it['delta']), ['color' => $col, 'size' => $fs * 0.95]);
                $paras[count($paras) - 1]['runs'][0]['b'] = true;
            }
            if (! empty($it['note'])) {
                $paras[] = $mk(Els::str($it['note']), ['color' => '@muted', 'size' => $fs * 0.8]);
            }
            $style = TextStyle::of(['size' => $fs, 'lh' => 1.15, 'ps' => 0.15, 'valign' => 'middle'], $this->theme);
            $id = $this->cur['nextId']++;
            $geom = PptxShapes::geometry('round', $tw, $th, min($fs * 0.7, 14));
            $pd = min($fs * 1.1, $tw * 0.1);
            $out .= '<p:sp><p:nvSpPr><p:cNvPr id="'.$id.'" name="KPI '.($i + 1).'"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:spPr>'.Ooxml::xfrm($x, $y, $tw, $th).'<a:prstGeom prst="'.$geom['prst'].'">'.$geom['av'].'</a:prstGeom>'.Ooxml::solid($this->theme, '@surface').'<a:ln><a:noFill/></a:ln></p:spPr>'.$this->text->txBody($paras, $style, ['w' => $tw, 'h' => $th, 'pad' => $pd]).'</p:sp>';
        }
        $this->note($el['id'] ?? null, 'approximated', 'Key figure tiles are exported as editable text boxes, not a chart.');

        return $out;
    }

    // ------------------------------------------------------------------ table

    /** @param array<string, mixed> $el */
    private function tableEl(array $el): string
    {
        $b = Els::box($el);
        $grid = TableGrid::build($el + ['w' => $b['w'], 'h' => $b['h']]);
        $style = TextStyle::of($el, $this->theme, ['size' => 24]);
        $lines = in_array($el['lines'] ?? null, ['none', 'rows', 'grid'], true) ? $el['lines'] : 'rows';
        $header = ! empty($el['header']);
        $band = ! empty($el['band']);
        $origin = [];
        $covered = [];
        foreach ($grid['cells'] as $c) {
            $origin[$c['r']][$c['c']] = $c;
            for ($rr = $c['r']; $rr < $c['r'] + $c['rs']; $rr++) {
                for ($cc = $c['c']; $cc < $c['c'] + $c['cs']; $cc++) {
                    if ($rr !== $c['r'] || $cc !== $c['c']) {
                        $covered[$rr][$cc] = ['r' => $c['r'], 'c' => $c['c']];
                    }
                }
            }
        }
        $pad = max(4.0, $style['size'] * 0.35);
        $totalH = array_sum($grid['rowh']) ?: $b['h'];
        $x = '<a:tbl><a:tblPr'.($header ? ' firstRow="1"' : '').($band ? ' bandRow="1"' : '').'/><a:tblGrid>';
        $emuW = Ooxml::emu($b['w']);
        $used = 0;
        foreach ($grid['colw'] as $i => $cw) {
            $w = $i === $grid['cols'] - 1 ? $emuW - $used : (int) round($cw * $emuW);
            $used += $w;
            $x .= '<a:gridCol w="'.max(1, $w).'"/>';
        }
        $x .= '</a:tblGrid>';
        for ($r = 0; $r < $grid['rows']; $r++) {
            $x .= '<a:tr h="'.max(1, Ooxml::emu($grid['rowh'][$r])).'">';
            $isHead = $header && $r === 0;
            for ($c = 0; $c < $grid['cols']; $c++) {
                if (isset($covered[$r][$c])) {
                    $o = $covered[$r][$c];
                    $attr = $o['r'] === $r ? ' hMerge="1"' : ' vMerge="1"';
                    $x .= '<a:tc'.$attr.'><a:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="en-US"/></a:p></a:txBody><a:tcPr/></a:tc>';

                    continue;
                }
                $cell = $origin[$r][$c] ?? null;
                if ($cell === null) {
                    $x .= '<a:tc><a:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="en-US"/></a:p></a:txBody><a:tcPr/></a:tc>';

                    continue;
                }
                $cd = $cell['cell'];
                $cs = $style;
                $cs['align'] = match ($cd['al'] ?? 'l') {
                    'c' => 'center',
                    'r' => 'right',
                    default => 'left',
                };
                if ($isHead || ! empty($cd['b'])) {
                    $cs['bold'] = true;
                }
                $cs['color'] = is_string($cd['color'] ?? null) ? $cd['color'] : $style['color'];
                $paras = TextDoc::paragraphs(null, Els::str($cd['t'] ?? null));
                if (! empty($cd['i'])) {
                    foreach ($paras as &$p) {
                        foreach ($p['runs'] as &$run) {
                            $run['i'] = true;
                        }
                    }
                    unset($p, $run);
                }
                $fill = $cd['fill'] ?? null;
                if ($fill === null) {
                    if ($isHead) {
                        $fill = '@surface';
                    } elseif ($band && $r % 2 === ($header ? 0 : 1)) {
                        $fill = '@surface';
                    }
                }
                $border = fn (string $tag, bool $on, string $color, float $w) => $on ? '<a:'.$tag.' w="'.max(1, Ooxml::emu($w)).'" cap="flat" cmpd="sng" algn="ctr"><a:solidFill>'.Ooxml::color($this->theme, $color).'</a:solidFill><a:prstDash val="solid"/></a:'.$tag.'>' : '<a:'.$tag.' w="0"><a:noFill/></a:'.$tag.'>';
                $bottomHead = $isHead && $lines !== 'grid';
                $tc = $border('lnL', $lines === 'grid', '@line', 1).$border('lnR', $lines === 'grid', '@line', 1).$border('lnT', $lines === 'grid', '@line', 1)
                    .($bottomHead ? $border('lnB', true, '@ink', 2) : $border('lnB', $lines === 'grid' || $lines === 'rows', '@line', 1));
                $tc .= $fill !== null ? Ooxml::solid($this->theme, $fill) : '<a:noFill/>';
                $span = ($cell['cs'] > 1 ? ' gridSpan="'.$cell['cs'].'"' : '').($cell['rs'] > 1 ? ' rowSpan="'.$cell['rs'].'"' : '');
                $x .= '<a:tc'.$span.'><a:txBody><a:bodyPr/><a:lstStyle/>';
                $count = count($paras);
                foreach ($paras as $pi => $p) {
                    $x .= $this->text->paragraph($p, $cs, $pi < $count - 1, $b['op']);
                }
                $x .= '</a:txBody><a:tcPr marL="'.Ooxml::emu($pad * 1.3).'" marR="'.Ooxml::emu($pad * 1.3).'" marT="'.Ooxml::emu($pad).'" marB="'.Ooxml::emu($pad).'" anchor="ctr">'.$tc.'</a:tcPr></a:tc>';
            }
            $x .= '</a:tr>';
        }
        $x .= '</a:tbl>';
        $id = $this->cur['nextId']++;
        if (abs($b['rot']) > 0.001) {
            $this->note($el['id'] ?? null, 'approximated', 'Tables cannot be rotated in PowerPoint, so the table is exported upright.');
        }

        return '<p:graphicFrame><p:nvGraphicFramePr>'.$this->cNvPr($id, $el, 'Table').'<p:cNvGraphicFramePr><a:graphicFrameLocks noGrp="1"/></p:cNvGraphicFramePr><p:nvPr/></p:nvGraphicFramePr><p:xfrm><a:off x="'.Ooxml::emu($b['x']).'" y="'.Ooxml::emu($b['y']).'"/><a:ext cx="'.Ooxml::emu($b['w']).'" cy="'.Ooxml::emu($totalH).'"/></p:xfrm><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/table">'.$x.'</a:graphicData></a:graphic></p:graphicFrame>';
    }
}
