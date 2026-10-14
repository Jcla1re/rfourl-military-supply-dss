<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\InventoryLogModel;
use App\Models\PdssComputationModel;
use App\Models\ProductModel;
use App\Models\SecurityLogModel;
use App\Models\SupplierModel;
use App\Libraries\Toast;

class InventoryController extends BaseController
{
    /** Rows-per-page choices offered under the inventory table; first is the default. */
    private const PER_PAGE_OPTIONS = [10, 25, 50];

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
        [$search, $searchInput, $searchError] = ProductModel::validateSearch($this->request->getGet('search'));
        $size     = $this->request->getGet('size');
        $status   = $this->request->getGet('status');
        $page     = (int) ($this->request->getGet('page') ?? 1);
        $perPage  = (int) ($this->request->getGet('per_page') ?? self::PER_PAGE_OPTIONS[0]);
        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = self::PER_PAGE_OPTIONS[0];
        }

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

        // Archived view (?status=archived): the table lists archived items
        // instead. Everything else on the page (summary cards, tab counts)
        // still counts active items only.
        $showArchived     = is_string($status) && strtolower($status) === 'archived';
        $archivedProducts = $this->productModel->where('is_active', 0)->findAll();
        foreach ($archivedProducts as &$p) {
            $p['status']    = ['label' => 'Archived', 'class' => 'status-archived'];
            $p['eoq_value'] = $eoqByItem[$p['item_id']] ?? null;
        }
        unset($p);
        $archivedCount = $this->productModel->countRowsByCategory($archivedProducts)['All'];

        $inStockCount  = count(array_filter($allProducts, fn ($p) => $p['status']['label'] === 'In Stock'));
        $lowStockCount = count(array_filter($allProducts, fn ($p) => $p['status']['label'] === 'Low Stock'));
        $reorderCount  = count(array_filter($allProducts, fn ($p) => $p['status']['label'] === 'Reorder Now'));

        // Rows per category tab (+ 'All'): same count as the table rows a tab
        // shows, active items only. Shared with the dashboard chart.
        $categoryCounts = $this->productModel->countRowsByCategory($allProducts);

        $sizes = array_values(array_unique(array_filter(array_column($showArchived ? $archivedProducts : $allProducts, 'size'))));
        sort($sizes, SORT_STRING);

        // Same item_name+category grouping as the POS/Stock Order screens —
        // one row per item, each size still its own real underlying product.
        // Filters narrow which *rows* are eligible before grouping, so an
        // item with only one matching size still shows (with just that size).
        $filtered = $showArchived ? $archivedProducts : $allProducts;
        if ($category && $category !== 'All') {
            $filtered = array_values(array_filter($filtered, fn ($p) => $p['category'] === $category));
        }
        if ($search) {
            $filtered = array_values(array_filter(
                $filtered,
                fn ($p) => ProductModel::matchesSearch($p, $search)
            ));
        }
        if ($size) {
            $filtered = array_values(array_filter($filtered, fn ($p) => ($p['size'] ?? '') === $size));
        }
        if ($status && ! $showArchived) {
            $filtered = array_values(array_filter($filtered, fn ($p) => $p['status']['label'] === $status));
        }

        usort($filtered, fn ($a, $b) => [$a['item_name'], $a['size'] ?? ''] <=> [$b['item_name'], $b['size'] ?? '']);

        $groups     = $this->productModel->groupRows($filtered);
        $totalItems = count($groups);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page       = min(max(1, $page), $totalPages);
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
            'totalPages'          => $totalPages,
            'perPage'             => $perPage,
            'perPageOptions'      => self::PER_PAGE_OPTIONS,
            'category'            => $category ?? 'All',
            'size'                => $size ?? '',
            'status'              => $status ?? '',
            'search'              => $search,
            'searchInput'         => $searchInput,
            'searchError'         => $searchError,
            'categories'          => ProductModel::CATEGORIES,
            'categoryCounts'      => $categoryCounts,
            'sizes'               => $sizes,
            'statuses'            => ['In Stock', 'Low Stock', 'Reorder Now'],
            'showArchived'        => $showArchived,
            'archivedCount'       => $archivedCount,
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
            return redirect()->to($this->listUrl())->with('toast', Toast::error("Couldn't add item", implode(' ', $this->validator->getErrors())));
        }

        if ($this->isDuplicateItem((string) $this->request->getPost('item_name'), $this->request->getPost('size'), null)) {
            return redirect()->to('/admin/inventory')->with('error', 'An item with this name and size already exists.');
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

        return redirect()->to($this->listUrl())->with('toast', Toast::success('Item added', 'was added to inventory.', (string) $this->request->getPost('item_name')));
    }

    /** True when another active item has the same name (case-insensitive) and size. */
    private function isDuplicateItem(string $name, ?string $size, ?string $exceptItemId): bool
    {
        $builder = $this->productModel
            ->where('is_active', 1)
            ->where('LOWER(item_name)', mb_strtolower(trim($name)));

        $size = trim((string) $size);
        if ($size === '') {
            $builder->groupStart()->where('size', null)->orWhere('size', '')->groupEnd();
        } else {
            $builder->where('LOWER(size)', mb_strtolower($size));
        }

        if ($exceptItemId !== null) {
            $builder->where('item_id !=', $exceptItemId);
        }

        return $builder->countAllResults() > 0;
    }

    public function update($itemId)
    {
        $product = $this->productModel->find($itemId);

        if (! $product) {
            return redirect()->to($this->listUrl())->with('toast', Toast::error("Couldn't save changes", 'This item no longer exists.'));
        }

        $rules = [
            'item_name'     => 'required|max_length[100]',
            'category'      => 'required',
            'unit_cost'     => 'required|numeric|greater_than_equal_to[0]',
            'selling_price' => 'required|numeric|greater_than_equal_to[0]',
            'current_stock' => 'permit_empty|integer|greater_than_equal_to[0]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->to($this->listUrl())->with('toast', Toast::error("Couldn't save changes", implode(' ', $this->validator->getErrors())));
        }

        if ($this->isDuplicateItem((string) $this->request->getPost('item_name'), $this->request->getPost('size'), (string) $itemId)) {
            return redirect()->to('/admin/inventory')->with('error', 'Another item with this name and size already exists.');
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
            // Optional reason picked in the Edit item modal; only the known
            // choices are accepted.
            $reason = (string) $this->request->getPost('stock_reason');
            $note   = 'Manual adjustment via Inventory screen';
            if (in_array($reason, ['Restock', 'Damaged', 'Returned', 'Count correction'], true)) {
                $note .= ' (' . $reason . ')';
            }

            $this->inventoryLogModel->record(
                $itemId,
                session()->get('user_id'),
                'MANUAL_ADJUSTMENT',
                $delta,
                $newStock,
                null,
                $note
            );
        }

        return redirect()->to($this->listUrl())->with('toast', Toast::success('Changes saved', 'was updated.', (string) $this->request->getPost('item_name')));
    }

    public function delete($itemId)
    {
        $product = $this->productModel->find($itemId);

        if (! $product) {
            return redirect()->to($this->listUrl())->with('toast', Toast::error('Item not found', 'It may have been removed already.'));
        }

        $this->productModel->update($itemId, ['is_active' => 0]);
        SecurityLogModel::log('ITEM_ARCHIVED', null, "Archived item {$itemId} ({$product['item_name']})");

        // Toast with Undo (restores right away) instead of a banner.
        return redirect()->to($this->listUrl())->with('toast', Toast::success(
            'Item archived',
            'moved to Archived items.',
            $product['item_name'],
            ['undo' => site_url('admin/inventory/restore/' . rawurlencode((string) $itemId))]
        ));
    }

    /**
     * Brings an archived item back into active inventory (also used by the
     * Undo button on the "archived" toast). Logged like archiving.
     */
    public function restore($itemId)
    {
        $product = $this->productModel->find($itemId);

        if (! $product) {
            return redirect()->to($this->listUrl())->with('toast', Toast::error('Item not found', 'It may have been removed already.'));
        }

        if ((int) $product['is_active'] !== 1) {
            $this->productModel->update($itemId, ['is_active' => 1]);
            SecurityLogModel::log('ITEM_RESTORED', null, "Restored item {$itemId} ({$product['item_name']})");
        }

        return redirect()->to($this->listUrl())->with('toast', Toast::success('Item restored', 'is back in inventory.', $product['item_name']));
    }

    /**
     * The inventory list URL the user came from, rebuilt from the
     * return_query the page posts along with each form (page, filters,
     * search, rows per page), so saving or archiving an item lands them back
     * on the same page instead of page 1. Only known list parameters are
     * kept.
     */
    private function listUrl(): string
    {
        parse_str(ltrim((string) $this->request->getPost('return_query'), '?'), $params);
        $params = array_intersect_key($params, array_flip(['category', 'search', 'size', 'status', 'page', 'per_page']));
        $params = array_filter($params, 'is_string');

        return '/admin/inventory' . ($params ? '?' . http_build_query($params) : '');
    }
}
