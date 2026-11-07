<?php

namespace App\Services\Press\Export\Pptx;

use App\Services\Press\Export\Support\ChartData;
use App\Services\Press\Export\Support\Els;
use App\Services\Press\Export\Support\Theme;

/**
 * Native DrawingML chart parts (chart XML plus the workbook behind "Edit data") for the spec chart types.
 * waterfall is drawn as stacked columns with a hidden base series and funnel as stacked bars with a hidden spacer;
 * kpi and map are not charts and are handled by the slide writer.
 */
final class PptxChart
{
    public const NATIVE = ['bar', 'column', 'line', 'area', 'pie', 'doughnut', 'scatter', 'bubble', 'radar', 'combo', 'waterfall', 'funnel'];

    /** @var list<list<string|int|float|null>> */
    private array $rows = [];

    private int $axA = 111111111;

    private int $axB = 222222222;

    public function __construct(private Theme $theme, private float $w, private float $h) {}

    /**
     * @param  array<string, mixed>  $chart  a normalised chart
     * @return array{xml: string, xlsx: string}
     */
    public function build(array $chart): array
    {
        $type = $chart['type'];
        $plot = match (true) {
            in_array($type, ['pie', 'doughnut'], true) => $this->pie($chart),
            $type === 'scatter' || $type === 'bubble' => $this->scatter($chart),
            $type === 'radar' => $this->radar($chart),
            $type === 'waterfall' => $this->waterfall($chart),
            $type === 'funnel' => $this->funnel($chart),
            default => $this->cartesian($chart),
        };
        $opt = $chart['opt'];
        $fs = max(10.0, min(36.0, min($this->h * 0.045, $this->w * 0.04)));
        $sz = (int) round($fs * 0.75 * 100);

        $x = Ooxml::head().'<c:chartSpace xmlns:c="'.Ooxml::NS_C.'" xmlns:a="'.Ooxml::NS_A.'" xmlns:r="'.Ooxml::NS_R.'"><c:date1904 val="0"/><c:roundedCorners val="0"/><c:chart>';
        $x .= isset($opt['title']) ? $this->title($opt['title'], $opt['sub'] ?? null, $sz) : '<c:autoTitleDeleted val="1"/>';
        $x .= $plot;
        $legend = $opt['legend'] ?? 'none';
        $hasLegend = $legend !== 'none' && ! ($type === 'funnel');
        if ($hasLegend) {
            $pos = ['bottom' => 'b', 'right' => 'r', 'top' => 't'][$legend] ?? 'b';
            $x .= '<c:legend><c:legendPos val="'.$pos.'"/>'.$this->legendDeletes($chart).'<c:overlay val="0"/>'.$this->txPr((int) round($sz * 0.92)).'</c:legend>';
        }
        $x .= '<c:plotVisOnly val="1"/><c:dispBlanksAs val="gap"/></c:chart>';
        $x .= '<c:spPr><a:noFill/><a:ln><a:noFill/></a:ln></c:spPr>';
        $x .= $this->txPr($sz, true);
        $x .= '<c:externalData r:id="rId1"><c:autoUpdate val="0"/></c:externalData></c:chartSpace>';

        return ['xml' => $x, 'xlsx' => XlsxWriter::build($this->rows)];
    }

    // ------------------------------------------------------------------ plots

