<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\InventoryLogModel;
use App\Models\PdssComputationModel;
use App\Models\ProductModel;
use App\Models\SupplierModel;

class InventoryController extends BaseController
{
    protected $productModel;
    protected $supplierModel;
    protected $inventoryLogModel;
    protected $pdssComputationModel;

    public function __construct()
    {
        $this->productModel     = new ProductModel();
        $this->supplierModel    = new SupplierModel();
        $this->inventoryLogModel = new InventoryLogModel();
        $this->pdssComputationModel = new PdssComputationModel();
    }

    public function index()
    {
        $category = $this->request->getGet('category');
        $search   = $this->request->getGet('search');
        $size     = $this->request->getGet('size');
        $status   = $this->request->getGet('status');
        $page     = (int) ($this->request->getGet('page') ?? 1);
        $perPage  = 10;

        $eoqByItem = [];
        foreach ($this->pdssComputationModel->latestPerItem() as $c) {
            $eoqByItem[$c['item_id']] = $c['eoq_value'];
        }

        $allProducts = $this->productModel->where('is_active', 1)->findAll();
        foreach ($allProducts as &$p) {
            $p['status']    = $this->productModel->getStatus($p);
            $p['eoq_value'] = $eoqByItem[$p['item_id']] ?? null;
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

        // Same item_name+category grouping as the POS/Stock Order screens —
        // one row per item, each size still its own real underlying product.
        // Filters narrow which *rows* are eligible before grouping, so an
        // item with only one matching size still shows (with just that size).
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
            'title'               => 'Inventory',
            'pageTitle'           => 'Inventory',
            'active'              => 'inventory',
            'products'            => $products,
            'suppliers'           => $this->supplierModel->where('is_active', 1)->findAll(),
            'inStockCount'        => $inStockCount,
            'lowStockCount'       => $lowStockCount,
            'reorderCount'        => $reorderCount,
            'totalItems'          => $totalItems,
            'currentPage'         => $page,
            'totalPages'          => (int) ceil($totalItems / $perPage),
            'category'            => $category ?? 'All',
            'size'                => $size ?? '',
            'status'              => $status ?? '',
            'search'              => $search ?? '',
            'categories'          => ProductModel::CATEGORIES,
            'categoryStockTotals' => $categoryStockTotals,
            'sizes'               => $sizes,
            'statuses'            => ['In Stock', 'Low Stock', 'Reorder Now'],
            'success'             => session()->getFlashdata('success'),
            'error'               => session()->getFlashdata('error'),
        ];

        // Live search/filtering fetches this same data in the background
        // (see the inline script in admin/inventory.php) and swaps just the
        // table + pagination in place — no full page reload, and it still
        // searches the whole dataset rather than only the rows already on
        // screen.
        if ($this->request->isAJAX()) {
            return view('admin/_inventory_table', $data);
        }

        return view('admin/inventory', $data);
    }

    public function store()
    {
        $rules = [
            'item_name'     => 'required|max_length[100]',
            'category'      => 'required',
            'unit_cost'     => 'required|numeric|greater_than_equal_to[0]',
            'selling_price' => 'required|numeric|greater_than_equal_to[0]',
            'current_stock' => 'permit_empty|integer|greater_than_equal_to[0]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->to('/admin/inventory')->with('error', implode(' ', $this->validator->getErrors()));
        }

        $newId = $this->productModel->generateNextId();

        $this->productModel->insert([
            'item_id'            => $newId,
            'supplier_id'        => $this->request->getPost('supplier_id') ?: null,
            'item_name'          => $this->request->getPost('item_name'),
            'category'           => $this->request->getPost('category'),
            'size'               => $this->request->getPost('size') ?: null,
            'unit_cost'          => (float) $this->request->getPost('unit_cost'),
            'selling_price'      => (float) $this->request->getPost('selling_price'),
            'current_stock'      => (int) ($this->request->getPost('current_stock') ?: 0),
            'manual_rop_warning' => (int) ($this->request->getPost('manual_rop_warning') ?: 0),
            'is_active'          => 1,
        ]);

        if ((int) $this->request->getPost('current_stock') > 0) {
            $this->inventoryLogModel->record(
                $newId,
                session()->get('user_id'),
                'MANUAL_ADJUSTMENT',
                (int) $this->request->getPost('current_stock'),
                (int) $this->request->getPost('current_stock'),
                null,
                'Initial stock on item creation'
            );
        }

        return redirect()->to('/admin/inventory')->with('success', 'Item added successfully.');
    }

    public function update($itemId)
    {
        $product = $this->productModel->find($itemId);

        if (! $product) {
            return redirect()->to('/admin/inventory')->with('error', 'Item not found.');
        }

        $rules = [
            'item_name'     => 'required|max_length[100]',
            'category'      => 'required',
            'unit_cost'     => 'required|numeric|greater_than_equal_to[0]',
            'selling_price' => 'required|numeric|greater_than_equal_to[0]',
            'current_stock' => 'permit_empty|integer|greater_than_equal_to[0]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->to('/admin/inventory')->with('error', implode(' ', $this->validator->getErrors()));
        }

        $newStock = (int) ($this->request->getPost('current_stock') ?: 0);
        $delta    = $newStock - (int) $product['current_stock'];

        $this->productModel->update($itemId, [
            'supplier_id'        => $this->request->getPost('supplier_id') ?: null,
            'item_name'          => $this->request->getPost('item_name'),
            'category'           => $this->request->getPost('category'),
            'size'               => $this->request->getPost('size') ?: null,
            'unit_cost'          => (float) $this->request->getPost('unit_cost'),
            'selling_price'      => (float) $this->request->getPost('selling_price'),
            'current_stock'      => $newStock,
            'manual_rop_warning' => (int) ($this->request->getPost('manual_rop_warning') ?: 0),
        ]);

        if ($delta !== 0) {
            $this->inventoryLogModel->record(
                $itemId,
                session()->get('user_id'),
                'MANUAL_ADJUSTMENT',
                $delta,
                $newStock,
                null,
                'Manual adjustment via Inventory screen'
            );
        }

        return redirect()->to('/admin/inventory')->with('success', 'Item updated successfully.');
    }

    public function delete($itemId)
    {
        $product = $this->productModel->find($itemId);

        if (! $product) {
            return redirect()->to('/admin/inventory')->with('error', 'Item not found.');
        }

        $this->productModel->update($itemId, ['is_active' => 0]);

        return redirect()->to('/admin/inventory')->with('success', 'Item archived.');
    }
}
