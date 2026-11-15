<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\PosCheckout;
use App\Libraries\Toast;
use App\Models\ProductModel;
use App\Models\SalesItemModel;
use App\Models\SalesTransactionModel;

class SalesController extends BaseController
{
    protected $salesTransactionModel;
    protected $salesItemModel;
    protected $productModel;

    public function __construct()
    {
        $this->salesTransactionModel = new SalesTransactionModel();
        $this->salesItemModel        = new SalesItemModel();
        $this->productModel          = new ProductModel();
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
            $groups = $this->productModel->groupedActive();

            foreach ($groups as &$g) {
                $prices             = array_column($g['variants'], 'selling_price');
                $g['min_price']     = min($prices);
                $g['max_price']     = max($prices);
                $g['is_multi_size'] = count($g['variants']) > 1;
            }
            unset($g);

            $data['groups']      = $groups;
            $data['categories']  = ProductModel::CATEGORIES;
        } else {
            $date         = $this->request->getGet('date') ?: date('Y-m-d');
            $transactions = $this->transactionsForDate($date);
            $weekRange    = $this->salesTransactionModel->weeklyTotals();

            $data['transactions'] = $transactions;
            $data['date']         = $date;
            $data['salesToday']   = $this->salesTransactionModel->totalForToday();
            $data['salesWeek']    = array_sum($weekRange['data']);
            $data['salesMonth']   = $this->salesTransactionModel->totalForMonth(date('Y-m'));
            $data['txnToday']     = $this->salesTransactionModel->countForToday();
            $data['txnWeek']      = $this->salesTransactionModel->countInRange(
                (new \DateTime('-6 days'))->format('Y-m-d'),
                date('Y-m-d')
            );
            $data['txnMonth']     = $this->salesTransactionModel->countForMonth(date('Y-m'));
        }

        return view('admin/sales', $data);
    }

    /**
     * Transactions (with line items) for one calendar day, newest first —
     * shared by the receipts tab and PDF export so both always agree on
     * exactly which day is being shown. Voided duplicates are listed (marked, see
     * SalesTransactionModel::voidLabel()) but never added to a total.
     */
    private function transactionsForDate(string $date): array
    {
        $builder = $this->salesTransactionModel
            ->select('sales_transaction.*, users.full_name as cashier_name, users.role as cashier_role')
            ->join('users', 'users.user_id = sales_transaction.user_id', 'left');
        $transactions = SalesTransactionModel::withOriginalReceipt($builder)
            ->where('sales_transaction.sale_date >=', $date . ' 00:00:00')
            ->where('sales_transaction.sale_date <', date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00')
            ->orderBy('sales_transaction.sale_date', 'DESC')
            ->findAll();

        foreach ($transactions as &$t) {
            $t['items'] = $this->salesItemModel->forSale($t['sales_id']);
        }
        unset($t);

        return $transactions;
    }

    public function exportCsv()
    {
        [$from, $to, $label] = \App\Libraries\SalesCsvExport::range(
            $this->request->getGet('date'),
            $this->request->getGet('month')
        );

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="sales-' . $label . '.csv"')
            ->setBody(\App\Libraries\SalesCsvExport::build($from, $to));
    }

    public function exportPdf()
    {
        $date         = $this->request->getGet('date') ?: date('Y-m-d');
        $transactions = $this->transactionsForDate($date);

        $html = view('admin/sales_pdf', [
            'date'         => $date,
            'transactions' => $transactions,
            'totalSales'   => SalesTransactionModel::totalOf($transactions), // voided duplicates not counted
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

    /**
     * Has this OR / invoice number been used before? The checkout form asks before it submits and, if so,
     * warns the cashier with the receipt it was used on. A warning only: checkout() still accepts the sale.
     */
    public function checkOr()
    {
        $used = $this->salesTransactionModel->findByOrNumber((string) $this->request->getGet('or_number'));

        return $this->response->setJSON($used ? ['used' => true, 'receipt_no' => (string) $used['receipt_no']] : ['used' => false]);
    }

    /**
     * Checkout & Print Receipt. The sale itself (one-time key, stock locked and decreased in one
     * transaction) is App\Libraries\PosCheckout, shared with the other role's POS.
     */
    public function checkout()
    {
        $result = PosCheckout::run(
            $this->request,
            session()->get('user_id') !== null ? (int) session()->get('user_id') : null,
            session()->get('role') === 'Admin' ? 'Owner' : (string) session()->get('full_name')
        );
        $back = redirect()->to('/admin/sales');

        if (isset($result['error'])) {
            // Not enough stock: nothing was sold; the cart comes back (the posted items and quantities,
            // as old input; prices are read again from the server) with an accurate title.
            if (($result['code'] ?? null) === PosCheckout::NOT_ENOUGH_STOCK) {
                return $back->withInput()->with('toast', Toast::error('Not enough stock', $result['error']));
            }

            return $back->with('error', $result['error']);
        }

        // The same form again (double click, resubmit): nothing new was saved; show the first receipt.
        if (! empty($result['repeat'])) {
            $back->with('toast', Toast::info($result['receipt_no'] !== null
                ? "This sale was already saved (receipt {$result['receipt_no']})."
                : 'This sale was already saved.'));

            return $result['receipt'] ? $back->with('receipt', $result['receipt']) : $back;
        }

        return $back->with('success', 'Sale completed and receipt printed.')->with('receipt', $result['receipt']);
    }
}
