<?php
// app/Views/admin/_inventory_table.php
// Table + "Showing X of Y" + pagination for the Inventory page. Rendered
// both by the normal full-page request and by the AJAX live-search
// request (InventoryController::index() returns just this partial when
// $this->request->isAJAX()), so there is exactly one place that knows
// how to draw a row — no duplicated markup to keep in sync.
?>
<div id="inventoryResults" class="inv-table-card">
    <div class="data-table-wrap">
        <table class="data-table inv-table">
            <colgroup>
                <col class="c-id">      <!-- Item ID -->
                <col>                   <!-- Item name: takes the remaining space -->
                <col class="c-size">    <!-- Size -->
                <col class="c-type">    <!-- Type -->
                <col class="c-onhand">  <!-- On hand -->
                <col class="c-rop">     <!-- ROP -->
                <col class="c-eoq">     <!-- EOQ -->
                <col class="c-stock">   <!-- Stock level -->
                <col class="c-status">  <!-- Status -->
                <col class="c-updated"> <!-- Last updated -->
                <col class="c-actions"> <!-- Actions -->
            </colgroup>
            <thead>
                <tr>
                    <th>Item ID</th>
                    <th>Item Name</th>
                    <th class="col-center">Size</th>
                    <th>Type</th>
                    <th class="col-num">On Hand</th>
                    <th class="col-num">ROP</th>
                    <th class="col-num">EOQ</th>
                    <th class="col-stock">Stock Level</th>
                    <th>Status</th>
                    <th>Last Updated</th>
                    <th class="col-actions">Actions</th>
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
                            'Archived' => 'archived',
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
                        <tr class="<?= ! empty($showArchived) ? 'is-archived' : '' ?>" data-category="<?= esc($group['category'] ?? '') ?>" data-variants="<?= esc(json_encode($variantsPayload), 'attr') ?>">
                            <td class="inv-item-id"><?= esc($default['item_id'] ?? '—') ?></td>
                            <td><strong><?= esc($group['item_name'] ?? 'Unknown item') ?></strong></td>
                            <td class="inv-size-cell col-center">
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
                            <td class="inv-stock-cell col-num"><?= esc($default['current_stock'] ?? 0) ?></td>
                            <td class="inv-rop-cell col-num"><?= esc($default['manual_rop_warning'] ?? 0) ?></td>
                            <td class="inv-eoq-cell col-num"><?= $default['eoq_value'] !== null ? esc($default['eoq_value']) : '—' ?></td>
                            <td class="inv-bar-cell">
                                <div class="stock-bar <?= $pillClass ?>"><span style="width: <?= $pct ?>%"></span></div>
                            </td>
                            <td class="inv-status-cell"><span class="status-pill <?= $pillClass ?>"><?= esc($itemStatus) ?></span></td>
                            <td class="inv-updated-cell"><?= esc(date('M j, Y', strtotime($default['updated_at'] ?? 'now'))) ?></td>
                            <td class="inv-actions-cell col-actions">
                                <?php if (! empty($showArchived)): ?>
                                <button type="button" class="btn-restore" data-item-id="<?= esc($default['item_id']) ?>" data-item-name="<?= esc($default['item_name'] ?? '') ?>">
                                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restore
                                </button>
                                <?php else: ?>
                                <div class="inv-actions">
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
                                <button type="button" class="inv-more-btn edit-row" data-item="<?= esc(json_encode($variantsPayload[0]), 'attr') ?>" aria-label="More options"><i class="bi bi-three-dots"></i></button>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php if (($search ?? '') !== ''): ?>
                        <tr>
                            <td colspan="11" class="inv-empty-search">
                                <div class="title">No items match &ldquo;<?= esc($search) ?>&rdquo;</div>
                                <p class="hint">Check the spelling or try a shorter word.</p>
                                <button type="button" class="btn-clear-search">Clear search</button>
                            </td>
                        </tr>
                    <?php elseif (! empty($showArchived)): ?>
                        <tr>
                            <td colspan="11" class="archived-empty">
                                <div class="archived-empty-icon" aria-hidden="true"><i class="bi bi-archive"></i></div>
                                <div class="archived-empty-title">No archived items</div>
                                <p class="archived-empty-text">Items you archive will appear here. You can restore them anytime.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <tr><td colspan="11" class="text-center py-4">No inventory items found.</td></tr>
                    <?php endif; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php // Empty archived list: no footer ("No results" / rows per page) under the empty state. ?>
    <?php if (! (! empty($showArchived) && empty($products) && ($search ?? '') === '')): ?>
    <?= view('partials/_pager', [
        'baseUrl'        => site_url('admin/inventory'),
        'currentPage'    => $currentPage ?? 1,
        'totalPages'     => $totalPages ?? 1,
        'totalItems'     => $totalItems ?? 0,
        'perPage'        => $perPage ?? 10,
        'perPageOptions' => $perPageOptions ?? [10, 25, 50],
        'shownCount'     => count($products ?? []),
    ]) ?>
    <?php endif; ?>
</div>