    private function cartesian(array $chart): string
    {
        $type = $chart['type'];
        $opt = $chart['opt'];
        $cats = $chart['data']['categories'];
        $series = $chart['data']['series'];
        $roles = ChartData::roles($chart);
        $colors = ChartData::seriesColors($chart, $this->theme);
        $this->catRows($cats, $series);
        $horizontal = $type === 'bar';
        $groups = ['bar' => '', 'line' => '', 'area' => ''];
        foreach ($series as $i => $s) {
            $groups[$roles[$i]] .= $this->serXml($roles[$i], $i, $s, $colors[$i], $cats, $opt);
        }
        $a = $this->axA;
        $b = $this->axB;
        $axIds = '<c:axId val="'.$a.'"/><c:axId val="'.$b.'"/>';
        $x = '<c:plotArea><c:layout/>';
        if ($groups['area'] !== '') {
            $x .= '<c:areaChart><c:grouping val="'.($opt['stacked'] ? 'stacked' : 'standard').'"/><c:varyColors val="0"/>'.$groups['area'].$axIds.'</c:areaChart>';
        }
        if ($groups['bar'] !== '') {
            $x .= '<c:barChart><c:barDir val="'.($horizontal ? 'bar' : 'col').'"/><c:grouping val="'.($opt['stacked'] ? 'stacked' : 'clustered').'"/><c:varyColors val="0"/>'.$groups['bar'].$this->dLbls($opt, false).'<c:gapWidth val="60"/>'.($opt['stacked'] ? '<c:overlap val="100"/>' : '').$axIds.'</c:barChart>';
        }
        if ($groups['line'] !== '') {
            $x .= '<c:lineChart><c:grouping val="standard"/><c:varyColors val="0"/>'.$groups['line'].$this->dLbls($opt, false).'<c:marker val="1"/>'.$axIds.'</c:lineChart>';
        }
        $x .= $this->axes($opt, $horizontal, true, $this->numFmt($opt));

        return $x.'</c:plotArea>';
    }

    private function pie(array $chart): string
    {
        $opt = $chart['opt'];
        $cats = $chart['data']['categories'];
        $s = $chart['data']['series'][0] ?? ['name' => 'Series 1', 'values' => []];
        $this->catRows($cats, [$s]);
        $palette = $this->theme->chart();
        $x = '<c:plotArea><c:layout/><c:'.($chart['type'] === 'doughnut' ? 'doughnutChart' : 'pieChart').'><c:varyColors val="1"/><c:ser><c:idx val="0"/><c:order val="0"/>'.$this->serTx(0, $s['name']);
        foreach ($cats as $i => $_) {
            $x .= '<c:dPt><c:idx val="'.$i.'"/><c:bubble3D val="0"/><c:spPr><a:solidFill><a:srgbClr val="'.strtoupper(substr($palette[$i % count($palette)], 1)).'"/></a:solidFill><a:ln w="19050"><a:solidFill>'.Ooxml::color($this->theme, '@bg').'</a:solidFill></a:ln></c:spPr></c:dPt>';
        }
        $x .= $this->dLbls($opt, true).$this->catRef($cats).$this->valRef(1, count($cats), $s['values']).'</c:ser>';
        $x .= '<c:firstSliceAng val="0"/>'.($chart['type'] === 'doughnut' ? '<c:holeSize val="58"/>' : '').'</c:'.($chart['type'] === 'doughnut' ? 'doughnutChart' : 'pieChart').'></c:plotArea>';

        return $x;
    }

