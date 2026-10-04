<?php
// app/Views/staff/_inventory_table.php
// Table + "Showing X of Y" + pagination for the Inventory page. Rendered
// both by the normal full-page request and by the AJAX live-search
// request (InventoryController::index() returns just this partial when
// $this->request->isAJAX()), so there is exactly one place that knows
// how to draw a row — no duplicated markup to keep in sync.
?>
<div id="inventoryResults">
    <div class="data-table-wrap">
        <table class="data-table" style="min-width: 980px;">
            <thead>
                <tr>
                    <th>Item ID</th><th>Item Name</th><th>Size</th><th>Type</th><th>On Hand</th><th>Rop</th>
                    <th>Stock Level</th><th>Status</th><th>Last Updated</th><th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($products)): ?>
                    <?php foreach ($products as $product): ?>
                        <?php
                        $itemStatus = $product['status']['label'] ?? 'In Stock';
                        $pillClass = match ($itemStatus) {
                            'Low Stock' => 'amber',
                            'Reorder Now' => 'red',
                            default => 'green',
                        };
                        $rop = max((int) ($product['manual_rop_warning'] ?? 0), 1);
                        $pct = min(100, round(((int) $product['current_stock'] / ($rop * 2)) * 100));
                        ?>
                        <tr>
                            <td><?= esc($product['item_id'] ?? '—') ?></td>
                            <td><strong><?= esc($product['item_name'] ?? 'Unknown item') ?></strong></td>
                            <td><?= esc($product['size'] ?? '—') ?></td>
                            <td><?= esc($product['category'] ?? '') ?></td>
                            <td><?= esc($product['current_stock'] ?? 0) ?></td>
                            <td><?= esc($product['manual_rop_warning'] ?? 0) ?></td>
                            <td><div class="stock-bar <?= $pillClass ?>"><span style="width: <?= $pct ?>%"></span></div></td>
                            <td><span class="status-pill <?= $pillClass ?>"><?= esc($itemStatus) ?></span></td>
                            <td><?= esc(date('M j, Y', strtotime($product['updated_at'] ?? 'now'))) ?></td>
                            <td class="text-center">
                                <?php if ($itemStatus === 'Reorder Now'): ?>
                                    <form method="post" action="<?= site_url('staff/inventory/notify/' . $product['item_id']) ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="notify-btn">Notify Admin</button>
                                    </form>
                                <?php else: ?>
                                    &hellip;
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="10" class="text-center py-4">No inventory items found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-3">
        <span class="text-muted small">Showing <?= count($products ?? []) ?> of <?= esc($totalItems ?? 0) ?> Items</span>
        <?php if (($totalPages ?? 1) > 1): ?>
            <div class="admin-pagination">
                <?php
                $currentPage = max(1, (int) ($currentPage ?? 1));
                $prevPage = max(1, $currentPage - 1);
                $nextPage = min((int) $totalPages, $currentPage + 1);
                $params = $_GET;

                // Only ever show a sliding window of PAGER_WINDOW page
                // buttons (centered on the current page) instead of one
                // link per page — with 562 items that was 57 buttons wide.
                $pagerWindow = 10;
                $windowStart = max(1, $currentPage - intdiv($pagerWindow, 2));
                $windowEnd   = min((int) $totalPages, $windowStart + $pagerWindow - 1);
                $windowStart = max(1, $windowEnd - $pagerWindow + 1);

                $params['page'] = $prevPage;
                ?>
                <a href="<?= site_url('staff/inventory') . '?' . http_build_query($params) ?>">&larr; Prev</a>
                <?php for ($i = $windowStart; $i <= $windowEnd; $i++): $params['page'] = $i; ?>
                    <a href="<?= site_url('staff/inventory') . '?' . http_build_query($params) ?>" class="<?= $i === (int) $currentPage ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
                <?php $params['page'] = $nextPage; ?>
                <a href="<?= site_url('staff/inventory') . '?' . http_build_query($params) ?>">Next &rarr;</a>
            </div>
        <?php endif; ?>
    </div>
</div>
