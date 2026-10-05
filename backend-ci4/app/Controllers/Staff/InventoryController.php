<?php
// app/Controllers/Staff/InventoryController.php

namespace App\Controllers\Staff;

use App\Controllers\BaseController;
use App\Models\NotificationModel;
use App\Models\NotificationPreferenceModel;
use App\Models\ProductModel;

class InventoryController extends BaseController
{
    protected $productModel;

    public function __construct()
    {
        $this->productModel = new ProductModel();
    }

    public function index()
    {
        $category = $this->request->getGet('category');
        $search   = $this->request->getGet('search');
        $size     = $this->request->getGet('size');
        $status   = $this->request->getGet('status');
        $page     = (int) ($this->request->getGet('page') ?? 1);
        $perPage  = 10;

        $allProducts = $this->productModel->where('is_active', 1)->findAll();
        foreach ($allProducts as &$p) {
            $p['status'] = $this->productModel->getStatus($p);
        }
        unset($p);

        $inStockCount  = count(array_filter($allProducts, fn ($p) => $p['status']['label'] === 'In Stock'));
        $lowStockCount = count(array_filter($allProducts, fn ($p) => $p['status']['label'] === 'Low Stock'));
        $reorderCount  = count(array_filter($allProducts, fn ($p) => $p['status']['label'] === 'Reorder Now'));

        // Total stock on hand per category, shown next to each category tab
        // (e.g. "Clothes (780)") so stock levels are visible before filtering.
        $categoryStockTotals = [];
        foreach ($allProducts as $p) {
            $cat = $p['category'] ?? 'Uncategorized';
            $categoryStockTotals[$cat] = ($categoryStockTotals[$cat] ?? 0) + (int) $p['current_stock'];
        }

        $sizes = array_values(array_unique(array_filter(array_column($allProducts, 'size'))));
        sort($sizes, SORT_STRING);

        // Same item_name+category grouping as Admin Inventory — one row per
        // item, each size still its own real underlying product underneath.
        $filtered = $allProducts;
        if ($category && $category !== 'All') {
            $filtered = array_values(array_filter($filtered, fn ($p) => $p['category'] === $category));
        }
        if ($search) {
            $needle   = mb_strtolower($search);
            $filtered = array_values(array_filter(
                $filtered,
                fn ($p) => str_contains(mb_strtolower($p['item_name']), $needle) || str_contains(mb_strtolower($p['item_id']), $needle)
            ));
        }
        if ($size) {
            $filtered = array_values(array_filter($filtered, fn ($p) => ($p['size'] ?? '') === $size));
        }
        if ($status) {
            $filtered = array_values(array_filter($filtered, fn ($p) => $p['status']['label'] === $status));
        }

        usort($filtered, fn ($a, $b) => [$a['item_name'], $a['size'] ?? ''] <=> [$b['item_name'], $b['size'] ?? '']);

        $groups     = $this->productModel->groupRows($filtered);
        $totalItems = count($groups);
        $products   = array_slice($groups, ($page - 1) * $perPage, $perPage);

        $data = [
            'title'         => 'Inventory',
            'active'        => 'inventory',
            'products'      => $products,
            'inStockCount'  => $inStockCount,
            'lowStockCount' => $lowStockCount,
            'reorderCount'  => $reorderCount,
            'totalItems'    => $totalItems,
            'currentPage'   => $page,
            'totalPages'    => (int) ceil($totalItems / $perPage),
            'category'      => $category ?? 'All',
            'size'          => $size ?? '',
            'status'        => $status ?? '',
            'search'        => $search ?? '',
            'categories'    => ProductModel::CATEGORIES,
            'categoryStockTotals' => $categoryStockTotals,
            'sizes'         => $sizes,
            'statuses'      => ['In Stock', 'Low Stock', 'Reorder Now'],
            'success'       => session()->getFlashdata('success'),
        ];

        return view('staff/inventory', $data);
    }

    public function notifyAdmin($itemId)
    {
        $product = $this->productModel->find($itemId);

        if ((new NotificationPreferenceModel())->isEnabled('rop_alerts')) {
            (new NotificationModel())->push(
                'Admin',
                'Reorder Point Notice',
                'Staff flagged ' . ($product['item_name'] ?? $itemId) . ' as needing reorder attention.',
                'Current stock: ' . ($product['current_stock'] ?? '—'),
                null,
                'staff_activity'
            );
        }

        return redirect()->to('/staff/inventory')->with('success', 'Notify Sent! Reorder Point Notice');
    }
}
