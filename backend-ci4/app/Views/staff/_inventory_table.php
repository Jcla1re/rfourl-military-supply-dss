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
        <table class="data-table" style="min-width: 980px;">
            <thead>
                <tr>
                    <th>Item ID</th><th>Item Name</th><th class="col-center">Size</th><th>Type</th><th class="col-num">On Hand</th><th class="col-num">ROP</th>
                    <th>Stock Level</th><th>Status</th><th>Last Updated</th><th class="col-actions">Actions</th>
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

                        $variantsPayload = array_map(fn ($v) => [
                            'item_id'            => $v['item_id'],
                            'current_stock'      => (int) ($v['current_stock'] ?? 0),
                            'manual_rop_warning' => (int) ($v['manual_rop_warning'] ?? 0),
                            'status_label'       => $v['status']['label'] ?? 'In Stock',
                            'updated_at_display' => date('M j, Y', strtotime($v['updated_at'] ?? 'now')),
                        ], $variants);
                        ?>
                        <tr data-variants="<?= esc(json_encode($variantsPayload), 'attr') ?>">
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
                            <td class="inv-bar-cell"><div class="stock-bar <?= $pillClass ?>"><span style="width: <?= $pct ?>%"></span></div></td>
                            <td class="inv-status-cell"><span class="status-pill <?= $pillClass ?>"><?= esc($itemStatus) ?></span></td>
                            <td class="inv-updated-cell"><?= esc(date('M j, Y', strtotime($default['updated_at'] ?? 'now'))) ?></td>
                            <td class="inv-notify-cell col-actions">
                                <form method="post" action="<?= site_url('staff/inventory/notify/' . $default['item_id']) ?>" class="notify-form" style="<?= $itemStatus === 'Reorder Now' ? '' : 'display:none;' ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="return_query" value="<?= esc($_SERVER['QUERY_STRING'] ?? '') ?>">
                                    <button type="submit" class="notify-btn">Notify Admin</button>
                                </form>
                                <span class="notify-none" style="<?= $itemStatus === 'Reorder Now' ? 'display:none;' : '' ?>"><i class="bi bi-three-dots"></i></span>
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
