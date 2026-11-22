<?php

namespace App\Libraries;

use App\Models\DssParameterModel;
use App\Models\SupplierPriceModel;
use CodeIgniter\Database\BaseConnection;

/**
 * The Procurement Report's "Suppliers" tab: procurement seen from the supplier, not the item.
 *
 *  - list     one row per active supplier: items (sizes grouped by name + category, as Inventory counts them) and
 *             their sizes, what needs action (Reorder Now + Low Stock), stock on hand, orders in progress, last
 *             order, delivery time and payment terms. A sample supplier shows only its price-list offers.
 *  - detail   one supplier's items (the Items tab's own rows, so no formula is repeated) or, for a sample
 *             supplier, its price-list offers.
 *  - report   a weekly (Mon-Sun) or monthly (calendar month) list of what was ordered from each real supplier,
 *             from stock_order + so_item only; Cancelled orders are left out.
 *
 * Read-only. It never touches pdss_computation: the sizes come from ProcurementReport::rows(), which reads the
 * saved DSS results, and an item belongs to its main supplier (products.supplier_id) only.
 */
class SupplierProcurement
{
    /** Orders that are on their way. Delivered and Cancelled ones are not "in progress". */
    public const IN_PROGRESS = ['Order Confirmed', 'Preparing', 'Shipped'];

    public const PERIODS = ['weekly' => 'Weekly', 'monthly' => 'Monthly'];

    // ------------------------------------------------------------------ counts over a supplier's sizes

    /**
     * Items are sizes grouped by name + category (what Inventory and the Reorder Alerts headline call an item); an item
     * needs action when any of its sizes does. Sizes are the Items tab's rows.
     *
     * @param list<array> $sizes ProcurementReport rows
     *
     * @return array{items: int, sizes: int, needs_items: int, needs_sizes: int, stock: int}
     */
    public static function counts(array $sizes): array
    {
        $items = [];
        $needs = [];
        $stock = 0;
        $needsSizes = 0;
        foreach ($sizes as $r) {
            $key          = $r['item_name'] . '|' . $r['category'];
            $items[$key]  = true;
            $stock       += (int) $r['stock'];
            if (in_array($r['status'], ProcurementReport::NEEDS_ACTION, true)) {
                $needs[$key] = true;
                $needsSizes++;
            }
        }

        return ['items' => count($items), 'sizes' => count($sizes), 'needs_items' => count($needs), 'needs_sizes' => $needsSizes, 'stock' => $stock];
    }

    // ------------------------------------------------------------------ the list

    /**
     * One row per active supplier, most items needing action first (then by name); sample suppliers last.
     *
     * Real: id, name, is_sample false, category, counts (see counts()), orders_in_progress, last_order (Y-m-d or null),
     * lead_time_days, payment_terms. Sample: is_sample true, offers (number of price-list rows), no order figures.
     *
     * @param list<array>|null $sizes ProcurementReport rows when the caller already has them
     *
     * @return list<array<string, mixed>>
     */
    public static function suppliers(?array $dss = null, ?array $sizes = null, ?BaseConnection $db = null): array
    {
        $db    ??= db_connect();
        $dss   ??= (new DssParameterModel())->current();
        $sizes ??= ProcurementReport::rows(ProcurementReport::filters([]), $dss);

        $bySupplier = [];
        foreach ($sizes as $r) {
            if ($r['supplier_id'] !== null) {
                $bySupplier[$r['supplier_id']][] = $r;
            }
        }

        $inProgress = array_column($db->table('stock_order')->select('supplier_id, COUNT(*) AS n')->whereIn('status', self::IN_PROGRESS)->groupBy('supplier_id')->get()->getResultArray(), 'n', 'supplier_id');
        $lastOrder  = array_column($db->table('stock_order')->select('supplier_id, MAX(order_date) AS d')->where('status !=', 'Cancelled')->groupBy('supplier_id')->get()->getResultArray(), 'd', 'supplier_id');
        $offers     = array_column($db->table('supplier_prices')->select('supplier_id, COUNT(*) AS n')->groupBy('supplier_id')->get()->getResultArray(), 'n', 'supplier_id');

        $rows = [];
        foreach ($db->table('suppliers')->where('is_active', 1)->orderBy('company_name', 'ASC')->get()->getResultArray() as $s) {
            $id   = (string) $s['supplier_id'];
            $base = [
                'id'             => $id,
                'name'           => (string) $s['company_name'],
                'is_sample'      => (bool) ($s['is_sample'] ?? 0),
                'category'       => (string) ($s['category'] ?? ''),
                'lead_time_days' => (int) $s['lead_time_days'],
                'payment_terms'  => ($s['payment_terms'] ?? '') !== '' ? (string) $s['payment_terms'] : null,
            ];
            if ($base['is_sample']) {
                $rows[] = $base + ['offers' => (int) ($offers[$id] ?? 0)];

                continue;
            }
            $rows[] = $base + [
                'counts'             => self::counts($bySupplier[$id] ?? []),
                'orders_in_progress' => (int) ($inProgress[$id] ?? 0),
                'last_order'         => $lastOrder[$id] ?? null,
            ];
        }

        usort($rows, static fn ($a, $b) => [$a['is_sample'], -($a['counts']['needs_items'] ?? 0), $a['name']] <=> [$b['is_sample'], -($b['counts']['needs_items'] ?? 0), $b['name']]);

        return $rows;
    }

