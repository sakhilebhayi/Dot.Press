<?php

namespace App\Services\Press\Export\Pdf;

use App\Services\Press\Export\Support\ChartData;
use App\Services\Press\Export\Support\Theme;

/**
 * Charts as SVG for the PDF export. Every type in the document spec is drawn from its data alone with the same layout
 * rules as the editor's chart engine (resources/js/press/charts). `map` is not drawn: the caller shows a table for it.
 */
final class ChartSvg
{
    /**
     * A standalone SVG (marks and text) for the chart.
     *
     * @param  array<string, mixed>  $chart
     * @param  array<string, mixed>|null  $theme  the deck theme (spec section 3)
     */
    public static function render(array $chart, float $w, float $h, ?array $theme = null): string
    {
        return self::draw($chart, $w, $h, new Theme($theme))->svg(ChartData::text($chart['opt']['title'] ?? 'Chart'));
    }

    /**
     * The marks as an SVG without text, and the text lines to set in real fonts on top.
     *
     * @param  array<string, mixed>  $chart
     * @return array{svg: string, texts: list<array<string, mixed>>}
     */
    public static function layers(array $chart, float $w, float $h, Theme $theme): array
    {
        $c = self::draw($chart, $w, $h, $theme);

        return ['svg' => $c->shapes(), 'texts' => $c->texts];
    }

    /** @param array<string, mixed> $chart */
    private static function draw(array $chart, float $w, float $h, Theme $theme): ChartCanvas
    {
        $norm = ChartData::normalize($chart);
        $c = new ChartCanvas($norm, $w, $h, $theme);
        try {
            match ($norm['type']) {
                'bar', 'column', 'line', 'area', 'combo', 'waterfall' => ChartCartesian::render($c),
                'pie', 'doughnut' => ChartRound::pie($c),
                'scatter', 'bubble' => ChartRound::scatter($c),
                'radar' => ChartRound::radar($c),
                'funnel' => ChartRound::funnel($c),
                'kpi' => ChartRound::kpi($c),
                default => self::unsupported($c),
            };
        } catch (\Throwable) {
            // Never throw out of a render: say so on the page instead of drawing half a chart.
            $c->body = [];
            $c->texts = [];
            $c->text($c->pad, $c->h / 2, 'This chart could not be drawn.', ['fill' => $c->muted]);
        }

        return $c;
    }

    private static function unsupported(ChartCanvas $c): void
    {
        $c->text($c->pad, $c->h / 2, 'This chart type is shown as a table in exports.', ['fill' => $c->muted]);
    }
}
