<?php

namespace App\Services\Press\Export\Pdf;

use App\Services\Press\Export\Support\Theme;

final class ChartSvg
{
    public static function layers(array $chart, float $w, float $h, Theme $theme): array
    {
        return ['svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'"></svg>', 'texts' => []];
    }
}