    // ------------------------------------------------------------------ one supplier

    /**
     * A supplier's detail, or null when there is no such active supplier. Real: its sizes (the Items tab's rows, optionally
     * only those that need action). Sample: its price-list offers.
     *
     * @return array<string, mixed>|null
     */
    public static function detail(string $supplierId, bool $needsActionOnly = false, ?array $dss = null, ?array $sizes = null, ?BaseConnection $db = null): ?array
    {
        $db       ??= db_connect();
        $supplier = $db->table('suppliers')->where('supplier_id', $supplierId)->where('is_active', 1)->get()->getRowArray();
        if (! $supplier) {
            return null;
        }

        $base = [
            'id'             => (string) $supplier['supplier_id'],
            'name'           => (string) $supplier['company_name'],
            'is_sample'      => (bool) ($supplier['is_sample'] ?? 0),
            'category'       => (string) ($supplier['category'] ?? ''),
            'lead_time_days' => (int) $supplier['lead_time_days'],
            'payment_terms'  => ($supplier['payment_terms'] ?? '') !== '' ? (string) $supplier['payment_terms'] : null,
        ];

        if ($base['is_sample']) {
            return $base + ['offers' => (new SupplierPriceModel())->offersBySupplier($supplierId)];
        }

        $dss   ??= (new DssParameterModel())->current();
        $sizes ??= ProcurementReport::rows(ProcurementReport::filters([]), $dss);
        $mine    = array_values(array_filter($sizes, static fn ($r) => $r['supplier_id'] === $supplierId));

        return $base + [
            'counts'          => self::counts($mine),
            'needs_only'      => $needsActionOnly,
            'sizes'           => $needsActionOnly ? array_values(array_filter($mine, static fn ($r) => in_array($r['status'], ProcurementReport::NEEDS_ACTION, true))) : $mine,
        ];
    }

    // ------------------------------------------------------------------ the weekly / monthly report

    /**
     * The period to report on: the week (Mon-Sun) or calendar month that contains $date (today when it is empty or
     * not a date). Weekly is the default.
     *
     * @return array{type: string, from: string, to: string, label: string, anchor: string, prev: string, next: string, is_current: bool}
     */
    public static function period(?string $type, ?string $date): array
    {
        $type  = $type === 'monthly' ? 'monthly' : 'weekly';
        $valid = $date !== null && (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && date('Y-m-d', (int) strtotime($date)) === $date;
        $day   = $valid ? $date : date('Y-m-d');

        if ($type === 'monthly') {
            $from = date('Y-m-01', strtotime($day));
            $to   = date('Y-m-t', strtotime($day));
            $label = date('F Y', strtotime($from));
            $prev  = date('Y-m-d', strtotime($from . ' -1 month'));
            $next  = date('Y-m-d', strtotime($from . ' +1 month'));
            $today = date('Y-m-01');
        } else {
            $from = date('Y-m-d', strtotime($day . ' -' . ((int) date('N', strtotime($day)) - 1) . ' days'));
            $to   = date('Y-m-d', strtotime($from . ' +6 days'));
            $label = date('M j', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to));
            $prev  = date('Y-m-d', strtotime($from . ' -7 days'));
            $next  = date('Y-m-d', strtotime($from . ' +7 days'));
            $today = date('Y-m-d', strtotime(date('Y-m-d') . ' -' . ((int) date('N') - 1) . ' days'));
        }

        return ['type' => $type, 'from' => $from, 'to' => $to, 'label' => $label, 'anchor' => $from, 'prev' => $prev, 'next' => $next, 'is_current' => $from === $today];
    }

