<?php

namespace App\Controllers\Supplier;

use App\Controllers\BaseController;
use App\Models\NotificationModel;
use App\Models\SoItemModel;
use App\Models\StockOrderModel;

class NewOrdersController extends BaseController
{
    protected $stockOrderModel;
    protected $soItemModel;
    protected $notificationModel;

    public function __construct()
    {
        $this->stockOrderModel  = new StockOrderModel();
        $this->soItemModel      = new SoItemModel();
        $this->notificationModel = new NotificationModel();
    }

    public function index()
    {
        $supplierId = session()->get('supplier_id');
        $search     = strtolower((string) $this->request->getGet('search'));
        $filter     = $this->request->getGet('filter') ?: 'all';

        $orders = $this->stockOrderModel->forSupplier($supplierId, ['Order Confirmed']);

        foreach ($orders as &$o) {
            $o['lines']       = $this->soItemModel->forOrder($o['so_id']);
            $o['total_units'] = array_sum(array_column($o['lines'], 'order_quantity'));
        }
        unset($o);

        if ($filter === 'urgent') {
            $orders = array_values(array_filter($orders, fn ($o) => $o['priority'] === 'Urgent'));
        }

        if ($search) {
            $orders = array_values(array_filter($orders, fn ($o) => str_contains(strtolower($o['so_id']), $search)));
        }

        $data = [
            'title'    => 'New Orders',
            'subtitle' => count($orders) . ' order' . (count($orders) === 1 ? '' : 's') . ' awaiting your response',
            'active'   => 'new_orders',
            'orders'   => $orders,
            'filter'   => $filter,
            'search'   => $this->request->getGet('search') ?? '',
            'success'  => session()->getFlashdata('success'),
        ];

        return view('supplier/new_orders', $data);
    }

    public function accept($soId)
    {
        $order = $this->stockOrderModel->find($soId);

        if (! $order || $order['supplier_id'] !== session()->get('supplier_id')) {
            return redirect()->to('/supplier/new-orders')->with('error', 'Order not found.');
        }

        $this->stockOrderModel->update($soId, ['status' => 'Preparing']);

        $this->notificationModel->push('Admin', 'Order Status', "Order {$soId} accepted by supplier", session()->get('full_name') . " accepted order {$soId} and has started preparing it.");

        return redirect()->to('/supplier/new-orders')->with('success', "Order {$soId} accepted. It now appears under Deliveries.");
    }

    public function decline($soId)
    {
        $order = $this->stockOrderModel->find($soId);

        if (! $order || $order['supplier_id'] !== session()->get('supplier_id')) {
            return redirect()->to('/supplier/new-orders')->with('error', 'Order not found.');
        }

        $this->stockOrderModel->update($soId, ['status' => 'Cancelled']);

        $this->notificationModel->push('Admin', 'Order Status', "Order {$soId} declined by supplier", session()->get('full_name') . " declined order {$soId}. Please reassign or follow up.");

        return redirect()->to('/supplier/new-orders')->with('success', "Order {$soId} declined.");
    }
}