    private function scatter(array $chart): string
    {
        $opt = $chart['opt'];
        $bubble = $chart['type'] === 'bubble';
        $colors = ChartData::seriesColors($chart, $this->theme);
        $cols = $bubble ? 3 : 2;
        $maxPts = 0;
        foreach ($chart['data']['series'] as $s) {
            $maxPts = max($maxPts, count($s['points'] ?? []));
        }
        $header = [];
        $this->rows = [];
        foreach ($chart['data']['series'] as $j => $s) {
            array_push($header, $s['name'].' (x)', $s['name'], ...($bubble ? [$s['name'].' (size)'] : []));
        }
        $this->rows[] = $header;
        for ($r = 0; $r < $maxPts; $r++) {
            $row = [];
            foreach ($chart['data']['series'] as $s) {
                $p = $s['points'][$r] ?? null;
                array_push($row, $p['x'] ?? null, $p['y'] ?? null, ...($bubble ? [isset($p) ? ($p['r'] ?? 1.0) : null] : []));
            }
            $this->rows[] = $row;
        }
        $x = '<c:plotArea><c:layout/><c:'.($bubble ? 'bubbleChart' : 'scatterChart').'>'.($bubble ? '' : '<c:scatterStyle val="lineMarker"/>').'<c:varyColors val="0"/>';
        foreach ($chart['data']['series'] as $j => $s) {
            $n = count($s['points'] ?? []);
            $c0 = $j * $cols;
            $col = strtoupper(substr($colors[$j], 1));
            $xs = array_column($s['points'] ?? [], 'x');
            $ys = array_column($s['points'] ?? [], 'y');
            $rs = array_map(fn ($p) => $p['r'] ?? 1.0, $s['points'] ?? []);
            $x .= '<c:ser><c:idx val="'.$j.'"/><c:order val="'.$j.'"/>'.$this->serTxRef($j * $cols + 1, $s['name']);
            if ($bubble) {
                $x .= '<c:spPr><a:solidFill><a:srgbClr val="'.$col.'"><a:alpha val="70000"/></a:srgbClr></a:solidFill><a:ln w="12700"><a:solidFill>'.Ooxml::color($this->theme, '@bg').'</a:solidFill></a:ln></c:spPr><c:invertIfNegative val="0"/>';
            } else {
                $x .= '<c:spPr><a:ln w="19050"><a:noFill/></a:ln></c:spPr><c:marker><c:symbol val="circle"/><c:size val="9"/><c:spPr><a:solidFill><a:srgbClr val="'.$col.'"/></a:solidFill><a:ln w="9525"><a:solidFill>'.Ooxml::color($this->theme, '@bg').'</a:solidFill></a:ln></c:spPr></c:marker>';
            }
            $x .= '<c:xVal>'.$this->numRefAt($c0, 1, $n, $xs).'</c:xVal><c:yVal>'.$this->numRefAt($c0 + 1, 1, $n, $ys).'</c:yVal>';
            $x .= $bubble ? '<c:bubbleSize>'.$this->numRefAt($c0 + 2, 1, $n, $rs).'</c:bubbleSize><c:bubble3D val="0"/>' : '<c:smooth val="0"/>';
            $x .= '</c:ser>';
        }
        $a = $this->axA;
        $b = $this->axB;
        if ($bubble) {
            $x .= '<c:bubbleScale val="100"/><c:showNegBubbles val="0"/>';
        }
        $x .= '<c:axId val="'.$a.'"/><c:axId val="'.$b.'"/></c:'.($bubble ? 'bubbleChart' : 'scatterChart').'>';
        $fmt = $this->numFmt($opt);
        $x .= $this->valAx($a, $b, 'b', $opt['ax']['x'] ?? [], false, $fmt, false, null, false);
        $x .= $this->valAx($b, $a, 'l', $opt['ax']['y'] ?? [], $opt['grid'], $fmt, true, null, false);

        return $x.'</c:plotArea>';
    }

    private function radar(array $chart): string
    {
        $opt = $chart['opt'];
        $cats = $chart['data']['categories'];
        $series = $chart['data']['series'];
        $colors = ChartData::seriesColors($chart, $this->theme);
        $this->catRows($cats, $series);
        $x = '<c:plotArea><c:layout/><c:radarChart><c:radarStyle val="marker"/><c:varyColors val="0"/>';
        foreach ($series as $i => $s) {
            $col = strtoupper(substr($colors[$i], 1));
            $x .= '<c:ser><c:idx val="'.$i.'"/><c:order val="'.$i.'"/>'.$this->serTx($i, $s['name']).'<c:spPr><a:ln w="28575"><a:solidFill><a:srgbClr val="'.$col.'"/></a:solidFill></a:ln></c:spPr><c:marker><c:symbol val="circle"/><c:size val="6"/><c:spPr><a:solidFill><a:srgbClr val="'.$col.'"/></a:solidFill></c:spPr></c:marker>'.$this->catRef($cats).$this->valRef($i + 1, count($cats), $s['values']).'</c:ser>';
        }
        $a = $this->axA;
        $b = $this->axB;
        $x .= '<c:axId val="'.$a.'"/><c:axId val="'.$b.'"/></c:radarChart>';
        $x .= $this->catAx($a, $b, 'b', false, false, []);
        $x .= $this->valAx($b, $a, 'l', $opt['ax']['y'] ?? [], $opt['grid'], $this->numFmt($opt), true, 'between', false);

        return $x.'</c:plotArea>';
    }

