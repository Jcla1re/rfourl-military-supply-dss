<?php

namespace App\Libraries;

use App\Models\ClusterSegmentModel;
use App\Models\DssParameterModel;
use App\Models\PdssComputationModel;
use App\Models\ProductModel;

/**
 * The Owner's Procurement Report (admin/procurement-report): one row per size with its
 * K-Means class, on hand, Reorder At, status, Safety Stock and Order Qty from the latest
 * DSS run. Shared by the page and its PDF / CSV exports so they always list the same rows.
 *
 * Read-only: nothing here writes (no reorder alert sync), so viewing or exporting never
 * changes data. Search, Class and Status filters work over every active size.
 */
class ProcurementReport
{
    /** Status dropdown order. */
    public const STATUSES = ['Reorder Now', 'Low Stock', 'Out of Stock', 'In Stock'];

    public const CLASSES = ['A', 'B', 'C'];

    /**
     * The filters from the query string: search (name or item ID, Inventory's rules),
     * class (A/B/C) and status. Unknown values are ignored.
     *
     * @return array{search: string, searchInput: string, searchError: ?string, class: string, status: string}
     */
    public static function filters(array $get): array
    {
        [$search, $searchInput, $searchError] = ProductModel::validateSearch($get['search'] ?? null);
        $class  = is_string($get['class'] ?? null) ? strtoupper($get['class']) : '';
        $status = is_string($get['status'] ?? null) ? $get['status'] : '';

        return [
            'search'      => $search,
            'searchInput' => $searchInput,
            'searchError' => $searchError,
            'class'       => in_array($class, self::CLASSES, true) ? $class : '',
            'status'      => in_array($status, self::STATUSES, true) ? $status : '',
        ];
    }

    /** How many filters are on (search, class, status), for "Clear all <n>". */
    public static function activeFilterCount(array $filters): int
    {
        return (int) ($filters['search'] !== '' || $filters['searchInput'] !== '')
            + (int) ($filters['class'] !== '')
            + (int) ($filters['status'] !== '');
    }

    /** The filters as query parameters (for export links). */
    public static function query(array $filters): array
    {
        return array_filter([
            'search' => $filters['search'],
            'class'  => $filters['class'],
            'status' => $filters['status'],
        ], static fn ($v) => $v !== '');
    }

    /**
     * Every active size matching the filters, sorted by item name then size.
     *
     * Each row: item_id, item_name, size, class (A/B/C or null), stock, rop (0 when none),
     * rop_default (no reorder point: shown as a gray 0), status, safety_stock, order_qty,
     * order_qty_default (Order Qty is only the minimum order because the EOQ was lower).
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(array $filters, ?array $dss = null): array
    {
        $dss ??= (new DssParameterModel())->current();
        $productModel = new ProductModel();

        $computations = [];
        foreach ((new PdssComputationModel())->latestPerItem() as $c) {
            $computations[$c['item_id']] = $c;
        }
        $classByItem = (new ClusterSegmentModel())->classByItem();

        $rows = [];
        foreach ($productModel->where('is_active', 1)->findAll() as $p) {
            if ($filters['search'] !== '' && ! ProductModel::matchesSearch($p, $filters['search'])) {
                continue;
            }
            $class = $classByItem[$p['item_id']] ?? null;
            $class = in_array($class, self::CLASSES, true) ? $class : null;
            if ($filters['class'] !== '' && $class !== $filters['class']) {
                continue;
            }
            $status = $productModel->getStatus($p)['label'];
            if ($filters['status'] !== '' && $status !== $filters['status']) {
                continue;
            }

            $c   = $computations[$p['item_id']] ?? null;
            $rop = max(0, (int) ($p['manual_rop_warning'] ?? 0));
            $eoq = $c !== null && $c['eoq_value'] !== null ? (int) $c['eoq_value'] : null;

            $rows[] = [
                'item_id'           => (string) $p['item_id'],
                'item_name'         => (string) $p['item_name'],
                'size'              => ($p['size'] ?? '') !== '' ? (string) $p['size'] : null,
                'class'             => $class,
                'stock'             => (int) $p['current_stock'],
                'rop'               => $rop,
                'rop_default'       => $rop === 0,
                'status'            => $status,
                'safety_stock'      => $c !== null && $c['safety_stock'] !== null ? (int) $c['safety_stock'] : null,
                'order_qty'         => $eoq,
                'order_qty_default' => self::isMinimumOrder($c, $dss),
            ];
        }

        usort($rows, static fn ($a, $b) => [$a['item_name'], $a['size'] ?? ''] <=> [$b['item_name'], $b['size'] ?? '']);

        return $rows;
    }

    /**
     * True when the saved Order Qty is only the minimum order: the EOQ before the minimum was
     * lower. Uses pdss_computation.eoq_raw (saved by dss:run). Rows saved before that column
     * existed (eoq_raw NULL) work the EOQ out again from the run's saved yearly demand and the
     * current ordering and holding costs (same formula as dss:run), which is exact unless those
     * settings changed after the run.
     */
    public static function isMinimumOrder(?array $computation, array $dss): bool
    {
        $min = (int) ($dss['minimum_order_qty'] ?? 0);
        if ($computation === null || $computation['eoq_value'] === null || $min <= 0 || (int) $computation['eoq_value'] !== $min) {
            return false;
        }

        $raw = isset($computation['eoq_raw'])
            ? (int) $computation['eoq_raw']
            : DssFormulas::eoq((int) ($computation['annual_demand'] ?? 0), (float) ($dss['ordering_cost'] ?? 0), (float) ($dss['holding_cost_per_unit'] ?? 0));

        return $raw < $min;
    }

