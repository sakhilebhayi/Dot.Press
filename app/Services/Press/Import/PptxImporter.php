<?php

namespace App\Services\Press\Import;

use App\Services\Press\Export\Support\ShapeGeometry;
use App\Services\Press\Export\Support\TextDoc;
use DOMDocument;
use DOMElement;

/**
 * Reads a .pptx into the presentation document (spec sections 4 to 6). Nothing in the file is ever executed or
 * evaluated: the package is read with strict limits (ZipGuard), XML is parsed with external entities and DTDs refused,
 * and everything the document model cannot hold is listed in the result's report with the slide it was on.
 */
final class PptxImporter
{
    private const REL_OFFICE_DOC = 'officeDocument';

    /** Spec shape kinds for DrawingML preset geometries. */
    private const PRESET = [
        'rect' => 'rect', 'roundRect' => 'round', 'round1Rect' => 'round', 'round2SameRect' => 'round', 'snip1Rect' => 'rect',
        'ellipse' => 'ellipse', 'triangle' => 'triangle', 'rtTriangle' => 'triangle', 'diamond' => 'diamond', 'pentagon' => 'pentagon', 'homePlate' => 'chevron',
        'hexagon' => 'hexagon', 'star5' => 'star', 'chevron' => 'chevron', 'rightArrow' => 'arrow-r', 'leftArrow' => 'arrow-l',
        'wedgeRectCallout' => 'callout', 'wedgeRoundRectCallout' => 'callout', 'wedgeEllipseCallout' => 'callout', 'plus' => 'cross', 'mathPlus' => 'cross', 'heart' => 'heart',
        'line' => 'line', 'straightConnector1' => 'line', 'bentConnector2' => 'line', 'bentConnector3' => 'line', 'curvedConnector3' => 'line',
    ];

    /** @var array{max_bytes: int, max_slides: int, max_entry_bytes: int, max_xml_bytes: int, max_total_bytes: int, max_entries: int, max_ratio: int} */
    private array $limits;

    private ZipGuard $zip;

    /** @var list<array{slide: ?int, element: ?string, kind: string, message: string}> */
    private array $report = [];

    /** @var array<string, bool> */
    private array $reported = [];

    /** @var array<string, array{name: string, mime: string, size: int, bytes: string}> */
    private array $media = [];

    private int $mediaBytes = 0;

    /** @var array<string, array{dom: DOMDocument, rels: array<string, array{type: string, target: string, external: bool}>}> */
    private array $parts = [];

    /** @var array<string, string> slide part => imported slide id */
    private array $slideIds = [];

    private float $W = 1280;

    private float $H = 720;

    /** @var array{scheme: array<string, string>, major: string, minor: string, name: string, lnW: array<int, float>} */
    private array $theme = ['scheme' => [], 'major' => 'Public Sans', 'minor' => 'Public Sans', 'name' => 'Imported', 'lnW' => [1 => 0.75, 2 => 1.5, 3 => 2.25]];

    private ?ColorResolver $colors = null;

    private ?DOMElement $defaultTextStyle = null;

    // per slide state
    private int $slideNo = 0;

    private int $seq = 0;

    private int $groupSeq = 0;

    /** @var list<array<string, mixed>> */
    private array $els = [];

    /** @var array<string, mixed> */
    private array $ctx = [];

    /** @param array<string, mixed> $options */
    private function __construct(private string $path, private array $options)
    {
        $cfg = (array) config('press_export.import', []);
        $this->limits = [
            'max_bytes' => (int) ($options['max_bytes'] ?? $cfg['max_bytes'] ?? 60 * 1048576),
            'max_slides' => (int) ($options['max_slides'] ?? $cfg['max_slides'] ?? 300),
            'max_entry_bytes' => (int) ($options['max_entry_bytes'] ?? $cfg['max_entry_bytes'] ?? 40 * 1048576),
            'max_xml_bytes' => (int) ($options['max_xml_bytes'] ?? $cfg['max_xml_bytes'] ?? 8 * 1048576),
            'max_total_bytes' => (int) ($options['max_total_bytes'] ?? $cfg['max_total_bytes'] ?? 400 * 1048576),
            'max_entries' => (int) ($options['max_entries'] ?? $cfg['max_entries'] ?? 6000),
            'max_ratio' => (int) ($options['max_ratio'] ?? $cfg['max_ratio'] ?? 200),
        ];
    }

    /** @param array<string, mixed> $options name, plus limit overrides for tests */
    public static function import(string $path, array $options = []): ImportResult
    {
        return (new self($path, $options))->run();
    }

    // ================================================================== package

    private function run(): ImportResult
    {
        $this->zip = new ZipGuard($this->path, $this->limits);
        $rootRels = $this->relsOf('');
        $presPart = null;
        foreach ($rootRels as $r) {
            if (str_ends_with($r['type'], self::REL_OFFICE_DOC)) {
                $presPart = $r['target'];
            }
        }
        if ($presPart === null || ! $this->zip->has($presPart)) {
            throw new ImportException('This is not a PowerPoint presentation: the main presentation part is missing.');
        }
        $pres = $this->dom($presPart);
        if ($pres === null) {
            throw new ImportException('The presentation could not be read.');
        }
        $root = $pres->documentElement;
        $sz = Xml::child($root, 'sldSz');
        $cx = Xml::int($sz, 'cx', 12192000);
        $cy = Xml::int($sz, 'cy', 6858000);
        $this->W = max(100.0, min(5000.0, round($cx / 9525, 2)));
        $this->H = max(100.0, min(5000.0, round($cy / 9525, 2)));
        $this->defaultTextStyle = Xml::child($root, 'defaultTextStyle');

        $presRels = $this->relsOf($presPart);
        $slideRefs = [];
        $idList = Xml::child($root, 'sldIdLst');
        foreach (Xml::children($idList, 'sldId') as $s) {
            $rid = Xml::attrLocal($s, 'id');
            if ($rid !== null && isset($presRels[$rid])) {
                $slideRefs[] = ['part' => $presRels[$rid]['target'], 'id' => Xml::attr($s, 'id')];
            }
        }
        if (count($slideRefs) > $this->limits['max_slides']) {
            throw new ImportException('The presentation has '.count($slideRefs).' slides; at most '.$this->limits['max_slides'].' can be imported at once.');
        }
        if ($slideRefs === []) {
            throw new ImportException('The presentation has no slides.');
        }
        foreach ($slideRefs as $i => $s) {
            $this->slideIds[$s['part']] = 's'.($i + 1);
        }

        // Theme of the first master gives the deck theme.
        $masterRid = Xml::child(Xml::child($root, 'sldMasterIdLst'), 'sldMasterId');
        $masterPart = $masterRid !== null && isset($presRels[Xml::attrLocal($masterRid, 'id') ?? '']) ? $presRels[Xml::attrLocal($masterRid, 'id')]['target'] : null;
        $themeXml = null;
        if ($masterPart !== null) {
            foreach ($this->relsOf($masterPart) as $r) {
                if (str_ends_with($r['type'], '/theme')) {
                    $themeXml = $this->dom($r['target']);
                }
            }
        }
        if ($themeXml === null) {
            foreach ($presRels as $r) {
                if (str_ends_with($r['type'], '/theme')) {
                    $themeXml = $this->dom($r['target']);
                }
            }
        }
        $this->readTheme($themeXml);
        $this->colors = new ColorResolver($this->theme['scheme']);

        // Sections.
        $sections = [];
        $sectionOf = [];
        foreach (Xml::findAll($root, 'section', 200) as $sec) {
            $sid = 'sec'.(count($sections) + 1);
            $sections[] = ['id' => $sid, 'title' => mb_substr(Xml::attr($sec, 'name', 'Section') ?? 'Section', 0, 120)];
            foreach (Xml::findAll($sec, 'sldId', 1000) as $sl) {
                $sectionOf[Xml::attr($sl, 'id') ?? ''] = $sid;
            }
        }

        $slides = [];
        foreach ($slideRefs as $i => $ref) {
            $slides[] = $this->importSlide($ref['part'], $i + 1, $sectionOf[$ref['id'] ?? ''] ?? null);
        }

        $deck = [
            'id' => null,
            'title' => $this->deckTitle(),
            'w' => (int) round($this->W),
            'h' => (int) round($this->H),
            'theme' => $this->deckTheme(),
            'rev' => 0,
            'status' => 'draft',
            'visibility' => 'private',
            'sections' => $sections,
            'slide_order' => array_column($slides, 'id'),
        ];

        return new ImportResult($deck, $slides, $this->media, $this->report);
    }

    private function deckTitle(): string
    {
        $core = $this->zip->has('docProps/core.xml') ? $this->dom('docProps/core.xml') : null;
        $t = $core !== null ? Xml::find($core->documentElement, 'title') : null;
        $title = $t !== null ? trim($t->textContent) : '';
        if ($title === '') {
            $title = trim((string) ($this->options['name'] ?? ''));
        }
        $title = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $title) ?? '';

