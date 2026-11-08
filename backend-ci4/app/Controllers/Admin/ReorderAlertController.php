<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\DssRunner;
use App\Libraries\Toast;
use App\Models\ClusterSegmentModel;
use App\Models\DssParameterModel;
use App\Models\PdssComputationModel;
use App\Models\ProductModel;
use App\Models\ReorderAlertModel;
use App\Models\SoItemModel;
use App\Models\StockOrderModel;
use App\Models\SupplierModel;

class ReorderAlertController extends BaseController
{
    protected ReorderAlertModel $reorderAlertModel;
    protected ProductModel $productModel;
    protected SupplierModel $supplierModel;
    protected PdssComputationModel $pdssComputationModel;
    protected DssParameterModel $dssParameterModel;
    protected ClusterSegmentModel $clusterSegmentModel;

    public function __construct()
    {
        $this->reorderAlertModel    = new ReorderAlertModel();
        $this->productModel         = new ProductModel();
        $this->supplierModel        = new SupplierModel();
        $this->pdssComputationModel = new PdssComputationModel();
        $this->dssParameterModel    = new DssParameterModel();
        $this->clusterSegmentModel  = new ClusterSegmentModel();
    }

    public function index()
    {
        // Reconcile against live inventory first so this page always
        // matches what Inventory shows right now, not whatever dss:run
        // last computed (stock changes constantly via POS/restocks).
        $this->reorderAlertModel->syncFromLiveInventory();

        $dss       = $this->dssParameterModel->current();
        $previous  = session()->get('previous_login') ?: null;
        $suppliers = array_column($this->supplierModel->findAll(), null, 'supplier_id');
        $board     = $this->reorderAlertModel->reorderNowBoard();

        // Order numbers → their expected delivery date, for "late" on the Ordered tab.
        $orders = [];
        $soIds  = array_values(array_unique(array_filter(array_column($board, 'so_id'))));
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
            $row['is_new']           = $size['tab'] === 'order' && $previous !== null && ! empty($alert['alert_timestamp'])
                                       && strtotime($alert['alert_timestamp']) > strtotime($previous);

            $order                 = $orders[$size['so_id'] ?? ''] ?? null;
            $row['order_date']     = $order['order_date'] ?? null;
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
        $rows = $this->withDetails($rows, $dss);
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

        $tab = $this->request->getGet('tab');
        if ($this->request->getGet('dismissed') === '1') {
            $tab = 'dismissed'; // the old "Show dismissed" link
        }

        $categories = array_values(array_unique(array_filter(array_column($rows, 'category'))));
        sort($categories);
        $supplierOptions = [];
        foreach ($rows as $r) {
            $supplierOptions[$r['supplier_id'] ?: 'none'] = $r['supplier_name'];
        }
        asort($supplierOptions);

        $lastRun = (new DssRunner())->lastRunAt();

        $data = [
            'title'           => 'Reorder Alerts',
            'active'          => 'reorder_alerts',
            'tabs'            => ['order' => $toOrder, 'ordered' => $ordered, 'dismissed' => $dismissed],
            // To order and Dismissed: one card per item (same item name and category).
            'cards'           => [
                'order'     => self::itemCards($toOrder, $rows),
                'dismissed' => self::itemCards($dismissed, $rows),
                // Ordered: one card per stock order, late first, then by expected arrival.
                'ordered'   => self::orderCards($ordered),
            ],
            'activeTab'       => in_array($tab, ['order', 'ordered', 'dismissed'], true) ? $tab : 'order',
            'counts'          => $this->productModel->reorderCounts(),
            'lastRunAt'       => $lastRun,
            'previousLogin'   => $previous,
            'categories'      => $categories,
            'supplierOptions' => $supplierOptions,
            'filters'         => [
                'q'    => trim((string) $this->request->getGet('q')),
                'cat'  => (string) $this->request->getGet('cat'),
                'sup'  => (string) $this->request->getGet('sup'),
                'only' => array_values(array_intersect(explode(',', (string) $this->request->getGet('only')), ['out', 'new'])),
            ],
            'suppliers'       => $this->supplierModel->where('is_active', 1)->findAll(),
            'dss'             => $dss,
            'success'         => session()->getFlashdata('success'),
            'error'           => session()->getFlashdata('error'),
        ];

        return view('admin/reorder_alerts', $data);
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
     * @return list<array{so_id: string|null, supplier_name: string|null, order_date: string|null, expected_date: string|null, late_days: int, sizes: list<array>}>
     */
    private static function orderCards(array $rows): array
    {
        $cards = [];
        foreach ($rows as $r) {
            $key = $r['so_id'] ?: 'item:' . $r['item_id'];
            $cards[$key] ??= ['so_id' => $r['so_id'] ?: null, 'supplier_name' => $r['order_supplier'] ?? $r['supplier_name'], 'order_date' => $r['order_date'], 'expected_date' => $r['expected_date'], 'late_days' => (int) $r['late_days'], 'sizes' => []];
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

    /** Adds what each alert card shows: DSS numbers, supplier, delivery time, class. */
    private function withDetails(array $alerts, ?array $dss): array
    {
        foreach ($alerts as &$alert) {
            $computation = $alert['computation_id']
                ? $this->pdssComputationModel->find($alert['computation_id'])
                : $this->pdssComputationModel->latestFor($alert['item_id']);

            $supplier = $alert['supplier_id'] ? $this->supplierModel->find($alert['supplier_id']) : null;
            $cluster  = $this->clusterSegmentModel->find($alert['item_id']);

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

    public function report()
    {
        $this->reorderAlertModel->syncFromLiveInventory();

        $products = $this->productModel->where('is_active', 1)->orderBy('item_name', 'ASC')->findAll();
        $computations = [];
        foreach ($this->pdssComputationModel->latestPerItem() as $c) {
            $computations[$c['item_id']] = $c;
        }

        $classByItem = $this->clusterSegmentModel->classByItem(); // the K-Means class, same as everywhere
        $rows = [];
        foreach ($products as $p) {
            $status = $this->productModel->getStatus($p);
            $rows[] = [
                'item_id'      => $p['item_id'],
                'item_name'    => $p['item_name'],
                'size'         => $p['size'] ?? null,
                'abc_category' => $classByItem[$p['item_id']] ?? '—', // K-Means class (cluster_segments)
                'stock'        => $p['current_stock'],
                'rop'          => $p['manual_rop_warning'],
                'status'       => $status['label'],
                'safety_stock' => $computations[$p['item_id']]['safety_stock'] ?? null,
                'eoq'          => $computations[$p['item_id']]['eoq_value'] ?? null,
                'z_score'      => $computations[$p['item_id']]['service_level_z'] ?? null,
                'supplier_id'  => $p['supplier_id'] ?? null,
                'unit_cost'    => $p['unit_cost'] ?? 0,
            ];
        }

        $alerts = $this->reorderAlertModel->openAlerts();

        $data = [
            'title'         => 'Ordering Report',
            'active'        => 'reorder_alerts',
            'rows'          => $rows,
            'suppliers'     => $this->supplierModel->where('is_active', 1)->findAll(),
            'dss'           => $this->dssParameterModel->current(),
            'criticalCount' => count(array_filter($alerts, fn ($a) => (int) $a['stock_at_trigger'] <= 0)),
            'lowStockCount' => count($alerts),
            'receivedCount' => $this->reorderAlertModel->resolvedThisWeek(),
            'openOrders'    => (new \App\Models\StockOrderModel())->whereNotIn('status', ['Delivered', 'Cancelled'])->countAllResults(),
        ];

        return view('admin/procurement_report', $data);
    }

    /**
     * Closes a single alert without ordering (false positive, stock
     * corrected manually, etc.) — distinct from Fulfilled, which only
     * happens when a linked stock order is actually delivered. It stays
     * in the Dismissed tab until its size recovers. No confirmation:
     * the toast offers Undo (which restores it).
     */
    public function dismiss(int|string $alertId)
    {
        $alert = $this->reorderAlertModel->find((int) $alertId);
        if (! $alert || ! $this->reorderAlertModel->dismissPending((int) $alertId)) {
            return redirect()->to($this->boardUrl())->with('toast', Toast::error("Couldn't dismiss this alert", 'It is no longer waiting to be ordered.'));
        }

        return redirect()->to($this->boardUrl())->with('toast', Toast::success(
            'Alert dismissed', 'moved to Dismissed.', $this->sizeName($alert['item_id']),
            ['undo' => site_url('admin/reorder-alerts/restore/' . $alert['alert_id'])]
        ));
    }

    /** Undoes a dismissal: the size is back in "To order". The toast's Undo dismisses it again. */
    public function restore(int|string $alertId)
    {
        $alert = $this->reorderAlertModel->find((int) $alertId);
        if (! $alert || ! $this->reorderAlertModel->restoreDismissed((int) $alertId)) {
            return redirect()->to($this->boardUrl())->with('toast', Toast::error("Couldn't restore this alert", 'It is no longer dismissed.'));
        }

        return redirect()->to($this->boardUrl())->with('toast', Toast::success(
            'Alert restored', 'is back in To order.', $this->sizeName($alert['item_id']),
            ['undo' => site_url('admin/reorder-alerts/dismiss/' . $alert['alert_id'])]
        ));
    }

    /** "Tactical Pants · Size L" for a toast. */
    private function sizeName(string $itemId): string
    {
        return ProductModel::nameWithSize($this->productModel->find($itemId) ?? ['item_id' => $itemId]);
    }

    /**
     * Back to the tab and filters the Owner was on: the page (or the toast's Undo)
     * posts them as return_query. Only this page's own parameters are kept.
     */
    private function boardUrl(): string
    {
        parse_str(ltrim((string) $this->request->getPost('return_query'), '?'), $params);
        $params = array_filter(array_intersect_key($params, array_flip(['tab', 'q', 'cat', 'sup', 'only'])), static fn ($v) => is_string($v) && $v !== '');

        return '/admin/reorder-alerts' . ($params ? '?' . http_build_query($params) : '');
    }

    /**
     * Kept for backward compatibility with the old inline order modal;
     * new orders are created through StockOrderController::store().
     */
    public function createOrder()
    {
        session()->setFlashdata('toast', Toast::success('Order created', 'The stock order was created.'));
        return redirect()->to('/admin/reorder-alerts');
    }
}
