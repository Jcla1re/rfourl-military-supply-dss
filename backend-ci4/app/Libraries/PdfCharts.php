<?php

namespace App\Libraries;

/**
 * Charts for the PDF reports, drawn on the server as plain SVG (lines, rects, circles and text
 * only) because Dompdf can't run JavaScript. Each method returns an SVG string; dataUri() turns
 * it into the value for <img src="...">. Colours are the ones the Trend Analysis page uses.
 *
 * The SVGs have a fixed pixel size (width x height) that matches how big they are shown in the
 * report, so nothing depends on the viewer scaling them.
 */
class PdfCharts
{
    public const DARK       = '#2C3A1E';
    public const MEDIUM     = '#71AA60';
    public const LIGHT      = '#C5DDBB';
    public const LINE       = '#4A6741';
    public const AREA       = '#E4EFDF';
    public const PREVIOUS   = '#A9A493';
    public const GRID       = '#EEECE5';
    public const SHADE      = '#F5F3EE';
    public const TEXT       = '#5C6152';
    public const MUTED      = '#8A8F80';
    public const UNCLASSED  = '#D9D6CC';

    private const FONT = 'DejaVu Sans, sans-serif';

    public static function dataUri(string $svg): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Two-series line chart over categories (months). $current may hold nulls (months that haven't
     * happened yet): the line stops there and, from $futureFrom on, the area is shaded "Not yet".
     * The highest point of $current is labelled "Peak: N".
     *
     * @param list<string>   $labels
     * @param list<int|null> $current
     * @param list<int|null> $previous
     * @param array{width?: int, height?: int, currentName?: string, previousName?: string, futureFrom?: int|null, yTitle?: string, xTitle?: string} $o
     */
    public static function lineChart(array $labels, array $current, array $previous, array $o = []): string
    {
        $w = $o['width'] ?? 680;
        $h = $o['height'] ?? 250;
        $n = max(1, count($labels));
        $left = 46;
        $right = 14;
        $top = 40;
        $bottom = 44;
        $plotW = $w - $left - $right;
        $plotH = $h - $top - $bottom;
        $step = $plotW / $n;

        $maxValue = max(1, ...array_map(static fn ($v) => (int) $v, array_merge($current, $previous)));
        [$yMax, $ticks] = self::niceScale($maxValue);
        $x = static fn (int $i) => $left + ($i + 0.5) * $step;
        $y = static fn (float $v) => $top + $plotH - ($v / $yMax) * $plotH;

        $svg = self::open($w, $h);

        // "Not yet": the months that haven't happened.
        $futureFrom = $o['futureFrom'] ?? null;
        if ($futureFrom !== null && $futureFrom < $n) {
            $sx = $left + $futureFrom * $step;
            $svg .= self::rect($sx, $top, $left + $plotW - $sx, $plotH, self::SHADE);
            $svg .= self::text($sx + ($left + $plotW - $sx) / 2, $top + $plotH - 8, 'Not yet', 9, self::MUTED, 'middle');
        }

        // Grid and the y axis.
        foreach ($ticks as $t) {
            $gy = $y($t);
            $svg .= self::line($left, $gy, $left + $plotW, $gy, self::GRID, 1);
            $svg .= self::text($left - 6, $gy + 3, number_format($t), 9, self::TEXT, 'end');
        }
        $svg .= self::line($left, $top + $plotH, $left + $plotW, $top + $plotH, self::PREVIOUS, 1);

        // X axis labels.
        foreach ($labels as $i => $label) {
            $svg .= self::text($x($i), $top + $plotH + 14, $label, 9, self::TEXT, 'middle');
        }
        $svg .= self::text($left + $plotW / 2, $h - 6, $o['xTitle'] ?? 'Month', 9, self::MUTED, 'middle');
        $svg .= self::text($left - 36, $top - 14, $o['yTitle'] ?? 'Items sold', 9, self::MUTED, 'start');

        // Previous year: dashed grey, no points.
        foreach (self::runs($previous) as $run) {
            $svg .= self::polyline(array_map(static fn ($i) => [$x($i), $y((float) $previous[$i])], $run), self::PREVIOUS, 2, '6 5');
        }

        // This year: area, line and points, one run at a time (a gap is a gap, not a zero).
        $peakIndex = null;
        foreach ($current as $i => $v) {
            if ($v !== null && $v > 0 && ($peakIndex === null || $v > $current[$peakIndex])) {
                $peakIndex = $i;
            }
        }
        foreach (self::runs($current) as $run) {
            $pts = array_map(static fn ($i) => [$x($i), $y((float) $current[$i])], $run);
            if (count($pts) > 1) {
                $area = array_merge([[$pts[0][0], $top + $plotH]], $pts, [[end($pts)[0], $top + $plotH]]);
                $svg .= self::polygon($area, self::AREA);
            }
            $svg .= self::polyline($pts, self::LINE, 3);
            foreach ($pts as [$px, $py]) {
                $svg .= '<circle cx="' . self::n($px) . '" cy="' . self::n($py) . '" r="3" fill="' . self::LINE . '"/>';
            }
        }

        // "Peak: N", fully inside the plot: above the point, or below it when there is no room.
        if ($peakIndex !== null) {
            $label = 'Peak: ' . number_format($current[$peakIndex]);
            $boxW = strlen($label) * 6.4 + 16;
            $boxH = 20;
            $px = $x($peakIndex);
            $py = $y((float) $current[$peakIndex]);
            $bx = min(max($px - $boxW / 2, $left + 2), $left + $plotW - $boxW - 2);
            $by = $py - $boxH - 8;
            if ($by < $top - 22) {
                $by = $py + 8;
            }
            $svg .= '<rect x="' . self::n($bx) . '" y="' . self::n($by) . '" width="' . self::n($boxW) . '" height="' . $boxH . '" rx="5" ry="5" fill="' . self::DARK . '"/>';
            $svg .= self::text($bx + $boxW / 2, $by + 14, $label, 10, '#FFFFFF', 'middle', true);
        }

        // Legend, top right.
        $lx = $left + $plotW;
        $prevName = (string) ($o['previousName'] ?? 'Last year');
        $curName = (string) ($o['currentName'] ?? 'This year');
        $prevW = strlen($prevName) * 6 + 40;
        $svg .= self::line($lx - $prevW, 11, $lx - $prevW + 26, 11, self::PREVIOUS, 2, '6 5');
        $svg .= self::text($lx - $prevW + 32, 14, $prevName, 10, self::TEXT, 'start');
        $curW = strlen($curName) * 6 + 40 + $prevW + 10;
        $svg .= self::line($lx - $curW, 11, $lx - $curW + 26, 11, self::LINE, 3);
        $svg .= self::text($lx - $curW + 32, 14, $curName, 10, self::TEXT, 'start');

        return $svg . '</svg>';
    }

