<?php
// app/Controllers/Staff/InventoryController.php

namespace App\Controllers\Staff;

use App\Controllers\BaseController;
use App\Models\NotificationModel;
use App\Models\NotificationPreferenceModel;
use App\Models\PdssComputationModel;
use App\Models\ProductModel;
use App\Models\ReorderNoticeModel;

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

        // "✓ Admin notified" per size that needs reordering and isn't on order yet.
        $itemIds = [];
        foreach ($products as $g) {
            $itemIds = array_merge($itemIds, array_column($g['variants'], 'item_id'));
        }
        $notices = (new ReorderNoticeModel())->openFor($itemIds);
        foreach ($products as &$g) {
            foreach ($g['variants'] as &$v) {
                $n = $notices[$v['item_id']] ?? null;
                $v['notice'] = $n && $v['status']['label'] === 'Reorder Now' && empty($v['open_so']) ? ReorderNoticeModel::tooltip($n) : null;
            }
            unset($v);
        }
        unset($g);

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
        if (! $product) {
            return redirect()->to($this->listUrl());
        }
        $done = 'Owner notified about ' . $product['item_name'] . '.';

        // One open notice per item, whichever staff account clicks: the row
        // lock keeps two clicks at the same moment from both notifying.
        $db      = db_connect();
        $notices = new ReorderNoticeModel();
        $db->transStart();
        $db->query('SELECT item_id FROM products WHERE item_id = ? FOR UPDATE', [$product['item_id']]);
        $existing = $notices->openFor([$product['item_id']])[$product['item_id']] ?? null;

        if ($existing === null) {
            $notificationId = null;
            if ((new NotificationPreferenceModel())->isEnabled('rop_alerts')) {
                $notificationId = (new NotificationModel())->push(
                    'Admin',
                    'Reorder Point Notice',
                    'Staff flagged ' . $product['item_name'] . ' as needing reorder attention.',
                    'Current stock: ' . $product['current_stock'],
                    null,
                    'staff_activity'
                ) ?: null;
            }
            // Remembered only while the item still needs reordering and isn't
            // on order: otherwise there's nothing for the row to show.
            $status = $this->productModel->getStatus($product)['label'];
            if ($status === 'Reorder Now' && ! isset($this->productModel->openOrders()[$product['item_id']])) {
                $notices->insert([
                    'item_id'         => $product['item_id'],
                    'notified_by'     => (int) session()->get('user_id') ?: null,
                    'notification_id' => $notificationId,
                    'created_at'      => date('Y-m-d H:i:s'),
                ]);
            }
        }
        $db->transComplete();

        if ($existing !== null) {
            // Already notified and not ordered yet: no second notification.
            $done .= ' ' . ReorderNoticeModel::summary($existing) . '.';
        }

        return redirect()->to($this->listUrl())->with('success', $done);
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
