<?php

namespace App\Services\Press\Export\Support;

/**
 * Parses SVG path data into absolute move, line, cubic and close segments. Quadratics are raised to cubics and
 * arcs are converted to cubic curves, so the result maps straight onto DrawingML custom geometry.
 */
final class SvgPath
{
    /**
     * @return list<array{0: string, 1?: float, 2?: float, 3?: float, 4?: float, 5?: float, 6?: float}> segments: ['M', x, y], ['L', x, y], ['C', x1, y1, x2, y2, x, y], ['Z']
     */
    public static function parse(string $d): array
    {
        preg_match_all('/([MmLlHhVvCcSsQqTtAaZz])|(-?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?)/', $d, $m);
        $tokens = $m[0];
        $segs = [];
        $i = 0;
        $n = count($tokens);
        $cx = $cy = $sx = $sy = 0.0;
        $lastC = null; // last cubic control point for S
        $lastQ = null;
        $cmd = '';
        $num = function () use (&$tokens, &$i, $n): ?float {
            if ($i < $n && ! ctype_alpha($tokens[$i])) {
                return (float) $tokens[$i++];
            }

            return null;
        };
        $guard = 0;
        while ($i < $n && $guard++ < 100000) {
            if (ctype_alpha($tokens[$i])) {
                $cmd = $tokens[$i++];
                if ($cmd === 'Z' || $cmd === 'z') {
                    $segs[] = ['Z'];
                    $cx = $sx;
                    $cy = $sy;
                    $lastC = $lastQ = null;

                    continue;
                }
            } elseif ($cmd === '') {
                break;
            }
            $rel = ctype_lower($cmd);
            $up = strtoupper($cmd);
            switch ($up) {
                case 'M':
                case 'L':
                    $x = $num();
                    $y = $num();
                    if ($x === null || $y === null) {
                        break 2;
                    }
                    if ($rel) {
                        $x += $cx;
                        $y += $cy;
                    }
                    $segs[] = [$up === 'M' ? 'M' : 'L', $x, $y];
                    if ($up === 'M') {
                        $sx = $x;
                        $sy = $y;
                        $cmd = $rel ? 'l' : 'L';
                    }
                    $cx = $x;
                    $cy = $y;
                    $lastC = $lastQ = null;
                    break;
                case 'H':
                    $x = $num();
                    if ($x === null) {
                        break 2;
                    }
                    $x = $rel ? $x + $cx : $x;
                    $segs[] = ['L', $x, $cy];
                    $cx = $x;
                    $lastC = $lastQ = null;
                    break;
                case 'V':
                    $y = $num();
                    if ($y === null) {
                        break 2;
                    }
                    $y = $rel ? $y + $cy : $y;
                    $segs[] = ['L', $cx, $y];
                    $cy = $y;
                    $lastC = $lastQ = null;
                    break;
                case 'C':
                case 'S':
                    if ($up === 'C') {
                        $x1 = $num();
                        $y1 = $num();
                    }
                    $x2 = $num();
                    $y2 = $num();
                    $x = $num();
                    $y = $num();
                    if ($x === null || $y === null || $x2 === null || $y2 === null || ($up === 'C' && ($x1 === null || $y1 === null))) {
                        break 2;
                    }
                    if ($rel) {
                        if ($up === 'C') {
                            $x1 += $cx;
                            $y1 += $cy;
                        }
                        $x2 += $cx;
                        $y2 += $cy;
                        $x += $cx;
                        $y += $cy;
                    }
                    if ($up === 'S') {
                        $x1 = $lastC ? 2 * $cx - $lastC[0] : $cx;
                        $y1 = $lastC ? 2 * $cy - $lastC[1] : $cy;
                    }
                    $segs[] = ['C', $x1, $y1, $x2, $y2, $x, $y];
                    $lastC = [$x2, $y2];
                    $lastQ = null;
                    $cx = $x;
                    $cy = $y;
                    break;
                case 'Q':
                case 'T':
                    if ($up === 'Q') {
                        $qx = $num();
                        $qy = $num();
                    }
                    $x = $num();
                    $y = $num();
                    if ($x === null || $y === null || ($up === 'Q' && ($qx === null || $qy === null))) {
                        break 2;
                    }
                    if ($rel) {
                        if ($up === 'Q') {
                            $qx += $cx;
                            $qy += $cy;
                        }
                        $x += $cx;
                        $y += $cy;
                    }
                    if ($up === 'T') {
                        $qx = $lastQ ? 2 * $cx - $lastQ[0] : $cx;
                        $qy = $lastQ ? 2 * $cy - $lastQ[1] : $cy;
                    }
                    $segs[] = ['C', $cx + 2 / 3 * ($qx - $cx), $cy + 2 / 3 * ($qy - $cy), $x + 2 / 3 * ($qx - $x), $y + 2 / 3 * ($qy - $y), $x, $y];
                    $lastQ = [$qx, $qy];
                    $lastC = null;
                    $cx = $x;
                    $cy = $y;
                    break;
                case 'A':
                    $rx = $num();
                    $ry = $num();
                    $rot = $num();
                    $fa = $num();
                    $fs = $num();
                    $x = $num();
                    $y = $num();
                    if ($x === null || $y === null || $ry === null || $rot === null || $fa === null || $fs === null || $rx === null) {
                        break 2;
                    }
                    if ($rel) {
                        $x += $cx;
                        $y += $cy;
                    }
                    foreach (self::arc($cx, $cy, $rx, $ry, $rot, $fa != 0, $fs != 0, $x, $y) as $c) {
                        $segs[] = $c;
                    }
                    $cx = $x;
                    $cy = $y;
                    $lastC = $lastQ = null;
                    break;
                default:
                    break 2;
            }
        }

        return $segs;
    }

