<?php

namespace App\Libraries;

/**
 * Sales older than 3 years are kept for as long as the Owner wants them, but the
 * Owner is asked once they pass that age: save a copy (PDF / print) and remove
 * them so the system stays light, or keep them. Nothing here ever deletes
 * automatically - purge() only runs from the Owner's confirmed request.
 */
class SalesArchive
{
    public const YEARS = 3;

    /** Sales dated before this day are "3 or more years old". */
    public static function cutoff(): string
    {
        return (new \DateTime('today'))->modify('-' . self::YEARS . ' years')->format('Y-m-d');
    }

    /** Per-year totals of everything past the cutoff, or null when nothing is due. */
    public static function summary($db = null): ?array
    {
        $rows = ($db ?? db_connect())->query(
            'SELECT YEAR(t.sale_date) AS yr, COUNT(DISTINCT t.sales_id) AS receipts,
                    COALESCE(SUM(i.quantity_sold), 0) AS units,
                    COALESCE(SUM(i.quantity_sold * i.selling_price), 0) AS revenue
             FROM sales_transaction t
             LEFT JOIN sales_item i ON i.sales_id = t.sales_id
             WHERE t.sale_date < ?
             GROUP BY yr ORDER BY yr',
            [self::cutoff()]
        )->getResultArray();

        if (! $rows) {
            return null;
        }

        return [
            'cutoff'   => self::cutoff(),
            'years'    => $rows,
            'receipts' => array_sum(array_column($rows, 'receipts')),
            'units'    => array_sum(array_column($rows, 'units')),
            'revenue'  => array_sum(array_column($rows, 'revenue')),
        ];
    }

    /** Same as summary(), but null when the Owner chose "remind me later" today. */
    public static function notice(): ?array
    {
        if (session()->get('archive_snoozed_on') === date('Y-m-d')) {
            return null;
        }

        return self::summary();
    }

    /** Everything needed to print/save the old history and its trend figures. */
    public static function report(): array
    {
        $db  = db_connect();
        $cut = self::cutoff();
        $out = [];

        $monthly = $db->query(
            'SELECT YEAR(t.sale_date) yr, MONTH(t.sale_date) m, SUM(i.quantity_sold) units, SUM(i.quantity_sold * i.selling_price) revenue
             FROM sales_transaction t JOIN sales_item i ON i.sales_id = t.sales_id
             WHERE t.sale_date < ? GROUP BY yr, m',
            [$cut]
        )->getResultArray();
        foreach ($monthly as $r) {
            $out[$r['yr']]['monthly'][(int) $r['m']] = ['units' => (int) $r['units'], 'revenue' => (float) $r['revenue']];
        }

        $top = $db->query(
            'SELECT YEAR(t.sale_date) yr, p.item_name, p.size, SUM(i.quantity_sold) units, SUM(i.quantity_sold * i.selling_price) revenue
             FROM sales_transaction t JOIN sales_item i ON i.sales_id = t.sales_id JOIN products p ON p.item_id = i.item_id
             WHERE t.sale_date < ? GROUP BY yr, p.item_id, p.item_name, p.size ORDER BY yr, units DESC',
            [$cut]
        )->getResultArray();
        foreach ($top as $r) {
            $out[$r['yr']]['top'][] = $r;
        }

        $receipts = $db->query(
            "SELECT YEAR(t.sale_date) yr, t.receipt_no, t.sale_date, t.total_amount,
                    GROUP_CONCAT(CONCAT(i.quantity_sold, ' x ', p.item_name, IF(p.size IS NULL OR p.size = '', '', CONCAT(' (', p.size, ')'))) ORDER BY i.sales_item_id SEPARATOR '; ') AS items
             FROM sales_transaction t JOIN sales_item i ON i.sales_id = t.sales_id JOIN products p ON p.item_id = i.item_id
             WHERE t.sale_date < ? GROUP BY t.sales_id ORDER BY t.sale_date",
            [$cut]
        )->getResultArray();
        foreach ($receipts as $r) {
            $out[$r['yr']]['receipts'][] = $r;
        }

        ksort($out);

        return $out;
    }

    /** Removes the old sales and any history-only products left with no sales. @return int receipts removed */
    public static function purge($db = null): int
    {
        $db  = $db ?? db_connect();
        $cut = self::cutoff();

        $db->transStart();
        $n = (int) $db->table('sales_transaction')->where('sale_date <', $cut)->countAllResults();
        $db->table('sales_transaction')->where('sale_date <', $cut)->delete(); // sales_item goes with it (ON DELETE CASCADE)
        if ($db->fieldExists('product_code', 'products')) {
            $db->query('DELETE p FROM products p LEFT JOIN sales_item si ON si.item_id = p.item_id
                        WHERE p.product_code IS NOT NULL AND p.is_active = 0 AND p.current_stock = 0 AND si.item_id IS NULL');
        }
        $db->transComplete();

        return $db->transStatus() ? $n : 0;
    }
}
