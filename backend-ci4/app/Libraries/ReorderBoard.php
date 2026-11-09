<?php

namespace App\Libraries;

use App\Models\ClusterSegmentModel;
use App\Models\DssParameterModel;
use App\Models\PdssComputationModel;
use App\Models\ProductModel;
use App\Models\ReorderAlertModel;
use App\Models\SoItemModel;
use App\Models\StockOrderModel;
use App\Models\SupplierModel;

/**
 * The Reorder Alerts board, the same for the Owner (Admin\ReorderAlertController) and Staff
 * (Staff\ReorderAlertController), so both pages show the same sizes and the same numbers:
 * the To order / Ordered / Dismissed tabs and their cards, the headline counts, the chips'
 * data, the filters and their options. Rendered by app/Views/partials/_reorder_board.php.
 */
class ReorderBoard
{
    /**
     * The board's view data.
     *
     * @param array       $get           the request's query (tab, q, cat, sup, only, dismissed)
     * @param string|null $previousLogin the viewer's previous sign-in, for "New since …"
     */
    public static function build(array $get, ?string $previousLogin): array
    {
        $alertModel    = new ReorderAlertModel();
        $supplierModel = new SupplierModel();

        // Reconcile against live inventory first so this page always
        // matches what Inventory shows right now, not whatever dss:run
        // last computed (stock changes constantly via POS/restocks).
        $alertModel->syncFromLiveInventory();

        $dss       = (new DssParameterModel())->current();
        $suppliers = array_column($supplierModel->findAll(), null, 'supplier_id');
        $board     = $alertModel->reorderNowBoard();

        // Order numbers → their expected delivery date, for "late" on the Ordered tab.
        $orders     = [];
        $soIds      = array_values(array_unique(array_filter(array_column($board, 'so_id'))));
        $orderedQty = []; // so_id => item_id => quantity ordered
        if ($soIds !== []) {
            $orders = array_column((new StockOrderModel())->whereIn('so_id', $soIds)->findAll(), null, 'so_id');
            foreach ((new SoItemModel())->whereIn('so_id', $soIds)->findAll() as $line) {
                $orderedQty[$line['so_id']][$line['item_id']] = ($orderedQty[$line['so_id']][$line['item_id']] ?? 0) + (int) $line['order_quantity'];
            }
        }
        $today = new \DateTimeImmutable('today');

        $rows = [];
        foreach ($board as $size) {
            $alert = $size['alert'] ?? [];
            // The size's alert fields (alert_id, recommended_eoq, ...) on top of its product row.
            $row = array_merge($size, $alert, ['so_id' => $size['so_id'], 'status' => $alert['status'] ?? null]);
            $row['computation_id'] ??= null;
            $row['supplier_name']    = $suppliers[$size['supplier_id'] ?? '']['company_name'] ?? 'Unassigned';
            $row['out']              = (int) $size['current_stock'] <= 0;
            $row['is_new']           = $size['tab'] === 'order' && $previousLogin !== null && ! empty($alert['alert_timestamp'])
                                       && strtotime($alert['alert_timestamp']) > strtotime($previousLogin);

            $order                 = $orders[$size['so_id'] ?? ''] ?? null;
            $row['order_date']     = $order['order_date'] ?? null;
            $row['order_status']   = $order['status'] ?? null; // Order Confirmed / Preparing / Shipped
            $row['expected_date']  = $order['expected_delivery_date'] ?? null;
            $row['ordered_qty']    = $orderedQty[$size['so_id'] ?? ''][$size['item_id']] ?? null;
            $row['order_supplier'] = $suppliers[$order['supplier_id'] ?? '']['company_name'] ?? null;
            $row['late_days']      = 0;
            if ($size['tab'] === 'ordered' && $row['expected_date']) {
                $expected         = new \DateTimeImmutable(date('Y-m-d', strtotime($row['expected_date'])));
                $row['late_days'] = $expected < $today ? $expected->diff($today)->days : 0;
            }
            $rows[] = $row;
        }
        $rows = self::withDetails($rows, $dss);
        foreach ($rows as &$row) {
            $row['recommended_eoq'] ??= $row['computation']['eoq_value'] ?? null;
            $row['bar']               = self::stockBar($row);
        }
        unset($row);

        usort($rows, self::byUrgency(...));
        $ordered = array_values(array_filter($rows, static fn ($r) => $r['tab'] === 'ordered'));
        usort($ordered, self::byArrival(...));
        $toOrder   = array_values(array_filter($rows, static fn ($r) => $r['tab'] === 'order'));
        $dismissed = array_values(array_filter($rows, static fn ($r) => $r['tab'] === 'dismissed'));

        $tab = $get['tab'] ?? null;
        if (($get['dismissed'] ?? null) === '1') {
            $tab = 'dismissed'; // the old "Show dismissed" link
        }

        $categories = array_values(array_unique(array_filter(array_column($rows, 'category'))));
        sort($categories);
        $supplierOptions = [];
        foreach ($rows as $r) {
            $supplierOptions[$r['supplier_id'] ?: 'none'] = $r['supplier_name'];
        }
        asort($supplierOptions);

        return [
            'tabs'            => ['order' => $toOrder, 'ordered' => $ordered, 'dismissed' => $dismissed],
            // To order and Dismissed: one card per item (same item name and category).
            'cards'           => [
                'order'     => self::itemCards($toOrder, $rows),
                'dismissed' => self::itemCards($dismissed, $rows),
                // Ordered: one card per stock order, late first, then by expected arrival.
                'ordered'   => self::orderCards($ordered),
            ],
            'activeTab'       => in_array($tab, ['order', 'ordered', 'dismissed'], true) ? $tab : 'order',
            'counts'          => (new ProductModel())->reorderCounts(),
            'lastRunAt'       => (new DssRunner())->lastRunAt(),
            'previousLogin'   => $previousLogin,
            'categories'      => $categories,
            'supplierOptions' => $supplierOptions,
            'filters'         => [
                'q'    => trim((string) ($get['q'] ?? '')),
                'cat'  => (string) ($get['cat'] ?? ''),
                'sup'  => (string) ($get['sup'] ?? ''),
                'only' => array_values(array_intersect(explode(',', (string) ($get['only'] ?? '')), ['out', 'new'])),
            ],
            'supplierMap'     => $suppliers, // all suppliers by ID (the Owner's order dialog data)
            'dss'             => $dss,
        ];
    }

