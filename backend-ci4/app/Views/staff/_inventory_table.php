<?php
// app/Views/staff/_inventory_table.php
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
                <col class="c-id"><col><col class="c-size"><col class="c-type"><col class="c-onhand"><col class="c-rop">
                <col class="c-stock"><col class="c-status"><col class="c-updated"><col class="c-actions">
            </colgroup>
            <thead>
                <tr>
                    <th>Item ID</th><th>Item Name</th><th class="col-center">Size</th><th>Type</th><th class="col-num">On Hand</th><th class="col-num">ROP</th>
                    <th class="col-stock">Stock Level</th><th>Status</th><th>Last Updated</th><th class="col-actions">Actions</th>
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

                        $variantsPayload = array_map(fn ($v) => [
                            'item_id'            => $v['item_id'],
                            'size'               => $v['size'] ?? '',
                            'current_stock'      => (int) ($v['current_stock'] ?? 0),
                            'manual_rop_warning' => (int) ($v['manual_rop_warning'] ?? 0),
                            'eoq_value'          => $v['eoq_value'] ?? null,
                            'status_label'       => $v['status']['label'] ?? 'In Stock',
                            'open_so'            => $v['open_so'] ?? null,
                            'updated_at_display' => date('M j, Y', strtotime($v['updated_at'] ?? 'now')),
                        ], $variants);
                        ?>
                        <tr data-row-status="<?= esc($itemStatus) ?>" data-worst-index="<?= $worstIndex ?>" data-variants="<?= esc(json_encode($variantsPayload), 'attr') ?>">
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
                            <td class="inv-bar-cell"><div class="stock-bar <?= $fill['class'] ?><?= $fill['empty'] ? ' is-empty' : '' ?>" title="<?= $fill['pct'] ?>% of reorder point + order quantity"><span style="width: <?= $fill['pct'] ?>%"></span></div></td>
                            <td class="inv-status-cell"><span class="status-pill <?= $pillClass ?>"<?= $pillTitle !== '' ? ' title="' . esc($pillTitle, 'attr') . '"' : '' ?>><?= esc($itemStatus) ?></span><?php if (! empty($group['on_order'])): ?> <span class="ordered-tag" title="On order: <?= esc($group['open_so']) ?>">Ordered</span><?php endif; ?></td>
                            <td class="inv-updated-cell"><?= esc(date('M j, Y', strtotime($default['updated_at'] ?? 'now'))) ?></td>
                            <td class="inv-notify-cell col-actions">
                                <form method="post" action="<?= site_url('staff/inventory/notify/' . $default['item_id']) ?>" class="notify-form" style="<?= $itemStatus === 'Reorder Now' && empty($group['on_order']) ? '' : 'display:none;' ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="return_query" value="<?= esc($_SERVER['QUERY_STRING'] ?? '') ?>">
                                    <button type="submit" class="notify-btn"<?= ! empty($default['size']) ? ' title="Notify about size ' . esc($default['size'], 'attr') . '"' : '' ?>>Notify Admin</button>
                                </form>
                                <span class="notify-none" style="<?= $itemStatus === 'Reorder Now' && empty($group['on_order']) ? 'display:none;' : '' ?>"><?= ! empty($group['on_order']) ? '<span class="on-order-note">On order</span>' : '<i class="bi bi-three-dots"></i>' ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php if (($search ?? '') !== ''): ?>
                        <tr>
                            <td colspan="10" class="inv-empty-search">
                                <div class="title">No items match &ldquo;<?= esc($search) ?>&rdquo;</div>
                                <p class="hint">Check the spelling or try a shorter word.</p>
                                <button type="button" class="btn-clear-search">Clear search</button>
                            </td>
                        </tr>
                    <?php else: ?>
                        <tr><td colspan="10" class="text-center py-4">No inventory items found.</td></tr>
                    <?php endif; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= view('partials/_pager', [
        'baseUrl'        => site_url('staff/inventory'),
        'currentPage'    => $currentPage ?? 1,
        'totalPages'     => $totalPages ?? 1,
        'totalItems'     => $totalItems ?? 0,
        'perPage'        => $perPage ?? 10,
        'perPageOptions' => $perPageOptions ?? [10, 25, 50],
        'shownCount'     => count($products ?? []),
    ]) ?>
</div>
