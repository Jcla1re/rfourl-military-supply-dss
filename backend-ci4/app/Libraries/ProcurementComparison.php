<?php

namespace App\Libraries;

use App\Models\DssParameterModel;

/**
 * The Procurement Report's "Cost comparison" tab: what the owner's usual order quantity costs a year
 * against the Order Qty the system suggests, for every size that has sales.
 *
 *   TC(Q)    = D / Q x S + Q / 2 x H      D = yearly demand, S = cost per order, H = storage cost per item per year
 *   savings  = TC(owner's Q) - TC(system's Q)   (in pesos, and as a share of the owner's cost)
 *   orders a year = D / Q
 *
 * Plain arithmetic on the saved DSS numbers: nothing is fitted or estimated. Read-only, and shared by the
 * page, the PDF and the CSV so they always show the same rows.
 */
class ProcurementComparison
{
    /** Total yearly cost of ordering Q at a time: ordering cost plus average storage. */
    public static function totalCost(float $demand, float $quantity, float $orderingCost, float $holdingCost): float
    {
        if ($quantity <= 0) {
            return 0.0;
        }

        return $demand / $quantity * $orderingCost + $quantity / 2 * $holdingCost;
    }

    /** How many orders a year ordering Q at a time takes. */
    public static function ordersPerYear(float $demand, float $quantity): float
    {
        return $quantity > 0 ? $demand / $quantity : 0.0;
    }

    /**
     * One row per size that has demand and an Order Qty, matching the report's filters, by item name then size.
     *
     * @return list<array{item_id: string, item_name: string, size: ?string, class: ?string, demand: int, q_owner: int, q_sys: int,
     *                    tc_owner: float, tc_sys: float, savings: float, savings_pct: float, orders_owner: float, orders_sys: float}>
     */
    public static function rows(array $filters, ?array $dss = null): array
    {
        $dss ??= (new DssParameterModel())->current();
        $owner   = self::ownerQty($dss);
        $S       = (float) ($dss['ordering_cost'] ?? 0);
        $H       = (float) ($dss['holding_cost_per_unit'] ?? 0);

        $rows = [];
        foreach (ProcurementReport::rows($filters, $dss) as $r) {
            if ($r['annual_demand'] <= 0 || $r['order_qty'] === null || $r['order_qty'] <= 0) {
                continue; // no demand, nothing to compare
            }
            $D        = (int) $r['annual_demand'];
            $tcOwner  = self::totalCost($D, $owner, $S, $H);
            $tcSys    = self::totalCost($D, (int) $r['order_qty'], $S, $H);
            $savings  = $tcOwner - $tcSys;
            $rows[]   = [
                'item_id'      => $r['item_id'],
                'item_name'    => $r['item_name'],
                'size'         => $r['size'],
                'class'        => $r['class'],
                'demand'       => $D,
                'q_owner'      => $owner,
                'q_sys'        => (int) $r['order_qty'],
                'tc_owner'     => round($tcOwner, 2),
                'tc_sys'       => round($tcSys, 2),
                'savings'      => round($savings, 2),
                'savings_pct'  => $tcOwner > 0 ? round($savings / $tcOwner * 100, 1) : 0.0,
                'orders_owner' => round(self::ordersPerYear($D, $owner), 1),
                'orders_sys'   => round(self::ordersPerYear($D, (int) $r['order_qty']), 1),
            ];
        }

        return $rows;
    }

    /** The owner's usual order quantity from the settings (20 until she changes it). */
    public static function ownerQty(array $dss): int
    {
        $q = (int) ($dss['owner_usual_order_qty'] ?? 0);

        return $q > 0 ? $q : DssParameterModel::DEFAULT_OWNER_USUAL_ORDER_QTY;
    }

    /**
     * The four cards: the yearly cost of the owner's usual order, with the system's Order Qty, the saving
     * and how many sizes were looked at (a size with no sales is not part of it).
     *
     * @return array{owner_cost: float, sys_cost: float, savings: float, savings_pct: float, items: int}
     */
    public static function totals(array $rows): array
    {
        $owner = round(array_sum(array_column($rows, 'tc_owner')), 2);
        $sys   = round(array_sum(array_column($rows, 'tc_sys')), 2);
        $save  = round($owner - $sys, 2);

        return ['owner_cost' => $owner, 'sys_cost' => $sys, 'savings' => $save, 'savings_pct' => $owner > 0 ? round($save / $owner * 100, 1) : 0.0, 'items' => count($rows)];
    }

    /**
     * The "Basis" lines under the exports.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function basis(?string $lastRunAt, array $dss): array
    {
        $money = static fn ($n): string => '₱' . rtrim(rtrim(number_format((float) $n, 2, '.', ','), '0'), '.');

        return [
            ['DSS results date', $lastRunAt ? date('M j, Y', strtotime($lastRunAt)) : 'Not calculated yet'],
            ["Owner's usual order qty", (string) self::ownerQty($dss)],
            ['Ordering cost (S)', $money($dss['ordering_cost'] ?? 0) . ' per order'],
            ['Storage cost (H)', $money($dss['holding_cost_per_unit'] ?? 0) . ' per item per year'],
            ['Formula', 'Yearly cost = D / Q x S + Q / 2 x H; savings = cost at the owner\'s usual qty - cost at the system Order Qty'],
        ];
    }

    /** The CSV export: one line per size, then the totals and the Basis. */
    public static function csv(array $rows, array $basis): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads peso signs correctly

        fputcsv($out, ['Item ID', 'Item', 'Size', 'Class', 'Yearly Demand', "Owner's Qty", 'System Qty', "Yearly Cost (Owner's Qty)", 'Yearly Cost (System Qty)', 'Savings', 'Savings %', "Orders a Year (Owner's Qty)", 'Orders a Year (System Qty)']);
        foreach ($rows as $r) {
            fputcsv($out, [
                self::safe($r['item_id']), self::safe($r['item_name']), self::safe($r['size'] ?? '—'), $r['class'] ?? '—', $r['demand'], $r['q_owner'], $r['q_sys'],
                number_format($r['tc_owner'], 2, '.', ''), number_format($r['tc_sys'], 2, '.', ''), number_format($r['savings'], 2, '.', ''), number_format($r['savings_pct'], 1, '.', ''),
                number_format($r['orders_owner'], 1, '.', ''), number_format($r['orders_sys'], 1, '.', ''),
            ]);
        }

        $t = self::totals($rows);
        fputcsv($out, ['Total', $t['items'] . ' sizes', '', '', '', '', '', number_format($t['owner_cost'], 2, '.', ''), number_format($t['sys_cost'], 2, '.', ''), number_format($t['savings'], 2, '.', ''), number_format($t['savings_pct'], 1, '.', '')]);
        fputcsv($out, []);
        fputcsv($out, ['Basis']);
        foreach ($basis as [$label, $value]) {
            fputcsv($out, [$label, $value]);
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** Prevents spreadsheet formula injection from entered text (same rule as the other exports). */
    private static function safe(?string $value): string
    {
        $value = (string) $value;

        return ($value !== '' && strpos('=+-@', $value[0]) !== false) ? "'" . $value : $value;
    }
}
