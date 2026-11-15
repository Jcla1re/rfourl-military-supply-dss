<?php

namespace App\Libraries;

use App\Models\AppSettingModel;
use App\Models\NotificationModel;
use App\Models\SalesTransactionModel;

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

    /**
     * Records older than the retention period: every row purge() removes, voided duplicates included
     * (they are removed with the rest), so deliberately not filtered.
     */
    public static function count($db = null): int
    {
        return (int) ($db ?? db_connect())->table('sales_transaction')->where('sale_date <', self::cutoff())->countAllResults();
    }

    /** First and last record date past the retention period (['first' => ..., 'last' => ...]), or null. Any record, as count(). */
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

    /**
     * Per-year totals of everything past the cutoff, or null when nothing is due. receipts / units /
     * revenue leave voided duplicates out; 'voided' counts those, and 'records' is every row the purge
     * removes (receipts + voided).
     */
    public static function summary($db = null): ?array
    {
        $db                = $db ?? db_connect();
        [$st, $si]         = [$db->prefixTable('sales_transaction'), $db->prefixTable('sales_item')];
        $real              = SalesTransactionModel::notVoided('t');
        $rows              = $db->query(
            "SELECT YEAR(t.sale_date) AS yr,
                    COUNT(DISTINCT CASE WHEN {$real} THEN t.sales_id END) AS receipts,
                    COUNT(DISTINCT CASE WHEN {$real} THEN NULL ELSE t.sales_id END) AS voided,
                    COALESCE(SUM(CASE WHEN {$real} THEN i.quantity_sold END), 0) AS units,
                    COALESCE(SUM(CASE WHEN {$real} THEN i.quantity_sold * i.selling_price END), 0) AS revenue
             FROM {$st} t
             LEFT JOIN {$si} i ON i.sales_id = t.sales_id
             WHERE t.sale_date < ?
             GROUP BY YEAR(t.sale_date) ORDER BY yr",
            [self::cutoff()]
        )->getResultArray();

        if (! $rows) {
            return null;
        }

        $receipts = (int) array_sum(array_column($rows, 'receipts'));
        $voided   = (int) array_sum(array_column($rows, 'voided'));

        return [
            'cutoff'   => self::cutoff(),
            'years'    => $rows,
            'receipts' => $receipts,
            'voided'   => $voided,
            'records'  => $receipts + $voided,
            'units'    => array_sum(array_column($rows, 'units')),
            'revenue'  => array_sum(array_column($rows, 'revenue')),
        ];
    }

    /**
     * Everything needed to print/save the old history and its trend figures. The monthly trend and
     * the best sellers leave voided duplicates out; the receipt list shows them, each with its
     * 'voided' label ("Voided duplicate of #XXXX"; null for a normal receipt).
     */
    public static function report(): array
    {
        $db   = db_connect();
        $cut  = self::cutoff();
        $out  = [];
        $real = SalesTransactionModel::notVoided('t');
        // Raw SQL: table names carry the connection's prefix (none in production).
        [$st, $si, $pr] = [$db->prefixTable('sales_transaction'), $db->prefixTable('sales_item'), $db->prefixTable('products')];

        $monthly = $db->query(
            "SELECT YEAR(t.sale_date) yr, MONTH(t.sale_date) m, SUM(i.quantity_sold) units, SUM(i.quantity_sold * i.selling_price) revenue
             FROM {$st} t JOIN {$si} i ON i.sales_id = t.sales_id
             WHERE t.sale_date < ? AND {$real} GROUP BY YEAR(t.sale_date), MONTH(t.sale_date)",
            [$cut]
        )->getResultArray();
        foreach ($monthly as $r) {
            $out[$r['yr']]['monthly'][(int) $r['m']] = ['units' => (int) $r['units'], 'revenue' => (float) $r['revenue']];
        }

        $top = $db->query(
            "SELECT YEAR(t.sale_date) yr, p.item_name, p.size, SUM(i.quantity_sold) units, SUM(i.quantity_sold * i.selling_price) revenue
             FROM {$st} t JOIN {$si} i ON i.sales_id = t.sales_id JOIN {$pr} p ON p.item_id = i.item_id
             WHERE t.sale_date < ? AND {$real} GROUP BY YEAR(t.sale_date), p.item_id, p.item_name, p.size ORDER BY yr, units DESC",
            [$cut]
        )->getResultArray();
        foreach ($top as $r) {
            $out[$r['yr']]['top'][] = $r;
        }

        // Every receipt (voided ones too, marked), with its lines as "2 x Goa Pants (M); 1 x Cap".
        $receipts = $db->query(
            "SELECT t.sales_id, YEAR(t.sale_date) yr, t.receipt_no, t.sale_date, t.total_amount, t.voided_at, o.receipt_no AS duplicate_of_receipt_no
             FROM {$st} t LEFT JOIN {$st} o ON o.sales_id = t.duplicate_of_sales_id
             WHERE t.sale_date < ? ORDER BY t.sale_date, t.sales_id",
            [$cut]
        )->getResultArray();
        $lines = [];
        foreach ($db->query(
            "SELECT i.sales_id, i.quantity_sold, p.item_name, p.size
             FROM {$si} i JOIN {$st} t ON t.sales_id = i.sales_id JOIN {$pr} p ON p.item_id = i.item_id
             WHERE t.sale_date < ? ORDER BY i.sales_item_id",
            [$cut]
        )->getResultArray() as $l) {
            $size = trim((string) ($l['size'] ?? ''));
            $lines[$l['sales_id']][] = $l['quantity_sold'] . ' x ' . $l['item_name'] . ($size !== '' ? " ({$size})" : '');
        }
        foreach ($receipts as $r) {
            if (! isset($lines[$r['sales_id']])) {
                continue; // a receipt with no lines is left out, as before (the list joined the lines)
            }
            $r['items']  = implode('; ', $lines[$r['sales_id']]);
            $r['voided'] = SalesTransactionModel::voidLabel($r);
            $out[$r['yr']]['receipts'][] = $r;
        }

        ksort($out);

        return $out;
    }

    /**
     * Removes the old sales (every record past the cutoff, voided duplicates too: not filtered) and
     * any history-only products left with no sales. @return int records removed
     */
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