        return $title !== '' ? mb_substr($title, 0, 200) : 'Imported presentation';
    }

    // ================================================================== parts and relationships

    private function dom(string $part): ?DOMDocument
    {
        if (isset($this->parts[$part])) {
            return $this->parts[$part]['dom'];
        }
        $xml = $this->zip->read($part);
        if ($xml === null) {
            return null;
        }
        $dom = Xml::load($xml);
        if ($dom === null) {
            return null;
        }
        // Keep layouts and masters (reused by many slides); slides are parsed once and dropped.
        if (str_contains($part, '/slideLayouts/') || str_contains($part, '/slideMasters/') || str_contains($part, '/theme/')) {
            $this->parts[$part] = ['dom' => $dom, 'rels' => []];
        }

        return $dom;
    }

    /** @return array<string, array{type: string, target: string, external: bool}> */
    private function relsOf(string $part): array
    {
        $dir = dirname($part);
        $relPart = $part === '' ? '_rels/.rels' : ($dir === '.' ? '' : $dir.'/').'_rels/'.basename($part).'.rels';
        $xml = $this->zip->read($relPart);
        if ($xml === null) {
            return [];
        }
        $dom = Xml::load($xml);
        if ($dom === null) {
            return [];
        }
        $out = [];
        foreach (Xml::children($dom->documentElement, 'Relationship') as $r) {
            $id = Xml::attr($r, 'Id');
            $target = Xml::attr($r, 'Target');
            if ($id === null || $target === null) {
                continue;
            }
            $external = Xml::attr($r, 'TargetMode') === 'External';
            $out[$id] = ['type' => (string) Xml::attr($r, 'Type', ''), 'target' => $external ? $target : ZipGuard::resolve($part === '' ? 'x' : $part, $target), 'external' => $external];
        }

        return $out;
    }

    private function rep(?string $element, string $kind, string $message): void
    {
        $key = $this->slideNo.'|'.$element.'|'.$message;
        if (isset($this->reported[$key])) {
            return;
        }
        $this->reported[$key] = true;
        $this->report[] = ['slide' => $this->slideNo > 0 ? $this->slideNo : null, 'element' => $element, 'kind' => $kind, 'message' => $message];
    }

    // ================================================================== theme

    private function readTheme(?DOMDocument $dom): void
    {
        if ($dom === null) {
            $this->theme['scheme'] = ['dk1' => '111827', 'lt1' => 'FFFFFF', 'dk2' => '6B7280', 'lt2' => 'F4F5F7', 'accent1' => '2563EB', 'accent2' => 'F59E0B', 'accent3' => '15803D', 'accent4' => '7C3AED', 'accent5' => 'DB2777', 'accent6' => '0891B2', 'hlink' => '2563EB', 'folHlink' => '6B7280'];
            $this->rep(null, 'approximated', 'The presentation has no readable theme, so the default theme is used.');

            return;
        }
        $root = $dom->documentElement;
        $scheme = Xml::find($root, 'clrScheme');
        $map = [];
        foreach (Xml::children($scheme) as $slot) {
            $c = Xml::children($slot)[0] ?? null;
            if ($c === null) {
                continue;
            }
            $hex = $c->localName === 'sysClr' ? (Xml::attr($c, 'lastClr') ?? ($c->getAttribute('val') === 'window' ? 'FFFFFF' : '000000')) : Xml::attr($c, 'val', '000000');
            $map[(string) $slot->localName] = strtoupper(substr(preg_replace('/[^0-9A-Fa-f]/', '', (string) $hex) ?? '000000', 0, 6));
        }
        $defaults = ['dk1' => '111827', 'lt1' => 'FFFFFF', 'dk2' => '6B7280', 'lt2' => 'F4F5F7', 'accent1' => '2563EB', 'accent2' => 'F59E0B', 'accent3' => '15803D', 'accent4' => '7C3AED', 'accent5' => 'DB2777', 'accent6' => '0891B2', 'hlink' => '2563EB', 'folHlink' => '6B7280'];
        foreach ($defaults as $k => $v) {
            $map[$k] = strlen($map[$k] ?? '') === 6 ? $map[$k] : $v;
        }
        $this->theme['scheme'] = $map;
        $this->theme['name'] = mb_substr((string) Xml::attr($root, 'name', 'Imported'), 0, 60) ?: 'Imported';
        $fonts = Xml::find($root, 'fontScheme');
        foreach (['major' => 'majorFont', 'minor' => 'minorFont'] as $k => $tag) {
            $latin = Xml::child(Xml::child($fonts, $tag), 'latin');
            $face = Xml::attr($latin, 'typeface');
            if ($face !== null && $face !== '') {
                $this->theme[$k] = preg_replace('/[^A-Za-z0-9 \-_.]/', '', $face) ?: 'Public Sans';
            }
        }
        $ln = Xml::find($root, 'lnStyleLst');
        $i = 1;
        foreach (Xml::children($ln, 'ln') as $l) {
            $this->theme['lnW'][$i++] = max(0.25, Xml::int($l, 'w', 9525) / 9525);
        }
    }

    /** @return array<string, mixed> */
    private function deckTheme(): array
    {
        $s = $this->theme['scheme'];
        $h = fn (string $k) => '#'.strtolower($s[$k]);
        $mix = function (string $a, string $b, float $t): string {
            $ca = [hexdec(substr($a, 0, 2)), hexdec(substr($a, 2, 2)), hexdec(substr($a, 4, 2))];
            $cb = [hexdec(substr($b, 0, 2)), hexdec(substr($b, 2, 2)), hexdec(substr($b, 4, 2))];

            return sprintf('#%02x%02x%02x', ...array_map(fn ($i) => (int) round($ca[$i] + ($cb[$i] - $ca[$i]) * $t), [0, 1, 2]));
        };

        return [
            'id' => 'imported',
            'name' => $this->theme['name'],
            'c' => [
                'bg' => $h('lt1'), 'surface' => $h('lt2'), 'ink' => $h('dk1'), 'muted' => $h('dk2'), 'line' => $mix($s['lt1'], $s['dk1'], 0.15),
                'accent' => $h('accent1'), 'accent2' => $h('accent2'), 'good' => '#15803d', 'warn' => '#b45309', 'bad' => '#b91c1c',
                'chart' => [$h('accent1'), $h('accent2'), $h('accent3'), $h('accent4'), $h('accent5'), $h('accent6'), $h('dk2'), '#475569'],
            ],
            't' => ['heading' => $this->theme['major'], 'body' => $this->theme['minor'], 'hw' => 700, 'scale' => 1],
            's' => ['radius' => 12, 'stroke' => 2],
            'bg' => null,
        ];
    }

    // ================================================================== slide

    /** @return array<string, mixed> */
    private function importSlide(string $part, int $n, ?string $sectionId): array
    {
        $this->slideNo = $n;
        $this->seq = 0;
        $this->groupSeq = 0;
        $this->els = [];
        $dom = $this->dom($part);
        if ($dom === null) {
            $this->rep(null, 'unsupported', 'The slide could not be read and is imported as an empty slide.');

            return $this->slideRecord($n, 'Slide '.$n, '', $sectionId, false, null, null, []);
        }
        $root = $dom->documentElement;
        $rels = $this->relsOf($part);
        $layoutPart = null;
        $notesPart = null;
        foreach ($rels as $r) {
            if (str_ends_with($r['type'], '/slideLayout')) {
                $layoutPart = $r['target'];
            } elseif (str_ends_with($r['type'], '/notesSlide')) {
                $notesPart = $r['target'];
            } elseif (str_contains($r['type'], 'comments') || str_contains($r['type'], 'Comments')) {
                $this->rep(null, 'unsupported', 'Comments on this slide were not imported.');
            }
        }
        $layout = $layoutPart !== null ? $this->dom($layoutPart) : null;
        $masterPart = null;
        if ($layoutPart !== null) {
            foreach ($this->relsOf($layoutPart) as $r) {
                if (str_ends_with($r['type'], '/slideMaster')) {
                    $masterPart = $r['target'];
                }
            }
        }
        $master = $masterPart !== null ? $this->dom($masterPart) : null;

        // Colour map: the master's, unless the slide overrides it.
        $map = [];
        $cm = $master !== null ? Xml::child($master->documentElement, 'clrMap') : null;
        if ($cm !== null) {
            foreach ($cm->attributes as $a) {
                $map[$a->name] = $a->value;
            }
        }
        $ov = Xml::child(Xml::child($root, 'clrMapOvr'), 'overrideClrMapping');
        if ($ov !== null) {
            foreach ($ov->attributes as $a) {
                $map[$a->name] = $a->value;
            }
        }
        $colors = $this->colors->withClrMap($map);

        $this->ctx = [
            'part' => $part,
            'rels' => $rels,
            'layout' => $layout?->documentElement,
            'master' => $master?->documentElement,
            'colors' => $colors,
            'text' => new TextImporter($colors, $this->theme['major'], $this->theme['minor'], fn (string $rid) => $this->linkFor($rid, $this->ctx['rels'] ?? [])),
        ];

        // Master and layout decoration sits behind the slide's own content.
        $showMaster = Xml::attr($root, 'showMasterSp', '1') !== '0';
        $layoutShows = $layout === null || Xml::attr($layout->documentElement, 'showMasterSp', '1') !== '0';
        if ($showMaster) {
            if ($layoutShows && $master !== null) {
                $this->decoration($master->documentElement, 'Master', $masterPart ?? '');
            }
            if ($layout !== null) {
                $this->decoration($layout->documentElement, 'Layout', $layoutPart ?? '');
            }
        }

        $cSld = Xml::child($root, 'cSld');
        $tree = Xml::child($cSld, 'spTree');
        $this->walkTree($tree, $this->identity(), null, false);

        // Everything else on the slide that does not become an element.
        if (Xml::child($root, 'timing') !== null && Xml::find(Xml::child($root, 'timing'), 'cTn') !== null) {
            $this->rep(null, 'unsupported', 'Animations were not imported.');
        }

        $title = '';
        foreach ($this->els as $el) {
            if (($el['role'] ?? null) === 'title' && $el['type'] === 'text') {
                $title = trim(TextDoc::plain($el['doc'] ?? null));
                break;
            }
        }
        $notes = $notesPart !== null ? $this->notesText($notesPart) : '';
        $bg = $this->background($cSld);
        $transition = $this->transition($root);
        $hidden = Xml::attr($root, 'show', '1') === '0';

        return $this->slideRecord($n, $title !== '' ? mb_substr($title, 0, 200) : 'Slide '.$n, $notes, $sectionId, $hidden, $bg, $transition, $this->els);
    }

    /** @param list<array<string, mixed>> $els @return array<string, mixed> */
    private function slideRecord(int $n, string $title, string $notes, ?string $section, bool $hidden, mixed $bg, mixed $transition, array $els): array
    {
        return ['id' => 's'.$n, 'title' => $title, 'notes' => $notes, 'section_id' => $section, 'hidden' => $hidden, 'layout' => 'blank', 'bg' => $bg, 'transition' => $transition, 'rev' => 0, 'els' => $els];
    }

    private function notesText(string $part): string
    {
        $dom = $this->dom($part);
        if ($dom === null) {
            return '';
        }
        foreach (Xml::findAll($dom->documentElement, 'sp', 50) as $sp) {
            $ph = Xml::find(Xml::child(Xml::child($sp, 'nvSpPr'), 'nvPr'), 'ph');
            if ($ph !== null && Xml::attr($ph, 'type') === 'body') {
                $lines = [];
                foreach (Xml::findAll(Xml::child($sp, 'txBody'), 'p', 500) as $p) {
                    $line = '';
                    foreach (Xml::findAll($p, 't', 500) as $t) {
                        $line .= $t->textContent;
                    }
                    $lines[] = $line;
                }

                return mb_substr(trim(implode("\n", $lines)), 0, 20000);
            }
        }

        return '';
    }

    // ------------------------------------------------------------------ background

    private function background(?DOMElement $cSld): mixed
    {
        $scopes = [$cSld];
        $layout = $this->ctx['layout'] ?? null;
        $master = $this->ctx['master'] ?? null;
        if ($layout !== null) {
            $scopes[] = Xml::child($layout, 'cSld');
        }
        if ($master !== null) {
            $scopes[] = Xml::child($master, 'cSld');
        }
        foreach ($scopes as $i => $c) {
            $bg = Xml::child($c, 'bg');
            if ($bg === null) {
                continue;
            }
            $res = $this->bgValue($bg);
            if ($res === null) {
                continue;
            }
            // The master's plain theme background is the deck default.
            if ($i === 2 && $res === '@bg') {
                return null;
            }

            return $res === '@bg' ? null : $res;
        }

        return null;
    }

    private function bgValue(DOMElement $bg): mixed
    {
        $pr = Xml::child($bg, 'bgPr');
        if ($pr !== null) {
            $fill = $this->fillOf($pr);
            if ($fill === null) {
                return null;
            }
            if ($fill['mode'] === 'solid') {
                return $fill['value'];
            }
            if ($fill['mode'] === 'grad') {
                return $fill['value'];
            }
            if ($fill['mode'] === 'blip') {
                return $fill['value'] === null ? null : ['img' => $fill['value'], 'fit' => 'cover', 'dim' => 0];
            }

            return null;
        }
        $ref = Xml::child($bg, 'bgRef');
        if ($ref !== null) {
            $c = $this->ctx['colors']->spec($ref);

            return $c;
        }

        return null;
    }

    // ------------------------------------------------------------------ transition

    private function transition(DOMElement $root): ?array
    {
        $t = Xml::find($root, 'transition');
        if ($t === null) {
            return null;
        }
        $kids = Xml::children($t);
        $kid = null;
        foreach ($kids as $k) {
            if (! in_array($k->localName, ['sndAc', 'extLst'], true)) {
                $kid = $k;
                break;
            }
        }
        if ($kid === null) {
            return null;
        }
        $name = (string) $kid->localName;
        $dirRaw = Xml::attr($kid, 'dir', 'l') ?? 'l';
        $dir = in_array($dirRaw, ['l', 'r', 'u', 'd'], true) ? $dirRaw : 'l';
        $type = match ($name) {
            'fade' => 'fade',
            'push' => 'push',
            'wipe' => 'wipe',
            'cover', 'pull' => 'reveal',
            'zoom' => 'zoom',
            'morph' => 'morph',
            default => null,
        };
        if ($type === null) {
            $this->rep(null, 'approximated', 'The "'.$name.'" slide transition is imported as a fade.');
            $type = 'fade';
        }
        $dur = null;
        foreach ($t->attributes as $a) {
            if ($a->localName === 'dur' && is_numeric($a->value)) {
                $dur = (int) $a->value;
            }
        }
        $dur ??= match (Xml::attr($t, 'spd', 'med')) {
            'fast' => 400,
            'slow' => 1000,
            default => 700,
        };

        return ['type' => $type, 'dir' => $dir, 'dur' => max(100, min(5000, $dur)), 'ease' => 'inout'];
    }

    // ================================================================== tree walking

    /** @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} an identity matrix a b c d e f */
    private function identity(): array
    {
        return [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
    }

    /**
     * Master and layout shapes that are not placeholders, as locked elements behind the slide.
     */
    private function decoration(DOMElement $root, string $label, string $part): void
    {
        $tree = Xml::child(Xml::child($root, 'cSld'), 'spTree');
        $before = count($this->els);
        $this->walkTree($tree, $this->identity(), null, true, $label);
        if (count($this->els) - $before > 40) {
            $this->els = array_slice($this->els, 0, $before + 40);
            $this->rep(null, 'approximated', 'The '.strtolower($label).' has many decorative shapes; only the first 40 were imported.');
        }
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}  $m
     */
    private function walkTree(?DOMElement $tree, array $m, ?string $group, bool $decoration, string $label = ''): void
    {
        if ($tree === null) {
            return;
        }
        foreach (Xml::children($tree) as $node) {
            if (count($this->els) >= 400) {
                $this->rep(null, 'approximated', 'The slide has more than 400 elements; the rest were left out.');

                return;
            }
            $this->node($node, $m, $group, $decoration, $label);
        }
    }

    /** @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $m */
    private function node(DOMElement $node, array $m, ?string $group, bool $decoration, string $label): void
    {
        switch ($node->localName) {
            case 'sp':
                $this->sp($node, $m, $group, $decoration, $label);
                break;
            case 'pic':
                $this->pic($node, $m, $group, $decoration, $label);
                break;
            case 'cxnSp':
                $this->cxn($node, $m, $group, $decoration, $label);
                break;
            case 'graphicFrame':
                if (! $decoration) {
                    $this->graphicFrame($node, $m, $group);
                }
                break;
            case 'grpSp':
                $this->grpSp($node, $m, $group, $decoration, $label);
                break;
            case 'AlternateContent':
                $fb = Xml::child($node, 'Fallback');
                $choice = Xml::child($node, 'Choice');
                $use = $fb ?? $choice;
                if ($use !== null) {
                    foreach (Xml::children($use) as $inner) {
                        $this->node($inner, $m, $group, $decoration, $label);
                    }
                }
                break;
            case 'contentPart':
                $this->rep(null, 'unsupported', 'An ink or content part was not imported.');
                break;
            case 'nvGrpSpPr':
            case 'grpSpPr':
            case 'extLst':
                break;
            default:
                break;
        }
    }

    /** @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $m */
    private function grpSp(DOMElement $g, array $m, ?string $group, bool $decoration, string $label): void
    {
        $xf = Xml::child(Xml::child($g, 'grpSpPr'), 'xfrm');
        $gid = $group ?? 'g'.(++$this->groupSeq);
        if ($xf === null) {
            foreach (Xml::children($g) as $c) {
                $this->node($c, $m, $gid, $decoration, $label);
            }

            return;
        }
        $off = Xml::child($xf, 'off');
        $ext = Xml::child($xf, 'ext');
        $chOff = Xml::child($xf, 'chOff');
        $chExt = Xml::child($xf, 'chExt');
        $ox = Xml::int($off, 'x') / 9525;
        $oy = Xml::int($off, 'y') / 9525;
        $ex = Xml::int($ext, 'cx') / 9525;
        $ey = Xml::int($ext, 'cy') / 9525;
        $cx = Xml::int($chOff, 'x') / 9525;
        $cy = Xml::int($chOff, 'y') / 9525;
        $cex = Xml::int($chExt, 'cx') / 9525;
        $cey = Xml::int($chExt, 'cy') / 9525;
        $cex = $cex != 0 ? $cex : ($ex ?: 1.0);
        $cey = $cey != 0 ? $cey : ($ey ?: 1.0);
        $rot = deg2rad(Xml::int($xf, 'rot') / 60000);
        $fh = Xml::attr($xf, 'flipH') === '1';
        $fv = Xml::attr($xf, 'flipV') === '1';
        // child point -> parent point: translate to the child centre, scale, flip, rotate, translate to the parent centre
        $t1 = [1.0, 0.0, 0.0, 1.0, -($cx + $cex / 2), -($cy + $cey / 2)];
        $sc = [$ex / $cex * ($fh ? -1 : 1), 0.0, 0.0, $ey / $cey * ($fv ? -1 : 1), 0.0, 0.0];
        $ro = [cos($rot), sin($rot), -sin($rot), cos($rot), 0.0, 0.0];
        $t2 = [1.0, 0.0, 0.0, 1.0, $ox + $ex / 2, $oy + $ey / 2];
        $local = $this->mul($t2, $this->mul($ro, $this->mul($sc, $t1)));
        $combined = $this->mul($m, $local);
        foreach (Xml::children($g) as $c) {
            $this->node($c, $combined, $gid, $decoration, $label);
        }
    }

    /**
     * Matrix product a * b: apply b first, then a.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}  $a
     * @param  array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}  $b
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}
     */
    private function mul(array $a, array $b): array
    {
        return [
            $a[0] * $b[0] + $a[2] * $b[1], $a[1] * $b[0] + $a[3] * $b[1],
            $a[0] * $b[2] + $a[2] * $b[3], $a[1] * $b[2] + $a[3] * $b[3],
            $a[0] * $b[4] + $a[2] * $b[5] + $a[4], $a[1] * $b[4] + $a[3] * $b[5] + $a[5],
        ];
    }

    /**
     * Place a rectangle (EMU converted to units already) through the group matrix.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}  $m
     * @return array{x: float, y: float, w: float, h: float, rot: float, flipH: bool, flipV: bool}
     */
    private function place(array $m, float $x, float $y, float $w, float $h, float $rotDeg, bool $fh, bool $fv): array
    {
        if ($m === $this->identity()) {
            return ['x' => round($x, 2), 'y' => round($y, 2), 'w' => round($w, 2), 'h' => round($h, 2), 'rot' => fmod(fmod($rotDeg, 360) + 360, 360), 'flipH' => $fh, 'flipV' => $fv];
        }
        $cx = $x + $w / 2;
        $cy = $y + $h / 2;
        $px = $m[0] * $cx + $m[2] * $cy + $m[4];
        $py = $m[1] * $cx + $m[3] * $cy + $m[5];
        $r = deg2rad($rotDeg);
        // the element's local axes through the linear part of the matrix
        $u1 = [$m[0] * cos($r) + $m[2] * sin($r), $m[1] * cos($r) + $m[3] * sin($r)];
        $u2 = [$m[0] * -sin($r) + $m[2] * cos($r), $m[1] * -sin($r) + $m[3] * cos($r)];
        $nw = $w * hypot($u1[0], $u1[1]);
        $nh = $h * hypot($u2[0], $u2[1]);
        $det = $m[0] * $m[3] - $m[1] * $m[2];
        $nrot = rad2deg(atan2($u1[1], $u1[0]));
        $nfv = $det < 0 ? ! $fv : $fv;

        return ['x' => round($px - $nw / 2, 2), 'y' => round($py - $nh / 2, 2), 'w' => round($nw, 2), 'h' => round($nh, 2), 'rot' => fmod(fmod($nrot, 360) + 360, 360), 'flipH' => $fh, 'flipV' => $nfv];
    }

    // ================================================================== placeholders and inheritance

    /** @return array{type: ?string, idx: ?string}|null */
    private function phOf(DOMElement $sp): ?array
    {
        $nv = Xml::child($sp, 'nvSpPr') ?? Xml::child($sp, 'nvPicPr') ?? Xml::child($sp, 'nvCxnSpPr');
        $ph = Xml::child(Xml::child($nv, 'nvPr'), 'ph');

        return $ph !== null ? ['type' => Xml::attr($ph, 'type'), 'idx' => Xml::attr($ph, 'idx')] : null;
    }

    /** The matching placeholder shape in a layout or master root. */
    private function findPh(?DOMElement $root, array $ph, bool $isMaster): ?DOMElement
    {
        if ($root === null) {
            return null;
        }
        $tree = Xml::child(Xml::child($root, 'cSld'), 'spTree');
        $type = $ph['type'] ?? 'body';
        $idx = $ph['idx'];
        $family = fn (?string $t) => match ($t ?? 'body') {
            'title', 'ctrTitle' => 'title',
            'body', 'subTitle', 'obj', 'tbl', 'chart', 'dgm', 'media', 'clipArt', 'pic' => 'body',
            default => $t,
        };
        $best = null;
        foreach (Xml::children($tree, 'sp') as $sp) {
            $p = $this->phOf($sp);
            if ($p === null) {
                continue;
            }
            if (! $isMaster && $idx !== null && $p['idx'] === $idx && ($family($p['type']) === $family($type) || $p['idx'] !== '0')) {
                return $sp;
            }
            if ($family($p['type']) === $family($type) && $best === null) {
                $best = $sp;
            }
        }

        return $best;
    }

    /** @return array{x: float, y: float, w: float, h: float, rot: float, fh: bool, fv: bool}|null EMU converted to units */
    private function xfrmOf(?DOMElement $holder): ?array
    {
        $x = Xml::child($holder, 'xfrm');
        if ($x === null) {
            return null;
        }
        $off = Xml::child($x, 'off');
        $ext = Xml::child($x, 'ext');
        if ($off === null || $ext === null) {
            return null;
        }

        return [
            'x' => Xml::int($off, 'x') / 9525, 'y' => Xml::int($off, 'y') / 9525,
            'w' => max(0, Xml::int($ext, 'cx')) / 9525, 'h' => max(0, Xml::int($ext, 'cy')) / 9525,
            'rot' => Xml::int($x, 'rot') / 60000, 'fh' => Xml::attr($x, 'flipH') === '1', 'fv' => Xml::attr($x, 'flipV') === '1',
        ];
    }

    /** @return list<DOMElement> list style sources for text in a shape, highest priority first */
    private function textChain(DOMElement $sp, ?array $ph, ?DOMElement $layoutSp, ?DOMElement $masterSp): array
    {
        $chain = [];
        $add = function (?DOMElement $e) use (&$chain): void {
            if ($e !== null) {
                $chain[] = $e;
            }
        };
        // The first entry is always the shape's own list style (an empty one when it has none).
        $own = Xml::child(Xml::child($sp, 'txBody'), 'lstStyle');
        $chain[] = $own ?? $sp->ownerDocument->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'a:lstStyle');
        if ($layoutSp !== null) {
            $add(Xml::child(Xml::child($layoutSp, 'txBody'), 'lstStyle'));
        }
        if ($masterSp !== null) {
            $add(Xml::child(Xml::child($masterSp, 'txBody'), 'lstStyle'));
        }
        $tx = Xml::child($this->ctx['master'] ?? null, 'txStyles');
        if ($ph !== null) {
            $t = $ph['type'] ?? 'body';
            $style = in_array($t, ['title', 'ctrTitle'], true) ? 'titleStyle' : (in_array($t, ['body', 'subTitle', 'obj'], true) || $t === null ? 'bodyStyle' : 'otherStyle');
            $add(Xml::child($tx, $style));
        } else {
            $add(Xml::child($tx, 'otherStyle'));
        }
        $add($this->defaultTextStyle);

        return $chain;
    }

    // ================================================================== fills, lines, effects

    /**
     * @return array{mode: string, value: mixed}|null null when the properties do not say (inherit)
     */
    private function fillOf(?DOMElement $pr, ?string $phClr = null): ?array
    {
        if ($pr === null) {
            return null;
        }
        /** @var ColorResolver $colors */
        $colors = $this->ctx['colors'];
        foreach (Xml::children($pr) as $c) {
            switch ($c->localName) {
                case 'noFill':
                    return ['mode' => 'none', 'value' => null];
                case 'solidFill':
                    $v = $colors->spec($c, $phClr);

                    return $v === null ? null : ['mode' => 'solid', 'value' => $v];
                case 'gradFill':
                    return ['mode' => 'grad', 'value' => $this->gradient($c, $phClr)];
                case 'pattFill':
                    $this->rep(null, 'approximated', 'A pattern fill was imported as a solid colour.');
                    $v = $colors->spec(Xml::child($c, 'fgClr'), $phClr);

                    return $v === null ? null : ['mode' => 'solid', 'value' => $v];
                case 'blipFill':
                    $rid = Xml::attrLocal(Xml::child($c, 'blip'), 'embed');
                    if ($rid !== null && isset($this->ctx['rels'][$rid])) {
                        $name = $this->loadMedia($this->ctx['rels'][$rid]['target']);

                        return ['mode' => 'blip', 'value' => $name !== null ? ['import' => $name] : null];
                    }

                    return ['mode' => 'blip', 'value' => null];
            }
        }

        return null;
    }

    /** @return array<string, mixed>|string|null */
    private function gradient(DOMElement $g, ?string $phClr): array|string|null
    {
        $stops = [];
        foreach (Xml::children(Xml::child($g, 'gsLst'), 'gs') as $gs) {
            $c = $this->ctx['colors']->spec($gs, $phClr);
            if ($c === null) {
                continue;
            }
            $stops[] = [round(max(0, min(100000, Xml::int($gs, 'pos'))) / 100000, 3), $c];
        }
        if (count($stops) < 2) {
            return $stops[0][1] ?? null;
        }
        $lin = Xml::child($g, 'lin');
        if (Xml::child($g, 'path') !== null) {
            $this->rep(null, 'approximated', 'A radial or shape gradient was imported as a vertical linear gradient.');
            $angle = 180.0;
        } else {
            $angle = fmod((Xml::int($lin, 'ang', 5400000) / 60000) + 90, 360);
        }

        return ['g' => 'linear', 'a' => round($angle, 1), 'stops' => $stops];
    }

    /**
     * @return array{stroke: ?string, sw: float, dash: string, ends: array{s: string, e: string}}|null null when the properties do not say
     */
    private function lineOf(?DOMElement $spPr, ?string $phClr = null): ?array
    {
        $ln = Xml::child($spPr, 'ln');
        if ($ln === null) {
            return null;
        }
        $w = Xml::int($ln, 'w', 9525) / 9525;
        $stroke = null;
        $explicitNone = false;
        foreach (Xml::children($ln) as $c) {
            if ($c->localName === 'noFill') {
                $explicitNone = true;
            } elseif ($c->localName === 'solidFill') {
                $stroke = $this->ctx['colors']->spec($c, $phClr);
            } elseif ($c->localName === 'gradFill') {
                $g = $this->gradient($c, $phClr);
                $stroke = is_array($g) ? ($g['stops'][0][1] ?? null) : $g;
            }
        }
        $dash = match (Xml::attr(Xml::child($ln, 'prstDash'), 'val', 'solid')) {
            'dash', 'sysDash', 'lgDash', 'lgDashDot', 'dashDot', 'sysDashDot', 'lgDashDotDot', 'sysDashDotDot' => 'dash',
            'dot', 'sysDot' => 'dot',
            default => 'solid',
        };
        $endOf = fn (?DOMElement $e) => match (Xml::attr($e, 'type', 'none')) {
            'triangle', 'arrow', 'stealth' => 'arrow',
            'oval', 'diamond' => 'dot',
            default => 'none',
        };

        return ['stroke' => $explicitNone ? null : $stroke, 'sw' => round($w, 2), 'dash' => $dash, 'ends' => ['s' => $endOf(Xml::child($ln, 'headEnd')), 'e' => $endOf(Xml::child($ln, 'tailEnd'))], 'none' => $explicitNone];
    }

    /** @return array{x: float, y: float, blur: float, c: string}|null */
    private function shadowOf(?DOMElement $spPr): ?array
    {
        $sh = Xml::find(Xml::child($spPr, 'effectLst'), 'outerShdw');
        if ($sh === null) {
            return null;
        }
        $dist = Xml::int($sh, 'dist') / 9525;
        $dir = deg2rad(Xml::int($sh, 'dir') / 60000);
        $c = $this->ctx['colors']->spec($sh) ?? '#00000066';
        if (str_starts_with($c, '@')) {
            $c = '#000000';
        }

        return ['x' => round($dist * cos($dir), 2), 'y' => round($dist * sin($dir), 2), 'blur' => round(Xml::int($sh, 'blurRad') / 9525, 2), 'c' => $c];
    }

    // ================================================================== shapes

    private function nextId(string $prefix = 'e'): string
    {
        return $prefix.(++$this->seq);
    }

    /** @return array<string, mixed> common element fields */
    private function common(DOMElement $nv, string $type, array $pl, bool $decoration, string $label): array
    {
        $cNvPr = Xml::child($nv, 'cNvPr');
        $name = trim((string) Xml::attr($cNvPr, 'name', ''));
        $el = ['id' => $this->nextId($decoration ? 'm' : 'e'), 'type' => $type, 'x' => $pl['x'], 'y' => $pl['y'], 'w' => $pl['w'], 'h' => $pl['h'], 'rot' => round($pl['rot'], 2), 'op' => 1, 'origin' => 'import'];
        if ($name !== '') {
            $el['name'] = mb_substr(($decoration ? $label.': ' : '').preg_replace('/[\x00-\x1F]/', '', $name), 0, 120);
        }
        $alt = trim((string) Xml::attr($cNvPr, 'descr', ''));
        if ($alt !== '') {
            $el['alt'] = mb_substr($alt, 0, 1000);
        }
        if (Xml::attr($cNvPr, 'hidden') === '1') {
            $el['hide'] = true;
        }
        if ($decoration) {
            $el['lock'] = true;
        }

        return $el;
    }

    /** @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $m */
    private function sp(DOMElement $sp, array $m, ?string $group, bool $decoration, string $label): void
    {
        $nv = Xml::child($sp, 'nvSpPr');
        $ph = $this->phOf($sp);
        if ($decoration && $ph !== null) {
            return;
        }
        $spPr = Xml::child($sp, 'spPr');
        $layoutSp = $ph !== null ? $this->findPh($this->ctx['layout'] ?? null, $ph, false) : null;
        $masterSp = $ph !== null ? $this->findPh($this->ctx['master'] ?? null, $ph, true) : null;
        $xf = $this->xfrmOf($spPr) ?? ($layoutSp !== null ? $this->xfrmOf(Xml::child($layoutSp, 'spPr')) : null) ?? ($masterSp !== null ? $this->xfrmOf(Xml::child($masterSp, 'spPr')) : null);
        $cNv = Xml::child($nv, 'cNvPr');
        $elName = (string) Xml::attr($cNv, 'name', 'shape');
        if ($xf === null) {
            $hasText = trim(Xml::child($sp, 'txBody')?->textContent ?? '') !== '';
            if ($hasText) {
                $this->rep(null, 'unsupported', 'The shape "'.mb_substr($elName, 0, 60).'" has text but no position, so it was left out.');
            }

            return;
        }
        // Geometry.
        $geom = Xml::child($spPr, 'prstGeom');
        $prst = Xml::attr($geom, 'prst');
        if (in_array($prst, ['wedgeRectCallout', 'wedgeRoundRectCallout'], true)) {
            // The preset's body is the whole box and its tail lies outside it; the spec's callout keeps its tail inside the box.
            $xf['h'] = $xf['h'] / ShapeGeometry::CALLOUT_BODY;
        }
        $pl = $this->place($m, $xf['x'], $xf['y'], $xf['w'], $xf['h'], $xf['rot'], $xf['fh'], $xf['fv']);
        $cust = $geom === null ? Xml::child($spPr, 'custGeom') : null;
        if ($cust !== null) {
            $prst = 'rect';
        } elseif ($prst === null && $ph !== null) {
            $prst = 'rect';
        }
        $prst ??= 'rect';
        $kind = self::PRESET[$prst] ?? null;
        if ($kind === null) {
            $this->rep(null, 'approximated', 'The shape "'.$prst.'" was imported as a rectangle.');
            $kind = 'rect';
        }

        // Fill, line and effects with placeholder and style fallbacks.
        $style = Xml::child($sp, 'style');
        $fillRef = Xml::child($style, 'fillRef');
        $lnRef = Xml::child($style, 'lnRef');
        $fontRef = Xml::child($style, 'fontRef');
        $refColor = fn (?DOMElement $r) => $r !== null ? $this->ctx['colors']->spec($r) : null;
        $fill = $this->fillOf($spPr)
            ?? ($layoutSp !== null ? $this->fillOf(Xml::child($layoutSp, 'spPr')) : null)
            ?? ($masterSp !== null ? $this->fillOf(Xml::child($masterSp, 'spPr')) : null);
        if ($fill === null && $fillRef !== null && Xml::int($fillRef, 'idx') > 0) {
            $c = $refColor($fillRef);
            $fill = $c !== null ? ['mode' => 'solid', 'value' => $c] : null;
        }
        $line = $this->lineOf($spPr) ?? ($layoutSp !== null ? $this->lineOf(Xml::child($layoutSp, 'spPr')) : null);
        if ($line === null && $lnRef !== null && Xml::int($lnRef, 'idx') > 0) {
            $c = $refColor($lnRef);
            $line = ['stroke' => $c, 'sw' => round($this->theme['lnW'][min(3, max(1, Xml::int($lnRef, 'idx')))] ?? 1.0, 2), 'dash' => 'solid', 'ends' => ['s' => 'none', 'e' => 'none'], 'none' => false];
        }
        $shadow = $this->shadowOf($spPr);
        $alt3d = Xml::child($spPr, 'sp3d');
        if ($alt3d !== null && (Xml::child($alt3d, 'bevelT') !== null || Xml::int($alt3d, 'extrusionH') > 0)) {
            $this->rep(null, 'approximated', '3D formatting of "'.mb_substr($elName, 0, 60).'" was dropped.');
        }
        if (($xf['fh'] || $xf['fv']) && ! in_array($kind, ['line'], true) && $m === $this->identity()) {
            $this->rep(null, 'approximated', 'A flip on "'.mb_substr($elName, 0, 60).'" was not imported.');
        }

        // Lines.
        if ($kind === 'line') {
            $this->emitLine($sp, $nv, $spPr, $xf, $m, $group, $decoration, $label, $line, $fillRef !== null ? $refColor($lnRef) : null);

            return;
        }

        // Text.
        $txBody = Xml::child($sp, 'txBody');
        $text = null;
        $role = null;
        if ($ph !== null) {
            $role = match ($ph['type'] ?? 'body') {
                'title', 'ctrTitle' => 'title',
                'subTitle' => 'subtitle',
                'body', 'obj' => 'body',
                default => null,
            };
        }
        $bodyPr = $this->bodyPr($sp, $layoutSp, $masterSp);
        if ($txBody !== null) {
            $fontScale = 1.0;
            $lnRed = 0.0;
            $na = Xml::child($bodyPr, 'normAutofit');
            if ($na !== null) {
                $fontScale = Xml::attr($na, 'fontScale') !== null ? max(0.1, min(1.0, Xml::int($na, 'fontScale', 100000) / 100000)) : 1.0;
                $lnRed = Xml::attr($na, 'lnSpcReduction') !== null ? max(0.0, min(0.5, Xml::int($na, 'lnSpcReduction') / 100000)) : 0.0;
            }
            $defaultColor = $fontRef !== null ? $refColor($fontRef) : null;
            if ($ph === null && Xml::attr(Xml::child($nv, 'cNvSpPr'), 'txBox') === '1') {
                $defaultColor = null;
            }
            /** @var TextImporter $ti */
            $ti = $this->ctx['text'];
            $text = $ti->parse($txBody, $this->textChain($sp, $ph, $layoutSp, $masterSp), $defaultColor, $fontScale, $lnRed);
            if ($text['equation']) {
                $this->rep(null, 'unsupported', 'An equation in "'.mb_substr($elName, 0, 60).'" was not imported.');
            }
            if ($bodyPr !== null) {
                $warp = Xml::attr(Xml::child($bodyPr, 'prstTxWarp'), 'prst');
                if ($warp !== null && $warp !== 'textNoShape') {
                    $this->rep(null, 'unsupported', 'WordArt text effect "'.$warp.'" was dropped; the text is imported as plain text.');
                }
                if (Xml::attr($bodyPr, 'vert', 'horz') !== 'horz') {
                    $this->rep(null, 'approximated', 'Vertical text in "'.mb_substr($elName, 0, 60).'" is imported horizontally.');
                }
            }
        }
        $hasText = $text !== null && trim($text['text']) !== '';
        $fillValue = $fill !== null && $fill['mode'] !== 'none' ? $fill['value'] : null;
        if ($fill !== null && $fill['mode'] === 'blip') {
            $this->rep(null, 'unsupported', 'A picture fill on "'.mb_substr($elName, 0, 60).'" was not imported.');
            $fillValue = null;
        }
        $stroke = $line !== null && ! ($line['none'] ?? false) && $line['stroke'] !== null ? $line : null;

        // A freeform becomes a picture of its outline; any text in it stays a text box on top.
        if ($cust !== null) {
            $drawn = $this->freeform($cust, $nv, $pl, $fill, $stroke, $decoration, $label, $group, $elName);
            if ($drawn) {
                $fillValue = null;
                $stroke = null;
            }
        }

        // An empty placeholder is a prompt, not content.
        if ($ph !== null && ! $hasText && $fillValue === null && $stroke === null) {
            return;
        }

        $isTextBox = $hasText && in_array($kind, ['rect', 'round'], true);
        if ($isTextBox) {
            $el = $this->common($nv, 'text', $pl, $decoration, $label) + [];
            $p = $text['props'];
            $el += ['font' => $p['font'], 'size' => $p['size'], 'weight' => $p['weight'], 'color' => $p['color'], 'align' => $p['align'], 'lh' => $p['lh'], 'ls' => 0, 'ps' => $p['ps'], 'doc' => $text['doc']];
            $el += $this->textBoxProps($bodyPr, $kind === 'round' ? $this->roundRadius($geom, $pl) : 0.0);
            if ($role !== null) {
                $el['role'] = $role;
            }
            $el['fx'] = 'none';
            if ($fillValue !== null) {
                $el['fill'] = $fillValue;
            }
            if ($stroke !== null) {
                $el['stroke'] = $stroke['stroke'];
                $el['sw'] = $stroke['sw'];
                if ($stroke['dash'] !== 'solid') {
                    $el['dash'] = $stroke['dash'];
                }
            }
            if ($shadow !== null) {
                $el['shadow'] = $shadow;
            }
            $this->attachLink($el, $nv);
            $this->push($el, $group);

            return;
        }
        // A plain rectangle with no text and no look is nothing to draw.
        if (! $hasText && $fillValue === null && $stroke === null) {
            return;
        }
        $el = $this->common($nv, 'shape', $pl, $decoration, $label);
        $el['kind'] = $kind;
        $el['fill'] = $fillValue;
        if ($kind === 'round') {
            $el['radius'] = $this->roundRadius($geom, $pl);
        }
        if ($stroke !== null) {
            $el['stroke'] = $stroke['stroke'];
            $el['sw'] = $stroke['sw'];
            $el['dash'] = $stroke['dash'];
        }
        if ($shadow !== null) {
            $el['shadow'] = $shadow;
        }
        if ($hasText) {
            $p = $text['props'];
            $el += ['font' => $p['font'], 'size' => $p['size'], 'weight' => $p['weight'], 'color' => $p['color'], 'align' => $p['align'], 'lh' => $p['lh'], 'ls' => 0, 'ps' => $p['ps'], 'doc' => $text['doc']];
            $el += $this->textBoxProps($bodyPr, 0.0);
            unset($el['radius']);
            if ($kind === 'round') {
                $el['radius'] = $this->roundRadius($geom, $pl);
            }
        }
        if ($role !== null) {
            $el['role'] = $role;
        }
        $this->attachLink($el, $nv);
        $this->push($el, $group);
    }

    /**
     * @param  array<string, mixed>  $pl
     * @param  array{mode: string, value: mixed}|null  $fill
     * @param  array<string, mixed>|null  $stroke
     */
    private function freeform(DOMElement $cust, ?DOMElement $nv, array $pl, ?array $fill, ?array $stroke, bool $decoration, string $label, ?string $group, string $elName): bool
    {
        $fillSpec = $fill !== null && in_array($fill['mode'], ['solid', 'grad'], true) ? $fill['value'] : null;
        if (is_array($fillSpec)) {
            $fillSpec = $fillSpec['stops'][0][1] ?? null;
        }
        [$fillHex, $fillAlpha] = $this->hexOf(is_string($fillSpec) ? $fillSpec : null);
        [$strokeHex, $strokeAlpha] = $this->hexOf($stroke['stroke'] ?? null);
        $png = FreeformRasterizer::render($cust, (float) $pl['w'], (float) $pl['h'], $fillHex, $fillAlpha, $strokeHex, $strokeAlpha, (float) ($stroke['sw'] ?? 0));
        if ($png === null) {
            $this->rep(null, 'approximated', 'The freeform shape "'.mb_substr($elName, 0, 60).'" has nothing to draw and was left out.');

            return true;
        }
        if ($this->mediaBytes + strlen($png) > 150 * 1048576) {
            $this->rep(null, 'unsupported', 'The freeform shape "'.mb_substr($elName, 0, 60).'" was left out because the pictures are too large.');

            return true;
        }
        $key = 'freeform:'.$this->slideNo.':'.($this->seq + 1);
        $this->mediaBytes += strlen($png);
        $this->media[$key] = ['name' => 'freeform-'.$this->slideNo.'-'.($this->seq + 1).'.png', 'mime' => 'image/png', 'size' => strlen($png), 'bytes' => $png];
        $el = $this->common($nv ?? $cust, 'image', $pl, $decoration, $label);
        $el['src'] = ['import' => $key];
        $el['fit'] = 'fill';
        $el['mask'] = 'none';
        if (! empty($pl['flipH'])) {
            $el['flipH'] = true;
        }
        if (! empty($pl['flipV'])) {
            $el['flipV'] = true;
        }
        $this->push($el, $group);
        $this->rep(null, 'approximated', 'The freeform shape "'.mb_substr($elName, 0, 60).'" was imported as a picture of its outline.');

        return true;
    }

    /** A spec colour as six hex digits (with "#") and its alpha. @return array{0: ?string, 1: float} */
    private function hexOf(?string $spec): array
    {
        if ($spec === null || $spec === '') {
            return [null, 1.0];
        }
        if (str_starts_with($spec, '@')) {
            $slot = array_search($spec, ['dk1' => '@ink', 'lt1' => '@bg', 'dk2' => '@muted', 'lt2' => '@surface', 'accent1' => '@accent', 'accent2' => '@accent2', 'accent3' => '@chart3', 'accent4' => '@chart4', 'accent5' => '@chart5', 'accent6' => '@chart6'], true);
            $hex = $slot !== false ? ($this->theme['scheme'][$slot] ?? null) : null;

            return [$hex !== null ? '#'.strtolower($hex) : null, 1.0];
        }
        if (preg_match('/^#([0-9a-f]{6})([0-9a-f]{2})?$/i', $spec, $m)) {
            return ['#'.strtolower($m[1]), isset($m[2]) ? hexdec($m[2]) / 255 : 1.0];
        }

        return [null, 1.0];
    }

    private function roundRadius(?DOMElement $geom, array $pl): float
    {
        $adj = 16667.0;
        foreach (Xml::children(Xml::child($geom, 'avLst'), 'gd') as $gd) {
            if (Xml::attr($gd, 'name') === 'adj' && preg_match('/val\s+(-?\d+)/', (string) Xml::attr($gd, 'fmla'), $mm)) {
                $adj = (float) $mm[1];
            }
        }

        return round(min($pl['w'], $pl['h']) * max(0.0, min(50000.0, $adj)) / 100000, 2);
    }

    /** The body properties of the shape, falling back to its layout and master placeholders. */
    private function bodyPr(DOMElement $sp, ?DOMElement $layoutSp, ?DOMElement $masterSp): ?DOMElement
    {
        // Merge by attribute: the slide's own bodyPr wins, then layout, then master. Children (autofit) come from the first that has one.
        $own = Xml::child(Xml::child($sp, 'txBody'), 'bodyPr');
        $l = $layoutSp !== null ? Xml::child(Xml::child($layoutSp, 'txBody'), 'bodyPr') : null;
        $mm = $masterSp !== null ? Xml::child(Xml::child($masterSp, 'txBody'), 'bodyPr') : null;
        $sources = array_values(array_filter([$own, $l, $mm]));
        if ($sources === []) {
            return null;
        }
        if (count($sources) === 1) {
            return $sources[0];
        }
        $doc = new DOMDocument;
        $merged = $doc->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'a:bodyPr');
        $doc->appendChild($merged);
        foreach (array_reverse($sources) as $s) {
            foreach ($s->attributes as $a) {
                $merged->setAttribute($a->name, $a->value);
            }
        }
        foreach ($sources as $s) {
            foreach (['normAutofit', 'spAutoFit', 'noAutofit', 'prstTxWarp'] as $k) {
                $c = Xml::child($s, $k);
                if ($c !== null && Xml::child($merged, $k) === null && ! ($k !== 'prstTxWarp' && (Xml::child($merged, 'normAutofit') || Xml::child($merged, 'spAutoFit') || Xml::child($merged, 'noAutofit')))) {
                    $merged->appendChild($doc->importNode($c, true));
                }
            }
        }

        return $merged;
    }

    /** @return array<string, mixed> valign, fit, pad (and radius when given) */
    private function textBoxProps(?DOMElement $bodyPr, float $radius): array
    {
        $anchor = Xml::attr($bodyPr, 'anchor', 't');
        $ins = [Xml::int($bodyPr, 'lIns', 91440), Xml::int($bodyPr, 'tIns', 45720), Xml::int($bodyPr, 'rIns', 91440), Xml::int($bodyPr, 'bIns', 45720)];
        $props = [
            'valign' => match ($anchor) {
                'ctr' => 'middle',
                'b' => 'bottom',
                default => 'top',
            },
            'fit' => Xml::child($bodyPr, 'normAutofit') !== null ? 'shrink' : 'none',
            'pad' => round(array_sum($ins) / 4 / 9525, 2),
        ];
        if ($radius > 0) {
            $props['radius'] = $radius;
        }

        return $props;
    }

    private function attachLink(array &$el, ?DOMElement $nv): void
    {
        $h = Xml::child(Xml::child($nv, 'cNvPr'), 'hlinkClick');
        $rid = Xml::attrLocal($h, 'id');
        if ($rid !== null && $rid !== '') {
            $l = $this->linkFor($rid, $this->ctx['rels'] ?? []);
            if ($l !== null) {
                $el['link'] = $l;
            }
        }
    }

    /** @param array<string, array{type: string, target: string, external: bool}> $rels */
    private function linkFor(string $rid, array $rels): ?string
    {
        $r = $rels[$rid] ?? null;
        if ($r === null) {
            return null;
        }
        if ($r['external']) {
            if (preg_match('#^https://[^\s<>"\']{1,2000}$#i', $r['target'])) {
                return $r['target'];
            }
            $this->rep(null, 'approximated', 'A link that is not an https address was not imported.');

            return null;
        }
        if (str_ends_with($r['type'], '/slide')) {
            $id = $this->slideIds[$r['target']] ?? null;

            return $id !== null ? '#slide:'.$id : null;
        }

        return null;
    }

    /** @param array<string, mixed> $el */
    private function push(array $el, ?string $group): void
    {
        if ($group !== null) {
            $el['group'] = $group;
        }
        $this->els[] = $el;
    }

    // ------------------------------------------------------------------ connectors and lines

    /** @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $m */
    private function cxn(DOMElement $c, array $m, ?string $group, bool $decoration, string $label): void
    {
        $spPr = Xml::child($c, 'spPr');
        $xf = $this->xfrmOf($spPr);
        if ($xf === null) {
            return;
        }
        $line = $this->lineOf($spPr);
        $style = Xml::child($c, 'style');
        $lnRef = Xml::child($style, 'lnRef');
        $this->emitLine($c, Xml::child($c, 'nvCxnSpPr'), $spPr, $xf, $m, $group, $decoration, $label, $line, $lnRef !== null ? $this->ctx['colors']->spec($lnRef) : null);
    }

    /**
     * A straight line as a horizontal line of the right length, rotated about the centre: that keeps its direction (which
     * end is the start) exactly, which the spec's line shape (a box diagonal) cannot say for a mirrored line.
     *
     * @param  array<string, mixed>  $xf
     * @param  array<string, mixed>|null  $line
     * @param  array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}  $m
     */
    private function emitLine(DOMElement $node, ?DOMElement $nv, ?DOMElement $spPr, array $xf, array $m, ?string $group, bool $decoration, string $label, ?array $line, ?string $styleColor): void
    {
        $x1 = $xf['x'] + ($xf['fh'] ? $xf['w'] : 0);
        $y1 = $xf['y'] + ($xf['fv'] ? $xf['h'] : 0);
        $x2 = $xf['x'] + ($xf['fh'] ? 0 : $xf['w']);
        $y2 = $xf['y'] + ($xf['fv'] ? 0 : $xf['h']);
        // rotate the segment about the box centre by the shape's rotation, then through the group matrix
        $cx = $xf['x'] + $xf['w'] / 2;
        $cy = $xf['y'] + $xf['h'] / 2;
        $r = deg2rad($xf['rot']);
        $rotPt = fn (float $x, float $y) => [$cx + ($x - $cx) * cos($r) - ($y - $cy) * sin($r), $cy + ($x - $cx) * sin($r) + ($y - $cy) * cos($r)];
        [$x1, $y1] = $rotPt($x1, $y1);
        [$x2, $y2] = $rotPt($x2, $y2);
        $tp = fn (float $x, float $y) => [$m[0] * $x + $m[2] * $y + $m[4], $m[1] * $x + $m[3] * $y + $m[5]];
        [$x1, $y1] = $tp($x1, $y1);
        [$x2, $y2] = $tp($x2, $y2);
        $len = hypot($x2 - $x1, $y2 - $y1);
        $ang = rad2deg(atan2($y2 - $y1, $x2 - $x1));
        $mx = ($x1 + $x2) / 2;
        $my = ($y1 + $y2) / 2;
        $pl = ['x' => round($mx - $len / 2, 2), 'y' => round($my, 2), 'w' => round($len, 2), 'h' => 0, 'rot' => fmod(fmod($ang, 360) + 360, 360)];
        $stroke = $line['stroke'] ?? $styleColor ?? '@ink';
        if ($line !== null && ($line['none'] ?? false)) {
            return;
        }
        $ends = $line['ends'] ?? ['s' => 'none', 'e' => 'none'];
        $el = $this->common($nv ?? $node, 'shape', $pl, $decoration, $label);
        $el['kind'] = ($ends['s'] !== 'none' || $ends['e'] !== 'none') ? 'arrow' : 'line';
        $el['stroke'] = $stroke;
        $el['sw'] = $line['sw'] ?? 1.5;
        $el['dash'] = $line['dash'] ?? 'solid';
        $el['ends'] = $ends;
        $this->push($el, $group);
    }

    // ------------------------------------------------------------------ pictures

    private function loadMedia(string $part): ?string
    {
        if (isset($this->media[$part])) {
            return $part;
        }
        $ext = strtolower(pathinfo($part, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg', 'jpe' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml',
            default => null,
        };
        if ($mime === null) {
            $this->rep(null, 'unsupported', 'A picture in the format ".'.($ext ?: 'unknown').'" cannot be imported.');

            return null;
        }
        if (! $this->zip->has($part)) {
            return null;
        }
        if ($this->mediaBytes + $this->zip->size($part) > 150 * 1048576) {
            $this->rep(null, 'unsupported', 'The pictures in this presentation are too large to import in one go; the rest were left out.');

            return null;
        }
        $bytes = $this->zip->read($part);
        if ($bytes === null || $bytes === '') {
            return null;
        }
        // Check the bytes are the image the name claims (and not a script).
        $sniff = @getimagesizefromstring($bytes);
        if ($mime !== 'image/svg+xml' && $sniff === false) {
            $this->rep(null, 'unsupported', 'A picture that is not a valid image was left out.');

            return null;
        }
        if ($mime === 'image/svg+xml') {
            return null; // svg is only ever a companion of a raster fallback
        }
        $mime = $sniff['mime'] ?? $mime;
        $this->mediaBytes += strlen($bytes);
        $this->media[$part] = ['name' => basename($part), 'mime' => $mime, 'size' => strlen($bytes), 'bytes' => $bytes];

        return $part;
    }

    /** @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $m */
    private function pic(DOMElement $pic, array $m, ?string $group, bool $decoration, string $label): void
    {
        $nv = Xml::child($pic, 'nvPicPr');
        $spPr = Xml::child($pic, 'spPr');
        $xf = $this->xfrmOf($spPr);
        if ($xf === null) {
            return;
        }
        $pl = $this->place($m, $xf['x'], $xf['y'], $xf['w'], $xf['h'], $xf['rot'], $xf['fh'], $xf['fv']);
        $blip = Xml::child(Xml::child($pic, 'blipFill'), 'blip');
        $rid = Xml::attrLocal($blip, 'embed');
        $nvPr = Xml::child($nv, 'nvPr');
        $isVideo = Xml::find($nvPr, 'videoFile') !== null || Xml::find($nvPr, 'wavAudioFile') !== null || Xml::find($nvPr, 'audioFile') !== null;
        if ($isVideo) {
            $this->rep(null, 'unsupported', 'Video and audio were not imported; the poster picture was kept.');
        }
        $rel = $rid !== null ? ($this->ctx['rels'][$rid] ?? null) : null;
        if ($rel === null) {
            $this->rep(null, 'unsupported', 'A picture whose image is missing from the file was left out.');

            return;
        }
        $name = $this->loadMedia($rel['target']);
        if ($name === null) {
            return;
        }
        $el = $this->common($nv, 'image', $pl, $decoration, $label);
        $el['src'] = ['import' => $name];
        $sr = Xml::child(Xml::child($pic, 'blipFill'), 'srcRect');
        $l = Xml::int($sr, 'l') / 100000;
        $t = Xml::int($sr, 't') / 100000;
        $r = Xml::int($sr, 'r') / 100000;
        $b = Xml::int($sr, 'b') / 100000;
        if ($sr !== null && ($l > 0 || $t > 0 || $r > 0 || $b > 0)) {
            $l = max(0.0, $l);
            $t = max(0.0, $t);
            $cw = max(0.01, 1 - $l - max(0.0, $r));
            $ch = max(0.01, 1 - $t - max(0.0, $b));
            $el['crop'] = ['x' => round($l, 4), 'y' => round($t, 4), 'w' => round($cw, 4), 'h' => round($ch, 4)];
        }
        $el['fit'] = 'fill';
        $geom = Xml::attr(Xml::child($spPr, 'prstGeom'), 'prst', 'rect');
        $el['mask'] = match ($geom) {
            'ellipse' => (abs($pl['w'] - $pl['h']) < 1 ? 'circle' : 'ellipse'),
            'roundRect', 'round1Rect', 'round2SameRect', 'snipRoundRect' => 'round',
            'triangle' => 'triangle',
            'star5' => 'star',
            'hexagon' => 'hexagon',
            default => 'none',
        };
        if ($el['mask'] === 'none' && ! in_array($geom, ['rect'], true)) {
            $this->rep(null, 'approximated', 'The picture shape "'.$geom.'" was not imported; the picture is a plain rectangle.');
        }
        $adj = [];
        $lum = Xml::child($blip, 'lum');
        if ($lum !== null) {
            $adj['br'] = round(Xml::int($lum, 'bright') / 1000, 1);
            $adj['ct'] = round(Xml::int($lum, 'contrast') / 1000, 1);
        }
        if (Xml::child($blip, 'grayscl') !== null) {
            $adj['gray'] = 100;
        }
        if ($adj) {
            $el['adj'] = array_filter($adj, fn ($v) => $v != 0);
            if ($el['adj'] === []) {
                unset($el['adj']);
            }
        }
        $amf = Xml::child($blip, 'alphaModFix');
        if ($amf !== null) {
            $el['op'] = round(max(0.0, min(1.0, Xml::int($amf, 'amt', 100000) / 100000)), 3);
        }
        foreach (['duotone', 'clrChange', 'biLevel', 'hsl', 'fillOverlay', 'tint'] as $eff) {
            if (Xml::child($blip, $eff) !== null) {
                $this->rep(null, 'approximated', 'A picture effect ("'.$eff.'") was not imported.');
            }
        }
        if (Xml::child($spPr, 'ln') !== null && Xml::find(Xml::child($spPr, 'ln'), 'solidFill') !== null) {
            $this->rep(null, 'approximated', 'A picture border was not imported.');
        }
        if ($xf['fh']) {
            $el['flipH'] = true;
        }
        if ($xf['fv'] || ($pl['flipV'] && ! $xf['fv'])) {
            $el['flipV'] = true;
        }
        $shadow = $this->shadowOf($spPr);
        if ($shadow !== null) {
            $el['shadow'] = $shadow;
        }
        $this->attachLink($el, $nv);
        $this->push($el, $group);
    }

    // ------------------------------------------------------------------ graphic frames: tables and charts

    /** @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $m */
    private function graphicFrame(DOMElement $gf, array $m, ?string $group): void
    {
        $nv = Xml::child($gf, 'nvGraphicFramePr');
        $xf = $this->xfrmOf($gf);
        $data = Xml::find($gf, 'graphicData');
        $uri = (string) Xml::attr($data, 'uri', '');
        $name = mb_substr((string) Xml::attr(Xml::child($nv, 'cNvPr'), 'name', 'object'), 0, 60);
        if ($xf === null) {
            $this->rep(null, 'unsupported', 'The object "'.$name.'" has no position and was left out.');

            return;
        }
        $pl = $this->place($m, $xf['x'], $xf['y'], $xf['w'], $xf['h'], 0, false, false);
        if (str_ends_with($uri, '/table')) {
            $tbl = Xml::child($data, 'tbl');
            if ($tbl !== null) {
                $this->table($tbl, $nv, $pl, $group);
            }

            return;
        }
        if (str_ends_with($uri, '/chart')) {
            $ch = Xml::child($data, 'chart');
            $rid = Xml::attrLocal($ch, 'id');
            $rel = $rid !== null ? ($this->ctx['rels'][$rid] ?? null) : null;
            $dom = $rel !== null ? $this->dom($rel['target']) : null;
            if ($dom === null) {
                $this->rep(null, 'unsupported', 'The chart "'.$name.'" could not be read and was left out.');

                return;
            }
            $ci = new ChartImporter($this->ctx['colors']);
            $chart = $ci->parse($dom);
            foreach ($ci->notes as $n) {
                $this->rep(null, $chart === null ? 'unsupported' : 'approximated', $n);
            }
            if ($chart === null) {
                return;
            }
            $el = $this->common($nv, 'chart', $pl, false, '');
            $el['chart'] = $chart;
            $this->push($el, $group);

            return;
        }
        if (str_contains($uri, '/diagram')) {
            $this->smartArt($name, $xf, $m, $group);

            return;
        }
        if (str_contains($uri, '/ole') || $uri === 'http://schemas.openxmlformats.org/presentationml/2006/ole') {
            $this->rep(null, 'unsupported', 'The embedded object "'.$name.'" (OLE) was not imported.');

            return;
        }
        $this->rep(null, 'unsupported', 'The object "'.$name.'" ('.mb_substr($uri, -30).') was not imported.');
    }

    /**
     * SmartArt keeps a cached drawing of itself next to its data. The shapes of that drawing are imported as ordinary
     * shapes (editable, grouped); the SmartArt layout itself cannot be carried over.
     *
     * @param  array<string, mixed>  $xf
     * @param  array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}  $m
     */
    private function smartArt(string $name, array $xf, array $m, ?string $group): void
    {
        $drawing = null;
        foreach ($this->ctx['rels'] as $r) {
            if (str_ends_with($r['type'], '/diagramDrawing')) {
                $drawing = $this->dom($r['target']);
            }
        }
        $tree = $drawing !== null ? Xml::find($drawing->documentElement, 'spTree') : null;
        if ($tree === null) {
            $this->rep(null, 'unsupported', 'The SmartArt graphic "'.$name.'" has no cached drawing and was not imported.');

            return;
        }
        $before = count($this->els);
        $gid = $group ?? 'g'.(++$this->groupSeq);
        // The cached shapes are positioned relative to the top left of the graphic frame.
        $shift = $this->mul($m, [1.0, 0.0, 0.0, 1.0, $xf['x'], $xf['y']]);
        $this->walkTree($tree, $shift, $gid, false);
        $this->rep(null, 'approximated', 'The SmartArt graphic "'.$name.'" was imported as the shapes of its cached drawing ('.(count($this->els) - $before).' elements); the SmartArt layout is not kept.');
    }

    /** @param array<string, mixed> $pl */
    private function table(DOMElement $tbl, ?DOMElement $nv, array $pl, ?string $group): void
    {
        $grid = [];
        foreach (Xml::children(Xml::child($tbl, 'tblGrid'), 'gridCol') as $g) {
            $grid[] = max(1, Xml::int($g, 'w', 914400));
        }
        $cols = max(1, min(40, count($grid)));
        $grid = array_slice($grid, 0, $cols);
        $sum = array_sum($grid) ?: 1;
        $colw = array_map(fn ($v) => round($v / $sum, 4), $grid);
        $rows = [];
        $rowh = [];
        $sizes = [];
        $anyBorder = false;
        foreach (array_slice(Xml::children($tbl, 'tr'), 0, 200) as $tr) {
            $rowh[] = round(Xml::int($tr, 'h', 370840) / 9525, 2);
            $row = [];
            foreach (Xml::children($tr, 'tc') as $tc) {
                $cell = ['t' => ''];
                $span = [max(1, Xml::int($tc, 'rowSpan', 1)), max(1, Xml::int($tc, 'gridSpan', 1))];
                if ($span !== [1, 1]) {
                    $cell['span'] = $span;
                }
                $covered = Xml::attr($tc, 'hMerge') === '1' || Xml::attr($tc, 'vMerge') === '1';
                $tx = Xml::child($tc, 'txBody');
                if ($tx !== null) {
                    $lines = [];
                    $first = null;
                    foreach (Xml::children($tx, 'p') as $p) {
                        $line = '';
                        foreach (Xml::children($p) as $r) {
                            if ($r->localName === 'r' || $r->localName === 'fld') {
                                $line .= Xml::child($r, 't')?->textContent ?? '';
                                $first ??= $r;
                            } elseif ($r->localName === 'br') {
                                $line .= "\n";
                            }
                        }
                        $lines[] = $line;
                    }
                    $cell['t'] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', trim(implode("\n", $lines))) ?? '';
                    $rPr = Xml::child($first, 'rPr');
                    if ($rPr !== null) {
                        if (Xml::attr($rPr, 'b') === '1') {
                            $cell['b'] = true;
                        }
                        if (Xml::attr($rPr, 'i') === '1') {
                            $cell['i'] = true;
                        }
                        if (Xml::attr($rPr, 'sz') !== null) {
                            $sizes[] = Xml::int($rPr, 'sz') / 100 / 0.75;
                        }
                        $col = $this->ctx['colors']->spec(Xml::child($rPr, 'solidFill'));
                        if ($col !== null && $col !== '@ink') {
                            $cell['color'] = $col;
                        }
                    }
                    $algn = Xml::attr(Xml::child(Xml::child($tx, 'p'), 'pPr'), 'algn');
                    if ($algn === 'ctr') {
                        $cell['al'] = 'c';
                    } elseif ($algn === 'r') {
                        $cell['al'] = 'r';
                    }
                }
                $tcPr = Xml::child($tc, 'tcPr');
                $fill = $this->fillOf($tcPr);
                if ($fill !== null && $fill['mode'] === 'solid') {
                    $cell['fill'] = $fill['value'];
                }
                foreach (['lnL', 'lnR', 'lnT', 'lnB'] as $b) {
                    if (Xml::find(Xml::child($tcPr, $b), 'solidFill') !== null) {
                        $anyBorder = true;
                    }
                }
                if ($covered) {
                    $cell = ['t' => ''];
                }
                $row[] = $cell;
            }
            $row = array_slice($row, 0, $cols);
            while (count($row) < $cols) {
                $row[] = ['t' => ''];
            }
            $rows[] = $row;
        }
        if ($rows === []) {
            return;
        }
        $pr = Xml::child($tbl, 'tblPr');
        $el = $this->common($nv, 'table', $pl + ['rot' => 0], false, '');
        $el += [
            'rows' => $rows, 'colw' => $colw, 'rowh' => $rowh,
            'header' => Xml::attr($pr, 'firstRow') === '1', 'band' => Xml::attr($pr, 'bandRow') === '1',
            'size' => $sizes ? round(array_sum($sizes) / count($sizes), 1) : 24, 'font' => '@body', 'lines' => $anyBorder ? 'grid' : 'rows',
        ];
        if (Xml::child($pr, 'tableStyleId') !== null) {
            $this->rep(null, 'approximated', 'The table style was not imported; cell fills and text formatting were.');
        }
        $this->push($el, $group);
    }
}
