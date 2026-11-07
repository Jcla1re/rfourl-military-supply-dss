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
        if ($soIds !== []) {
            $orders = array_column((new StockOrderModel())->whereIn('so_id', $soIds)->findAll(), null, 'so_id');
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
        }
        unset($row);

        usort($rows, self::byUrgency(...));
        $ordered = array_values(array_filter($rows, static fn ($r) => $r['tab'] === 'ordered'));
        usort($ordered, self::byArrival(...));

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
            'tabs'            => [
                'order'     => array_values(array_filter($rows, static fn ($r) => $r['tab'] === 'order')),
                'ordered'   => $ordered,
                'dismissed' => array_values(array_filter($rows, static fn ($r) => $r['tab'] === 'dismissed')),
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
            'products'        => $this->productModel->where('is_active', 1)->findAll(),
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
     * hidden until its size recovers.
     */
    public function dismiss(int|string $alertId)
    {
        $this->reorderAlertModel->dismissPending((int) $alertId);
        session()->setFlashdata('success', 'Alert dismissed.');
        return redirect()->to('/admin/reorder-alerts');
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
