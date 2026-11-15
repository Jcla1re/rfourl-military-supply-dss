<?php

namespace App\Libraries;

/**
 * Trend Analysis > Projection: the expected items sold per month for next year, from plain averages
 * of what was really sold. Not machine learning, and nothing is fitted: every number can be checked
 * by hand from the monthly totals.
 *
 *  - For each month m, the projection is the average of month m over the last 3 years in which that
 *    month is complete. The band is the lowest and highest of those years.
 *  - A month does not count when the shop recorded no sales in it, when it comes after the last
 *    recorded sale, or when it is partial (the month of the first sale unless that sale was on the
 *    1st, and the month of the last sale unless that sale was on the last day of the month).
 *  - With an item chosen, the same method runs on that item's units; whether a month counts is still
 *    decided by the shop's sales, so a quiet month for one item is a real zero.
 */
class DemandProjection
{
    public const YEARS_USED = 3;

    public const MONTH_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    public const METHOD = 'Each month is the average of that month in the last 3 complete years that have data for it '
        . '(partial months and months after the last recorded sale are left out); the band runs from the lowest to the highest of those years.';

    public static function monthName(int $month): string
    {
        return date('F', mktime(0, 0, 0, $month, 1, 2000));
    }

    // ------------------------------------------------------------------ the rule

    /**
     * Is this month a whole month the shop has data for?
     *
     * @param array<string, mixed> $shopMonths 'YYYY-MM' => (anything) for months with at least one sale, any item
     */
    public static function isCompleteMonth(int $year, int $month, array $shopMonths, ?string $firstSale, ?string $lastSale): bool
    {
        if ($firstSale === null || $lastSale === null) {
            return false;
        }
        $ym = sprintf('%04d-%02d', $year, $month);
        if (! isset($shopMonths[$ym])) {
            return false;
        }

        $firstYm = substr($firstSale, 0, 7);
        $lastYm  = substr($lastSale, 0, 7);
        if ($ym < $firstYm || $ym > $lastYm) {
            return false; // before the data starts / after the last recorded sale
        }
        if ($ym === $firstYm && (int) substr($firstSale, 8, 2) > 1) {
            return false; // the first month is partial unless the first sale was on the 1st
        }
        if ($ym === $lastYm && (int) substr($lastSale, 8, 2) < (int) date('t', strtotime($ym . '-01'))) {
            return false; // the last month is partial unless the last sale was on its last day
        }

        return true;
    }

    /**
     * The projection for each month of $targetYear.
     *
     * @param array<int, array<int, int|float>> $units      year => month => units sold (the item's, or the whole shop's)
     * @param array<string, mixed>              $shopMonths 'YYYY-MM' => true for months with any sale
     *
     * @return list<array{month: int, name: string, projection: float|null, min: int|null, max: int|null, years: list<int>}>
     */
    public static function project(array $units, array $shopMonths, ?string $firstSale, ?string $lastSale, int $targetYear): array
    {
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $values = [];
            for ($y = $targetYear - 1; $y >= $targetYear - 30 && count($values) < self::YEARS_USED; $y--) {
                if (self::isCompleteMonth($y, $m, $shopMonths, $firstSale, $lastSale)) {
                    $values[$y] = (int) round((float) ($units[$y][$m] ?? 0));
                }
            }
            $out[] = [
                'month'      => $m,
                'name'       => self::monthName($m),
                'projection' => $values === [] ? null : round(array_sum($values) / count($values), 2),
                'min'        => $values === [] ? null : min($values),
                'max'        => $values === [] ? null : max($values),
                'years'      => array_keys($values), // newest first
            ];
        }