    private function waterfall(array $chart): string
    {
        $opt = $chart['opt'];
        $cats = $chart['data']['categories'];
        $steps = ChartData::waterfallSteps($chart);
        $n = count($cats);
        $base = array_fill(0, $n, null);
        $inc = $dec = $tot = $incN = $decN = $totN = array_fill(0, $n, null);
        foreach ($steps as $s) {
            $i = $s['i'];
            if (! empty($s['skip'])) {
                continue;
            }
            $lo = min($s['from'], $s['to']);
            $hi = max($s['from'], $s['to']);
            $kind = ! empty($s['total']) ? 'tot' : ($s['delta'] >= 0 ? 'inc' : 'dec');
            $pos = $kind === 'tot' ? 'tot' : $kind;
            if ($lo >= 0) {
                $base[$i] = $lo;
                ${$pos}[$i] = $hi - $lo;
            } elseif ($hi <= 0) {
                $base[$i] = $hi;
                ${$pos.'N'}[$i] = $lo - $hi;
            } else {
                $base[$i] = 0.0;
                ${$pos}[$i] = $hi;
                ${$pos.'N'}[$i] = $lo;
            }
        }
        $defs = [['Base', $base, null]];
        $good = $this->theme->hex('@good');
        $bad = $this->theme->hex('@bad');
        $acc = $this->theme->hex('@accent');
        foreach ([['Increase', $inc, $good], ['Decrease', $dec, $bad], ['Total', $tot, $acc], ['Increase (below zero)', $incN, $good], ['Decrease (below zero)', $decN, $bad], ['Total (below zero)', $totN, $acc]] as $d) {
            if (array_filter($d[1], fn ($v) => $v !== null)) {
                $defs[] = $d;
            }
        }
        $series = array_map(fn ($d) => ['name' => $d[0], 'values' => $d[1]], $defs);
        $this->catRows($cats, $series);
        $x = '<c:plotArea><c:layout/><c:barChart><c:barDir val="col"/><c:grouping val="stacked"/><c:varyColors val="0"/>';
        foreach ($defs as $i => $d) {
            $fill = $d[2] === null ? '<a:noFill/>' : '<a:solidFill><a:srgbClr val="'.strtoupper(substr($d[2], 1)).'"/></a:solidFill>';
            $x .= '<c:ser><c:idx val="'.$i.'"/><c:order val="'.$i.'"/>'.$this->serTx($i, $d[0]).'<c:spPr>'.$fill.'<a:ln><a:noFill/></a:ln></c:spPr><c:invertIfNegative val="0"/>'.$this->catRef($cats).$this->valRef($i + 1, $n, $d[1]).'</c:ser>';
        }
        $a = $this->axA;
        $b = $this->axB;
        $x .= '<c:gapWidth val="50"/><c:overlap val="100"/><c:axId val="'.$a.'"/><c:axId val="'.$b.'"/></c:barChart>';
        $x .= $this->axes($opt, false, true, $this->numFmt($opt));

        // The base series has no legend entry.
        return $x.'</c:plotArea>';
    }

    private function funnel(array $chart): string
    {
        $opt = $chart['opt'];
        $cats = $chart['data']['categories'];
        $vals = array_map(fn ($v) => $v !== null && $v > 0 ? $v : 0.0, $chart['data']['series'][0]['values'] ?? []);
        $max = $vals ? max($vals) : 0;
        $spacer = array_map(fn ($v) => ($max - $v) / 2, $vals);
        $series = [['name' => 'Spacer', 'values' => $spacer], ['name' => $chart['data']['series'][0]['name'] ?? 'Value', 'values' => $vals]];
        $this->catRows($cats, $series);
        $col = ChartData::seriesColors($chart, $this->theme)[0] ?? $this->theme->hex('@accent');
        $x = '<c:plotArea><c:layout/><c:barChart><c:barDir val="bar"/><c:grouping val="stacked"/><c:varyColors val="0"/>';
        $x .= '<c:ser><c:idx val="0"/><c:order val="0"/>'.$this->serTx(0, 'Spacer').'<c:spPr><a:noFill/><a:ln><a:noFill/></a:ln></c:spPr><c:invertIfNegative val="0"/>'.$this->catRef($cats).$this->valRef(1, count($cats), $spacer).'</c:ser>';
        $x .= '<c:ser><c:idx val="1"/><c:order val="1"/>'.$this->serTx(1, $series[1]['name']).'<c:spPr><a:solidFill><a:srgbClr val="'.strtoupper(substr($col, 1)).'"/></a:solidFill><a:ln><a:noFill/></a:ln></c:spPr><c:invertIfNegative val="0"/>'.$this->dLbls($opt, false).$this->catRef($cats).$this->valRef(2, count($cats), $vals).'</c:ser>';
        $a = $this->axA;
        $b = $this->axB;
        $x .= '<c:gapWidth val="25"/><c:overlap val="100"/><c:axId val="'.$a.'"/><c:axId val="'.$b.'"/></c:barChart>';
        $x .= $this->catAx($a, $b, 'l', true, true, $opt['ax']['x'] ?? []);
        $x .= '<c:valAx><c:axId val="'.$b.'"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:delete val="1"/><c:axPos val="b"/><c:numFmt formatCode="General" sourceLinked="0"/><c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/><c:crossAx val="'.$a.'"/><c:crosses val="max"/><c:crossBetween val="between"/></c:valAx>';

        return $x.'</c:plotArea>';
    }

