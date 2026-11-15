<?php

namespace App\Libraries;

use App\Models\SalesTransactionModel;

/**
 * The owner's Reports: Sales Journal, Purchases Journal and Period Summary for a date range.
 * Read-only: every figure comes straight from sales_transaction and the Delivered stock orders.
 *
 *  - Sales Journal      one row per receipt, daily subtotals, a period total.
 *  - Purchases Journal  one row per Delivered stock order (by the date it was received);
 *                       amount = quantity received x unit cost, quantity received being what the
 *                       supplier shipped and the delivery added to stock (as StockOrderModel::markDelivered()).
 *  - Period Summary     the totals an accountant posts to the trial balance.
 *
 * PO / DR / OR numbers come from the procurement-documents work; "—" is shown when one is missing.
 */
class AccountingReports
{
    public const TYPES = [
        'sales'     => 'Sales Journal',
        'purchases' => 'Purchases Journal',
        'summary'   => 'Period Summary',
    ];

    public const SUMMARY_LABEL = 'Totals for posting to the trial balance (prepared by the accountant)';

    public static function type(?string $type): string
    {
        return isset(self::TYPES[(string) $type]) ? (string) $type : 'sales';
    }

    /**
     * The date range from the query string: this month by default; a bad date falls back to its default;
     * a range typed backwards is put the right way round.
     *
     * @return array{0: string, 1: string} [from, to] as Y-m-d
     */
    public static function range(?string $from, ?string $to): array
    {
        $valid = static fn (?string $d): bool => $d !== null && (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && date('Y-m-d', (int) strtotime($d)) === $d;
        $from  = $valid($from) ? $from : date('Y-m-01');
        $to    = $valid($to) ? $to : date('Y-m-t');

        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    /** The first moment after the last day of the range (sale_date is a date-time). */
    private static function after(string $to): string
    {
        return date('Y-m-d', strtotime($to . ' +1 day'));
    }

    private static function money(float $value): float
    {
        return round($value, 2);
    }

    /** One receipt added to a subtotal / total bucket. */
    private static function add(array $bucket, array $row): array
    {
        $bucket['count']++;
        foreach (['gross', 'discount', 'net', 'cash', 'gcash'] as $k) {
            $bucket[$k] = self::money($bucket[$k] + $row[$k]);
        }

        return $bucket;
    }

    /** @return array<string, true> OR numbers (trimmed, lower case) that appear on more than one receipt, in any period */
    private static function duplicateOrNumbers(): array
    {
        $rows = db_connect()->table('sales_transaction')
            ->select('LOWER(TRIM(or_number)) AS k', false)
            ->where('or_number IS NOT NULL', null, false)
            ->where(SalesTransactionModel::notVoided(), null, false) // a voided duplicate is not a second use
            ->where("TRIM(or_number) <> ''", null, false)
            ->groupBy('LOWER(TRIM(or_number))', false)
            ->having('COUNT(*) > 1', null, false)
            ->get()->getResultArray();

        return array_fill_keys(array_column($rows, 'k'), true);
    }

    // ------------------------------------------------------------------ Sales Journal

    /**
     * @return array{rows: list<array>, days: list<array>, total: array}
     *         rows: one per receipt; days: date => subtotal row (oldest first); total: the period.
     *         A voided duplicate is listed (its 'voided' is "Voided duplicate of #XXXX") but not added to
     *         its day's subtotal, the period total or the receipt counts.
     */
    public static function salesJournal(string $from, string $to): array
    {
        $builder = db_connect()->table('sales_transaction st')
            ->select('st.sales_id, st.receipt_no, st.or_number, st.sale_date, u.full_name AS cashier, st.subtotal, st.discount, st.total_amount, st.cash_amount, st.gcash_amount')
            ->join('users u', 'u.user_id = st.user_id', 'left');
        $receipts = SalesTransactionModel::withOriginalReceipt($builder, 'st')
            ->where('st.sale_date >=', $from . ' 00:00:00')
            ->where('st.sale_date <', self::after($to) . ' 00:00:00')
            ->orderBy('st.sale_date', 'ASC')
            ->orderBy('st.sales_id', 'ASC')
            ->get()->getResultArray();

        $duplicates = self::duplicateOrNumbers();
        $empty = static fn (): array => ['count' => 0, 'gross' => 0.0, 'discount' => 0.0, 'net' => 0.0, 'cash' => 0.0, 'gcash' => 0.0];
        $total = $empty();
        $days  = [];
        $rows  = [];

        foreach ($receipts as $r) {
            $date = substr((string) $r['sale_date'], 0, 10);
            $row  = [
                'receipt_no' => (string) $r['receipt_no'],
                'or_number'  => ($r['or_number'] ?? '') !== '' ? (string) $r['or_number'] : null,
                // The same OR number is on another receipt (in any period): marked "duplicate" on every one of them.
                'duplicate'  => ($r['or_number'] ?? '') !== '' && isset($duplicates[strtolower(trim((string) $r['or_number']))]),
                // Voided by `php spark sales:void-duplicate`: "Voided duplicate of #XXXX", else null.
                'voided'     => SalesTransactionModel::voidLabel($r),
                'date'       => $date,
                'time'       => date('g:i A', strtotime((string) $r['sale_date'])),
                'cashier'    => (string) ($r['cashier'] ?? ''),
                'gross'      => self::money((float) $r['subtotal']),
                'discount'   => self::money((float) $r['discount']),
                'net'        => self::money((float) $r['total_amount']),
                'cash'       => self::money((float) $r['cash_amount']),
                'gcash'      => self::money((float) $r['gcash_amount']),
            ];
            $rows[] = $row;

            $days[$date] ??= ['date' => $date] + $empty();
            if ($row['voided'] === null) {
                $days[$date] = self::add($days[$date], $row);
                $total       = self::add($total, $row);
            }
        }

        return ['rows' => $rows, 'days' => array_values($days), 'total' => $total];
    }

    // ------------------------------------------------------------------ Purchases Journal

    /**
     * @return array{rows: list<array>, total: array{count: int, amount: float}}
     */
    public static function purchasesJournal(string $from, string $to): array
    {
        $db    = db_connect();
        $after = self::after($to);

        // Received in the range: by the received time, or (orders received before that was recorded) the delivery date.
        $orders = $db->table('stock_order so')
            ->select('so.so_id, so.po_number, so.dr_number, so.received_at, so.actual_delivery_date, s.company_name AS supplier')
            ->join('suppliers s', 's.supplier_id = so.supplier_id', 'left')
            ->where('so.status', 'Delivered')
            ->groupStart()
                ->groupStart()->where('so.received_at >=', $from . ' 00:00:00')->where('so.received_at <', $after . ' 00:00:00')->groupEnd()
                ->orGroupStart()->where('so.received_at', null)->where('so.actual_delivery_date >=', $from)->where('so.actual_delivery_date <=', $to)->groupEnd()
            ->groupEnd()
            ->get()->getResultArray();

        $lines = [];
        if ($orders !== []) {
            foreach ($db->table('so_item')->whereIn('so_id', array_column($orders, 'so_id'))->get()->getResultArray() as $l) {
                $lines[$l['so_id']][] = $l;
            }
        }

        $rows = [];
        foreach ($orders as $o) {
            $amount = 0.0;
            foreach ($lines[$o['so_id']] ?? [] as $l) {
                $amount += (float) ($l['shipped_quantity'] ?? $l['order_quantity']) * (float) $l['unit_price'];
            }
            $received = $o['received_at'] ?: $o['actual_delivery_date'];
            $rows[]   = [
                'so_id'     => (string) $o['so_id'],
                'po_number' => ($o['po_number'] ?? '') !== '' ? (string) $o['po_number'] : null,
                'dr_number' => ($o['dr_number'] ?? '') !== '' ? (string) $o['dr_number'] : null,
                'supplier'  => (string) ($o['supplier'] ?? ''),
                'received'  => substr((string) $received, 0, 10),
                'items'     => count($lines[$o['so_id']] ?? []),
                'amount'    => self::money($amount),
            ];
        }
        usort($rows, static fn ($a, $b) => [$a['received'], $a['so_id']] <=> [$b['received'], $b['so_id']]);

        return ['rows' => $rows, 'total' => ['count' => count($rows), 'amount' => self::money(array_sum(array_column($rows, 'amount')))]];
    }

    // ------------------------------------------------------------------ Period Summary

    /**
     * @return array{sales: float, discounts: float, net_sales: float, cash: float, gcash: float, purchases: float, receipts: int, orders: int}
     */
    public static function summary(string $from, string $to): array
    {
        $sales     = self::salesJournal($from, $to)['total'];
        $purchases = self::purchasesJournal($from, $to)['total'];

        return [
            'sales'     => $sales['gross'],
            'discounts' => $sales['discount'],
            'net_sales' => $sales['net'],
            'cash'      => $sales['cash'],
            'gcash'     => $sales['gcash'],
            'purchases' => $purchases['amount'],
            'receipts'  => $sales['count'],
            'orders'    => $purchases['count'],
        ];
    }

    // ------------------------------------------------------------------ CSV

    /** Spreadsheet formula injection guard for text a person typed. */
    private static function safe(?string $value): string
    {
        $value = (string) $value;

        return ($value !== '' && strpos('=+-@', $value[0]) !== false) ? "'" . $value : $value;
    }

    private static function num(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /** @return list<string> the CSV's column names for a report type */
    public static function csvColumns(string $type): array
    {
        return match ($type) {
            'purchases' => ['PO No', 'DR No', 'Supplier', 'Date Received', 'Items', 'Amount'],
            'summary'   => ['Item', 'Amount'],
            default     => ['Row', 'Receipt No', 'OR No', 'Date', 'Time', 'Cashier', 'Gross', 'Discount', 'Net', 'Cash', 'GCash'],
        };
    }

    public static function csv(string $type, string $from, string $to): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads peso signs and accents correctly

        if ($type === 'purchases') {
            $report = self::purchasesJournal($from, $to);
            fputcsv($out, self::csvColumns('purchases'));
            foreach ($report['rows'] as $r) {
                fputcsv($out, [$r['po_number'] ?? '—', $r['dr_number'] ?? '—', self::safe($r['supplier']), $r['received'], $r['items'], self::num($r['amount'])]);
            }
            fputcsv($out, ['Period total', '', '', '', $report['total']['count'] . ' orders', self::num($report['total']['amount'])]);
        } elseif ($type === 'summary') {
            $s = self::summary($from, $to);
            fputcsv($out, ['Period Summary', $from . ' to ' . $to]);
            fputcsv($out, [self::SUMMARY_LABEL, '']);
            fputcsv($out, self::csvColumns('summary'));
            foreach (self::summaryLines($s) as [$label, $value]) {
                fputcsv($out, [$label, $value]);
            }
        } else {
            $report = self::salesJournal($from, $to);
            fputcsv($out, self::csvColumns('sales'));
            $byDay = array_column($report['days'], null, 'date');
            $last  = count($report['rows']) - 1;
            foreach ($report['rows'] as $i => $r) {
                fputcsv($out, ['Receipt', self::safe($r['receipt_no']), self::csvOrCell($r), $r['date'], $r['time'], self::safe($r['cashier']),
                    self::num($r['gross']), self::num($r['discount']), self::num($r['net']), self::num($r['cash']), self::num($r['gcash'])]);
                if ($i === $last || $report['rows'][$i + 1]['date'] !== $r['date']) {
                    $d = $byDay[$r['date']];
                    fputcsv($out, ['Daily subtotal', $d['count'] . ' receipts', '', $r['date'], '', '', self::num($d['gross']), self::num($d['discount']), self::num($d['net']), self::num($d['cash']), self::num($d['gcash'])]);
                }
            }
            $t = $report['total'];
            fputcsv($out, ['Period total', $t['count'] . ' receipts', '', $from . ' to ' . $to, '', '', self::num($t['gross']), self::num($t['discount']), self::num($t['net']), self::num($t['cash']), self::num($t['gcash'])]);
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * The Sales Journal CSV's "OR No" cell: the number, then " (duplicate)" for an OR number on more than one
     * receipt and " (voided duplicate of #XXXX)" for a voided receipt, so the columns stay the same.
     */
    private static function csvOrCell(array $r): string
    {
        return self::safe(($r['or_number'] ?? '—')
            . ($r['duplicate'] ? ' (duplicate)' : '')
            . ($r['voided'] !== null ? ' (' . lcfirst($r['voided']) . ')' : ''));
    }

    /**
     * The Period Summary as label / formatted value pairs, in the order it is read.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function summaryLines(array $s): array
    {
        return [
            ['Total sales (gross)', self::num($s['sales'])],
            ['Discounts', self::num($s['discounts'])],
            ['Net sales', self::num($s['net_sales'])],
            ['Cash', self::num($s['cash'])],
            ['GCash', self::num($s['gcash'])],
            ['Total purchases', self::num($s['purchases'])],
            ['Number of receipts', (string) $s['receipts']],
            ['Number of orders received', (string) $s['orders']],
        ];
    }
}
