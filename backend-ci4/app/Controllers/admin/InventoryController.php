<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ProductModel;
use App\Models\SupplierModel;

class InventoryController extends BaseController
{
    protected $productModel;
    protected $supplierModel;

    public function __construct()
    {
        $this->productModel  = new ProductModel();
        $this->supplierModel = new SupplierModel();
    }

    public function index()
    {
        $category = $this->request->getGet('category');
        $search   = $this->request->getGet('search');
        $size     = $this->request->getGet('size');
        $status   = $this->request->getGet('status');
        $page     = (int) ($this->request->getGet('page') ?? 1);
        $perPage  = 6;

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

            if (!empty($filteredIds)) {
                $builder = $builder->whereIn('item_id', $filteredIds);
            } else {
                $builder = $builder->where('item_id', null);
            }
        }

        $totalItems = $builder->countAllResults(false);

        $products = $builder
            ->orderBy('item_name', 'ASC')
            ->findAll($perPage, ($page - 1) * $perPage);

        foreach ($products as &$p) {
            $p['status'] = $this->productModel->getStatus($p);
        }
        unset($p);

        $allProducts    = $this->productModel->where('is_active', 1)->findAll();
        $inStockCount   = count(array_filter($allProducts, fn($p) => ($this->productModel->getStatus($p)['label'] ?? 'In Stock') === 'In Stock'));
        $lowStockCount  = count(array_filter($allProducts, fn($p) => ($this->productModel->getStatus($p)['label'] ?? 'In Stock') === 'Low Stock'));
        $reorderCount   = count(array_filter($allProducts, fn($p) => ($this->productModel->getStatus($p)['label'] ?? 'In Stock') === 'Reorder Now'));

        $categories = $this->productModel
            ->select('category')
            ->where('is_active', 1)
            ->groupBy('category')
            ->orderBy('category', 'ASC')
            ->findAll();

        $sizes = $this->productModel
            ->select('size')
            ->where('is_active', 1)
            ->groupBy('size')
            ->orderBy('size', 'ASC')
            ->findAll();

        $categories = ['Clothing', 'Accessories', 'Military gear'];

        $data = [
            'title'               => 'Inventory',
            'pageTitle'           => 'Inventory',
            'active'              => 'inventory',
            'unreadNotifications' => 4,
            'products'            => $products,
            'suppliers'           => $this->supplierModel->findAll(),
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
            'categories'          => $categories,
            'sizes'               => array_map(fn($s) => $s['size'], $sizes),
            'statuses'            => ['In Stock', 'Low Stock', 'Reorder Now'],
        ];

        return view('admin/inventory', $data);
    }

    public function store()
    {
        $itemName     = $this->request->getPost('item_name');
        $size         = $this->request->getPost('size');
        $category     = $this->request->getPost('category');
        $onHand       = (int) $this->request->getPost('current_stock');
        $unitCost     = (float) $this->request->getPost('unit_cost');
        $sellingPrice = (float) $this->request->getPost('selling_price');
        $rop          = (int) $this->request->getPost('manual_rop_warning');

        $newId = $this->productModel->generateNextId();

        $this->productModel->insert([
            'item_id'            => $newId,
            'item_name'          => $itemName,
            'category'           => $category,
            'size'               => $size,
            'unit_cost'          => $unitCost,
            'selling_price'      => $sellingPrice,
            'current_stock'      => $onHand,
            'manual_rop_warning' => $rop,
            'is_active'          => 1,
        ]);

        return redirect()->to('/admin/inventory')->with('success', 'Item added successfully');
    }
}