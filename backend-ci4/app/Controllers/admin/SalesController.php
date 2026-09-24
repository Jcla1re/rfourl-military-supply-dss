<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\InventoryLogModel;
use App\Models\ProductModel;
use App\Models\SalesItemModel;
use App\Models\SalesTransactionModel;

class SalesController extends BaseController
{
    protected $salesTransactionModel;
    protected $salesItemModel;
    protected $productModel;
    protected $inventoryLogModel;

    public function __construct()
    {
        $this->salesTransactionModel = new SalesTransactionModel();
        $this->salesItemModel        = new SalesItemModel();
        $this->productModel          = new ProductModel();
        $this->inventoryLogModel     = new InventoryLogModel();
    }

    public function index()
    {
        $tab = $this->request->getGet('tab') === 'receipts' ? 'receipts' : 'pos';

        $data = [
            'title'  => 'POS & Sales',
            'active' => 'sales',
            'tab'    => $tab,
            'success'=> session()->getFlashdata('success'),
            'error'  => session()->getFlashdata('error'),
        ];

        if ($tab === 'pos') {
            $products = $this->productModel->where('is_active', 1)->orderBy('item_name', 'ASC')->findAll();
            foreach ($products as &$p) {
                $rop = (int) ($p['manual_rop_warning'] ?? 0);
                $stock = (int) $p['current_stock'];
                $p['pos_status'] = $stock <= 0
                    ? ['label' => 'Out of Stock', 'class' => 'red']
                    : ($rop > 0 && $stock <= $rop
                        ? ['label' => "{$stock} Left (Low)", 'class' => 'amber']
                        : ['label' => "{$stock} in Stock", 'class' => 'green']);
            }
            unset($p);

            $data['products']   = $products;
            $data['categories'] = ProductModel::CATEGORIES;
        } else {
            $month = $this->request->getGet('month') ?: date('Y-m');

            $transactions = $this->salesTransactionModel
                ->where("DATE_FORMAT(sale_date, '%Y-%m')", $month)
                ->orderBy('sale_date', 'DESC')
                ->findAll();

            foreach ($transactions as &$t) {
                $t['items'] = $this->salesItemModel->forSale($t['sales_id']);
            }
            unset($t);

            $data['transactions'] = $transactions;
            $data['month']        = $month;
            $data['salesToday']   = $this->salesTransactionModel->totalForToday();
            $data['salesWeek']    = array_sum($this->salesTransactionModel->weeklyTotals()['data']);
            $data['salesMonth']   = (float) ($this->salesTransactionModel->selectSum('total_amount')
                ->where("DATE_FORMAT(sale_date, '%Y-%m')", date('Y-m'))->first()['total_amount'] ?? 0);
        }

        return view('admin/sales', $data);
    }

    public function checkout()
    {
        $itemIds   = $this->request->getPost('item_id') ?? [];
        $quantities= $this->request->getPost('quantity') ?? [];
        $method    = $this->request->getPost('payment_method') ?: 'Cash';
        $discount  = (float) ($this->request->getPost('discount') ?: 0);

        if (empty($itemIds)) {
            return redirect()->to('/admin/sales')->with('error', 'Cart is empty.');
        }

        $subtotal = 0;
        $lines = [];
        foreach ($itemIds as $i => $itemId) {
            $product = $this->productModel->find($itemId);
            if (! $product) {
                continue;
            }
            $qty = max(1, (int) ($quantities[$i] ?? 1));
            if ($qty > (int) $product['current_stock']) {
                return redirect()->to('/admin/sales')->with('error', "Not enough stock for {$product['item_name']}.");
            }
            $lineTotal = $qty * (float) $product['selling_price'];
            $subtotal += $lineTotal;
            $lines[] = ['product' => $product, 'qty' => $qty];
        }

        if (empty($lines)) {
            return redirect()->to('/admin/sales')->with('error', 'Cart is empty.');
        }

        $total = max(0, $subtotal - $discount);

        $db = db_connect();
        $db->transStart();

        $salesId = $this->salesTransactionModel->insert([
            'receipt_no'     => $this->salesTransactionModel->generateReceiptNo(),
            'user_id'        => session()->get('user_id'),
            'sale_date'      => date('Y-m-d H:i:s'),
            'payment_method' => $method,
            'subtotal'       => $subtotal,
            'discount'       => $discount,
            'total_amount'   => $total,
        ], true);

        foreach ($lines as $line) {
            $this->salesItemModel->insert([
                'sales_id'      => $salesId,
                'item_id'       => $line['product']['item_id'],
                'quantity_sold' => $line['qty'],
                'selling_price' => $line['product']['selling_price'],
            ]);

            $newStock = (int) $line['product']['current_stock'] - $line['qty'];
            $this->productModel->update($line['product']['item_id'], [
                'current_stock' => $newStock,
            ]);

            $this->inventoryLogModel->record(
                $line['product']['item_id'],
                session()->get('user_id'),
                'SALE',
                -$line['qty'],
                $newStock,
                null,
                'Ref: sales_id=' . $salesId
            );
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return redirect()->to('/admin/sales')->with('error', 'Checkout failed and was rolled back. Please try again.');
        }

        return redirect()->to('/admin/sales')->with('success', 'Sale completed and receipt printed.');
    }
}
