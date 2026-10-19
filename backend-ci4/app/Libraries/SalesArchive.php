<?php

namespace App\Libraries;

use App\Models\AppSettingModel;
use App\Models\NotificationModel;

/**
 * Sales older than the retention period (Settings > Data & Records: 3 / 5 /
 * 7 / 10 years, default 10) can be saved as a PDF and removed by the Owner on
 * the Old Sales Records page. The Owner is told once, through one bell
 * notification (syncNotification()). Nothing here ever deletes
 * automatically - purge() only runs from the Owner's confirmed request.
 */
class SalesArchive
{
    public const YEAR_OPTIONS  = [3, 5, 7, 10];
    public const DEFAULT_YEARS = 10;

    private const SETTING_YEARS   = 'sales_retention_years';
    private const SETTING_NOTICE  = 'sales_retention_notified'; // the period the current notification is for
    private const NOTICE_CATEGORY = 'sales_records';

    /** The retention period in years. */
    public static function years(): int
    {
        $years = (int) (new AppSettingModel())->getValue(self::SETTING_YEARS, (string) self::DEFAULT_YEARS);

        return in_array($years, self::YEAR_OPTIONS, true) ? $years : self::DEFAULT_YEARS;
    }

    public static function setYears(int $years, ?int $userId = null): void
    {
        (new AppSettingModel())->setValue(self::SETTING_YEARS, (string) $years, $userId);
    }

    /** Sales dated before this day are older than the retention period. */
    public static function cutoff(): string
    {
        return (new \DateTime('today'))->modify('-' . self::years() . ' years')->format('Y-m-d');
    }

    /** Receipts older than the retention period. */
    public static function count($db = null): int
    {
        return (int) ($db ?? db_connect())->table('sales_transaction')->where('sale_date <', self::cutoff())->countAllResults();
    }

    /** First and last sale date past the retention period (['first' => ..., 'last' => ...]), or null. */
    public static function range($db = null): ?array
    {
        $row = ($db ?? db_connect())->table('sales_transaction')
            ->select('MIN(sale_date) AS first, MAX(sale_date) AS last')
            ->where('sale_date <', self::cutoff())
            ->get()->getRowArray();

        return ! empty($row['first']) ? $row : null;
    }

    /**
     * One bell notification while records are past the retention period:
     * "[count] sales records are older than [N] years — Review". It is created
     * once per period (not again after it's read), its count is kept current
     * while it's unread, and it's removed when nothing is past the period any
     * more (after a clean-up, or after choosing a longer period).
     */
    public static function syncNotification(): void
    {
        try {
            $settings = new AppSettingModel();
            $notes    = new NotificationModel();
            $years    = self::years();
            $count    = self::count();
            $notified = $settings->getValue(self::SETTING_NOTICE, '');
            $unread   = $notes->where('category', self::NOTICE_CATEGORY)->where('is_read', 0)->findAll();

            if ($count === 0) { // nothing to review: drop the notice, read or not, so no stale count lingers
                $notes->where('category', self::NOTICE_CATEGORY)->delete();
                if ($notified !== '') {
                    $settings->setValue(self::SETTING_NOTICE, '');
                }

                return;
            }

            $title = number_format($count) . ' sales records are older than ' . $years . ' years — Review';
            if ($notified === (string) $years) {
                foreach ($unread as $n) {
                    if ($n['title'] !== $title) {
                        $notes->update($n['notification_id'], ['title' => $title]);
                    }
                }

                return;
            }

            foreach ($unread as $n) { // an older notice for a different period
                $notes->delete($n['notification_id']);
            }
            $notes->push('Admin', 'Old sales records', $title, 'Save a copy as PDF, then remove them if you no longer need them.', null, self::NOTICE_CATEGORY, '/admin/archive');
            $settings->setValue(self::SETTING_NOTICE, (string) $years);
        } catch (\Throwable $e) {
            log_message('error', 'Sales retention notice: ' . $e->getMessage());
        }
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
