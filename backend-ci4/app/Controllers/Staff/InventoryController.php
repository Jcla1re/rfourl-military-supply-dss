<?php
// app/Controllers/Staff/InventoryController.php

namespace App\Controllers\Staff;

use App\Controllers\BaseController;
use App\Models\NotificationModel;
use App\Models\NotificationPreferenceModel;
use App\Models\PdssComputationModel;
use App\Models\ProductModel;

class InventoryController extends BaseController
{
    /** Rows-per-page choices offered under the inventory table; first is the default. */
    private const PER_PAGE_OPTIONS = [10, 25, 50];

    protected $productModel;

    public function __construct()
    {
        $this->productModel = new ProductModel();
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

        // EOQ per item: the stock-level bar fills against ROP + EOQ.
        $eoqByItem = [];
        foreach ((new PdssComputationModel())->latestPerItem() as $c) {
            $eoqByItem[$c['item_id']] = $c['eoq_value'];
        }

        $allProducts = $this->productModel->where('is_active', 1)->findAll();
        foreach ($allProducts as &$p) {
            $p['status']    = $this->productModel->getStatus($p);
            $p['eoq_value'] = $eoqByItem[$p['item_id']] ?? null;
        }
        unset($p);

        // Summary cards count ITEMS (sizes grouped, like the tabs) by each
        // item's worst status, so they add up to the All Items count.
        $statusCounts  = $this->productModel->statusCounts($allProducts);
        $inStockCount  = $statusCounts['In Stock'];
        $lowStockCount = $statusCounts['Low Stock'];
        $reorderCount  = $statusCounts['Reorder Now'];
        $outOfStockCount = $statusCounts['Out of Stock'];

        // Rows per category tab (+ 'All'): same count as the table rows a tab
        // shows, active items only. Shared with the dashboard chart.
        $categoryCounts = $this->productModel->countRowsByCategory($allProducts);

        // Size filter: only sizes that exist in the selected category, sorted
        // naturally (grouped when All categories). A size that isn't valid for
        // the category is dropped, so the filter resets to "All sizes".
        $sizeSource      = $allProducts;
        $sizeOptions     = $this->productModel->sizeOptions($sizeSource, $category);
        $sizesByCategory = $this->productModel->sizesByCategory($sizeSource);
        $sizes           = $sizeOptions['all'];
        if ($size && ! in_array($size, $sizes, true)) {
            $size = '';
        }

        // Same item_name+category grouping as Admin Inventory — one row per
        // item, each size still its own real underlying product underneath.
        $filtered = $allProducts;
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

        usort($filtered, fn ($a, $b) => [$a['item_name'], $a['size'] ?? ''] <=> [$b['item_name'], $b['size'] ?? '']);

        $groups = $this->productModel->groupRows($filtered);
        // Status filter works on the item's worst status (same rule as the
        // cards and the row badge), applied after grouping.
        if ($status) {
            $groups = array_values(array_filter($groups, fn ($g) => $g['status']['label'] === $status));
        }
        $totalItems = count($groups);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page       = min(max(1, $page), $totalPages);
        $products   = array_slice($groups, ($page - 1) * $perPage, $perPage);

        $data = [
            'title'         => 'Inventory',
            'active'        => 'inventory',
            'products'      => $products,
            'inStockCount'  => $inStockCount,
            'lowStockCount' => $lowStockCount,
            'reorderCount'  => $reorderCount,
            'outOfStockCount' => $outOfStockCount,
            'totalItems'    => $totalItems,
            'currentPage'   => $page,
            'totalPages'    => $totalPages,
            'perPage'       => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'category'      => $category ?? 'All',
            'size'          => $size ?? '',
            'status'        => $status ?? '',
            'search'        => $search,
            'searchInput'   => $searchInput,
            'searchError'   => $searchError,
            'categories'    => ProductModel::CATEGORIES,
            'categoryCounts'      => $categoryCounts,
            'sizes'         => $sizes,
            'sizeOptions'   => $sizeOptions,
            'sizesByCategory' => $sizesByCategory,
            'statuses'      => ProductModel::STATUSES,
            'success'       => session()->getFlashdata('success'),
        ];

        // Live search/filtering fetches this same data in the background
        // (see the inline script in staff/inventory.php) and swaps just the
        // table + pagination in place — no full page reload, and it still
        // searches the whole dataset rather than only the rows already on
        // screen.
        if ($this->request->isAJAX()) {
            return view('staff/_inventory_table', $data);
        }

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

        return redirect()->to($this->listUrl())->with('success', 'Notify Sent! Reorder Point Notice');
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

        return '/staff/inventory' . ($params ? '?' . http_build_query($params) : '');
    }
}
