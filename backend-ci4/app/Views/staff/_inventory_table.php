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
                <col class="c-chev"><col><col class="c-size"><col class="c-onhand"><col class="c-rop"><col class="c-status"><col class="c-actions">
            </colgroup>
            <thead>
                <tr>
                    <th class="col-chev"><span class="visually-hidden">Details</span></th><th>Item</th><th class="col-center">Size</th><th class="col-num">On Hand</th><th class="col-num col-term"><?= \App\Libraries\Glossary::labelWithTip('reorder_at') ?></th>
                    <th>Status <?= help_tip('Status', [
                        ['name' => 'In Stock', 'text' => 'Enough stock', 'dot' => 'green'],
                        ['name' => 'Low Stock', 'text' => 'Getting close to Reorder At', 'dot' => 'amber'],
                        ['name' => 'Reorder Now', 'text' => 'At or below Reorder At, time to order', 'dot' => 'red'],
                        ['name' => 'Out of Stock', 'text' => 'None left', 'dot' => 'gray'],
                    ]) ?></th><th class="col-actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($products)): ?>
                    <?php foreach ($products as $gi => $group): ?>
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
                            'notice'             => $v['notice'] ?? null,
                            'dismissed'          => ! empty($v['dismissed']),
                            'updated_at_display' => date('M j, Y', strtotime($v['updated_at'] ?? 'now')),
                        ], $variants);
                        ?>
                        <tr class="inv-row" data-row-status="<?= esc($itemStatus) ?>" data-worst-index="<?= $worstIndex ?>" data-variants="<?= esc(json_encode($variantsPayload), 'attr') ?>">
                            <td class="inv-chev-cell"><button type="button" class="inv-expand" aria-expanded="false" aria-controls="inv-detail-<?= (int) $gi ?>" aria-label="Show details for <?= esc($group['item_name'] ?? 'item', 'attr') ?>" title="Details"><i class="bi bi-chevron-right" aria-hidden="true"></i></button></td>
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
                            <td class="inv-stock-cell col-num"><?= esc($default['current_stock'] ?? 0) ?></td>
                            <td class="inv-rop-cell col-num"><?= esc($default['manual_rop_warning'] ?? 0) ?></td>
                            <td class="inv-status-cell"><span class="status-pill <?= $pillClass ?>"<?= $pillTitle !== '' ? ' title="' . esc($pillTitle, 'attr') . '"' : '' ?>><?= esc($itemStatus) ?></span><?php if (! empty($group['on_order'])): ?> <span class="ordered-tag" title="On order: <?= esc($group['open_label'] ?? $group['open_so']) ?>">Ordered</span><?php endif; ?></td>
                            <?php $needsReorder = $itemStatus === 'Reorder Now' && empty($group['on_order']); $ownerDismissed = $needsReorder && ! empty($default['dismissed']); ?>
                            <td class="inv-notify-cell col-actions">
                                <form method="post" action="<?= site_url('staff/inventory/notify/' . $default['item_id']) ?>" class="notify-form" style="<?= $needsReorder && ! $ownerDismissed && empty($default['notice']) ? '' : 'display:none;' ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="return_query" value="<?= esc($_SERVER['QUERY_STRING'] ?? '') ?>">
                                    <button type="submit" class="notify-btn"<?= ! empty($default['size']) ? ' title="Notify about size ' . esc($default['size'], 'attr') . '"' : '' ?>>Notify Owner</button>
                                </form>
                                <?php /* Already notified (any staff account) and not ordered yet. Same box as Notify Owner: the hidden copy of that label sets the width. */ ?>
                                <button type="button" class="notify-btn notify-done" aria-disabled="true" data-tip="<?= esc($default['notice'] ?? '', 'attr') ?>" style="<?= $needsReorder && ! $ownerDismissed && ! empty($default['notice']) ? '' : 'display:none;' ?>"><span class="notify-done-sizer" aria-hidden="true">Notify Owner</span><span class="notify-done-label">Owner notified</span></button>
                                <?php /* The Owner dismissed this size's alert: no Notify Owner. */ ?>
                                <span class="notify-dismissed" style="<?= $ownerDismissed ? '' : 'display:none;' ?>">Dismissed by owner</span>
                                <?php /* Staff have no row actions (no ··· menu; "On order" shows as the Status column's Ordered tag): the empty placeholder only keeps the row as tall as admin's. */ ?>
                                <span class="notify-none" style="<?= $itemStatus === 'Reorder Now' && empty($group['on_order']) ? 'display:none;' : '' ?>" aria-hidden="true"></span>
                            </td>
                        </tr>
                        <tr class="inv-detail" id="inv-detail-<?= (int) $gi ?>" hidden>
                            <td colspan="7">
                                <dl class="inv-detail-grid">
                                    <div><dt>Item ID</dt><dd class="inv-item-id"><?= esc($default['item_id'] ?? '—') ?></dd></div>
                                    <div><dt>Category</dt><dd><?= esc($group['category'] ?? '') ?></dd></div>
                                    <div><dt>Stock level</dt><dd class="inv-bar-cell"><div class="stock-bar <?= $fill['class'] ?><?= $fill['empty'] ? ' is-empty' : '' ?>" title="<?= $fill['pct'] ?>% full"><span style="width: <?= $fill['pct'] ?>%"></span></div></dd></div>
                                    <div><dt>Last updated</dt><dd class="inv-updated-cell"><?= esc(date('M j, Y', strtotime($default['updated_at'] ?? 'now'))) ?></dd></div>
                                </dl>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php if (($search ?? '') !== ''): ?>
                        <tr>
                            <td colspan="7" class="inv-empty-search">
                                <div class="inv-empty-icon" aria-hidden="true"><i class="bi bi-search"></i></div>
                                <div class="title">No items match &ldquo;<?= esc($search) ?>&rdquo;</div>
                                <p class="hint">Check the spelling or try a shorter word.</p>
                                <button type="button" class="btn-clear-search">Clear search</button>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?= view('partials/_inventory_empty', [
                            'colspan'  => 7,
                            'category' => $category ?? 'All',
                            'size'     => $size ?? '',
                            'status'   => $status ?? '',
                            'clearUrl' => site_url('staff/inventory') . (isset($_GET['per_page']) ? '?' . http_build_query(['per_page' => $_GET['per_page']]) : ''),
                            'canAdd'   => false,
                        ]) ?>
                    <?php endif; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php // No rows: no footer ("No results" / rows per page) under the empty state. ?>
    <?php if (! empty($products)): ?>
    <?= view('partials/_pager', [
        'baseUrl'        => site_url('staff/inventory'),
        'currentPage'    => $currentPage ?? 1,
        'totalPages'     => $totalPages ?? 1,
        'totalItems'     => $totalItems ?? 0,
        'perPage'        => $perPage ?? 10,
        'perPageOptions' => $perPageOptions ?? [10, 25, 50],
        'shownCount'     => count($products ?? []),
    ]) ?>
    <?php endif; ?>
</div>
