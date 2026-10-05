<?php
// app/Views/admin/_inventory_table.php
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
                    <th>Item ID</th>
                    <th>Item Name</th>
                    <th>Size</th>
                    <th>Type</th>
                    <th>On Hand</th>
                    <th>Rop</th>
                    <th>EOQ</th>
                    <th>Stock Level</th>
                    <th>Status</th>
                    <th>Last Updated</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($products)): ?>
                    <?php foreach ($products as $group): ?>
                        <?php
                        $variants = $group['variants'];
                        $default  = $variants[0];
                        $itemStatus = $default['status']['label'] ?? 'In Stock';
                        $pillClass = match ($itemStatus) {
                            'Low Stock' => 'amber',
                            'Reorder Now' => 'red',
                            default => 'green',
                        };
                        $rop = max((int) ($default['manual_rop_warning'] ?? 0), 1);
                        $pct = min(100, round(((int) $default['current_stock'] / ($rop * 2)) * 100));

                        // Lean payload embedded on the row — switching the
                        // Size dropdown swaps every other cell (and the
                        // Order/Edit buttons' data) to that size's own
                        // underlying product row, client-side, no reload.
                        $variantsPayload = array_map(fn ($v) => [
                            'item_id'            => $v['item_id'],
                            'item_name'          => $v['item_name'],
                            'category'           => $v['category'] ?? '',
                            'size'               => $v['size'] ?? '',
                            'supplier_id'        => $v['supplier_id'] ?? '',
                            'unit_cost'          => (float) ($v['unit_cost'] ?? 0),
                            'selling_price'      => (float) ($v['selling_price'] ?? 0),
                            'current_stock'      => (int) ($v['current_stock'] ?? 0),
                            'manual_rop_warning' => (int) ($v['manual_rop_warning'] ?? 0),
                            'eoq_value'          => $v['eoq_value'],
                            'status_label'       => $v['status']['label'] ?? 'In Stock',
                            'updated_at_display'=> date('M j, Y', strtotime($v['updated_at'] ?? 'now')),
                        ], $variants);
                        ?>
                        <tr data-category="<?= esc($group['category'] ?? '') ?>" data-variants="<?= esc(json_encode($variantsPayload), 'attr') ?>">
                            <td class="inv-item-id"><?= esc($default['item_id'] ?? '—') ?></td>
                            <td><strong><?= esc($group['item_name'] ?? 'Unknown item') ?></strong></td>
                            <td class="inv-size-cell">
                                <?php if (count($variants) > 1): ?>
                                    <select class="inv-size-select">
                                        <?php foreach ($variants as $i => $v): ?>
                                            <option value="<?= $i ?>"><?= esc($v['size'] ?? '—') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <?= esc($default['size'] ?? '—') ?>
                                <?php endif; ?>
                            </td>
                            <td><?= esc($group['category'] ?? '') ?></td>
                            <td class="inv-stock-cell"><?= esc($default['current_stock'] ?? 0) ?></td>
                            <td class="inv-rop-cell"><?= esc($default['manual_rop_warning'] ?? 0) ?></td>
                            <td class="inv-eoq-cell"><?= $default['eoq_value'] !== null ? esc($default['eoq_value']) : '—' ?></td>
                            <td class="inv-bar-cell">
                                <div class="stock-bar <?= $pillClass ?>"><span style="width: <?= $pct ?>%"></span></div>
                            </td>
                            <td class="inv-status-cell"><span class="status-pill <?= $pillClass ?>"><?= esc($itemStatus) ?></span></td>
                            <td class="inv-updated-cell"><?= esc(date('M j, Y', strtotime($default['updated_at'] ?? 'now'))) ?></td>
                            <td class="inv-actions-cell">
                                <button type="button"
                                        class="inv-action-btn open-order-modal"
                                        style="<?= $itemStatus === 'Reorder Now' ? '' : 'display:none;' ?>"
                                        data-item-id="<?= esc($default['item_id']) ?>"
                                        data-item-name="<?= esc($default['item_name'] ?? '') ?>"
                                        data-supplier-id="<?= esc($default['supplier_id'] ?? '') ?>"
                                        data-unit-cost="<?= esc($default['unit_cost'] ?? 0) ?>"
                                        data-recommended-eoq="<?= esc($default['eoq_value'] ?? 1) ?>">
                                    Order
                                </button>
                                <button type="button" class="inv-more-btn edit-row" data-item="<?= esc(json_encode($variantsPayload[0]), 'attr') ?>">&hellip;</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="11" class="text-center py-4">No inventory items found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php $totalPages = max(1, (int) ($totalPages ?? 1)); ?>
    <div class="d-flex justify-content-between align-items-center mt-3">
        <span class="text-muted small">Showing <?= count($products ?? []) ?> of <?= esc((string) ($totalItems ?? 0)) ?> Items</span>
        <?php if (($totalPages ?? 1) > 1): ?>
            <div class="admin-pagination">
                <?php
                $currentPage = max(1, (int) ($currentPage ?? 1));
                $prevPage = max(1, $currentPage - 1);
                $nextPage = min((int) $totalPages, (int) $currentPage + 1);
                $params = $_GET;

                // Only ever show a sliding window of PAGER_WINDOW page
                // buttons (centered on the current page) instead of one
                // link per page — with 562 items that was 57 buttons wide.
                $pagerWindow = 10;
                $windowStart = max(1, $currentPage - intdiv($pagerWindow, 2));
                $windowEnd   = min($totalPages, $windowStart + $pagerWindow - 1);
                $windowStart = max(1, $windowEnd - $pagerWindow + 1);

                $params['page'] = $prevPage;
                ?>
                <a href="<?= site_url('admin/inventory') . '?' . http_build_query($params) ?>">&larr; Prev</a>
                <?php for ($i = $windowStart; $i <= $windowEnd; $i++): $params['page'] = $i; ?>
                    <a href="<?= site_url('admin/inventory') . '?' . http_build_query($params) ?>" class="<?= $i === (int) $currentPage ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
                <?php $params['page'] = $nextPage; ?>
                <a href="<?= site_url('admin/inventory') . '?' . http_build_query($params) ?>">Next &rarr;</a>
            </div>
        <?php endif; ?>
    </div>
</div>
