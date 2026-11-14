<?php

namespace App\Controllers\Supplier;

use App\Controllers\BaseController;
use App\Libraries\EmailNotifier;
use App\Libraries\ProcurementDocs;
use App\Models\NotificationModel;
use App\Models\SoItemModel;
use App\Models\StockOrderModel;

class NewOrdersController extends BaseController
{
    protected $stockOrderModel;
    protected $soItemModel;
    protected $notificationModel;
    protected $emailNotifier;

    public function __construct()
    {
        $this->stockOrderModel  = new StockOrderModel();
        $this->soItemModel      = new SoItemModel();
        $this->notificationModel = new NotificationModel();
        $this->emailNotifier    = new EmailNotifier();
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
            $orders = array_values(array_filter($orders, fn ($o) => str_contains(strtolower($o['so_id']), $search) || str_contains(strtolower($o['po_number'] ?? ''), $search)));
        }

        $data = [
            'title'    => 'New Orders',
            'active'   => 'new_orders',
            'orders'   => $orders,
            'filter'   => $filter,
            'search'   => $this->request->getGet('search') ?? '',
            'success'  => session()->getFlashdata('success'),
            'error'    => session()->getFlashdata('error'),
        ];

        return view('supplier/new_orders', $data);
    }

    public function accept($soId)
    {
        $order = $this->stockOrderModel->find($soId);
        $label = StockOrderModel::labelFor((string) $soId);

        if (! $order || $order['supplier_id'] !== session()->get('supplier_id')) {
            return redirect()->to('/supplier/new-orders')->with('error', 'Order not found.');
        }

        $this->stockOrderModel->update($soId, ['status' => 'Preparing']);
        ProcurementDocs::ensureNumbers($soId); // the Sales Order number: the supplier's confirmation of the PO

        $this->notificationModel->push('Admin', 'Order Status', "Order {$label} accepted by supplier", session()->get('full_name') . " accepted order {$label} and has started preparing it.", null, 'order_status', "/admin/orders/{$soId}");
        $this->emailNotifier->toRole('Admin', "Order {$label} accepted by supplier", session()->get('full_name') . " accepted order {$label} and has started preparing it.");

        return redirect()->to('/supplier/new-orders')->with('success', "Order {$label} accepted. It now appears under Deliveries.");
    }

    public function decline($soId)
    {
        $order = $this->stockOrderModel->find($soId);
        $label = StockOrderModel::labelFor((string) $soId);

        if (! $order || $order['supplier_id'] !== session()->get('supplier_id')) {
            return redirect()->to('/supplier/new-orders')->with('error', 'Order not found.');
        }

        $reason = trim((string) $this->request->getPost('reason'));
        if ($reason === '') {
            return redirect()->to('/supplier/new-orders')->with('error', 'Please give a reason for declining the order.');
        }
        $reason = mb_substr($reason, 0, 255);

        $this->stockOrderModel->markCancelled($soId, ['decline_reason' => $reason, 'cancelled_at' => date('Y-m-d H:i:s')]); // alerts back to Active

        $declineMsg = session()->get('full_name') . " declined order {$label}. Reason: {$reason}. Please reassign or follow up.";
        $this->notificationModel->push('Admin', 'Order Status', "Order {$label} declined by supplier", $declineMsg, null, 'order_status', "/admin/orders/{$soId}");
        $this->emailNotifier->toRole('Admin', "Order {$label} declined by supplier", $declineMsg);

        return redirect()->to('/supplier/new-orders')->with('success', "Order {$label} declined.");
    }
}
