<?php
// app/Controllers/Admin/DashboardController.php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ProductModel;

class DashboardController extends BaseController
{
    public function index()
    {
        $productModel = new ProductModel();

        $data = [
            'title'          => 'Dashboard',
            'active'         => 'dashboard',
            'totalStock'     => array_sum(array_column($productModel->findAll(), 'current_stock')),
            'lowStockCount'  => count($productModel->getLowStock()),
            'salesToday'     => 0,      // wire this to sales_transaction once POS is built
            'weekLabels'     => ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],
            'weekData'       => [0,0,0,0,0,0,0],
            'seasonLabels'   => ['Jan','Feb','Mar','Apr','May','Jun'],
            'seasonData'     => [0,0,0,0,0,0],
            'topProducts'    => [],
        ];

        return view('admin/dashboard', $data);
    }
}