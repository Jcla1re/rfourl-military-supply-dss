<?php
// app/Controllers/Staff/LogTransactionController.php

namespace App\Controllers\Staff;

use App\Controllers\BaseController;
use App\Libraries\EmailNotifier;
use App\Models\InventoryLogModel;
use App\Models\NotificationModel;
use App\Models\ProductModel;

class LogTransactionController extends BaseController
{
    private const TYPES = [
        'restock'      => ['label' => 'Restock', 'log_type' => 'RESTOCK'],
        'return'       => ['label' => 'Customer Return', 'log_type' => 'RETURN'],
        'damaged'      => ['label' => 'Damaged / Lost', 'log_type' => 'DAMAGED_LOST'],
        'adjustment'   => ['label' => 'Manual Adjustment', 'log_type' => 'MANUAL_ADJUSTMENT'],
    ];

    protected $productModel;
    protected $inventoryLogModel;
    protected $notificationModel;
    protected $emailNotifier;

    public function __construct()
    {
        $this->productModel      = new ProductModel();
        $this->inventoryLogModel = new InventoryLogModel();
        $this->notificationModel = new NotificationModel();
        $this->emailNotifier     = new EmailNotifier();
    }

    public function index()
    {
        $logs = $this->inventoryLogModel
            ->select('inventory_log.*, products.item_name, users.full_name as staff_name')
            ->join('products', 'products.item_id = inventory_log.item_id', 'left')
            ->join('users', 'users.user_id = inventory_log.user_id', 'left')
            ->orderBy('inventory_log.timestamp', 'DESC')
            ->findAll(10);

        $data = [
            'title'   => 'Log Transaction',
            'active'  => 'log_transaction',
            'types'   => self::TYPES,
            'logs'    => $logs,
            'success' => session()->getFlashdata('success'),
            'error'   => session()->getFlashdata('error'),
        ];

        return view('staff/log_transaction', $data);
    }

    public function form($type)
    {
        if (! isset(self::TYPES[$type])) {
            return redirect()->to('/staff/log-transaction');
        }

        $data = [
            'title'    => 'Log Transaction',
            'active'   => 'log_transaction',
            'type'     => $type,
            'typeInfo' => self::TYPES[$type],
            'products' => $this->productModel->where('is_active', 1)->orderBy('item_name', 'ASC')->findAll(),
            'error'    => session()->getFlashdata('error'),
        ];

        return view('staff/log_transaction_form', $data);
    }

    public function store()
    {
        $type = (string) $this->request->getPost('type');

        if (! isset(self::TYPES[$type])) {
            return redirect()->to('/staff/log-transaction')->with('error', 'Unknown transaction type.');
        }

        $itemId = (string) $this->request->getPost('item_id');
        $product = $itemId ? $this->productModel->find($itemId) : null;

        if (! $product) {
            return redirect()->to("/staff/log-transaction/{$type}")->with('error', 'Please select a valid item.');
        }

        $currentStock = (int) $product['current_stock'];
        $logType      = self::TYPES[$type]['log_type'];
        $userId       = session()->get('user_id');

        switch ($type) {
            case 'restock':
                $qty     = max(0, (int) $this->request->getPost('quantity'));
                $delta   = $qty;
                $notes   = 'Supplier: ' . ($this->request->getPost('supplier') ?: '—');
                break;

            case 'return':
                $qty     = max(0, (int) $this->request->getPost('qty_returned'));
                $delta   = $qty;
                $notes   = trim(
                    'Condition: ' . ($this->request->getPost('item_condition') ?: '—') .
                    '. Reason: ' . ($this->request->getPost('reason') ?: '—') .
                    '. Refund action: ' . ($this->request->getPost('refund_action') ?: '—') .
                    ($this->request->getPost('original_sale_ref') ? '. Ref: ' . $this->request->getPost('original_sale_ref') : '')
                );
                break;

            case 'damaged':
                $qty     = max(0, (int) $this->request->getPost('qty_affected'));
                $delta   = -$qty;
                $notes   = trim(
                    'Condition: ' . ($this->request->getPost('item_condition') ?: '—') .
                    '. ' . ($this->request->getPost('notes') ?: '') .
                    ($this->request->getPost('est_loss') ? '. Est. loss: ₱' . $this->request->getPost('est_loss') : '')
                );
                break;

            case 'adjustment':
                $actual  = max(0, (int) $this->request->getPost('actual_counted_qty'));
                $delta   = $actual - $currentStock;
                $qty     = abs($delta);
                $notes   = trim(
                    'Reason: ' . ($this->request->getPost('adjustment_reason') ?: '—') .
                    ($this->request->getPost('staff_ref') ? '. Staff: ' . $this->request->getPost('staff_ref') : '')
                );
                break;

            default:
                $qty   = 0;
                $delta = 0;
                $notes = '';
        }

        if ($delta === 0) {
            return redirect()->to("/staff/log-transaction/{$type}")->with('error', 'Nothing to log — quantity is zero.');
        }

        $newStock = max(0, $currentStock + $delta);

        $this->productModel->update($itemId, ['current_stock' => $newStock]);

        $this->inventoryLogModel->record(
            $itemId,
            $userId,
            $logType,
            $delta,
            $newStock,
            null,
            $notes ?: null
        );

        $this->notifyAdmin($type, (array) $product, $qty, $delta, $newStock, $notes);

        return redirect()->to('/staff/log-transaction')->with('success', 'Transaction logged successfully.');
    }

    /**
     * Every logged transaction notifies the Admin/Owner for visibility.
     * Restock is the one that needs a fast response — it means a supplier
     * delivery physically arrived, and the matching stock order is still
     * sitting open until the Owner marks it Delivered from the Orders page
     * — so restock also gets a direct link there and an email, the same
     * "needs quick action" treatment given to other order-status events.
     */
    private function notifyAdmin(string $type, array $product, int $qty, int $delta, int $newStock, string $notes): void
    {
        $staffName = session()->get('full_name') ?? 'A staff member';
        $itemName  = $product['item_name'];

        if ($type === 'restock') {
            $title   = "Restock logged: {$itemName}";
            $message = "{$staffName} logged a restock of {$qty} unit(s) for {$itemName}. New stock: {$newStock}. {$notes}"
                . ' If this completes an open order, mark it Delivered from Orders.';

            $this->notificationModel->push('Admin', 'Restock', $title, $message, null, 'order_status', '/admin/orders');

            $this->emailNotifier->toRole(
                'Admin',
                "Restock logged for {$itemName} — check open orders",
                "{$message}\n\nLog in to the admin portal and check Orders to mark the matching order as delivered."
            );

            return;
        }

        $labels = [
            'return'     => ['type' => 'Customer Return', 'title' => "Customer return logged: {$itemName}"],
            'damaged'    => ['type' => 'Damaged/Lost Item', 'title' => "Damaged/Lost reported: {$itemName}"],
            'adjustment' => ['type' => 'Manual Adjustment', 'title' => "Stock adjustment logged: {$itemName}"],
        ];
        $label = $labels[$type] ?? ['type' => 'Inventory Update', 'title' => "Inventory updated: {$itemName}"];

        $message = "{$staffName} logged a " . strtolower($label['type']) . " for {$itemName} ({$delta} units). New stock: {$newStock}. {$notes}";

        $this->notificationModel->push('Admin', $label['type'], $label['title'], trim($message), null, 'staff_activity');
    }
}
