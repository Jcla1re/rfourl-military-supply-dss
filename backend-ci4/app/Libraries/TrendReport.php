<?php

namespace App\Libraries;

use App\Models\ClusterSegmentModel;

/**
 * Everything the Trend Analysis PDF shows, worked out from the page's own data (the array
 * TrendAnalysisController::buildData() returns, plus a few extras added for the report). The view
 * only lays this out, so the numbers in the PDF are the numbers on screen, and the sentences
 * ("What this shows") can be tested without a database or a PDF engine.
 */
class TrendReport
{
    public const STORE = 'RfourL Military Apparel';

    private const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    private const MEANINGS = [
        'A' => 'Highest priority: keep the highest safety stock (95% service level).',
        'B' => 'Medium priority: keep a medium safety stock (90% service level).',
        'C' => 'Lowest priority: keep the lowest safety stock (85% service level).',
    ];

    /**
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    public static function build(array $d): array
    {
        $year      = (int) $d['selectedYear'];
        $item      = $d['itemLabel'] ?? null;
        $scoped    = $item !== null && $item !== '';
        $scopeText = $scoped ? $item : 'all items';

        $report = [
            'year'      => $year,
            'scoped'    => $scoped,
            'itemLabel' => $scoped ? $item : 'All items',
            'footer'    => self::STORE . ' · Trend Analysis ' . $year . ' · Page {PAGE_NUM} of {PAGE_COUNT}',
        ];

        $report['header'] = [
            'store'      => self::STORE,
            'year'       => $year,
            'item'       => $report['itemLabel'],
            'coverage'   => 'Data covers ' . $d['periodLabel'] . '.',
            'generated'  => ($d['generatedAt'] ?? date('M j, Y g:i A')) . ' (Philippine time)',
            'lastSale'   => ! empty($d['lastSaleAt']) ? date('M j, Y', strtotime((string) $d['lastSaleAt'])) : 'No sales recorded yet',
        ];

        $report['cards']    = self::cards($d, $year, $scoped, $scopeText);
        $report['monthly']  = self::monthly($d, $year, $scopeText);
        $report['weekly']   = self::weekly($d, $scoped);
        $report['products'] = self::products($d);
        $report['abc']      = self::abc($d, $year);
        $report['demand']   = self::demand($d);
        $report['notes']    = self::notes($d, $year);

        return $report;
    }

    // ------------------------------------------------------------------ 2. summary

    private static function cards(array $d, int $year, bool $scoped, string $scopeText): array
    {
        $units     = (int) $d['unitsYtd'];
        $prevUnits = (int) $d['unitsPrev'];
        $pct       = $d['changePct'];
        $prevRange = (string) $d['prevPeriodLabel'];

        if ($pct === null) {
            $changeValue    = 'No sales last year';
            $changeSub      = 'Nothing to compare with';
            $changeSentence = "There were no sales in the same dates last year ({$prevRange}), so there is nothing to compare with.";
        } else {
            $abs            = number_format(abs($pct), 1);
            $changeValue    = self::signed($pct, 1) . '%';
            $changeSub      = number_format($prevUnits) . ' items, ' . $prevRange;
            $changeSentence = abs($pct) < 0.05
                ? "Compared with the same dates last year ({$prevRange}), sales were about the same."
                : "Compared with the same dates last year ({$prevRange}), sales were {$abs}% " . ($pct > 0 ? 'higher' : 'lower') . '.';
        }
        $changeSentence .= ' This card compares the same dates in both years, not the full years.';

        $best = $d['bestMonth'] ?? null;
        $top  = $d['topItem'] ?? null;

        return [
            [
                'label'    => "Items sold in {$year}",
                'value'    => number_format($units),
                'sub'      => (string) $d['periodLabel'],
                'tone'     => 'plain',
                'sentence' => number_format($units) . ' items of ' . $scopeText . " were sold from {$d['periodLabel']}.",
            ],
            [
                'label'    => 'vs. same period last year',
                'value'    => $changeValue,
                'sub'      => $changeSub,
                'tone'     => $pct === null ? 'plain' : ($pct > 0.05 ? 'up' : ($pct < -0.05 ? 'down' : 'plain')),
                'sentence' => $changeSentence,
            ],
            [
                'label'    => 'Best month',
                'value'    => $best ? $best['name'] : '—',
                'sub'      => $best ? number_format($best['units']) . ' items sold' : 'No sales yet',
                'tone'     => 'plain',
                'sentence' => $best
                    ? "{$best['name']} had the most items sold in {$year}: " . number_format($best['units']) . '.'
                    : "No month in {$year} has any sales yet.",
            ],
            [
                'label'    => "Top item in {$year}",
                'value'    => $top ? (string) ($top['item_name'] ?? $top['item_id']) : '—',
                'sub'      => $top ? number_format((int) $top['units_sold']) . ' items sold' : 'No sales yet',
                'tone'     => 'plain',
                'sentence' => $top
                    ? ($top['item_name'] ?? $top['item_id']) . ' sold the most in ' . $year . ': ' . number_format((int) $top['units_sold']) . ' items'
                        . ($scoped ? ' (counted across all items, as on the page).' : '.')
                    : "No item has sold in {$year} yet.",
            ],
        ];
    }

    // ------------------------------------------------------------------ 3. months

    private static function monthly(array $d, int $year, string $scopeText): array
    {
        $labels   = $d['annualLabels'];
        $current  = array_values($d['annualData']);
        $previous = array_map('intval', array_values($d['annualDataPrev']));
        $sofar    = (int) $d['monthsSoFar'];

        $rows = [];
        foreach ($labels as $i => $label) {
            $cur = $current[$i] ?? null;
            $prev = $previous[$i];
            $rows[] = [
                'month' => $label,
                'this'  => $cur === null ? '' : number_format($cur),
                'prev'  => number_format($prev),
                'diff'  => $cur === null ? '' : self::signed($cur - $prev, 0),
                'pct'   => $cur === null ? '' : self::pct($cur, $prev),
            ];
        }

        $end   = date('M j', strtotime((string) $d['periodEnd']));
        $units = (int) $d['unitsYtd'];
        $prevU = (int) $d['unitsPrev'];
        $totals = [
            [
                'label' => "Total (Jan 1 – {$end})",
                'this'  => number_format($units),
                'prev'  => number_format($prevU),
                'diff'  => self::signed($units - $prevU, 0),
                'pct'   => self::pct($units, $prevU),
            ],
            [
                'label' => 'Full year ' . ($year - 1),
                'this'  => '',
                'prev'  => number_format(array_sum($previous)),
                'diff'  => '',
                'pct'   => '',
            ],
        ];

        $chart = PdfCharts::lineChart(
            $labels,
            array_map(static fn ($v) => $v === null ? null : (int) $v, $current),
            $previous,
            [
                'currentName'  => (string) $year,
                'previousName' => (string) ($year - 1),
                'futureFrom'   => $sofar < 12 ? $sofar : null,
                'height'       => 250,
            ]
        );

        return [
            'title'    => 'Items sold per month · ' . ($scopeText === 'all items' ? 'all items' : $scopeText),
            'chart'    => PdfCharts::dataUri($chart),
            'rows'     => $rows,
            'totals'   => $totals,
            'insights' => self::insights($d, $year),
            'footnote' => 'The total rows compare the same dates in both years.'
                . ($year === (int) date('Y') ? ' The monthly rows compare whole months, so the month in progress looks smaller than last year\'s full month.' : ''),
        ];
    }

    /** 3-4 plain sentences from the monthly numbers. @return list<string> */
    public static function insights(array $d, int $year): array
    {
        $current  = array_values($d['annualData']);
        $previous = array_map('intval', array_values($d['annualDataPrev']));
        $sofar    = (int) $d['monthsSoFar'];
        $out      = [];

        if ($sofar < 1 || array_sum(array_map('intval', array_slice($current, 0, $sofar))) === 0) {
            return ["No items have been sold in {$year} yet."];
        }

        // The month in progress is only partly done, so it isn't judged against whole months.
        $partial  = $year === (int) date('Y') && ! empty($d['periodEnd']) && date('t', strtotime((string) $d['periodEnd'])) !== date('j', strtotime((string) $d['periodEnd']));
        $whole    = $partial ? $sofar - 1 : $sofar;
        $name     = static fn (int $i) => self::MONTHS[$i];
        $short    = static fn (int $i) => substr(self::MONTHS[$i], 0, 3);

        // 1. Best and weakest month.
        $withSales = [];
        for ($i = 0; $i < $whole; $i++) {
            if ((int) $current[$i] > 0) {
                $withSales[$i] = (int) $current[$i];
            }
        }
        if ($withSales !== []) {
            $best = array_search(max($withSales), $withSales, true);
            $weak = array_search(min($withSales), $withSales, true);
            $s = "{$name($best)} was the strongest month with " . number_format($withSales[$best]) . ' items';
            if ($weak !== $best) {
                $s .= ", and {$name($weak)} the weakest with " . number_format($withSales[$weak]) . ' items';
            }
            $out[] = $s . ($partial ? ' (' . $name($sofar - 1) . ', still in progress, is not counted).' : '.');
        }

        // 2. Biggest increase and drop against last year, whole months only.
        $diffs = [];
        for ($i = 0; $i < $whole; $i++) {
            $diffs[$i] = (int) $current[$i] - $previous[$i];
        }
        if ($diffs !== []) {
            $up   = array_search(max($diffs), $diffs, true);
            $down = array_search(min($diffs), $diffs, true);
            $parts = [];
            if ($diffs[$up] > 0) {
                $parts[] = "{$short($up)} had the biggest increase (+" . number_format($diffs[$up]) . ' items)';
            }
            if ($diffs[$down] < 0) {
                $parts[] = "{$short($down)} had the biggest drop (" . number_format($diffs[$down]) . ' items)';
            }
            if ($parts !== []) {
                $out[] = 'Compared with ' . ($year - 1) . ', ' . implode(' and ', $parts) . '.';
            } else {
                $out[] = 'No month was above or below ' . ($year - 1) . '.';
            }
        }

        // 3. Runs of months with no sales (the month in progress counts: nothing sold so far is still nothing).
        $runs = [];
        $start = null;
        for ($i = 0; $i <= $sofar; $i++) {
            $zero = $i < $sofar && (int) $current[$i] === 0;
            if ($zero && $start === null) {
                $start = $i;
            }
            if (! $zero && $start !== null) {
                $runs[] = [$start, $i - 1];
                $start = null;
            }
        }
        if ($runs !== []) {
            $text = array_map(static fn ($r) => $r[0] === $r[1]
                ? "in {$short($r[0])} {$year}"
                : "from {$short($r[0])} to {$short($r[1])} {$year}", $runs);
            $out[] = 'No sales were recorded ' . implode(', and ', $text) . '.';
        }

        return $out;
    }