    /** Every item_id on the board (for the Staff page's "Owner notified" notices). */
    public static function itemIds(array $data): array
    {
        return array_values(array_unique(array_column(array_merge(...array_values($data['tabs'])), 'item_id')));
    }

    /**
     * "To order" and "Dismissed": out of stock first, then the lowest stock compared
     * with Reorder at, then item name and size (same rule as ReorderAlertModel).
     */
    private static function byUrgency(array $a, array $b): int
    {
        $key = static function (array $r): array {
            $stock = (int) $r['current_stock'];
            $rop   = (int) ($r['manual_rop_warning'] ?? 0);

            return [$stock <= 0 ? 0 : 1, $rop > 0 ? $stock / $rop : 999999, (string) $r['item_name'], (string) ($r['size'] ?? ''), (int) ($r['alert_id'] ?? 0)];
        };

        return $key($a) <=> $key($b);
    }

    /**
     * One card per item, grouped like Inventory and reorderCounts (item name +
     * category). $rows is already most urgent first, so each card's sizes are too
     * and the cards follow their most urgent size. 'total' is how many of the
     * item's sizes are at Reorder Now on any tab ($all), for "1 of 2 sizes".
     *
     * @return list<array{item_name: string, category: string, total: int, sizes: list<array>}>
     */
    private static function itemCards(array $rows, array $all): array
    {
        $key    = static fn (array $r): string => $r['item_name'] . '|' . $r['category'];
        $totals = array_count_values(array_map($key, $all));

        $cards = [];
        foreach ($rows as $r) {
            $cards[$key($r)] ??= ['item_name' => (string) $r['item_name'], 'category' => (string) $r['category'], 'total' => $totals[$key($r)], 'sizes' => []];
            $cards[$key($r)]['sizes'][] = $r;
        }

        return array_values($cards);
    }

