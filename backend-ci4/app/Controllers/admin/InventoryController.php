<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\InventoryLogModel;
use App\Models\ProductModel;
use App\Models\SupplierModel;

class InventoryController extends BaseController
{
    protected $productModel;
    protected $supplierModel;
    protected $inventoryLogModel;

    public function __construct()
    {
        $this->productModel     = new ProductModel();
        $this->supplierModel    = new SupplierModel();
        $this->inventoryLogModel = new InventoryLogModel();
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

        $sizes = $this->productModel
            ->select('size')
            ->where('is_active', 1)
            ->where('size IS NOT NULL')
            ->groupBy('size')
            ->orderBy('size', 'ASC')
            ->findAll();

        $data = [
            'title'               => 'Inventory',
            'pageTitle'           => 'Inventory',
            'active'              => 'inventory',
            'products'            => $products,
            'suppliers'           => $this->supplierModel->where('is_active', 1)->findAll(),
            'inStockCount'        => $inStockCount,
            'lowStockCount'       => $lowStockCount,
            'reorderCount'        => $reorderCount,
            'totalItems'          => count($allProducts),
            'currentPage'         => $page,
            'totalPages'          => (int) ceil($totalItems / $perPage),
            'category'            => $category ?? 'All',
            'size'                => $size ?? '',
            'status'              => $status ?? '',
            'search'              => $search ?? '',
            'categories'          => ProductModel::CATEGORIES,
            'sizes'               => array_map(fn ($s) => $s['size'], $sizes),
            'statuses'            => ['In Stock', 'Low Stock', 'Reorder Now'],
            'success'             => session()->getFlashdata('success'),
            'error'               => session()->getFlashdata('error'),
        ];

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