    // ------------------------------------------------------------------ 4. weeks

    private static function weekly(array $d, bool $scoped): array
    {
        $weeks = $d['weeks12'] ?? [];
        $chart = PdfCharts::barChart(
            array_column($weeks, 'short'),
            array_map('intval', array_column($weeks, 'units')),
            ['highlightLast' => true, 'height' => 200]
        );

        $last = $weeks ? end($weeks) : null;
        $prev = count($weeks) > 1 ? $weeks[count($weeks) - 2] : null;

        return [
            'chart'    => PdfCharts::dataUri($chart),
            'rows'     => array_map(static fn ($w) => ['range' => $w['label'], 'units' => number_format((int) $w['units'])], $weeks),
            'sentence' => $last
                ? 'The latest week (' . $last['label'] . ') sold ' . number_format((int) $last['units']) . ' items'
                    . ($prev ? ', and the week before sold ' . number_format((int) $prev['units']) . ' items' : '')
                    . ". These are the same two weeks the page's Weekly view compares."
                : 'No weekly sales to show yet.',
        ];
    }

    // ------------------------------------------------------------------ 5. products

    private static function products(array $d): array
    {
        $sellers = $d['topSellers'] ?? [];
        $rows    = array_map(static fn ($s) => [(string) ($s['item_name'] ?? $s['item_id']), (int) $s['units_sold']], $sellers);
        $total   = array_sum(array_column($rows, 1));
        $sentence = 'No sales recorded yet.';
        if ($rows !== [] && $total > 0) {
            $share    = number_format(($rows[0][1] / $total) * 100, 1);
            $sentence = "{$rows[0][0]} is the top item with " . number_format($rows[0][1]) . " items, {$share}% of the " . number_format($total) . ' items sold by these five.';
        }

        return [
            'chart'    => $rows ? PdfCharts::dataUri(PdfCharts::horizontalBars($rows)) : null,
            'sentence' => $sentence,
        ];
    }

