<?php
// app/Controllers/Staff/LogTransactionController.php

namespace App\Controllers\Staff;

use App\Controllers\BaseController;
use App\Libraries\EmailNotifier;
use App\Libraries\OnceToken;
use App\Libraries\Toast;
use App\Models\InventoryLogModel;
use App\Models\NotificationModel;
use App\Models\ProductModel;
use App\Models\SoItemModel;
use App\Models\StockOrderModel;

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
    protected $stockOrderModel;

    public function __construct()
    {
        $this->productModel      = new ProductModel();
        $this->inventoryLogModel = new InventoryLogModel();
        $this->notificationModel = new NotificationModel();
        $this->emailNotifier     = new EmailNotifier();
        $this->stockOrderModel   = new StockOrderModel();
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

        // Restock isn't free-text anymore — it's a checklist confirming an
        // actual supplier shipment against what they declared, so it gets
        // its own view backed by real stock_order data instead of the
        // generic item/quantity form the other 3 types still use.
        if ($type === 'restock') {
            return $this->restockChecklist();
        }

        $data = [
            'title'    => 'Log Transaction',
            'active'   => 'log_transaction',
            'type'     => $type,
            'typeInfo' => self::TYPES[$type],
            'products' => $this->productModel->where('is_active', 1)->orderBy('item_name', 'ASC')->findAll(),
            'recent'   => $this->inventoryLogModel
                ->select('inventory_log.*, products.item_name')
                ->join('products', 'products.item_id = inventory_log.item_id', 'left')
                ->where('inventory_log.log_type', self::TYPES[$type]['log_type'])
                ->orderBy('inventory_log.timestamp', 'DESC')
                ->findAll(5),
            'error'    => session()->getFlashdata('error'),
        ];

        return view('staff/log_transaction_form', $data);
    }

    private function restockChecklist()
    {
        $orders      = $this->stockOrderModel->listWithSupplier('Shipped');
        $soItemModel = new SoItemModel();

        foreach ($orders as &$o) {
            $o['lines'] = $soItemModel->forOrder($o['so_id']);
        }
        unset($o);

        return view('staff/log_transaction_restock', [
            'title'   => 'Log Transaction',
            'active'  => 'log_transaction',
            'orders'  => $orders,
            'success' => session()->getFlashdata('success'),
            'error'   => session()->getFlashdata('error'),
        ]);
    }

    /**
     * Confirms a supplier delivery against the checklist — the Staff
     * equivalent of the Admin's "Mark as Done" confirm, sharing the exact
     * same StockOrderModel::markDelivered() so both roles restock inventory
     * identically and consistently, with the supplier no longer able to
     * finalize this themselves (see Supplier\DeliveriesController).
     */
    public function confirmRestock($soId)
    {
        $order = $this->stockOrderModel->find($soId);

        $back = redirect()->to('/staff/log-transaction/restock');
        $key  = OnceToken::key($this->request);
        // The same form again: stock was added the first time; say so instead of an error.
        if (OnceToken::used($key)) {
            return $back->with('toast', Toast::info('Already saved.'));
        }
        if (! $order) {
            return $back->with('error', 'Order not found or not ready to confirm.');
        }
        if ($order['status'] === 'Delivered') {
            return $back->with('toast', Toast::info('This order was already received.'));
        }
        if ($order['status'] !== 'Shipped') {
            return $back->with('error', 'Order not found or not ready to confirm.');
        }

        // Stock is added once, only from Shipped, in one transaction (also if the Owner confirms it at the same moment).
        $result = $this->stockOrderModel->markDelivered($soId, (int) session()->get('user_id'), $key);
        if ($result === StockOrderModel::ALREADY_SAVED) {
            return $back->with('toast', Toast::info('Already saved.'));
        }
        if ($result === StockOrderModel::ALREADY_RECEIVED) {
            return $back->with('toast', Toast::info('This order was already received.'));
        }
        if ($result === StockOrderModel::FAILED) {
            return $back->with('error', 'Saving failed and nothing was changed. Please try again.');
        }

        $staffName = session()->get('full_name') ?? 'A staff member';
        $message   = "{$staffName} confirmed delivery for order {$soId}. Inventory has been updated.";

        $this->notificationModel->push('Admin', 'Order Status', "Order {$soId} delivery confirmed", $message, null, 'order_status', "/admin/orders/{$soId}");
        $this->emailNotifier->toRole('Admin', "Order {$soId} delivery confirmed", $message);

        return redirect()->to('/staff/log-transaction/restock')->with('success', "Order {$soId} confirmed as delivered. Inventory updated.");
    }

    public function store()
    {
        $type = (string) $this->request->getPost('type');

        if (! isset(self::TYPES[$type])) {
            return redirect()->to('/staff/log-transaction')->with('error', 'Unknown transaction type.');
        }

        // The same form again (double click, resubmit): the first one was saved; say so.
        $onceKey = OnceToken::key($this->request);
        if (OnceToken::used($onceKey)) {
            return redirect()->to('/staff/log-transaction')->with('toast', Toast::info('Already saved.'));
        }

        $itemId = (string) $this->request->getPost('item_id');
        $product = $itemId ? $this->productModel->find($itemId) : null;

        if (! $product) {
            return redirect()->to("/staff/log-transaction/{$type}")->with('error', 'Please select a valid item.');
        }

        $currentStock = (int) $product['current_stock'];
        $logType      = self::TYPES[$type]['log_type'];
        $userId       = session()->get('user_id');

        if ($type === 'damaged' && (int) $this->request->getPost('qty_affected') > $currentStock) {
            return redirect()->to("/staff/log-transaction/{$type}")
                ->with('error', "Only {$currentStock} in stock. Enter {$currentStock} or less.");
        }

        if ($type === 'return') {
            $saleRef = trim((string) $this->request->getPost('original_sale_ref'));
            $sale    = $saleRef !== '' ? db_connect()->table('sales_transaction')->where('receipt_no', $saleRef)->get()->getRowArray() : null;
            if (! $sale) {
                return redirect()->to("/staff/log-transaction/{$type}")
                    ->with('error', 'Receipt number not found. Check and try again.');
            }
        }

        switch ($type) {
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

        // Saved once per form (OnceToken), and the stock change is made inside one transaction on
        // the locked row, relative to what is there now, never an absolute value from the read above.
        $db = db_connect();
        $db->transStart();

        if (OnceToken::claim($onceKey, 'stock_log_' . $type, $userId ? (int) $userId : null)) {
            return redirect()->to('/staff/log-transaction')->with('toast', Toast::info('Already saved.'));
        }

        $locked = $this->productModel->lockRows([$itemId])[$itemId] ?? null;
        $now    = (int) ($locked['current_stock'] ?? 0);
        if ($type === 'adjustment') {
            $delta = $actual - $now; // a count sets the stock to what was counted
            $qty   = abs($delta);
        }
        if ($type === 'damaged' && $qty > $now) {
            $db->transRollback();

            return redirect()->to("/staff/log-transaction/{$type}")
                ->with('error', "Only {$now} in stock. Enter {$now} or less.");
        }

        $newStock = $delta === 0 ? null : $this->productModel->changeStock($itemId, $delta);
        if ($newStock === null) {
            $db->transRollback();

            return redirect()->to("/staff/log-transaction/{$type}")->with('error', 'Nothing to log — quantity is zero.');
        }

        $this->inventoryLogModel->record(
            $itemId,
            $userId,
            $logType,
            $delta,
            $newStock,
            null,
            $notes ?: null
        );

        OnceToken::complete($onceKey, 'stock_log', $itemId);
        $db->transComplete();

        if ($db->transStatus() === false) {
            return redirect()->to("/staff/log-transaction/{$type}")->with('error', 'Saving failed and nothing was changed. Please try again.');
        }

        (new \App\Models\ReorderNoticeModel())->clearResolved([$itemId]);
        (new \App\Models\ReorderAlertModel())->syncFromLiveInventory();

        $this->notifyAdmin($type, (array) $product, $qty, $delta, $newStock, $notes);

        return redirect()->to('/staff/log-transaction')->with('success', 'Transaction logged successfully.');
    }

    /**
     * Every logged transaction notifies the Admin/Owner for visibility.
     * (Restock no longer goes through here — it's confirmed via the
     * checklist in confirmRestock(), which sends its own notification.)
     */
    private function notifyAdmin(string $type, array $product, int $qty, int $delta, int $newStock, string $notes): void
    {
        $staffName = session()->get('full_name') ?? 'A staff member';
        $itemName  = $product['item_name'];

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