    /**
     * Vertical bars with the value above each one. The last bar can be highlighted.
     *
     * @param list<string> $labels
     * @param list<int>    $values
     * @param array{width?: int, height?: int, yTitle?: string, highlightLast?: bool} $o
     */
    public static function barChart(array $labels, array $values, array $o = []): string
    {
        $w = $o['width'] ?? 680;
        $h = $o['height'] ?? 200;
        $n = max(1, count($labels));
        $left = 46;
        $right = 14;
        $top = 28;
        $bottom = 34;
        $plotW = $w - $left - $right;
        $plotH = $h - $top - $bottom;
        $slot = $plotW / $n;
        $barW = min(34, $slot * 0.62);

        [$yMax, $ticks] = self::niceScale(max(1, ...array_map('intval', $values)));
        $y = static fn (float $v) => $top + $plotH - ($v / $yMax) * $plotH;

        $svg = self::open($w, $h);
        foreach ($ticks as $t) {
            $gy = $y($t);
            $svg .= self::line($left, $gy, $left + $plotW, $gy, self::GRID, 1);
            $svg .= self::text($left - 6, $gy + 3, number_format($t), 9, self::TEXT, 'end');
        }
        $svg .= self::text($left - 36, $top - 14, $o['yTitle'] ?? 'Items sold', 9, self::MUTED, 'start');

        foreach ($values as $i => $v) {
            $cx = $left + ($i + 0.5) * $slot;
            $by = $y((float) $v);
            $isLast = ($o['highlightLast'] ?? false) && $i === count($values) - 1;
            $barH = max(0.0, $top + $plotH - $by);
            if ($barH > 0) {
                $svg .= self::rect($cx - $barW / 2, $by, $barW, $barH, $isLast ? self::DARK : self::MEDIUM);
            }
            $svg .= self::text($cx, ($barH > 0 ? $by : $top + $plotH) - 4, number_format($v), 9, self::DARK, 'middle', true);
            $svg .= self::text($cx, $top + $plotH + 13, $labels[$i] ?? '', 8, self::TEXT, 'middle');
        }
        $svg .= self::line($left, $top + $plotH, $left + $plotW, $top + $plotH, self::PREVIOUS, 1);
        $svg .= self::text($left + $plotW / 2, $h - 5, $o['xTitle'] ?? 'Week starting', 9, self::MUTED, 'middle');

        return $svg . '</svg>';
    }