    // ------------------------------------------------------------------ 6. ABC

    private static function abc(array $d, int $year): array
    {
        $counts  = $d['abcClassCounts'] ?? ['A' => 0, 'B' => 0, 'C' => 0];
        $shares  = $d['abcClassTotals'] ?? ['A' => 0.0, 'B' => 0.0, 'C' => 0.0];
        $unclass = (int) ($d['abcUnclassified'] ?? 0);
        $byClass = $d['abcByClass'] ?? ['A' => [], 'B' => [], 'C' => []];
        $svc     = ClusterSegmentModel::SERVICE_LEVELS;

        $classes = [];
        foreach (['A', 'B', 'C'] as $c) {
            $classes[] = [
                'class'    => $c,
                'items'    => number_format((int) ($counts[$c] ?? 0)),
                'priority' => ClusterSegmentModel::PRIORITY[$c],
                'service'  => $svc[$c] . '%',
                'share'    => number_format((float) ($shares[$c] ?? 0), 1) . '%',
                'meaning'  => self::MEANINGS[$c],
                'top'      => array_map(static fn ($it) => [
                    'rank'  => $it['rank'],
                    'label' => $it['label'] . ($it['show_code'] ? ' [' . $it['code'] . ']' : ''),
                    'pct'   => number_format((float) $it['pct'], 1) . '%',
                ], array_slice($byClass[$c] ?? [], 0, 5)),
            ];
        }

        $segments = [
            ['label' => 'Class A: ' . number_format((int) ($counts['A'] ?? 0)) . ' items', 'value' => (int) ($counts['A'] ?? 0), 'color' => PdfCharts::DARK],
            ['label' => 'Class B: ' . number_format((int) ($counts['B'] ?? 0)) . ' items', 'value' => (int) ($counts['B'] ?? 0), 'color' => PdfCharts::MEDIUM],
            ['label' => 'Class C: ' . number_format((int) ($counts['C'] ?? 0)) . ' items', 'value' => (int) ($counts['C'] ?? 0), 'color' => PdfCharts::LIGHT, 'textColor' => PdfCharts::DARK],
        ];
        if ($unclass > 0) {
            $segments[] = ['label' => 'Not classified yet: ' . number_format($unclass) . ' items', 'value' => $unclass, 'color' => PdfCharts::UNCLASSED, 'textColor' => PdfCharts::DARK];
        }

        return [
            'chart'      => PdfCharts::dataUri(PdfCharts::stackedBar($segments)),
            'classes'    => $classes,
            'any'        => array_sum($counts) + $unclass > 0,
            'unclassed'  => $unclass,
            'unclassedNote' => $unclass > 0
                ? number_format($unclass) . ' ' . ($unclass === 1 ? 'item sold' : 'items sold') . " in {$year} " . ($unclass === 1 ? 'has' : 'have') . ' no class yet (run the DSS engine); their sales are not in these shares.'
                : '',
            'sentence'   => 'Items are grouped by K-Means clustering using unit cost, average daily demand, demand variability, and supplier lead time.',
            'shareNote'  => "Share of sales is each class's part of the {$year} sales value (quantity × selling price).",
        ];
    }