    // ------------------------------------------------------------------ axes

    private function axes(array $opt, bool $horizontal, bool $hasCat, string $fmt): string
    {
        $a = $this->axA;
        $b = $this->axB;
        $x = $this->catAx($a, $b, $horizontal ? 'l' : 'b', $horizontal, false, $opt['ax']['x'] ?? []);
        $x .= $this->valAx($b, $a, $horizontal ? 'b' : 'l', $opt['ax']['y'] ?? [], $opt['grid'], $fmt, true, 'between', $horizontal);

        return $x;
    }

    /** @param array<string, mixed> $ax */
    private function catAx(int $id, int $cross, string $pos, bool $reverse, bool $deleteLine, array $ax): string
    {
        $show = ($ax['show'] ?? true) !== false;
        $x = '<c:catAx><c:axId val="'.$id.'"/><c:scaling><c:orientation val="'.($reverse ? 'maxMin' : 'minMax').'"/></c:scaling><c:delete val="'.($show ? 0 : 1).'"/><c:axPos val="'.$pos.'"/>';
        if (! empty($ax['title'])) {
            $x .= $this->axTitle($ax['title'], $pos === 'l');
        }
        $x .= '<c:numFmt formatCode="General" sourceLinked="0"/><c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/><c:spPr><a:ln w="9525"><a:solidFill>'.Ooxml::color($this->theme, '@muted', 0.6).'</a:solidFill></a:ln></c:spPr>';
        $x .= '<c:crossAx val="'.$cross.'"/><c:crosses val="autoZero"/><c:auto val="1"/><c:lblAlgn val="ctr"/><c:lblOffset val="100"/><c:noMultiLvlLbl val="0"/></c:catAx>';

        return $x;
    }

    /** @param array<string, mixed> $ax */
    private function valAx(int $id, int $cross, string $pos, array $ax, bool $grid, string $fmt, bool $showDefault, ?string $crossBetween, bool $crossMax): string
    {
        $show = ($ax['show'] ?? true) !== false;
        $x = '<c:valAx><c:axId val="'.$id.'"/><c:scaling><c:orientation val="minMax"/>';
        if (isset($ax['max'])) {
            $x .= '<c:max val="'.$ax['max'].'"/>';
        }
        if (isset($ax['min'])) {
            $x .= '<c:min val="'.$ax['min'].'"/>';
        }
        $x .= '</c:scaling><c:delete val="'.($show ? 0 : 1).'"/><c:axPos val="'.$pos.'"/>';
        if ($grid) {
            $x .= '<c:majorGridlines><c:spPr><a:ln w="6350"><a:solidFill>'.Ooxml::color($this->theme, '@line').'</a:solidFill></a:ln></c:spPr></c:majorGridlines>';
        }
        if (! empty($ax['title'])) {
            $x .= $this->axTitle($ax['title'], $pos === 'l');
        }
        $x .= '<c:numFmt formatCode="'.Ooxml::esc($fmt).'" sourceLinked="0"/><c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/><c:spPr><a:ln><a:noFill/></a:ln></c:spPr>';
        $x .= '<c:crossAx val="'.$cross.'"/><c:crosses val="'.($crossMax ? 'max' : 'autoZero').'"/>';
        if ($crossBetween !== null) {
            $x .= '<c:crossBetween val="'.$crossBetween.'"/>';
        } else {
            $x .= '<c:crossBetween val="midCat"/>';
        }

        return $x.'</c:valAx>';
    }

