<?php

namespace App\Services\Press\Export\Pdf;

/** Remembers the label boxes already placed so a new label can be tested against them. */
final class ChartPlacer
{
    /** @var list<array{x: float, y: float, w: float, h: float}> */
    public array $placed = [];

    /** @param array{x: float, y: float, w: float, h: float} $bounds @param list<array{x: float, y: float, w: float, h: float}> $blocked */
    public function __construct(private array $bounds, public array $blocked = []) {}

    private static function overlap(array $a, array $b, float $pad = 0.0): bool
    {
        return $a['x'] < $b['x'] + $b['w'] + $pad && $b['x'] < $a['x'] + $a['w'] + $pad && $a['y'] < $b['y'] + $b['h'] + $pad && $b['y'] < $a['y'] + $a['h'] + $pad;
    }

    public function fits(array $box): bool
    {
        $b = $this->bounds;
        if ($box['x'] < $b['x'] - 0.5 || $box['y'] < $b['y'] - 0.5 || $box['x'] + $box['w'] > $b['x'] + $b['w'] + 0.5 || $box['y'] + $box['h'] > $b['y'] + $b['h'] + 0.5) {
            return false;
        }
        foreach ($this->placed as $p) {
            if (self::overlap($box, $p, 1.0)) {
                return false;
            }
        }
        foreach ($this->blocked as $p) {
            if (self::overlap($box, $p)) {
                return false;
            }
        }

        return true;
    }

    public function tryPlace(array $box): bool
    {
        if (! $this->fits($box)) {
            return false;
        }
        $this->placed[] = $box;

        return true;
    }
}
