<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\DssParameterModel;
use App\Models\PdssComputationModel;
use App\Models\ProductModel;
use App\Models\ReorderAlertModel;
use App\Models\SupplierModel;

class ReorderAlertController extends BaseController
{
    protected $reorderAlertModel;
    protected $productModel;
    protected $supplierModel;
    protected $pdssComputationModel;
    protected $dssParameterModel;

    public function __construct()
    {
        $this->reorderAlertModel    = new ReorderAlertModel();
        $this->productModel         = new ProductModel();
        $this->supplierModel        = new SupplierModel();
        $this->pdssComputationModel = new PdssComputationModel();
        $this->dssParameterModel    = new DssParameterModel();
    }

    public function index()
    {
        $alerts = $this->reorderAlertModel->openAlerts();
        $dss    = $this->dssParameterModel->current();

        foreach ($alerts as &$alert) {
            $computation = $alert['computation_id']
                ? $this->pdssComputationModel->find($alert['computation_id'])
                : $this->pdssComputationModel->latestFor($alert['item_id']);

            $supplier = $alert['supplier_id'] ? $this->supplierModel->find($alert['supplier_id']) : null;

            $alert['computation']    = $computation;
            $alert['supplier_name']  = $supplier['company_name'] ?? 'Unassigned';
            $alert['lead_time_days'] = $computation['lead_time_days'] ?? ($supplier['lead_time_days'] ?? null);
        }
        unset($alert);

        $critical  = count(array_filter($alerts, fn ($a) => (int) $a['stock_at_trigger'] <= 0));
        $lowStock  = count($alerts) - $critical;

        $data = [
            'title'          => 'Reorder Alerts',
            'active'         => 'reorder_alerts',
            'alerts'         => $alerts,
            'suppliers'      => $this->supplierModel->where('is_active', 1)->findAll(),
            'products'       => $this->productModel->where('is_active', 1)->findAll(),
            'dss'            => $dss,
            'criticalCount'  => $critical,
            'lowStockCount'  => $lowStock,
            'receivedCount'  => $this->reorderAlertModel->resolvedThisWeek(),
            'lastUpdated'    => 'just now',
            'success'        => session()->getFlashdata('success'),
            'error'          => session()->getFlashdata('error'),
        ];

        return view('admin/reorder_alerts', $data);
    }

    public function report()
    {
        $products = $this->productModel->where('is_active', 1)->orderBy('item_name', 'ASC')->findAll();
        $computations = [];
        foreach ($this->pdssComputationModel->latestPerItem() as $c) {
            $computations[$c['item_id']] = $c;
        }

        $rows = [];
        foreach ($products as $p) {
            $status = $this->productModel->getStatus($p);
            $rows[] = [
                'item_id'      => $p['item_id'],
                'item_name'    => $p['item_name'],
                'abc_category' => $p['abc_category'] ?? '—',
                'stock'        => $p['current_stock'],
                'rop'          => $p['manual_rop_warning'],
                'status'       => $status['label'],
                'safety_stock' => $computations[$p['item_id']]['safety_stock'] ?? null,
                'eoq'          => $computations[$p['item_id']]['eoq_value'] ?? null,
            ];
        }

        $alerts = $this->reorderAlertModel->openAlerts();

        $data = [
            'title'         => 'Procurement Report',
            'active'        => 'reorder_alerts',
            'rows'          => $rows,
            'dss'           => $this->dssParameterModel->current(),
            'criticalCount' => count(array_filter($alerts, fn ($a) => (int) $a['stock_at_trigger'] <= 0)),
            'lowStockCount' => count($alerts),
            'receivedCount' => $this->reorderAlertModel->resolvedThisWeek(),
            'openOrders'    => (new \App\Models\StockOrderModel())->whereNotIn('status', ['Delivered', 'Cancelled'])->countAllResults(),
        ];

        return view('admin/procurement_report', $data);
    }

    public function resolveAll()
    {
        $this->reorderAlertModel->where('status', 'Open')->set(['status' => 'Resolved'])->update();
        session()->setFlashdata('success', 'All reorder alerts resolved.');
        return redirect()->to('/admin/reorder-alerts');
    }

    public function resolve($alertId)
    {
        $this->reorderAlertModel->update((int) $alertId, ['status' => 'Resolved']);
        session()->setFlashdata('success', 'Alert resolved.');
        return redirect()->to('/admin/reorder-alerts');
    }

    /**
     * Kept for backward compatibility with the old inline order modal;
     * new orders are created through StockOrderController::store().
     */
    public function createOrder()
    {
        session()->setFlashdata('success', 'Stock order created.');
        return redirect()->to('/admin/reorder-alerts');
    }
}