    /**
     * Horizontal bars, longest first, each with its count at the end.
     *
     * @param list<array{0: string, 1: int|float}> $rows [label, value]
     * @param array{width?: int, rowHeight?: int, labelWidth?: int} $o
     */
    public static function horizontalBars(array $rows, array $o = []): string
    {
        $w = $o['width'] ?? 680;
        $rowH = $o['rowHeight'] ?? 30;
        $labelW = $o['labelWidth'] ?? 210;
        $h = max(1, count($rows)) * $rowH + 8;
        $barLeft = $labelW + 8;
        $barMax = $w - $barLeft - 64;
        $max = max(1, ...array_map(static fn ($r) => (float) $r[1], $rows ?: [['', 1]]));

        $svg = self::open($w, $h);
        foreach (array_values($rows) as $i => [$label, $value]) {
            $cy = 4 + $i * $rowH;
            $svg .= self::text($labelW, $cy + $rowH / 2 + 3, self::fit((string) $label, $labelW, 10), 10, self::DARK, 'end');
            $len = max(2.0, ($value / $max) * $barMax);
            $svg .= self::rect($barLeft, $cy + 5, $len, $rowH - 12, $i === 0 ? self::DARK : self::MEDIUM);
            $svg .= self::text($barLeft + $len + 6, $cy + $rowH / 2 + 3, number_format($value), 10, self::DARK, 'start', true);
        }

        return $svg . '</svg>';
    }

    /**
     * One bar split into segments, with the value inside each wide-enough segment and a legend below.
     *
     * @param list<array{label: string, value: int|float, color: string, textColor?: string}> $segments
     * @param array{width?: int, barHeight?: int} $o
     */
    public static function stackedBar(array $segments, array $o = []): string
    {
        $w = $o['width'] ?? 680;
        $barH = $o['barHeight'] ?? 30;
        $total = array_sum(array_column($segments, 'value'));
        $h = $barH + 46;

        $svg = self::open($w, $h);
        if ($total <= 0) {
            $svg .= self::rect(0, 6, $w, $barH, self::UNCLASSED);
            $svg .= self::text($w / 2, 6 + $barH / 2 + 3, 'No items', 10, self::TEXT, 'middle');

            return $svg . '</svg>';
        }

        $x = 0.0;
        foreach ($segments as $seg) {
            if ($seg['value'] <= 0) {
                continue;
            }
            $segW = ($seg['value'] / $total) * $w;
            $svg .= self::rect($x, 6, $segW, $barH, $seg['color']);
            if ($segW > 34) {
                $svg .= self::text($x + $segW / 2, 6 + $barH / 2 + 4, number_format($seg['value']), 11, $seg['textColor'] ?? '#FFFFFF', 'middle', true);
            }
            $x += $segW;
        }

        // Legend.
        $lx = 0.0;
        foreach ($segments as $seg) {
            $svg .= self::rect($lx, $barH + 22, 10, 10, $seg['color']);
            $text = $seg['label'];
            $svg .= self::text($lx + 15, $barH + 31, $text, 10, self::TEXT, 'start');
            $lx += 15 + strlen($text) * 6 + 22;
        }

        return $svg . '</svg>';
    }

