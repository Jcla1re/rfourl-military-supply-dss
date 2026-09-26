<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\PosPricing;
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
            'receipt' => session()->getFlashdata('receipt'),
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
            $date         = $this->request->getGet('date') ?: date('Y-m-d');
            $transactions = $this->transactionsForDate($date);
            $weekRange    = $this->salesTransactionModel->weeklyTotals();

            $data['transactions'] = $transactions;
            $data['date']         = $date;
            $data['salesToday']   = $this->salesTransactionModel->totalForToday();
            $data['salesWeek']    = array_sum($weekRange['data']);
            $data['salesMonth']   = (float) ($this->salesTransactionModel->selectSum('total_amount')
                ->where("DATE_FORMAT(sale_date, '%Y-%m')", date('Y-m'))->first()['total_amount'] ?? 0);
            $data['txnToday']     = $this->salesTransactionModel->countForToday();
            $data['txnWeek']      = $this->salesTransactionModel->countInRange(
                (new \DateTime('-6 days'))->format('Y-m-d'),
                date('Y-m-d')
            );
            $data['txnMonth']     = $this->salesTransactionModel
                ->where("DATE_FORMAT(sale_date, '%Y-%m')", date('Y-m'))
                ->countAllResults();
        }

        return view('admin/sales', $data);
    }

    /**
     * Transactions (with line items) for one calendar day, newest first —
     * shared by the receipts tab and PDF export so both always agree on
     * exactly which day is being shown.
     */
    private function transactionsForDate(string $date): array
    {
        $transactions = $this->salesTransactionModel
            ->where('DATE(sale_date)', $date)
            ->orderBy('sale_date', 'DESC')
            ->findAll();

        foreach ($transactions as &$t) {
            $t['items'] = $this->salesItemModel->forSale($t['sales_id']);
        }
        unset($t);

        return $transactions;
    }

    public function exportPdf()
    {
        $date         = $this->request->getGet('date') ?: date('Y-m-d');
        $transactions = $this->transactionsForDate($date);

        $html = view('admin/sales_pdf', [
            'date'         => $date,
            'transactions' => $transactions,
            'totalSales'   => array_sum(array_column($transactions, 'total_amount')),
        ]);

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->setPaper('a4', 'landscape');
        $dompdf->loadHtml($html);
        $dompdf->render();

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="sales-' . $date . '.pdf"')
            ->setBody($dompdf->output());
    }

    public function checkout()
    {
        $itemIds    = $this->request->getPost('item_id') ?? [];
        $quantities = $this->request->getPost('quantity') ?? [];
        $method     = $this->request->getPost('payment_method') ?: 'Cash';

        if (empty($itemIds)) {
            return redirect()->to('/admin/sales')->with('error', 'Cart is empty.');
        }

        if (! in_array($method, ['Cash', 'GCash', 'Split'], true)) {
            $method = 'Cash';
        }

        $products = [];
        $cartLines = [];
        foreach ($itemIds as $i => $itemId) {
            $product = $this->productModel->find($itemId);
            if (! $product) {
                continue;
            }
            $qty = max(1, (int) ($quantities[$i] ?? 1));
            if ($qty > (int) $product['current_stock']) {
                return redirect()->to('/admin/sales')->with('error', "Not enough stock for {$product['item_name']}.");
            }
            $products[] = $product;
            $cartLines[] = [
                'item_id'    => $product['item_id'],
                'item_name'  => $product['item_name'],
                'unit_price' => (float) $product['selling_price'],
                'qty'        => $qty,
            ];
        }

        if (empty($cartLines)) {
            return redirect()->to('/admin/sales')->with('error', 'Cart is empty.');
        }

        $pricing  = PosPricing::priceCart($cartLines);
        $subtotal = $pricing['subtotal'];
        $discount = $pricing['discount'];
        $total    = $pricing['total'];

        $gcashRef = trim((string) ($this->request->getPost('gcash_reference_no') ?: ''));

        if ($method === 'Split') {
            $gcashAmount = round((float) ($this->request->getPost('gcash_amount') ?: 0), 2);
            if ($gcashAmount <= 0 || $gcashAmount >= $total) {
                return redirect()->to('/admin/sales')->with('error', 'GCash amount must be between ₱0 and the total for a split payment.');
            }
            if ($gcashRef === '') {
                return redirect()->to('/admin/sales')->with('error', 'GCash reference number is required.');
            }
            $cashDue      = round($total - $gcashAmount, 2);
            $cashReceived = round((float) ($this->request->getPost('cash_received') ?: 0), 2);
            if ($cashReceived < $cashDue) {
                return redirect()->to('/admin/sales')->with('error', 'Insufficient cash received for the remaining balance.');
            }
            $cashAmount = $cashDue;
            $changeDue  = round($cashReceived - $cashDue, 2);
        } elseif ($method === 'GCash') {
            $gcashAmount = $total;
            $cashAmount  = 0.0;
            if ($gcashRef === '') {
                return redirect()->to('/admin/sales')->with('error', 'GCash reference number is required.');
            }
            $cashReceived = $total;
            $changeDue    = 0.0;
        } else {
            $cashReceived = round((float) ($this->request->getPost('cash_received') ?: 0), 2);
            if ($cashReceived < $total) {
                return redirect()->to('/admin/sales')->with('error', 'Insufficient cash received.');
            }
            $cashAmount  = $total;
            $gcashAmount = 0.0;
            $gcashRef    = null;
            $changeDue   = round($cashReceived - $total, 2);
        }

        $receiptNo = $this->salesTransactionModel->generateReceiptNo();

        $db = db_connect();
        $db->transStart();

        $salesId = $this->salesTransactionModel->insert([
            'receipt_no'          => $receiptNo,
            'user_id'             => session()->get('user_id'),
            'sale_date'           => date('Y-m-d H:i:s'),
            'payment_method'      => $method,
            'cash_amount'         => $cashAmount,
            'gcash_amount'        => $gcashAmount,
            'gcash_reference_no'  => $gcashRef,
            'subtotal'            => $subtotal,
            'discount'            => $discount,
            'total_amount'        => $total,
        ], true);

        foreach ($pricing['lines'] as $i => $line) {
            $product = $products[$i];

            $this->salesItemModel->insert([
                'sales_id'      => $salesId,
                'item_id'       => $product['item_id'],
                'quantity_sold' => $line['qty'],
                'selling_price' => $line['unit_price'],
            ]);

            $newStock = (int) $product['current_stock'] - $line['qty'];
            $this->productModel->update($product['item_id'], [
                'current_stock' => $newStock,
            ]);

            $this->inventoryLogModel->record(
                $product['item_id'],
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

        $receipt = [
            'receipt_no'         => $receiptNo,
            'sale_date'          => date('Y-m-d H:i:s'),
            'lines'              => array_map(fn ($l) => [
                'item_name'  => $l['item_name'],
                'qty'        => $l['qty'],
                'unit_price' => $l['unit_price'],
                'total'      => $l['line_total'],
                'discount'   => $l['discount'],
                'badge'      => $l['badge'],
            ], $pricing['lines']),
            'subtotal'           => $subtotal,
            'discount'           => $discount,
            'vatable'            => $pricing['vatable'],
            'vat'                => $pricing['vat'],
            'total'              => $total,
            'cash_received'      => $cashReceived,
            'change'             => max(0, $changeDue),
            'payment_method'     => $method,
            'cash_amount'        => $cashAmount,
            'gcash_amount'       => $gcashAmount,
            'gcash_reference_no' => $gcashRef,
            'staff_name'         => session()->get('full_name'),
        ];

        return redirect()->to('/admin/sales')->with('success', 'Sale completed and receipt printed.')->with('receipt', $receipt);
    }
}
