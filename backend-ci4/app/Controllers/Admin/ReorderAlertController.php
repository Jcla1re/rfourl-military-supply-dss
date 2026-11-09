<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\ReorderBoard;
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
        // The board (tabs, cards, counts, filters) is shared with the Staff page: App\Libraries\ReorderBoard.
        $data = ReorderBoard::build($this->request->getGet() ?? [], session()->get('previous_login') ?: null) + [
            'title'     => 'Reorder Alerts',
            'active'    => 'reorder_alerts',
            'role'      => 'admin',
            'suppliers' => $this->supplierModel->where('is_active', 1)->findAll(),
            'success'   => session()->getFlashdata('success'),
            'error'     => session()->getFlashdata('error'),
        ];

        return view('admin/reorder_alerts', $data);
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