    /**
     * Ordered tab: one card per stock order (an "Order all sizes" order is one card with
     * a row per size). $rows is sorted by arrival and every size of an order shares its
     * dates, so the cards keep that order. A size whose alert is Ordered without an order
     * number gets a card of its own.
     *
     * @return list<array{so_id: string|null, supplier_name: string|null, order_date: string|null, expected_date: string|null, late_days: int, status: string|null, sizes: list<array>}>
     */
    private static function orderCards(array $rows): array
    {
        $cards = [];
        foreach ($rows as $r) {
            $key = $r['so_id'] ?: 'item:' . $r['item_id'];
            $cards[$key] ??= ['so_id' => $r['so_id'] ?: null, 'supplier_name' => $r['order_supplier'] ?? $r['supplier_name'], 'order_date' => $r['order_date'], 'expected_date' => $r['expected_date'], 'late_days' => (int) $r['late_days'], 'status' => $r['order_status'] ?? null, 'sizes' => []];
            $cards[$key]['sizes'][] = $r;
        }
        foreach ($cards as &$card) {
            usort($card['sizes'], static fn ($a, $b) => [(string) $a['item_name'], (string) ($a['size'] ?? '')] <=> [(string) $b['item_name'], (string) ($b['size'] ?? '')]);
        }
        unset($card);

        return array_values($cards);
    }

    /**
     * The On hand bar: on hand ÷ (Reorder at + order quantity), capped at 100%,
     * and where Reorder at sits on the same scale (null when Reorder at is 0).
     *
     * @return array{pct: int, tick: float|null}
     */
    private static function stockBar(array $r): array
    {
        $stock  = (int) $r['current_stock'];
        $rop    = (int) ($r['manual_rop_warning'] ?? 0);
        $target = $rop + (int) ($r['recommended_eoq'] ?? 0);

        return [
            'pct'  => $stock <= 0 ? 0 : ($target > 0 ? (int) min(100, round($stock / $target * 100)) : 100),
            'tick' => $rop > 0 && $target > 0 ? round($rop / $target * 100, 1) : null,
        ];
    }

    /** "Ordered": late deliveries first (most days late first), then by expected arrival; no date last. */
    private static function byArrival(array $a, array $b): int
    {
        $key = static fn (array $r): array => [
            -$r['late_days'],
            $r['expected_date'] ? 0 : 1,
            $r['expected_date'] ? strtotime($r['expected_date']) : 0,
            (string) $r['item_name'],
            (string) ($r['size'] ?? ''),
        ];

        return $key($a) <=> $key($b);
    }

    /** Adds what each row shows: DSS numbers, supplier, delivery time, class. */
    private static function withDetails(array $alerts, ?array $dss): array
    {
        $computations = new PdssComputationModel();
        $supplierModel = new SupplierModel();
        $clusters     = new ClusterSegmentModel();

        foreach ($alerts as &$alert) {
            $computation = $alert['computation_id']
                ? $computations->find($alert['computation_id'])
                : $computations->latestFor($alert['item_id']);

            $supplier = $alert['supplier_id'] ? $supplierModel->find($alert['supplier_id']) : null;
            $cluster  = $clusters->find($alert['item_id']);

            $alert['computation']    = $computation;
            $alert['supplier_name']  = $supplier['company_name'] ?? 'Unassigned';
            $alert['lead_time_days'] = $computation['lead_time_days'] ?? ($supplier['lead_time_days'] ?? null);
            // This item's own Class A/B/C service level, not the admin's
            // global default — items are no longer all on one shared Z-score.
            $alert['service_level']  = $cluster['service_level'] ?? ($dss['service_level_target'] ?? null);
            $alert['abc_class']      = $cluster['abc_class'] ?? null;
        }
        unset($alert);

        return $alerts;
    }
}