    /**
     * What was ordered from each real supplier in the period: one line per so_item of an order dated in it, Cancelled
     * orders left out, sample suppliers never included (they have no orders). Quantity is what was ordered; Received is
     * the quantity of a Delivered order (what the supplier shipped, as the delivery added to stock), else null.
     *
     * @return array{suppliers: list<array{id: string, name: string, lines: list<array<string, mixed>>, totals: array<string, int|float>}>, grand: array<string, int|float>}
     */
    public static function report(array $period, ?BaseConnection $db = null): array
    {
        $db ??= db_connect();

        $lines = $db->table('stock_order so')
            ->select('so.so_id, so.po_number, so.order_date, so.status, so.supplier_id, s.company_name, si.so_item_id, si.order_quantity, si.shipped_quantity, si.unit_price, p.item_name, p.size, p.current_stock')
            ->join('suppliers s', 's.supplier_id = so.supplier_id')
            ->join('so_item si', 'si.so_id = so.so_id')
            ->join('products p', 'p.item_id = si.item_id', 'left')
            ->where('so.order_date >=', $period['from'])
            ->where('so.order_date <=', $period['to'])
            ->where('so.status !=', 'Cancelled')
            ->where('s.is_sample', 0)
            ->orderBy('s.company_name', 'ASC')
            ->orderBy('so.order_date', 'ASC')
            ->orderBy('so.so_id', 'ASC')
            ->orderBy('si.so_item_id', 'ASC')
            ->get()->getResultArray();

        $empty = static fn (): array => ['lines' => 0, 'quantity' => 0, 'received' => 0, 'amount' => 0.0];
        $add   = static function (array $t, array $line): array {
            $t['lines']++;
            $t['quantity'] += $line['quantity'];
            $t['received'] += (int) ($line['received'] ?? 0);
            $t['amount']    = round($t['amount'] + $line['total'], 2);

            return $t;
        };

        $suppliers = [];
        $grand     = $empty();
        foreach ($lines as $r) {
            $quantity = (int) $r['order_quantity'];
            $price    = (float) $r['unit_price'];
            $line     = [
                'order'     => ($r['po_number'] ?? '') !== '' ? (string) $r['po_number'] : (string) $r['so_id'],
                'date'      => (string) $r['order_date'],
                'item'      => (string) ($r['item_name'] ?? '—'),
                'size'      => (string) ($r['size'] ?? ''),
                'quantity'  => $quantity,
                'price'     => $price,
                'total'     => round($quantity * $price, 2),
                'status'    => (string) $r['status'],
                'received'  => $r['status'] === 'Delivered' ? (int) ($r['shipped_quantity'] ?? $quantity) : null,
                'stock'     => $r['current_stock'] !== null ? (int) $r['current_stock'] : null,
            ];
            $id = (string) $r['supplier_id'];
            $suppliers[$id] ??= ['id' => $id, 'name' => (string) $r['company_name'], 'lines' => [], 'totals' => $empty()];
            $suppliers[$id]['lines'][] = $line;
            $suppliers[$id]['totals']  = $add($suppliers[$id]['totals'], $line);
            $grand                     = $add($grand, $line);
        }

        return ['suppliers' => array_values($suppliers), 'grand' => $grand];
    }

    // ------------------------------------------------------------------ CSV

    private static function safe(?string $value): string
    {
        $value = (string) $value;

        return ($value !== '' && strpos('=+-@', $value[0]) !== false) ? "'" . $value : $value;
    }

    private static function num(float $n): string
    {
        return number_format($n, 2, '.', '');
    }

    /** @return list<string> */
    public static function csvColumns(): array
    {
        return ['Row', 'Supplier', 'Order', 'Order Date', 'Item', 'Size', 'Quantity', 'Price per Piece', 'Line Total', 'Status', 'Received', 'Current Stock'];
    }

    /** The period report as CSV: one line per so_item, a total per supplier, the grand total, then the period. */
    public static function csv(array $period, array $report): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads peso signs correctly

        fputcsv($out, self::csvColumns());
        foreach ($report['suppliers'] as $s) {
            foreach ($s['lines'] as $l) {
                fputcsv($out, ['Line', self::safe($s['name']), self::safe($l['order']), $l['date'], self::safe($l['item']), self::safe($l['size'] !== '' ? $l['size'] : '—'), $l['quantity'], self::num($l['price']), self::num($l['total']), $l['status'], $l['received'] ?? '—', $l['stock'] ?? '—']);
            }
            $t = $s['totals'];
            fputcsv($out, ['Supplier total', self::safe($s['name']), '', '', $t['lines'] . ' lines', '', $t['quantity'], '', self::num($t['amount']), '', $t['received'], '']);
        }
        $g = $report['grand'];
        fputcsv($out, ['Grand total', '', '', '', $g['lines'] . ' lines', '', $g['quantity'], '', self::num($g['amount']), '', $g['received'], '']);
        fputcsv($out, []);
        fputcsv($out, ['Period', ucfirst($period['type']), $period['from'] . ' to ' . $period['to']]);
        fputcsv($out, ['Basis', 'Real stock orders (order date in the period), Cancelled orders left out; quantity is what was ordered; Received is shown for Delivered orders only.']);

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
