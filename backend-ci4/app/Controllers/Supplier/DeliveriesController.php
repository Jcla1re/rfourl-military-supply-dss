<?php

namespace App\Controllers\Supplier;

use App\Controllers\BaseController;
use App\Libraries\EmailNotifier;
use App\Models\NotificationModel;
use App\Models\SoItemModel;
use App\Models\StockOrderModel;

class DeliveriesController extends BaseController
{
    protected $stockOrderModel;
    protected $soItemModel;
    protected $notificationModel;
    protected $emailNotifier;

    public function __construct()
    {
        $this->stockOrderModel   = new StockOrderModel();
        $this->soItemModel       = new SoItemModel();
        $this->notificationModel = new NotificationModel();
        $this->emailNotifier     = new EmailNotifier();
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

    /**
     * The Supplier declares exactly what they're shipping, per line — this
     * is as far as the Supplier's authority goes. Confirming receipt (and
     * therefore restocking) is now an Admin/Staff-only action performed
     * against these declared quantities, not something the Supplier can
     * trigger themselves (see StockOrderModel::markDelivered()).
     */
    public function ship($soId)
    {
        $order = $this->stockOrderModel->find($soId);

        if (! $order || $order['supplier_id'] !== session()->get('supplier_id')) {
            return redirect()->to('/supplier/deliveries')->with('error', 'Order not found.');
        }

        // Only ever write quantities for so_item rows confirmed to belong
        // to this order — never trust posted so_item_id keys directly.
        $lines     = $this->soItemModel->forOrder($soId);
        $postedQty = $this->request->getPost('shipped_qty') ?? [];

        $quantities    = [];
        $itemSummaries = [];
        foreach ($lines as $line) {
            $soItemId = (int) $line['so_item_id'];
            $qty      = isset($postedQty[$soItemId]) ? max(0, (int) $postedQty[$soItemId]) : (int) $line['order_quantity'];

            $quantities[$soItemId] = $qty;
            $itemSummaries[]       = ($line['item_name'] ?? $line['item_id']) . ": {$qty} unit(s)";
        }

        $this->soItemModel->recordShippedQuantities($quantities);

        $trackingNo = $this->request->getPost('tracking_no') ?: $order['tracking_no'];

        $this->stockOrderModel->update($soId, [
            'status'      => 'Shipped',
            'tracking_no' => $trackingNo,
        ]);

        $message = 'Tracking no.: ' . ($trackingNo ?: 'not provided') . '. Items shipped: ' . implode(', ', $itemSummaries) . '.';

        $this->notificationModel->push('Admin', 'Order Status', "Order {$soId} shipped out", $message, null, 'order_status', "/admin/orders/{$soId}");
        $this->emailNotifier->toRole('Admin', "Order {$soId} shipped out", $message . ' Please confirm receipt once the delivery arrives.');

        return redirect()->to('/supplier/deliveries')->with('success', "Order {$soId} marked as shipped out.");
    }
}
