<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\EmailNotifier;
use App\Models\NotificationModel;
use App\Models\ProductModel;
use App\Models\ReorderAlertModel;
use App\Models\SoItemModel;
use App\Models\StockOrderModel;
use App\Models\SupplierModel;
use App\Models\UserModel;

class StockOrderController extends BaseController
{
    protected $stockOrderModel;
    protected $soItemModel;
    protected $supplierModel;
    protected $productModel;
    protected $reorderAlertModel;
    protected $userModel;
    protected $notificationModel;
    protected $emailNotifier;

    public function __construct()
    {
        $this->stockOrderModel   = new StockOrderModel();
        $this->soItemModel       = new SoItemModel();
        $this->supplierModel     = new SupplierModel();
        $this->productModel      = new ProductModel();
        $this->reorderAlertModel = new ReorderAlertModel();
        $this->userModel         = new UserModel();
        $this->notificationModel = new NotificationModel();
        $this->emailNotifier     = new EmailNotifier();
    }

    /**
     * The Supplier-portal account tied to a given supplier_id, so an order
     * notification (in-app or email) reaches only that one supplier's
     * inbox instead of broadcasting to every supplier account.
     */
    private function supplierUser(string $supplierId): ?array
    {
        return $this->userModel->where('supplier_id', $supplierId)->where('role', 'Supplier')->first();
    }

    private function supplierUserId(string $supplierId): ?int
    {
        return $this->supplierUser($supplierId)['user_id'] ?? null;
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

        $historySearch = trim((string) ($this->request->getGet('search') ?? ''));
        if ($historySearch !== '') {
            $needle = strtolower($historySearch);
            $historyOrders = array_values(array_filter($historyOrders, function ($o) use ($needle) {
                return str_contains(strtolower($o['so_id']), $needle)
                    || str_contains(strtolower($o['company_name'] ?? ''), $needle);
            }));
        }

        // order_date is stored as 'Y-m-d', so plain string comparison sorts
        // correctly without needing to parse it.
        $historyDateFrom = trim((string) ($this->request->getGet('date_from') ?? ''));
        $historyDateTo   = trim((string) ($this->request->getGet('date_to') ?? ''));
        if ($historyDateFrom !== '') {
            $historyOrders = array_values(array_filter($historyOrders, fn ($o) => $o['order_date'] >= $historyDateFrom));
        }
        if ($historyDateTo !== '') {
            $historyOrders = array_values(array_filter($historyOrders, fn ($o) => $o['order_date'] <= $historyDateTo));
        }

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
            'orders'          => $activeOrders,
            'historyOrders'   => $historyOrders,
            'historySearch'   => $historySearch,
            'historyDateFrom' => $historyDateFrom,
            'historyDateTo'   => $historyDateTo,
            'suppliers'      => $this->supplierModel->where('is_active', 1)->findAll(),
            'productGroups'  => $this->productModel->groupedActive(),
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

        // "Follow up"/"Delayed" is the admin asking the SUPPLIER for a status
        // update — the admin already knows they just clicked this, so the
        // notification belongs in the supplier's inbox, not the admin's own.
        $supplierUser = $this->supplierUser($order['supplier_id']);

        $this->notificationModel->push(
            'Supplier',
            'Order Status',
            "Order {$soId} needs a status update",
            "The expected delivery for {$soId} has passed. Please follow up and provide an update.",
            $supplierUser['user_id'] ?? null,
            'order_status',
            '/supplier/deliveries'
        );

        $this->emailNotifier->toUser(
            $supplierUser,
            "Order {$soId} needs a status update",
            "The expected delivery date for order {$soId} has passed. Please log in to the supplier portal and provide a status update."
        );

        return redirect()->to('/admin/orders')->with('success', "Order {$soId} flagged as delayed. Supplier has been notified to follow up.");
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

        $db = db_connect();
        $db->transStart();

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

        $alert   = null;
        $product = null;
        if ($alertId) {
            $alert   = $this->reorderAlertModel->find((int) $alertId);
            $product = $alert ? $this->productModel->find($alert['item_id']) : null;
            $this->reorderAlertModel->update((int) $alertId, ['status' => 'Ordered', 'so_id' => $soId]);
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return redirect()->back()->with('error', 'Failed to create the stock order. Please try again.');
        }

        if ($alertId && $alert) {
            // Closes the loop back to whoever flagged this item — staff share
            // a single portal login, so this broadcasts to all Staff rather
            // than one specific account.
            $this->notificationModel->push(
                'Staff',
                'Order Status',
                "Order placed for {$soId}",
                'The reorder you flagged for ' . ($product['item_name'] ?? 'an item') . " has been ordered as {$soId}.",
                null,
                'staff_activity',
                '/staff/reorder-alerts'
            );
        }

        $newOrderSupplierUser = $this->supplierUser($supplierId);

        $this->notificationModel->push(
            'Supplier',
            'Order Status',
            "New order {$soId}",
            "A new stock order {$soId} has been placed. Check New Orders to accept it.",
            $newOrderSupplierUser['user_id'] ?? null,
            'order_status',
            '/supplier/new-orders'
        );

        $this->emailNotifier->toUser(
            $newOrderSupplierUser,
            "New stock order {$soId}",
            "A new stock order {$soId} has been placed. Please log in to the supplier portal to review and accept it."
        );

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

        if ($newStatus !== $order['status']) {
            $statusSupplierUser = $this->supplierUser($order['supplier_id']);

            $this->notificationModel->push(
                'Supplier',
                'Order Status',
                "Order {$soId} updated to {$newStatus}",
                "The admin manually set order {$soId} to {$newStatus}.",
                $statusSupplierUser['user_id'] ?? null,
                'order_status',
                $newStatus === 'Cancelled' ? '/supplier/new-orders' : '/supplier/deliveries'
            );

            $this->emailNotifier->toUser(
                $statusSupplierUser,
                "Order {$soId} updated to {$newStatus}",
                "The admin manually set order {$soId} to {$newStatus}. Please log in to the supplier portal for details."
            );
        }

        return redirect()->to('/admin/orders')->with('success', "Order {$soId} marked as {$newStatus}.");
    }
}
