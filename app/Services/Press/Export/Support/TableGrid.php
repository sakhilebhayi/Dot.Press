<?php

namespace App\Services\Press\Export\Support;

/**
 * Normalises a table element (spec section 6, table) into a grid.
 *
 * The rows are read as a full grid when every row has as many cells as there are columns: the cell that carries
 * `span` is the origin and the cells it covers are skipped. A row with fewer cells than columns is read in compact form,
 * where covered positions are left out.
 */
final class TableGrid
{
    public const MAX_ROWS = 200;

    public const MAX_COLS = 40;

    /**
     * @param  array<string, mixed>  $el
     * @return array{cols: int, rows: int, colw: list<float>, rowh: list<float>, cells: list<array{r: int, c: int, rs: int, cs: int, cell: array<string, mixed>}>}
     */
    public static function build(array $el): array
    {
        $rowsIn = array_slice(array_values(array_filter((array) ($el['rows'] ?? []), 'is_array')), 0, self::MAX_ROWS);
        $cols = 0;
        foreach ($rowsIn as $r) {
            $cols = max($cols, count($r));
        }
        $cols = max(1, min(self::MAX_COLS, $cols));
        $colw = [];
        foreach ((array) ($el['colw'] ?? []) as $v) {
            if (is_numeric($v) && $v > 0) {
                $colw[] = (float) $v;
            }
        }
        if (count($colw) !== $cols) {
            $colw = array_fill(0, $cols, 1.0);
        }
        $sum = array_sum($colw) ?: 1.0;
        $colw = array_map(fn ($v) => $v / $sum, $colw);

        $nRows = count($rowsIn);
        $rowh = [];
        foreach ((array) ($el['rowh'] ?? []) as $v) {
            if (is_numeric($v) && $v > 0) {
                $rowh[] = (float) $v;
            }
        }
        if (count($rowh) !== $nRows) {
            $h = Els::num($el['h'] ?? 0, 0, 0);
            $rowh = $nRows > 0 ? array_fill(0, $nRows, $h > 0 ? $h / $nRows : 40.0) : [];
        }

        $occupied = [];
        $cells = [];
        foreach ($rowsIn as $ri => $row) {
            $full = count($row) >= $cols;
            $c = 0;
            foreach (array_values($row) as $cell) {
                if (! is_array($cell)) {
                    $cell = ['t' => is_scalar($cell) ? (string) $cell : ''];
                }
                if ($full) {
                    $col = $c++;
                    if (isset($occupied[$ri][$col])) {
                        continue; // covered by a span from above or to the left
                    }
                } else {
                    while (isset($occupied[$ri][$c])) {
                        $c++;
                    }
                    $col = $c++;
                }
                if ($col >= $cols) {
                    break;
                }
                $span = is_array($cell['span'] ?? null) ? $cell['span'] : [1, 1];
                $rs = max(1, min($nRows - $ri, (int) ($span[0] ?? 1)));
                $cs = max(1, min($cols - $col, (int) ($span[1] ?? 1)));
                for ($rr = $ri; $rr < $ri + $rs; $rr++) {
                    for ($cc = $col; $cc < $col + $cs; $cc++) {
                        if ($rr !== $ri || $cc !== $col) {
                            $occupied[$rr][$cc] = true;
                        }
                    }
                }
                $cells[] = ['r' => $ri, 'c' => $col, 'rs' => $rs, 'cs' => $cs, 'cell' => $cell];
            }
        }

        return ['cols' => $cols, 'rows' => $nRows, 'colw' => $colw, 'rowh' => $rowh, 'cells' => $cells];
    }
}