    private function axTitle(string $text, bool $vertical): string
    {
        return '<c:title><c:tx><c:rich><a:bodyPr'.($vertical ? ' rot="-5400000" vert="horz"' : '').'/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="1000" b="0">'.Ooxml::solid($this->theme, '@muted').'</a:defRPr></a:pPr><a:r><a:rPr lang="en-US" sz="1000" b="0">'.Ooxml::solid($this->theme, '@muted').'</a:rPr><a:t>'.Ooxml::esc($text).'</a:t></a:r></a:p></c:rich></c:tx><c:overlay val="0"/></c:title>';
    }

    // ------------------------------------------------------------------ series and data

    /** @param array<string, mixed> $s */
    private function serXml(string $role, int $i, array $s, string $color, array $cats, array $opt): string
    {
        $col = strtoupper(substr($color, 1));
        $x = '<c:ser><c:idx val="'.$i.'"/><c:order val="'.$i.'"/>'.$this->serTx($i, $s['name']);
        if ($role === 'line') {
            $x .= '<c:spPr><a:ln w="31750" cap="rnd"><a:solidFill><a:srgbClr val="'.$col.'"/></a:solidFill><a:round/></a:ln></c:spPr><c:marker><c:symbol val="circle"/><c:size val="6"/><c:spPr><a:solidFill><a:srgbClr val="'.$col.'"/></a:solidFill><a:ln w="9525"><a:solidFill>'.Ooxml::color($this->theme, '@bg').'</a:solidFill></a:ln></c:spPr></c:marker>';
        } elseif ($role === 'area') {
            $x .= '<c:spPr><a:solidFill><a:srgbClr val="'.$col.'"><a:alpha val="75000"/></a:srgbClr></a:solidFill><a:ln w="19050"><a:solidFill><a:srgbClr val="'.$col.'"/></a:solidFill></a:ln></c:spPr>';
        } else {
            $x .= '<c:spPr><a:solidFill><a:srgbClr val="'.$col.'"/></a:solidFill><a:ln><a:noFill/></a:ln></c:spPr><c:invertIfNegative val="0"/>';
        }
        $x .= $this->catRef($cats).$this->valRef($i + 1, count($cats), $s['values']);
        if ($role === 'line') {
            $x .= '<c:smooth val="'.($opt['smooth'] ? 1 : 0).'"/>';
        }

        return $x.'</c:ser>';
    }

    private function serTx(int $i, string $name): string
    {
        return $this->serTxRef($i + 1, $name);
    }

    private function serTxRef(int $col, string $name): string
    {
        return '<c:tx><c:strRef><c:f>Sheet1!$'.XlsxWriter::col($col).'$1</c:f><c:strCache><c:ptCount val="1"/><c:pt idx="0"><c:v>'.Ooxml::esc($name).'</c:v></c:pt></c:strCache></c:strRef></c:tx>';
    }

    /** @param list<string> $cats */
    private function catRef(array $cats): string
    {
        $n = count($cats);
        $x = '<c:cat><c:strRef><c:f>Sheet1!$A$2:$A$'.($n + 1).'</c:f><c:strCache><c:ptCount val="'.$n.'"/>';
        foreach ($cats as $i => $c) {
            $x .= '<c:pt idx="'.$i.'"><c:v>'.Ooxml::esc($c).'</c:v></c:pt>';
        }

        return $x.'</c:strCache></c:strRef></c:cat>';
    }

    /** @param list<float|null> $vals */
    private function valRef(int $col, int $n, array $vals): string
    {
        return '<c:val>'.$this->numRefAt($col, 1, $n, $vals).'</c:val>';
    }

    /** @param list<float|null> $vals */
    private function numRefAt(int $col, int $row0, int $n, array $vals): string
    {
        $c = XlsxWriter::col($col);
        $x = '<c:numRef><c:f>Sheet1!$'.$c.'$'.($row0 + 1).':$'.$c.'$'.($row0 + max(1, $n)).'</c:f><c:numCache><c:formatCode>General</c:formatCode><c:ptCount val="'.$n.'"/>';
        foreach (array_values($vals) as $i => $v) {
            if ($v !== null && is_finite((float) $v) && $i < $n) {
                $x .= '<c:pt idx="'.$i.'"><c:v>'.self::numStr((float) $v).'</c:v></c:pt>';
            }
        }

        return $x.'</c:numCache></c:numRef>';
    }

