<?php
// app/Controllers/Admin/DashboardController.php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
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

        // Keep the "low stock" count in sync with live inventory rather
        // than whatever dss:run last computed (see ReorderAlertModel).
        $reorderAlertModel->syncFromLiveInventory();

        $weekly     = $salesTransactionModel->weeklyTotals();
        $lastWeekly = $salesTransactionModel->lastWeekTotals();

        $categoryCounts = $productModel
            ->select('category, COUNT(*) as total')
            ->where('is_active', 1)
            ->groupBy('category')
            ->findAll();

        $data = [
            'title'          => 'Dashboard',
            'pageTitle'      => 'Dashboard',
            'active'         => 'dashboard',
            'totalStock'     => array_sum(array_column($productModel->where('is_active', 1)->findAll(), 'current_stock')),
            'lowStockCount'  => $reorderAlertModel->pendingCount(),
            'salesToday'     => $salesTransactionModel->totalForToday(),
            'weekLabels'     => $weekly['labels'],
            'weekData'       => $weekly['data'],
            'lastWeekData'   => $lastWeekly['data'],
            'stockTrendPct'  => '0%',
            'salesTrendPct'  => '0%',
            'seasonLabels'   => array_column($categoryCounts, 'category'),
            'seasonData'     => array_column($categoryCounts, 'total'),
            'topProducts'    => array_map(
                fn ($p) => ['item_name' => $p['item_name'] ?? $p['item_id'], 'units_sold' => $p['units_sold']],
                $salesItemModel->topSellers(5)
            ),
        ];

        return view('admin/dashboard', $data);
    }
}