    /** @return list<array{0: string, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float}> */
    private static function arc(float $x1, float $y1, float $rx, float $ry, float $phi, bool $fa, bool $fs, float $x2, float $y2): array
    {
        if ($x1 == $x2 && $y1 == $y2) {
            return [];
        }
        $rx = abs($rx);
        $ry = abs($ry);
        if ($rx == 0 || $ry == 0) {
            return [['C', $x1, $y1, $x2, $y2, $x2, $y2]];
        }
        $phi = deg2rad($phi);
        $cp = cos($phi);
        $sp = sin($phi);
        $dx = ($x1 - $x2) / 2;
        $dy = ($y1 - $y2) / 2;
        $x1p = $cp * $dx + $sp * $dy;
        $y1p = -$sp * $dx + $cp * $dy;
        $lam = ($x1p ** 2) / ($rx ** 2) + ($y1p ** 2) / ($ry ** 2);
        if ($lam > 1) {
            $s = sqrt($lam);
            $rx *= $s;
            $ry *= $s;
        }
        $num = ($rx ** 2) * ($ry ** 2) - ($rx ** 2) * ($y1p ** 2) - ($ry ** 2) * ($x1p ** 2);
        $den = ($rx ** 2) * ($y1p ** 2) + ($ry ** 2) * ($x1p ** 2);
        $coef = $den == 0 ? 0 : sqrt(max(0, $num / $den));
        if ($fa === $fs) {
            $coef = -$coef;
        }
        $cxp = $coef * ($rx * $y1p / $ry);
        $cyp = $coef * (-$ry * $x1p / $rx);
        $cx = $cp * $cxp - $sp * $cyp + ($x1 + $x2) / 2;
        $cy = $sp * $cxp + $cp * $cyp + ($y1 + $y2) / 2;
        $ang = fn (float $ux, float $uy, float $vx, float $vy): float => atan2($ux * $vy - $uy * $vx, $ux * $vx + $uy * $vy);
        $t1 = $ang(1, 0, ($x1p - $cxp) / $rx, ($y1p - $cyp) / $ry);
        $dt = $ang(($x1p - $cxp) / $rx, ($y1p - $cyp) / $ry, (-$x1p - $cxp) / $rx, (-$y1p - $cyp) / $ry);
        if (! $fs && $dt > 0) {
            $dt -= 2 * M_PI;
        } elseif ($fs && $dt < 0) {
            $dt += 2 * M_PI;
        }
        $n = (int) ceil(abs($dt) / (M_PI / 2) - 1e-9);
        $n = max(1, $n);
        $step = $dt / $n;
        $out = [];
        $t = $t1;
        for ($k = 0; $k < $n; $k++) {
            $alpha = 4 / 3 * tan($step / 4);
            $c1 = cos($t);
            $s1 = sin($t);
            $c2 = cos($t + $step);
            $s2 = sin($t + $step);
            $pts = [
                [$c1 - $alpha * $s1, $s1 + $alpha * $c1],
                [$c2 + $alpha * $s2, $s2 - $alpha * $c2],
                [$c2, $s2],
            ];
            $abs = [];
            foreach ($pts as [$ux, $uy]) {
                $ux *= $rx;
                $uy *= $ry;
                $abs[] = $cp * $ux - $sp * $uy + $cx;
                $abs[] = $sp * $ux + $cp * $uy + $cy;
            }
            $out[] = ['C', ...$abs];
            $t += $step;
        }

        return $out;
    }
}
