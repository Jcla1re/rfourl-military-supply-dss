<?php
// app/Controllers/Staff/LogTransactionController.php

namespace App\Controllers\Staff;

use App\Controllers\BaseController;
use App\Models\InventoryLogModel;
use App\Models\ProductModel;
use App\Models\UserModel;

class LogTransactionController extends BaseController
{
    private const TYPES = [
        'restock'      => ['label' => 'Restock', 'log_type' => 'Restock'],
        'return'       => ['label' => 'Customer Return', 'log_type' => 'Return'],
        'damaged'      => ['label' => 'Damaged / Lost', 'log_type' => 'Damaged'],
        'adjustment'   => ['label' => 'Manual Adjustment', 'log_type' => 'Adjustment'],
    ];

    protected $productModel;
    protected $inventoryLogModel;

    public function __construct()
    {
        $this->productModel      = new ProductModel();
        $this->inventoryLogModel = new InventoryLogModel();
    }

    public function index()
    {
        $userModel = new UserModel();

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
        $type = $this->request->getPost('type');

        if (! isset(self::TYPES[$type])) {
            return redirect()->to('/staff/log-transaction')->with('error', 'Unknown transaction type.');
        }

        $itemId = $this->request->getPost('item_id');
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

        return redirect()->to('/staff/log-transaction')->with('success', 'Transaction logged successfully.');
    }
}
