<?php

namespace App\Libraries;

/**
 * The pure math of the DSS engine (demand statistics, Safety Stock,
 * Reorder Point, EOQ), kept free of database and I/O so it can be unit
 * tested. `php spark dss:run` calls these; the formulas are unchanged.
 */
class DssFormulas
{
    /**
     * Mean and standard deviation of a daily-demand series (one entry per
     * day of the lookback window, zeros included).
     *
     * @param list<int|float> $series
     * @return array{avg: float, std: float}
     */
    public static function demandStats(array $series): array
    {
        $n = count($series);
        if ($n === 0) {
            return ['avg' => 0.0, 'std' => 0.0];
        }

        $avg      = array_sum($series) / $n;
        $variance = 0.0;
        foreach ($series as $v) {
            $variance += ($v - $avg) ** 2;
        }

        // Population standard deviation (divides by n, not n - 1).
        return ['avg' => $avg, 'std' => sqrt($variance / $n)];
    }

    /**
     * First day of the demand window: `lookbackDays` days before the as-of
     * date, inclusive. A sale exactly `lookbackDays` days back is counted;
     * one day further back is not.
     */
    public static function windowStart(\DateTimeInterface $asOf, int $lookbackDays): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($asOf)->modify('-' . $lookbackDays . ' days');
    }

    public static function annualDemand(float $avgDaily): int
    {
        return (int) round($avgDaily * 365);
    }

    /** SS = Z x sigma_d x sqrt(L), rounded up to a whole unit, never negative. */
    public static function safetyStock(float $zScore, float $stdDev, int $leadTimeDays): int
    {
        return (int) ceil(max(0, $zScore * $stdDev * sqrt($leadTimeDays)));
    }

    /** ROP = (d x L) + SS, rounded up to a whole unit. */
    public static function reorderPoint(float $avgDaily, int $leadTimeDays, int $safetyStock): int
    {
        return (int) ceil(($avgDaily * $leadTimeDays) + $safetyStock);
    }

    /**
     * What dss:run writes to the item's Reorder At (products.manual_rop_warning / rop_source),
     * or null to leave it as it is.
     *
     *  - Calculated ROP above 0: written and marked 'dss', even over a value the Owner typed
     *    (the Edit item field then shows "Auto").
     *  - Calculated ROP 0 (too few sales): written as 0 only when the current value came from the
     *    DSS ('dss'), so an old calculated value doesn't stay behind; a typed value ('manual', or
     *    no source recorded) is kept.
     *
     * @return array{manual_rop_warning: int, rop_source: string}|null
     */
    public static function reorderPointUpdate(int $calculated, ?int $current, ?string $source): ?array
    {
        if ($calculated > 0) {
            return ['manual_rop_warning' => $calculated, 'rop_source' => 'dss'];
        }
        if ($source === 'dss' && (int) $current !== 0) {
            return ['manual_rop_warning' => 0, 'rop_source' => 'dss'];
        }

        return null;
    }

    /**
     * The Order Qty dss:run saves: the EOQ, but never below the system minimum order qty or the supplier's
     * own minimum for the item (null / 0 = the supplier has none).
     */
    public static function orderQty(int $eoqRaw, int $systemMinimum, ?int $supplierMinimum = null): int
    {
        return max($eoqRaw, $systemMinimum, (int) $supplierMinimum);
    }

    /**
     * EOQ = sqrt(2DS / H). Returns 0 (no recommendation) when demand or
     * holding cost is not positive, so there is never a division by zero.
     */
    public static function eoq(int $annualDemand, float $orderingCost, float $holdingCost): int
    {
        if ($annualDemand <= 0 || $holdingCost <= 0) {
            return 0;
        }

        return (int) round(sqrt((2 * $annualDemand * $orderingCost) / $holdingCost));
    }
}
