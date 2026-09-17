<?php

namespace App\Controllers\Supplier;

use App\Controllers\BaseController;
use App\Models\NotificationModel;
use App\Models\SoItemModel;
use App\Models\StockOrderModel;

class DeliveriesController extends BaseController
{
    protected $stockOrderModel;
    protected $soItemModel;
    protected $notificationModel;

    public function __construct()
    {
        $this->stockOrderModel   = new StockOrderModel();
        $this->soItemModel       = new SoItemModel();
        $this->notificationModel = new NotificationModel();
    }

    public function index()
    {
        $supplierId = session()->get('supplier_id');
        $orders     = $this->stockOrderModel->forSupplier($supplierId, ['Preparing', 'Shipped']);

        foreach ($orders as &$o) {
            $o['lines']       = $this->soItemModel->forOrder($o['so_id']);
            $o['total_units'] = array_sum(array_column($o['lines'], 'order_quantity'));
        }
        unset($o);

        $data = [
            'title'    => 'Deliveries',
            'subtitle' => count($orders) . ' active shipment' . (count($orders) === 1 ? '' : 's'),
            'active'   => 'deliveries',
            'orders'   => $orders,
            'success'  => session()->getFlashdata('success'),
            'error'    => session()->getFlashdata('error'),
        ];

        return view('supplier/deliveries', $data);
    }

    public function ship($soId)
    {
        $order = $this->stockOrderModel->find($soId);

        if (! $order || $order['supplier_id'] !== session()->get('supplier_id')) {
            return redirect()->to('/supplier/deliveries')->with('error', 'Order not found.');
        }

        $this->stockOrderModel->update($soId, [
            'status'      => 'Shipped',
            'tracking_no' => $this->request->getPost('tracking_no') ?: $order['tracking_no'],
        ]);

        $this->notificationModel->push('Admin', 'Order Status', "Order {$soId} shipped out", "Tracking no.: " . ($this->request->getPost('tracking_no') ?: 'not provided'));

        return redirect()->to('/supplier/deliveries')->with('success', "Order {$soId} marked as shipped out.");
    }

    public function deliver($soId)
    {
        $order = $this->stockOrderModel->find($soId);

        if (! $order || $order['supplier_id'] !== session()->get('supplier_id')) {
            return redirect()->to('/supplier/deliveries')->with('error', 'Order not found.');
        }

        $this->stockOrderModel->markDelivered($soId, session()->get('user_id'));

        $this->notificationModel->push('Admin', 'Order Status', "Order {$soId} delivered", session()->get('full_name') . " marked order {$soId} as delivered.");

        return redirect()->to('/supplier/deliveries')->with('success', "Order {$soId} marked as delivered. Inventory has been updated.");
    }
}