    // ------------------------------------------------------------------ helpers

    /** A rounded-up top for the y axis and 4-5 ticks from 0 to it. @return array{0: int, 1: list<int>} */
    public static function niceScale(int $max): array
    {
        $max = max(1, $max);
        $rough = $max / 4;
        $mag = 10 ** floor(log10($rough));
        $norm = $rough / $mag;
        $stepUnit = $norm <= 1 ? 1 : ($norm <= 2 ? 2 : ($norm <= 5 ? 5 : 10));
        $step = max(1, (int) ($stepUnit * $mag));
        $top = (int) (ceil($max / $step) * $step);
        $ticks = [];
        for ($t = 0; $t <= $top; $t += $step) {
            $ticks[] = $t;
        }

        return [$top, $ticks];
    }

    /** Consecutive indexes that have a value: [[0,1,2],[5,6]]. @return list<list<int>> */
    private static function runs(array $values): array
    {
        $runs = [];
        $run = [];
        foreach (array_values($values) as $i => $v) {
            if ($v === null) {
                if ($run !== []) {
                    $runs[] = $run;
                    $run = [];
                }
                continue;
            }
            $run[] = $i;
        }
        if ($run !== []) {
            $runs[] = $run;
        }

        return $runs;
    }

    /** Shortens a label with an ellipsis so it fits $width pixels at $size (about 0.58 em per character). */
    private static function fit(string $text, float $width, float $size): string
    {
        $max = (int) floor($width / ($size * 0.58));

        return mb_strlen($text) > $max ? mb_substr($text, 0, max(1, $max - 1)) . '…' : $text;
    }

    private static function open(int $w, int $h): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">'
            . '<rect x="0" y="0" width="' . $w . '" height="' . $h . '" fill="#FFFFFF"/>';
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    private static function rect(float $x, float $y, float $w, float $h, string $fill): string
    {
        return '<rect x="' . self::n($x) . '" y="' . self::n($y) . '" width="' . self::n($w) . '" height="' . self::n($h) . '" fill="' . $fill . '"/>';
    }

    private static function line(float $x1, float $y1, float $x2, float $y2, string $stroke, float $width, ?string $dash = null): string
    {
        return '<line x1="' . self::n($x1) . '" y1="' . self::n($y1) . '" x2="' . self::n($x2) . '" y2="' . self::n($y2)
            . '" stroke="' . $stroke . '" stroke-width="' . self::n($width) . '"' . ($dash ? ' stroke-dasharray="' . $dash . '"' : '') . '/>';
    }

    /** @param list<array{0: float, 1: float}> $points */
    private static function polyline(array $points, string $stroke, float $width, ?string $dash = null): string
    {
        $pts = implode(' ', array_map(static fn ($p) => self::n($p[0]) . ',' . self::n($p[1]), $points));

        return '<polyline points="' . $pts . '" fill="none" stroke="' . $stroke . '" stroke-width="' . self::n($width) . '"'
            . ($dash ? ' stroke-dasharray="' . $dash . '"' : '') . ' stroke-linejoin="round" stroke-linecap="round"/>';
    }

    /** @param list<array{0: float, 1: float}> $points */
    private static function polygon(array $points, string $fill): string
    {
        $pts = implode(' ', array_map(static fn ($p) => self::n($p[0]) . ',' . self::n($p[1]), $points));

        return '<polygon points="' . $pts . '" fill="' . $fill . '"/>';
    }

    private static function text(float $x, float $y, string $text, float $size, string $fill, string $anchor = 'start', bool $bold = false): string
    {
        return '<text x="' . self::n($x) . '" y="' . self::n($y) . '" font-family="' . self::FONT . '" font-size="' . self::n($size)
            . '" fill="' . $fill . '" text-anchor="' . $anchor . '"' . ($bold ? ' font-weight="bold"' : '') . '>'
            . htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</text>';
    }
}
