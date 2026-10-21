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
                <col class="c-rop">     <!-- Reorder At (ROP) -->
                <col class="c-eoq">     <!-- Order Qty (EOQ) -->
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
                    <th>Category</th>
                    <th class="col-num">On Hand</th>
                    <th class="col-num col-term"><?= \App\Libraries\Glossary::labelWithTip('reorder_at') ?></th>
                    <th class="col-num col-term"><?= \App\Libraries\Glossary::labelWithTip('order_qty') ?></th>
                    <th class="col-stock col-term">Stock Level</th>
                    <th>Status <?= help_tip('Status', [
                        ['name' => 'In Stock', 'text' => 'Enough stock', 'dot' => 'green'],
                        ['name' => 'Low Stock', 'text' => 'Getting close to Reorder At', 'dot' => 'amber'],
                        ['name' => 'Reorder Now', 'text' => 'At or below Reorder At, time to order', 'dot' => 'red'],
                        ['name' => 'Out of Stock', 'text' => 'None left', 'dot' => 'gray'],
                    ]) ?></th>
                    <th class="col-term">Last Updated</th>
                    <th class="col-actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($products)): ?>
                    <?php foreach ($products as $group): ?>
                        <?php
                        $variants = $group['variants'];
                        // Row shows the item's WORST status across sizes; the size
                        // dropdown starts on that size so its Order/Notify works.
                        $worstIndex = (int) ($group['worst_index'] ?? 0);
                        $default    = $variants[$worstIndex];
                        $itemStatus = $group['status']['label'] ?? ($default['status']['label'] ?? 'In Stock');
                        $pillClass = \App\Models\ProductModel::statusColor($itemStatus);
                        $pillTitle = \App\Models\ProductModel::statusTitle($itemStatus);
                        // Bar for the selected (default) size: on hand ÷ (ROP + EOQ), its own status color.
                        $fill = model(\App\Models\ProductModel::class)->stockFill($default);

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
                            // Edit item: Reorder At is read-only ("Auto") once the DSS has calculated it.
                            'rop_calculated_at'  => ! empty($v['rop_calculated_at']) ? date('M j, Y', strtotime($v['rop_calculated_at'])) : null,
                            'status_label'       => $v['status']['label'] ?? 'In Stock',
                            'open_so'            => $v['open_so'] ?? null,
                            'updated_at_display'=> date('M j, Y', strtotime($v['updated_at'] ?? 'now')),
                        ], $variants);
                        ?>
                        <tr class="<?= ! empty($showArchived) ? 'is-archived' : '' ?>" data-row-status="<?= esc($itemStatus) ?>" data-worst-index="<?= $worstIndex ?>" data-category="<?= esc($group['category'] ?? '') ?>" data-variants="<?= esc(json_encode($variantsPayload), 'attr') ?>">
                            <td class="inv-item-id"><?= esc($default['item_id'] ?? '—') ?></td>
                            <td><strong><?= esc($group['item_name'] ?? 'Unknown item') ?></strong></td>
                            <td class="inv-size-cell col-center">
                                <?php if (count($variants) > 1): ?>
                                    <?php // Closed: the label shows only the size. The invisible native select on top opens the full list, with statuses. ?>
                                    <span class="size-picker">
                                        <span class="size-picker-label" aria-hidden="true"><?= esc($default['size'] ?? '—') ?></span>
                                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                        <select class="inv-size-select" aria-label="Size">
                                            <?php foreach ($variants as $i => $v): ?>
                                                <?php $vStatus = $v['status']['label'] ?? 'In Stock'; ?>
                                                <option value="<?= $i ?>" data-size="<?= esc($v['size'] ?? '—') ?>" <?= $i === $worstIndex ? 'selected' : '' ?>><?= esc($v['size'] ?? '—') ?><?= in_array($vStatus, ['Low Stock', 'Reorder Now', 'Out of Stock'], true) ? ' — ' . esc($vStatus) : '' ?><?= ! empty($v['open_so']) && $vStatus === 'Reorder Now' ? ' (ordered)' : '' ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </span>
                                <?php else: ?>
                                    <?= esc($default['size'] ?? '—') ?>
                                <?php endif; ?>
                            </td>
                            <td><?= esc($group['category'] ?? '') ?></td>
                            <td class="inv-stock-cell col-num"><?= esc($default['current_stock'] ?? 0) ?></td>
                            <td class="inv-rop-cell col-num"><?= esc($default['manual_rop_warning'] ?? 0) ?></td>
                            <td class="inv-eoq-cell col-num"><?= $default['eoq_value'] !== null ? esc($default['eoq_value']) : '—' ?></td>
                            <td class="inv-bar-cell">
                                <div class="stock-bar <?= $fill['class'] ?><?= $fill['empty'] ? ' is-empty' : '' ?>" title="<?= $fill['pct'] ?>% full"><span style="width: <?= $fill['pct'] ?>%"></span></div>
                            </td>
                            <td class="inv-status-cell"><span class="status-pill <?= $pillClass ?>"<?= $pillTitle !== '' ? ' title="' . esc($pillTitle, 'attr') . '"' : '' ?>><?= esc($itemStatus) ?></span><?php if (! empty($group['on_order'])): ?> <span class="ordered-tag" title="On order: <?= esc($group['open_so']) ?>">Ordered</span><?php endif; ?></td>
                            <td class="inv-updated-cell"><?= esc(date('M j, Y', strtotime($default['updated_at'] ?? 'now'))) ?></td>
                            <td class="inv-actions-cell col-actions">
                                <?php if (! empty($showArchived)): ?>
                                <button type="button" class="btn-restore" data-item-id="<?= esc($default['item_id']) ?>" data-item-name="<?= esc($default['item_name'] ?? '') ?>">
                                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restore
                                </button>
                                <?php else: ?>
                                <div class="inv-actions">
                                <?php if (! empty($group['on_order'])): ?>
                                <?php // Already on order: link to the order instead, so a duplicate PO can't be created. ?>
                                <a class="inv-view-order" href="<?= site_url('admin/orders/' . rawurlencode((string) $group['open_so'])) ?>">View order</a>
                                <?php else: ?>
                                <button type="button"
                                        class="inv-action-btn open-order-modal"
                                        style="<?= $itemStatus === 'Reorder Now' ? '' : 'display:none;' ?>"
                                        data-item-id="<?= esc($default['item_id']) ?>"
                                        data-item-name="<?= esc(($default['item_name'] ?? '') . (! empty($default['size']) ? ' · Size ' . $default['size'] : '')) ?>"
                                        data-supplier-id="<?= esc($default['supplier_id'] ?? '') ?>"
                                        data-unit-cost="<?= esc($default['unit_cost'] ?? 0) ?>"
                                        data-recommended-eoq="<?= esc($default['eoq_value'] ?? 1) ?>"
                                        <?= ! empty($default['size']) ? 'title="Orders size ' . esc($default['size'], 'attr') . '"' : '' ?>>
                                    Order
                                </button>
                                <?php endif; ?>
                                <button type="button" class="inv-more-btn edit-row" data-item="<?= esc(json_encode($variantsPayload[$worstIndex]), 'attr') ?>" aria-label="More options"><i class="bi bi-three-dots"></i></button>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php if (($search ?? '') !== ''): ?>
                        <tr>
                            <td colspan="11" class="inv-empty-search">
                                <div class="title">No items match &ldquo;<?= esc($search ?? '') ?>&rdquo;</div>
                                <p class="hint">Check the spelling or try a shorter word.</p>
                                <button type="button" class="btn-clear-search">Clear search</button>
                            </td>
                        </tr>
                    <?php elseif (! empty($showArchived)): ?>
                        <tr>
                            <td colspan="11" class="archived-empty">
                                <div class="archived-empty-icon" aria-hidden="true"><i class="bi bi-archive"></i></div>
                                <div class="archived-empty-title">No archived items</div>
                                <p class="archived-empty-text">Archived items show here.</p>
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
