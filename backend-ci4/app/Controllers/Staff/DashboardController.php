<?php
// app/Controllers/Staff/DashboardController.php

namespace App\Controllers\Staff;

use App\Controllers\BaseController;
use App\Models\NotificationModel;
use App\Models\ProductModel;
use App\Models\ReorderAlertModel;
use App\Models\SalesItemModel;
use App\Models\SalesTransactionModel;

class DashboardController extends BaseController
{
    public function index()
    {
        $productModel          = new ProductModel();
        $salesTransactionModel = new SalesTransactionModel();
        $salesItemModel        = new SalesItemModel();
        $reorderAlertModel     = new ReorderAlertModel();

        // Keep alerts in sync with live inventory (see ReorderAlertModel).
        $reorderAlertModel->syncFromLiveInventory();

        $products    = $productModel->where('is_active', 1)->findAll();
        $totalStock  = array_sum(array_column($products, 'current_stock'));
        $salesToday  = $salesTransactionModel->totalForToday();
        $salesYesterday = (float) ($salesTransactionModel->selectSum('total_amount')
            ->where('DATE(sale_date)', date('Y-m-d', strtotime('-1 day')))->first()['total_amount'] ?? 0);

        $txnToday     = $salesTransactionModel->where('DATE(sale_date)', date('Y-m-d'))->countAllResults();
        $txnYesterday = $salesTransactionModel->where('DATE(sale_date)', date('Y-m-d', strtotime('-1 day')))->countAllResults();

        $itemsSoldToday = $salesItemModel
            ->select('sales_item.item_id, products.item_name, SUM(sales_item.quantity_sold) as qty, SUM(sales_item.quantity_sold * sales_item.selling_price) as line_total')
            ->join('products', 'products.item_id = sales_item.item_id', 'left')
            ->join('sales_transaction', 'sales_transaction.sales_id = sales_item.sales_id', 'left')
            ->where('DATE(sales_transaction.sale_date)', date('Y-m-d'))
            ->groupBy('sales_item.item_id')
            ->orderBy('line_total', 'DESC')
            ->findAll(4);

        $maxLine = $itemsSoldToday ? (max(array_column($itemsSoldToday, 'line_total')) ?: 1) : 1;

        $lowStockItems = array_values(array_filter($products, function ($p) use ($productModel) {
            return in_array($productModel->getStatus($p)['label'], ['Low Stock', 'Reorder Now'], true);
        }));
        usort($lowStockItems, fn ($a, $b) => (int) $a['current_stock'] <=> (int) $b['current_stock']);
        $lowStockItems = array_slice($lowStockItems, 0, 3);
        foreach ($lowStockItems as &$li) {
            $li['status_label'] = $productModel->getStatus($li)['label'] === 'Reorder Now' ? 'Critical' : 'Low';
        }
        unset($li);

        $recentTransactions = $salesTransactionModel->orderBy('sale_date', 'DESC')->findAll(4);
        foreach ($recentTransactions as &$t) {
            $t['items'] = $salesItemModel->forSale($t['sales_id']);
        }
        unset($t);

        $openAlerts   = $reorderAlertModel->openAlerts();
        $alertItem    = $openAlerts[0] ?? null;

        $data = [
            'title'               => 'Dashboard',
            'active'              => 'dashboard',
            'totalStock'          => $totalStock,
            'salesToday'          => $salesToday,
            'salesTrendPct'       => $salesYesterday > 0 ? round((($salesToday - $salesYesterday) / $salesYesterday) * 100) . '%' : '0%',
            'txnToday'            => $txnToday,
            'txnDelta'            => $txnToday - $txnYesterday,
            'itemsSoldToday'      => $itemsSoldToday,
            'maxLine'             => $maxLine,
            'lowStockItems'       => $lowStockItems,
            'recentTransactions'  => $recentTransactions,
            'openAlertCount'      => count($openAlerts),
            'alertItem'           => $alertItem,
            'success'             => session()->getFlashdata('success'),
        ];

        return view('staff/dashboard', $data);
    }

    public function notifyAdmin()
    {
        $itemId   = $this->request->getPost('item_id');
        $itemName = $this->request->getPost('item_name') ?: 'an item';

        (new NotificationModel())->push(
            'Admin',
            'Reorder Point Notice',
            "Staff flagged {$itemName} as needing reorder attention.",
            $itemId ? "Item ID: {$itemId}" : null
        );

        return redirect()->to('/staff/dashboard')->with('success', 'Notify Sent! Reorder Point Notice');
    }
}