        return $out;
    }

    /**
     * The 3 months with the highest projection, each with the month to order before.
     *
     * @param list<array> $months project()'s result
     *
     * @return list<array{month: int, name: string, projection: float, order_before: string, text: string}>
     */
    public static function peaks(array $months): array
    {
        $candidates = array_values(array_filter($months, static fn ($m) => $m['projection'] !== null && $m['projection'] > 0));
        usort($candidates, static fn ($a, $b) => [$b['projection'], $a['month']] <=> [$a['projection'], $b['month']]);

        $peaks = [];
        foreach (array_slice($candidates, 0, 3) as $m) {
            $before  = self::monthName($m['month'] === 1 ? 12 : $m['month'] - 1);
            $peaks[] = [
                'month'        => $m['month'],
                'name'         => $m['name'],
                'projection'   => $m['projection'],
                'order_before' => $before,
                'text'         => "Expected peak in {$m['name']}: order before {$before} so stock arrives in time.",
            ];
        }

        return $peaks;
    }

    /**
     * With an item chosen: how much to hold for its peak month. Projected sales plus the item's safety
     * stock (0 when the DSS has not calculated one yet).
     *
     * @param list<array> $peaks peaks()'s result
     *
     * @return array{month: string, pieces: int, safety_stock: int, has_safety_stock: bool, text: string}|null
     */
    public static function suggestion(array $peaks, ?float $safetyStock): ?array
    {
        if ($peaks === []) {
            return null;
        }
        $peak   = $peaks[0];
        $safety = (int) round($safetyStock ?? 0);
        $pieces = (int) round($peak['projection']) + $safety;

        return [
            'month'            => $peak['name'],
            'pieces'           => $pieces,
            'safety_stock'     => $safety,
            'has_safety_stock' => $safetyStock !== null,
            'text'             => "Suggested stock for {$peak['name']}: about {$pieces} pieces (projected sales + safety stock).",
        ];
    }

    // ------------------------------------------------------------------ everything the page and the PDF show

    /**
     * @param array<int, array<int, int|float>> $units
     * @param array<string, mixed>              $shopMonths
     *
     * @return array<string, mixed>
     */
    public static function assemble(array $units, array $shopMonths, ?string $firstSale, ?string $lastSale, int $currentYear, ?float $safetyStock = null, ?string $itemLabel = null): array
    {
        $months = self::project($units, $shopMonths, $firstSale, $lastSale, $currentYear + 1);
        $peaks  = self::peaks($months);

        // The current year's actual: whole months only, like the months that feed the projection.
        $actual = [];
        for ($m = 1; $m <= 12; $m++) {
            $actual[] = self::isCompleteMonth($currentYear, $m, $shopMonths, $firstSale, $lastSale) ? (int) round((float) ($units[$currentYear][$m] ?? 0)) : null;
        }

        return [
            'targetYear'  => $currentYear + 1,
            'currentYear' => $currentYear,
            'labels'      => self::MONTH_SHORT,
            'months'      => $months,
            'projection'  => array_column($months, 'projection'),
            'min'         => array_column($months, 'min'),
            'max'         => array_column($months, 'max'),
            'actual'      => $actual,
            'peaks'       => $peaks,
            'suggestion'  => $itemLabel !== null ? self::suggestion($peaks, $safetyStock) : null,
            'item'        => $itemLabel,
            'hasData'     => $peaks !== [],
            'method'      => self::METHOD,
            'seriesName'  => 'Projection (average of past years)',
        ];
    }

    /**
     * Reads the monthly totals and builds the projection (one item, or all items when $itemId is null).
     * Month keys are cut from the sale date as text ('YYYY-MM'), which both MySQL and SQLite read the same way.
     *
     * @return array<string, mixed>
     */
    public static function build(?string $itemId, ?float $safetyStock = null, ?string $itemLabel = null, ?int $currentYear = null): array
    {
        $db = db_connect();

        $builder = $db->table('sales_item si')
            ->select('SUBSTR(st.sale_date, 1, 7) AS ym, SUM(si.quantity_sold) AS total', false)
            ->join('sales_transaction st', 'st.sales_id = si.sales_id')
            ->groupBy('SUBSTR(st.sale_date, 1, 7)', false);
        if ($itemId) {
            $builder->where('si.item_id', $itemId);
        }
        $units = [];
        foreach ($builder->get()->getResultArray() as $row) {
            $units[(int) substr((string) $row['ym'], 0, 4)][(int) substr((string) $row['ym'], 5, 2)] = (int) $row['total'];
        }

        $shopMonths = [];
        foreach ($db->table('sales_transaction')->select('SUBSTR(sale_date, 1, 7) AS ym', false)->groupBy('SUBSTR(sale_date, 1, 7)', false)->get()->getResultArray() as $row) {
            $shopMonths[(string) $row['ym']] = true;
        }
        $range = $db->table('sales_transaction')->select('MIN(sale_date) AS first_sale, MAX(sale_date) AS last_sale', false)->get()->getRowArray();

        return self::assemble($units, $shopMonths, $range['first_sale'] ?? null, $range['last_sale'] ?? null, $currentYear ?? (int) date('Y'), $safetyStock, $itemId ? ($itemLabel ?? $itemId) : null);
    }
}