    // ------------------------------------------------------------------ 7. daily demand

    private static function demand(array $d): array
    {
        $rows = [];
        foreach ($d['variabilityAll'] ?? [] as $v) {
            $rows[] = [
                'rank'   => $v['rank'],
                'item'   => $v['label'] . ($v['show_code'] ? ' [' . $v['code'] . ']' : ''),
                'daily'  => number_format((float) $v['avg_daily_demand'], 2),
                'safety' => number_format((float) $v['safety_stock'], 0),
                'class'  => $v['class'] ?? '–',
            ];
        }

        return [
            'rows'   => $rows,
            'howTo'  => [
                'Daily sales' => 'the average number of units sold per day.',
                'Safety stock' => 'extra units kept to cover changes in demand during the supplier lead time.',
                'Class' => 'the ABC group from the K-Means clustering (A = highest priority, C = lowest).',
            ],
        ];
    }

    // ------------------------------------------------------------------ 8. notes

    private static function notes(array $d, int $year): array
    {
        $run = ! empty($d['dssRunAt']) ? date('M j, Y', strtotime((string) $d['dssRunAt'])) : null;

        return [
            'lines' => [
                'Items sold = the total of the quantities on every sale in the period (or month).',
                'Difference = this year minus last year; % change = difference ÷ last year × 100, shown as "–" when last year is 0.',
                'vs. same period last year = items sold from Jan 1 to the last day shown, compared with the same dates one year earlier.',
                'Best month = the month with the most items sold so far; top item = the item with the most items sold in ' . $year . '.',
                'Weeks run for 7 days and end on the last recorded sale date of the year; the latest bar is the page\'s "this week".',
                'Share of sales = quantity × selling price of the items in a class ÷ the total sales value of ' . $year . '.',
                'Daily sales = average units sold per day over the demand window; safety stock = Z-score × standard deviation of daily demand × √lead time, rounded up.',
            ],
            'dss' => $run
                ? "The classes, daily sales and safety stock values come from the latest DSS run on {$run}."
                : 'The DSS engine has not been run yet, so classes, daily sales and safety stock may be missing.',
        ];
    }

    // ------------------------------------------------------------------ small helpers

    private static function signed(float|int $n, int $decimals): string
    {
        $s = number_format(abs($n), $decimals);

        return $n > 0 ? "+{$s}" : ($n < 0 ? "-{$s}" : $s);
    }

    private static function pct(int $cur, int $prev): string
    {
        return $prev > 0 ? self::signed((($cur - $prev) / $prev) * 100, 1) . '%' : '–';
    }
}
