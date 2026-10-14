<?php

namespace App\Libraries;

/**
 * CSV export of sales transactions, one row per sold line item (receipt
 * fields repeat on each row so the file filters and pivots cleanly in a
 * spreadsheet). Shared by the Admin and Staff Sales pages.
 *
 * Accepts either ?date=YYYY-MM-DD (one day) or ?month=YYYY-MM (whole month).
 */
class SalesCsvExport
{
    /**
     * @return array{0: string, 1: string, 2: string} [from date, to date, label used in the file name]
     */
    public static function range(?string $date, ?string $month): array
    {
        if ($month !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return [$month . '-01', date('Y-m-t', strtotime($month . '-01')), $month];
        }

        $date = ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) ? $date : date('Y-m-d');

        return [$date, $date, $date];
    }

    public static function build(string $from, string $to): string
    {
        $rows = db_connect()->table('sales_item si')
            ->select('st.receipt_no, st.sale_date, u.full_name AS cashier, st.payment_method, st.gcash_reference_no, '
                . 'st.subtotal, st.discount, st.total_amount, si.item_id, p.item_name, si.quantity_sold, si.selling_price')
            ->join('sales_transaction st', 'st.sales_id = si.sales_id')
            ->join('users u', 'u.user_id = st.user_id', 'left')
            ->join('products p', 'p.item_id = si.item_id', 'left')
            ->where('DATE(st.sale_date) >=', $from)
            ->where('DATE(st.sale_date) <=', $to)
            ->orderBy('st.sale_date', 'ASC')
            ->orderBy('st.sales_id', 'ASC')
            ->get()->getResultArray();

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads peso signs and accents correctly

        fputcsv($out, [
            'Receipt No', 'Sale Date', 'Cashier', 'Payment Method', 'GCash Ref', 'Item ID', 'Item Name',
            'Quantity', 'Unit Price', 'Line Total', 'Receipt Subtotal', 'Receipt Discount', 'Receipt Total',
        ]);

        foreach ($rows as $r) {
            fputcsv($out, [
                self::safe($r['receipt_no']),
                $r['sale_date'],
                self::safe($r['cashier']),
                $r['payment_method'],
                self::safe($r['gcash_reference_no']),
                $r['item_id'],
                self::safe($r['item_name']),
                (int) $r['quantity_sold'],
                number_format((float) $r['selling_price'], 2, '.', ''),
                number_format((float) $r['selling_price'] * (int) $r['quantity_sold'], 2, '.', ''),
                number_format((float) $r['subtotal'], 2, '.', ''),
                number_format((float) $r['discount'], 2, '.', ''),
                number_format((float) $r['total_amount'], 2, '.', ''),
            ]);
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** Prevents spreadsheet formula injection from user-entered text. */
    private static function safe(?string $value): string
    {
        $value = (string) $value;

        return ($value !== '' && strpos('=+-@', $value[0]) !== false) ? "'" . $value : $value;
    }
}