    private static function numStr(float $v): string
    {
        return rtrim(rtrim(sprintf('%.10F', $v), '0'), '.') ?: '0';
    }

    /** @param list<string> $cats @param list<array<string, mixed>> $series */
    private function catRows(array $cats, array $series): void
    {
        $this->rows = [array_merge([''], array_map(fn ($s) => $s['name'], $series))];
        foreach ($cats as $i => $c) {
            $this->rows[] = array_merge([$c], array_map(fn ($s) => isset($s['values'][$i]) && $s['values'][$i] !== null ? (float) $s['values'][$i] : null, $series));
        }
    }

    // ------------------------------------------------------------------ pieces

    private function dLbls(array $opt, bool $pie): string
    {
        $mode = $opt['labels'] ?? 'none';
        if ($mode === 'none') {
            return '';
        }
        $percent = $mode === 'percent' && $pie;
        $val = $mode === 'value' || ($mode === 'percent' && ! $pie);

        return '<c:dLbls><c:numFmt formatCode="'.Ooxml::esc($percent ? '0%' : $this->numFmt($opt)).'" sourceLinked="0"/><c:spPr><a:noFill/><a:ln><a:noFill/></a:ln></c:spPr>'.$this->txPr(1000).'<c:showLegendKey val="0"/><c:showVal val="'.($val ? 1 : 0).'"/><c:showCatName val="0"/><c:showSerName val="0"/><c:showPercent val="'.($percent ? 1 : 0).'"/><c:showBubbleSize val="0"/>'.($pie ? '<c:showLeaderLines val="1"/>' : '').'</c:dLbls>';
    }

    private function numFmt(array $opt): string
    {
        $cur = str_replace('"', '', Els::str($opt['cur'] ?? null, 'R'));
        $dec = $opt['dec'] ?? null;
        $zeros = $dec !== null && $dec > 0 ? '.'.str_repeat('0', $dec) : '';

        return match ($opt['fmt'] ?? 'num') {
            'pct' => '0'.$zeros.'"%"',
            'cur' => '"'.$cur.'"#,##0'.$zeros,
            'compact' => '[>=1000000]0.0,,"M";[>=1000]0.0,"K";0',
            default => $dec !== null ? '#,##0'.$zeros : 'General',
        };
    }

    private function title(string $title, ?string $sub, int $sz): string
    {
        $rpr = fn (int $s, bool $bold) => '<a:rPr lang="en-US" sz="'.$s.'" b="'.($bold ? 1 : 0).'">'.Ooxml::solid($this->theme, '@ink').'</a:rPr>';
        $x = '<c:title><c:tx><c:rich><a:bodyPr/><a:lstStyle/><a:p><a:pPr algn="l"><a:defRPr sz="'.(int) round($sz * 1.3).'" b="1"/></a:pPr><a:r>'.$rpr((int) round($sz * 1.3), true).'<a:t>'.Ooxml::esc($title).'</a:t></a:r></a:p>';
        if ($sub !== null && $sub !== '') {
            $x .= '<a:p><a:pPr algn="l"><a:defRPr sz="'.(int) round($sz * 0.92).'" b="0"/></a:pPr><a:r><a:rPr lang="en-US" sz="'.(int) round($sz * 0.92).'" b="0">'.Ooxml::solid($this->theme, '@muted').'</a:rPr><a:t>'.Ooxml::esc($sub).'</a:t></a:r></a:p>';
        }

        return $x.'</c:rich></c:tx><c:overlay val="0"/></c:title><c:autoTitleDeleted val="0"/>';
    }

    private function txPr(int $sz, bool $latin = false): string
    {
        return '<c:txPr><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="'.$sz.'">'.Ooxml::solid($this->theme, '@ink').($latin ? '<a:latin typeface="+mn-lt"/>' : '').'</a:defRPr></a:pPr><a:endParaRPr lang="en-US"/></a:p></c:txPr>';
    }

    /** The waterfall base and the funnel spacer should not show in the legend. */
    private function legendDeletes(array $chart): string
    {
        if ($chart['type'] === 'waterfall') {
            return '<c:legendEntry><c:idx val="0"/><c:delete val="1"/></c:legendEntry>';
        }

        return '';
    }
}