    /**
     * The "Basis" note under the exports: what the numbers were worked out with.
     *
     * @return list<array{0: string, 1: string}> [label, value]
     */
    public static function basis(?string $lastRunAt, array $dss): array
    {
        $money  = static fn ($n): string => '₱' . rtrim(rtrim(number_format((float) $n, 2, '.', ','), '0'), '.');
        $levels = [];
        foreach (ClusterSegmentModel::SERVICE_LEVELS as $class => $pct) {
            $levels[] = $class . ' ' . $pct . '%';
        }

        return [
            ['DSS results date', $lastRunAt ? date('M j, Y', strtotime($lastRunAt)) : 'Not calculated yet'],
            ['Ordering cost', $money($dss['ordering_cost'] ?? 0) . ' per order'],
            ['Holding (storage) cost', $money($dss['holding_cost_per_unit'] ?? 0) . ' per item per year'],
            ['Service levels', implode(' / ', $levels)],
            ['Minimum order quantity', (string) (int) ($dss['minimum_order_qty'] ?? 0)],
            ['Sales history window', (int) ($dss['demand_lookback_days'] ?? 0) . ' days'],
        ];
    }

    /** "Class A · Reorder Now · Search “pants”", or '' with no filters (export headers). */
    public static function filterLabel(array $filters): string
    {
        return implode(' · ', array_filter([
            $filters['class'] !== '' ? 'Class ' . $filters['class'] : '',
            $filters['status'],
            $filters['search'] !== '' ? 'Search “' . $filters['search'] . '”' : '',
        ]));
    }

    /** The CSV export: "Item ID" then the table's columns, then the Basis note. */
    public static function csv(array $rows, array $basis): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads peso signs correctly

        fputcsv($out, ['Item ID', 'Item', 'Size', 'Class', 'On Hand', 'Reorder At', 'Status', 'Safety Stock', 'Order Qty']);
        foreach ($rows as $r) {
            fputcsv($out, [
                self::safe($r['item_id']),
                self::safe($r['item_name']),
                self::safe($r['size'] ?? '—'),
                $r['class'] ?? '—',
                $r['stock'],
                $r['rop'],
                $r['status'],
                $r['safety_stock'] ?? '—',
                $r['order_qty'] ?? '—',
            ]);
        }

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

    /** Prevents spreadsheet formula injection from entered text (same rule as SalesCsvExport). */
    private static function safe(?string $value): string
    {
        $value = (string) $value;

        return ($value !== '' && strpos('=+-@', $value[0]) !== false) ? "'" . $value : $value;
    }
}
