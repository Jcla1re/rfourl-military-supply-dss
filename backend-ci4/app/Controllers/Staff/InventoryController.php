<?php
// app/Controllers/Staff/InventoryController.php

namespace App\Controllers\Staff;

use App\Controllers\BaseController;
use App\Models\NotificationModel;
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

        $builder = $this->productModel->where('is_active', 1);

        if ($category && $category !== 'All') {
            $builder = $builder->where('category', $category);
        }
        if ($size) {
            $builder = $builder->where('size', $size);
        }
        if ($search) {
            $builder = $builder->like('item_name', $search);
        }

        if ($status) {
            $allProducts = $this->productModel->where('is_active', 1)->findAll();
            $filteredIds = [];

            foreach ($allProducts as $product) {
                $label = $this->productModel->getStatus($product)['label'] ?? 'In Stock';

                if ($label === $status) {
                    $filteredIds[] = $product['item_id'];
                }
            }

            $builder = $filteredIds
                ? $builder->whereIn('item_id', $filteredIds)
                : $builder->where('item_id', null);
        }

        $totalItems = $builder->countAllResults(false);

        $products = $builder
            ->orderBy('item_name', 'ASC')
            ->findAll($perPage, ($page - 1) * $perPage);

        foreach ($products as &$p) {
            $p['status'] = $this->productModel->getStatus($p);
        }
        unset($p);

        $allProducts   = $this->productModel->where('is_active', 1)->findAll();
        $inStockCount  = count(array_filter($allProducts, fn ($p) => ($this->productModel->getStatus($p)['label'] ?? 'In Stock') === 'In Stock'));
        $lowStockCount = count(array_filter($allProducts, fn ($p) => ($this->productModel->getStatus($p)['label'] ?? 'In Stock') === 'Low Stock'));
        $reorderCount  = count(array_filter($allProducts, fn ($p) => ($this->productModel->getStatus($p)['label'] ?? 'In Stock') === 'Reorder Now'));

        // Total stock on hand per category, shown next to each category tab
        // (e.g. "Clothes (780)") so stock levels are visible before filtering.
        $categoryStockTotals = [];
        foreach ($allProducts as $p) {
            $cat = $p['category'] ?? 'Uncategorized';
            $categoryStockTotals[$cat] = ($categoryStockTotals[$cat] ?? 0) + (int) $p['current_stock'];
        }

        $sizes = $this->productModel
            ->select('size')
            ->where('is_active', 1)
            ->where('size IS NOT NULL')
            ->groupBy('size')
            ->orderBy('size', 'ASC')
            ->findAll();

        $data = [
            'title'         => 'Inventory',
            'active'        => 'inventory',
            'products'      => $products,
            'inStockCount'  => $inStockCount,
            'lowStockCount' => $lowStockCount,
            'reorderCount'  => $reorderCount,
            'totalItems'    => count($allProducts),
            'currentPage'   => $page,
            'totalPages'    => (int) ceil($totalItems / $perPage),
            'category'      => $category ?? 'All',
            'size'          => $size ?? '',
            'status'        => $status ?? '',
            'search'        => $search ?? '',
            'categories'    => ProductModel::CATEGORIES,
            'categoryStockTotals' => $categoryStockTotals,
            'sizes'         => array_map(fn ($s) => $s['size'], $sizes),
            'statuses'      => ['In Stock', 'Low Stock', 'Reorder Now'],
            'success'       => session()->getFlashdata('success'),
        ];

        return view('staff/inventory', $data);
    }

    public function notifyAdmin($itemId)
    {
        $product = $this->productModel->find($itemId);

        (new NotificationModel())->push(
            'Admin',
            'Reorder Point Notice',
            'Staff flagged ' . ($product['item_name'] ?? $itemId) . ' as needing reorder attention.',
            'Current stock: ' . ($product['current_stock'] ?? '—')
        );

        return redirect()->to('/staff/inventory')->with('success', 'Notify Sent! Reorder Point Notice');
    }
}
