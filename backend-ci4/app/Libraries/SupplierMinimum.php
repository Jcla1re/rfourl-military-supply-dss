<?php

namespace App\Libraries;

use App\Models\DssParameterModel;
use App\Models\PdssComputationModel;

/**
 * The note shown next to an Order Qty when the supplier's own minimum order (products.supplier_min_qty)
 * is above the EOQ, so the Order Qty is the supplier's minimum rather than the cost-saving quantity.
 * Shown on Reorder Alerts, in the purchase order dialog and in the Procurement Report.
 *
 *   Supplier minimum is M (EOQ E). Covers about N months of sales; extra storage about ₱X/year.
 *     N = M / D x 12   (D = yearly demand)
 *     X = (M - E) / 2 x H   (the extra average stock held, at the storage cost per item per year)
 *
 * When N is more than 6 it is a warning instead (the owner would not order a big minimum she does not need).
 * Nothing is shown when the minimum is not above the EOQ. No item has one unless the owner typed it.
 */
class SupplierMinimum
{
    public const WARN_MONTHS = 6;

    public const WARNING_TEXT = 'This minimum is more than 6 months of sales. Consider a smaller order from another supplier.';

    /**
     * @return array{level: string, text: string, minimum: int, eoq: int, months: float|null, extra_storage: float}|null
     */
    public static function note(int $minimum, int $eoq, int $annualDemand, float $holdingCost): ?array
    {
        if ($minimum <= 0 || $minimum <= $eoq) {
            return null;
        }

        $exact  = $annualDemand > 0 ? $minimum / $annualDemand * 12 : null; // no sales: no end to it
        $months = $exact !== null ? round($exact, 1) : null;                 // shown to one decimal
        $extra  = round(($minimum - $eoq) / 2 * $holdingCost, 2);

        $tooBig = $exact === null || $exact > self::WARN_MONTHS;
        $text   = $tooBig
            ? self::WARNING_TEXT
            : "Supplier minimum is {$minimum} (EOQ {$eoq}). Covers about " . self::months($months) . ' of sales; extra storage about ₱' . number_format($extra) . '/year.';

        return ['level' => $tooBig ? 'warning' : 'info', 'text' => $text, 'minimum' => $minimum, 'eoq' => $eoq, 'months' => $months, 'extra_storage' => $extra];
    }

    /** "2.4 months", "1 month", "3 months". */
    private static function months(float $n): string
    {
        $label = rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');

        return $label . ($label === '1' ? ' month' : ' months');
    }

    /**
     * The note for one product row. Reads the item's latest DSS numbers (eoq_raw, yearly demand) and the
     * storage cost only when the item has a supplier minimum, so most items cost nothing extra.
     *
     * @param array|null $computation the item's pdss_computation row when the caller already has it
     * @param array|null $dss         dss_parameters when the caller already has it
     */
    public static function forProduct(array $product, ?array $computation = null, ?array $dss = null): ?array
    {
        $minimum = (int) ($product['supplier_min_qty'] ?? 0);
        if ($minimum <= 0) {
            return null;
        }

        $computation ??= (is_array($product['computation'] ?? null) ? $product['computation'] : null) ?? (new PdssComputationModel())->latestFor((string) $product['item_id']);
        $dss         ??= (new DssParameterModel())->current();
        if ($computation === null) {
            return null; // no DSS numbers yet: nothing to compare the minimum with
        }

        $demand  = (int) ($computation['annual_demand'] ?? 0);
        $holding = (float) ($dss['holding_cost_per_unit'] ?? 0);
        $eoq     = isset($computation['eoq_raw']) && $computation['eoq_raw'] !== null
            ? (int) $computation['eoq_raw']
            : DssFormulas::eoq($demand, (float) ($dss['ordering_cost'] ?? 0), $holding); // rows saved before eoq_raw existed

        return self::note($minimum, $eoq, $demand, $holding);
    }
}
