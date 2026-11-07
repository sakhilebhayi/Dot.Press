<?php

namespace App\Services\Press\Export\Pptx;

use App\Services\Press\Export\Support\ShapeGeometry;

/** Spec shape kinds as DrawingML preset geometry, with the adjustments that make them match the PDF outlines. */
final class PptxShapes
{
    /**
     * @return array{prst: string, av: string, h?: float} the preset name, the avLst XML and an optional replacement height
     */
    public static function geometry(string $kind, float $w, float $h, float $radius): array
    {
        $ss = max(1.0, min($w, $h));
        $gd = fn (string $name, float $val) => '<a:gd name="'.$name.'" fmla="val '.(int) round($val).'"/>';
        switch ($kind) {
            case 'rect':
                if ($radius > 0) {
                    return ['prst' => 'roundRect', 'av' => '<a:avLst>'.$gd('adj', min(50000, $radius / $ss * 100000)).'</a:avLst>'];
                }

                return ['prst' => 'rect', 'av' => '<a:avLst/>'];
            case 'round':
                return ['prst' => 'roundRect', 'av' => '<a:avLst>'.$gd('adj', min(50000, max(0.0, $radius) / $ss * 100000)).'</a:avLst>'];
            case 'ellipse':
                return ['prst' => 'ellipse', 'av' => '<a:avLst/>'];
            case 'triangle':
                return ['prst' => 'triangle', 'av' => '<a:avLst/>'];
            case 'diamond':
                return ['prst' => 'diamond', 'av' => '<a:avLst/>'];
            case 'pentagon':
                return ['prst' => 'pentagon', 'av' => '<a:avLst/>'];
            case 'hexagon':
                return ['prst' => 'hexagon', 'av' => '<a:avLst>'.$gd('adj', min(50000 * $w / $ss, 25000 * $w / $ss)).$gd('vf', 115470).'</a:avLst>'];
            case 'star':
                return ['prst' => 'star5', 'av' => '<a:avLst/>'];
            case 'chevron':
                return ['prst' => 'chevron', 'av' => '<a:avLst>'.$gd('adj', ShapeGeometry::chevronDepth($w, $h) / $ss * 100000).'</a:avLst>'];
            case 'arrow-r':
            case 'arrow-l':
                return ['prst' => $kind === 'arrow-r' ? 'rightArrow' : 'leftArrow', 'av' => '<a:avLst>'.$gd('adj1', 50000).$gd('adj2', ShapeGeometry::arrowHead($w, $h) / $ss * 100000).'</a:avLst>'];
            case 'callout':
                $body = $h * ShapeGeometry::CALLOUT_BODY;
                $adj2 = ($h - $body / 2) / $body * 100000;

                return ['prst' => 'wedgeRectCallout', 'av' => '<a:avLst>'.$gd('adj1', -25000).$gd('adj2', $adj2).'</a:avLst>', 'h' => $body];
            case 'cross':
                return ['prst' => 'plus', 'av' => '<a:avLst>'.$gd('adj', min(50000, ($w / 3) / $ss * 100000)).'</a:avLst>'];
            case 'heart':
                return ['prst' => 'heart', 'av' => '<a:avLst/>'];
            default:
                return ['prst' => 'rect', 'av' => '<a:avLst/>'];
        }
    }

    /** Mask kinds of a picture as preset geometry. */
    public static function maskGeometry(string $mask, float $w, float $h): array
    {
        $ss = max(1.0, min($w, $h));

        return match ($mask) {
            'round' => ['prst' => 'roundRect', 'av' => '<a:avLst><a:gd name="adj" fmla="val '.(int) round(min(50000, $ss * 0.12 / $ss * 100000)).'"/></a:avLst>'],
            'circle', 'ellipse' => ['prst' => 'ellipse', 'av' => '<a:avLst/>'],
            'triangle' => ['prst' => 'triangle', 'av' => '<a:avLst/>'],
            'star' => ['prst' => 'star5', 'av' => '<a:avLst/>'],
            'hexagon' => ['prst' => 'hexagon', 'av' => '<a:avLst><a:gd name="adj" fmla="val '.(int) round(25000 * $w / $ss).'"/><a:gd name="vf" fmla="val 115470"/></a:avLst>'],
            default => ['prst' => 'rect', 'av' => '<a:avLst/>'],
        };
    }
}
