<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ProductModel;
use App\Models\ReorderAlertModel;
use App\Models\SoItemModel;
use App\Models\StockOrderModel;
use App\Models\SupplierModel;

class StockOrderController extends BaseController
{
    protected $stockOrderModel;
    protected $soItemModel;
    protected $supplierModel;
    protected $productModel;
    protected $reorderAlertModel;

    public function __construct()
    {
        $this->stockOrderModel   = new StockOrderModel();
        $this->soItemModel       = new SoItemModel();
        $this->supplierModel     = new SupplierModel();
        $this->productModel      = new ProductModel();
        $this->reorderAlertModel = new ReorderAlertModel();
    }

    public function index()
    {
        $tab = $this->request->getGet('tab') === 'history' ? 'history' : 'status';

        $allOrders = $this->stockOrderModel->listWithSupplier();

        $counts = [
            'Order Confirmed' => 0,
            'Preparing'       => 0,
            'Shipped'         => 0,
            'Delivered'       => 0,
            'Cancelled'       => 0,
        ];
        foreach ($allOrders as $o) {
            $counts[$o['status']] = ($counts[$o['status']] ?? 0) + 1;
        }

        $activeOrders  = array_values(array_filter($allOrders, fn ($o) => ! in_array($o['status'], ['Delivered', 'Cancelled'], true)));
        $historyOrders = array_values(array_filter($allOrders, fn ($o) => in_array($o['status'], ['Delivered', 'Cancelled'], true)));

        foreach ($activeOrders as &$o) {
            $lines             = $this->soItemModel->forOrder($o['so_id']);
            $o['lines']        = $lines;
            $o['total_units']  = array_sum(array_column($lines, 'order_quantity'));
            $o['estimated']    = array_sum(array_map(fn ($l) => $l['order_quantity'] * $l['unit_price'], $lines));
            $o['primary_item'] = $lines[0]['item_name'] ?? null;
        }
        unset($o);

        $month = (int) ($this->request->getGet('month') ?? date('n'));
        $year  = (int) ($this->request->getGet('year') ?? date('Y'));
        if ($month < 1) { $month = 12; $year--; }
        if ($month > 12) { $month = 1; $year++; }

        $restockDates = [];
        foreach ($allOrders as $o) {
            if (! empty($o['expected_delivery_date'])) {
                $restockDates[] = date('Y-m-d', strtotime($o['expected_delivery_date']));
            }
        }

        $data = [
            'title'          => 'Orders',
            'active'         => 'orders',
            'tab'            => $tab,
            'orders'         => $activeOrders,
            'historyOrders'  => $historyOrders,
            'suppliers'      => $this->supplierModel->where('is_active', 1)->findAll(),
            'products'       => $this->productModel->where('is_active', 1)->orderBy('item_name', 'ASC')->findAll(),
            'statuses'       => StockOrderModel::STATUSES,
            'priorities'     => StockOrderModel::PRIORITIES,
            'counts'         => $counts,
            'calMonth'       => $month,
            'calYear'        => $year,
            'restockDates'   => $restockDates,
            'success'        => session()->getFlashdata('success'),
            'error'          => session()->getFlashdata('error'),
        ];

        return view('admin/orders', $data);
    }

    public function flagDelayed($soId)
    {
        $order = $this->stockOrderModel->find($soId);

        if (! $order) {
            return redirect()->to('/admin/orders')->with('error', 'Order not found.');
        }

        (new \App\Models\NotificationModel())->push(
            'Admin',
            'Order Status',
            "Order {$soId} flagged as delayed",
            "The expected delivery for {$soId} has passed. Follow up with the supplier."
        );

        return redirect()->to('/admin/orders')->with('success', "Order {$soId} flagged as delayed. Supplier will be followed up.");
    }

    public function show($soId)
    {
        $order = $this->stockOrderModel->findWithDetails($soId);

        if (! $order) {
            return redirect()->to('/admin/orders')->with('error', 'Order not found.');
        }

        $data = [
            'title'    => 'Order ' . $soId,
            'active'   => 'orders',
            'order'    => $order,
            'items'    => $this->soItemModel->forOrder($soId),
            'statuses' => StockOrderModel::STATUSES,
        ];

        return view('admin/order_detail', $data);
    }

    public function store()
    {
        $supplierId = $this->request->getPost('supplier_id');
        $itemIds    = $this->request->getPost('item_id') ?? [];
        $quantities = $this->request->getPost('quantity') ?? [];
        $prices     = $this->request->getPost('unit_price') ?? [];
        $alertId    = $this->request->getPost('alert_id');

        if (! $supplierId) {
            return redirect()->back()->with('error', 'Please select a supplier.');
        }

        $lines = [];
        foreach ($itemIds as $i => $itemId) {
            if (! $itemId) {
                continue;
            }
            $qty   = (int) ($quantities[$i] ?? 0);
            $price = (float) ($prices[$i] ?? 0);

            if ($qty <= 0) {
                continue;
            }

            $lines[] = ['item_id' => $itemId, 'qty' => $qty, 'price' => $price];
        }

        if (empty($lines)) {
            return redirect()->back()->with('error', 'Add at least one item with a quantity greater than zero.');
        }

        $soId = $this->stockOrderModel->generateNextId();

        $this->stockOrderModel->insert([
            'so_id'                   => $soId,
            'supplier_id'             => $supplierId,
            'user_id'                 => session()->get('user_id'),
            'priority'                => $this->request->getPost('priority') ?: 'Order',
            'status'                  => 'Order Confirmed',
            'order_date'              => date('Y-m-d'),
            'expected_delivery_date'  => $this->request->getPost('expected_delivery_date') ?: null,
            'tracking_no'             => $this->request->getPost('tracking_no') ?: null,
        ]);

        foreach ($lines as $line) {
            $this->soItemModel->insert([
                'so_id'          => $soId,
                'item_id'        => $line['item_id'],
                'order_quantity' => $line['qty'],
                'unit_price'     => $line['price'],
            ]);
        }

        if ($alertId) {
            $this->reorderAlertModel->update((int) $alertId, ['status' => 'Resolved']);
        }

        return redirect()->to('/admin/orders')->with('success', "Stock order {$soId} created and sent to supplier.");
    }

    public function updateStatus($soId)
    {
        $order = $this->stockOrderModel->find($soId);

        if (! $order) {
            return redirect()->to('/admin/orders')->with('error', 'Order not found.');
        }

        $newStatus = $this->request->getPost('status');

        if (! in_array($newStatus, StockOrderModel::STATUSES, true)) {
            return redirect()->to('/admin/orders')->with('error', 'Invalid status.');
        }

        if ($newStatus === 'Delivered' && $order['status'] !== 'Delivered') {
            $this->stockOrderModel->markDelivered($soId, session()->get('user_id'));
        } else {
            $this->stockOrderModel->update($soId, ['status' => $newStatus]);
        }

        return redirect()->to('/admin/orders')->with('success', "Order {$soId} marked as {$newStatus}.");
    }
}
