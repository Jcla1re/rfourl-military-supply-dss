<?php
// app/Views/partials/_reorder_board.php
// The Reorder Alerts board, the same for the Owner (admin/reorder_alerts.php) and Staff
// (staff/reorder_alerts.php): headline, search and filters, the To order / Ordered /
// Dismissed tabs, the Show only chips and the item and order cards. Data:
// App\Libraries\ReorderBoard. Styles: assets/css/reorder-alerts.css; behaviour:
// assets/js/reorder-alerts.js.
//
// $role switches the actions:
//   'admin': Order / Order all sizes / Dismiss (To order), Restore (Dismissed), View order
//            (Ordered) and the Ordering Report button.
//   'staff': Notify Owner, or "Owner notified" once any staff account notified the Owner
//            about that size ($notices, ReorderNoticeModel::openFor), on To order only.
//            Ordered (the order's status) and Dismissed ("Dismissed by owner") are view-only.
$role          = ($role ?? 'admin') === 'staff' ? 'staff' : 'admin';
$isOwner       = $role === 'admin';
$base          = $isOwner ? 'admin' : 'staff';
$notices       = $notices ?? [];
$tabs          = $tabs ?? ['order' => [], 'ordered' => [], 'dismissed' => []];
$dss           = $dss ?? [];
$filters       = $filters ?? ['q' => '', 'cat' => '', 'sup' => '', 'only' => []];
$activeTab     = $activeTab ?? 'order';
$items         = (int) ($counts['items'] ?? 0);
$sizes         = (int) ($counts['sizes'] ?? 0);
$plural        = static fn (int $n, string $word): string => $n . ' ' . $word . ($n === 1 ? '' : 's');
$searchText    = static fn (array $r): string => strtolower(implode(' ', array_filter([$r['item_name'] ?? '', $r['item_id'] ?? '', $r['product_code'] ?? '', $r['supplier_name'] ?? ''])));
$lateCount     = count(array_filter($tabs['ordered'], static fn ($r) => $r['late_days'] > 0));
$tabLabels     = ['order' => 'To order', 'ordered' => 'Ordered', 'dismissed' => 'Dismissed'];
$captions      = [
    'order'     => 'Most urgent first.',
    'ordered'   => 'Late deliveries first.',
    'dismissed' => 'Hidden until restocked.',
];
$captionTips   = [
    'order'     => ['How this list is sorted', ['Sorted by stock left: out of stock first, then lowest compared to Reorder at.', 'Hover an item name to see its supplier and delivery days.']],
    'ordered'   => ['Ordered', ['Late deliveries first, then by expected arrival.', 'Items leave this tab when the delivery is received.']],
    'dismissed' => ['Dismissed', ["Dismissed alerts stay here until the item's stock is above Reorder at again."]],
];
$emptyTexts    = [
    'order'     => $sizes === 0 ? 'All stocked up. Nothing is at Reorder Now.' : 'Nothing left to order. Every size at Reorder Now is ordered or dismissed.',
    'ordered'   => $isOwner ? 'Nothing on order. Sizes move here when you order them.' : 'Nothing on order. Sizes move here when the owner orders them.',
    'dismissed' => 'No dismissed alerts.',
];
// Data the filters read (search, category, supplier, Show only chips, late).
$rowAttrs = static fn (array $r): string => ' data-ra-row data-tab="' . esc($r['tab'], 'attr') . '" data-item-id="' . esc($r['item_id'], 'attr') . '"'
    . ' data-search="' . esc($searchText($r), 'attr') . '"'
    . ' data-category="' . esc($r['category'] ?? '', 'attr') . '"'
    . ' data-supplier="' . esc($r['supplier_id'] ?: 'none', 'attr') . '"'
    . ' data-out="' . ($r['out'] ? 1 : 0) . '" data-new="' . ($r['is_new'] ? 1 : 0) . '" data-late="' . ($r['late_days'] > 0 ? 1 : 0) . '"';

// Item cards (To order, Dismissed).
$cards     = $cards ?? ['order' => [], 'dismissed' => []];
$newTag    = '<span class="ra-new">NEW</span>';
$sizeCount = static fn (int $shown, int $total): string => $shown === $total ? $total . ' sizes' : $shown . ' of ' . $total . ' sizes';
// "Tactical Gear Inc · 5-day delivery"
$supplierLine = static fn (array $r): string => implode(' · ', array_filter([$r['supplier_name'], ! empty($r['lead_time_days']) ? (int) $r['lead_time_days'] . '-day delivery' : null]));
// The tooltip on an item name: "Tactical Gear Inc · 5-day delivery · Clothes · 0290".
$nameTip = static fn (array $r): string => ' data-tooltip="' . esc(implode(' · ', array_filter([$supplierLine($r), $r['category'] ?? null, ($r['product_code'] ?? '') ?: ($r['item_id'] ?? null)])), 'attr') . '" data-tooltip-pos="above" tabindex="0"';
$statusPill   = static fn (array $r): string => match (true) {
    $r['tab'] === 'dismissed' => '<span class="status-pill gray">Dismissed</span>',
    $r['out']                 => '<span class="status-pill ra-pill-out">Out of stock</span>',
    default                   => '<span class="status-pill red">Reorder Now</span>',
};
// On hand: just the number (the stock-level bar is in Inventory's row detail).
$onHand = static fn (array $r): string => '<span class="ra-num">' . (int) $r['current_stock'] . '</span>';
$rop = static fn (array $r): int => (int) ($r['manual_rop_warning'] ?? 0);
// Shown under the name when the Reorder at and Status columns don't fit (narrow content).
$narrowMeta = static fn (array $r): string => '<span class="ra-narrow">' . $statusPill($r) . '<span>Reorder at ' . $rop($r) . '</span></span>';
// The shared purchase order dialog's data for one size (App\Libraries\PurchaseOrderLine).
$orderLine = static fn (array $r): string => esc(json_encode(\App\Libraries\PurchaseOrderLine::build($r, $supplierMap ?? [])), 'attr');
$date = static fn (?string $d): string => $d ? date('M j, Y', strtotime($d)) : '—';
$lateBadge = static fn (int $days): string => $days > 0 ? '<span class="status-pill red ra-late">Late &middot; ' . $days . ' day' . ($days === 1 ? '' : 's') . '</span>' : '';
?>

<link href="/assets/css/item-modal.css" rel="stylesheet">
<link href="/assets/css/reorder-alerts.css" rel="stylesheet">


<div class="page-wrap ra-page" id="raBoard" data-active-tab="<?= esc($activeTab, 'attr') ?>">
    <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>

    <div class="ra-top">
        <div class="ra-top-left">
            <?php // Same numbers as Inventory's Reorder Now (dismissed sizes included); never filtered. ?>
            <span class="stat-pill red ra-headline"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> <span>Reorder Now: <span class="text-nowrap"><?= esc($plural($items, 'item')) ?></span> &middot; <span class="text-nowrap"><?= esc($plural($sizes, 'size')) ?></span></span></span>
            <span class="ra-calc"><?= ! empty($lastRunAt) ? 'Reorder levels calculated ' . esc(date('M j, Y', strtotime($lastRunAt))) : 'Reorder levels not calculated yet' ?></span>
        </div>
        <?php if ($isOwner): ?>
            <a href="<?= site_url('admin/procurement-report') ?>" class="btn btn-outline-success ra-report-btn">Ordering Report</a>
        <?php endif; ?>
    </div>

    <div class="inv-toolbar ra-toolbar">
        <div class="inv-search-field">
            <div class="inv-search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input id="raSearch" type="search" value="<?= esc($filters['q']) ?>" placeholder="Search by item, ID or supplier…" maxlength="50" aria-label="Search by item, ID or supplier">
                <button type="button" class="inv-search-clear" id="raSearchClear" aria-label="Clear search"<?= $filters['q'] === '' ? ' hidden' : '' ?>><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
        </div>
        <select id="raCategory" aria-label="Category" class="<?= $filters['cat'] !== '' ? 'has-value' : '' ?>">
            <option value="">All categories</option>
            <?php foreach ($categories ?? [] as $c): ?>
                <option value="<?= esc($c, 'attr') ?>"<?= $filters['cat'] === $c ? ' selected' : '' ?>><?= esc($c) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="raSupplier" aria-label="Supplier" class="<?= $filters['sup'] !== '' ? 'has-value' : '' ?>">
            <option value="">All suppliers</option>
            <?php foreach ($supplierOptions ?? [] as $id => $name): ?>
                <option value="<?= esc((string) $id, 'attr') ?>"<?= $filters['sup'] === (string) $id ? ' selected' : '' ?>><?= esc($name) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="clear-filters" id="raClearAll" data-clear-all hidden aria-label="Clear all filters">
            Clear all <span class="clear-filters-count" id="raClearCount" aria-hidden="true">0</span>
        </button>
    </div>

    <?php // Counts are sizes; with no filter on they add up to the headline's sizes. ?>
    <div class="underline-tabs ra-tabs" role="tablist" aria-label="Reorder alerts">
        <?php foreach ($tabLabels as $key => $label): ?>
            <a href="<?= site_url($base . '/reorder-alerts') . '?tab=' . $key ?>" role="tab" data-tab-link="<?= $key ?>"
               class="<?= $activeTab === $key ? 'active' : '' ?>" aria-selected="<?= $activeTab === $key ? 'true' : 'false' ?>"><?= esc($label) ?> (<span data-tab-count="<?= $key ?>"><?= count($tabs[$key]) ?></span>)<?php if ($key === 'ordered'): ?><span class="ra-tab-late" data-late-count<?= $lateCount ? '' : ' hidden' ?>> &middot; <span data-late-number><?= $lateCount ?></span> late</span><?php endif; ?></a>
        <?php endforeach; ?>
    </div>

    <?php foreach ($tabLabels as $key => $label): ?>
        <section class="ra-panel" data-panel="<?= $key ?>" role="tabpanel"<?= $activeTab === $key ? '' : ' hidden' ?>>
            <?php if ($key === 'order'): ?>
                <?php $outCount = count(array_filter($tabs['order'], static fn ($r) => $r['out'])); $newCount = count(array_filter($tabs['order'], static fn ($r) => $r['is_new'])); ?>
                <div class="ra-only">
                    <span class="ra-only-label">Show only:</span>
                    <div class="pill-tabs ra-chips">
                        <button type="button" data-only="out" class="<?= in_array('out', $filters['only'], true) ? 'active' : '' ?>" aria-pressed="<?= in_array('out', $filters['only'], true) ? 'true' : 'false' ?>">Out of stock &middot; <span data-chip-count="out"><?= $outCount ?></span></button>
                        <?php if (! empty($previousLogin)): ?>
                            <?php // New = the alert was raised after the viewer's previous login. ?>
                            <button type="button" data-only="new" class="<?= in_array('new', $filters['only'], true) ? 'active' : '' ?>" aria-pressed="<?= in_array('new', $filters['only'], true) ? 'true' : 'false' ?>">New since <?= esc(date('M j, g:i A', strtotime($previousLogin))) ?> &middot; <span data-chip-count="new"><?= $newCount ?></span></button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <p class="ra-caption"><?= esc($captions[$key]) ?> <?= help_tip($captionTips[$key][0], $captionTips[$key][1]) ?></p>

            <div class="ra-empty" data-tab-empty<?= $tabs[$key] === [] ? '' : ' hidden' ?>>
                <i class="bi bi-check-circle" aria-hidden="true"></i> <?= esc($emptyTexts[$key]) ?>
                <?php if ($key === 'order' && $sizes === 0): ?><a href="<?= site_url($base . '/inventory') ?>">View Inventory</a><?php endif; ?>
            </div>
            <div class="ra-empty" data-no-results hidden>
                <span>No sizes match these filters.</span>
                <button type="button" class="btn btn-outline-secondary" data-clear-all>Clear all</button>
            </div>

            <?php if ($key === 'ordered'): ?>
            <?php // One card per stock order: the order's dates on its first row, a row per size ordered. ?>
            <div class="ra-items ra-ordered" data-items<?= $tabs['ordered'] === [] ? ' hidden' : '' ?>>
                <div class="ra-head ra-grid">
                    <span class="ra-c-item">Item</span>
                    <span class="ra-c-size">Size</span>
                    <span class="ra-c-qty">Qty ordered</span>
                    <span class="ra-c-expected">Expected arrival</span>
                    <span class="ra-c-action"><?= $isOwner ? 'Action' : 'Status' ?></span>
                </div>
                <?php foreach ($cards['ordered'] ?? [] as $order): ?>
                    <article class="ra-item ra-order-card" data-ra-card>
                        <div class="ra-row ra-grid ra-item-head">
                            <div class="ra-c-item">
                                <span class="ra-name"><?= $order['so_id'] ? 'Order ' . esc(\App\Models\StockOrderModel::label($order)) : 'Ordered' ?></span>
                                <span class="ra-sub"><?= esc(implode(' · ', array_filter([$order['supplier_name'], $order['order_date'] ? 'ordered ' . $date($order['order_date']) : null]))) ?></span>
                                <span class="ra-narrow">Expected <?= esc($date($order['expected_date'])) ?><?= $lateBadge($order['late_days']) ?></span>
                            </div>
                            <div class="ra-c-size"></div>
                            <div class="ra-c-qty"></div>
                            <div class="ra-c-expected"><span class="ra-expected"><?= esc($date($order['expected_date'])) ?></span><?= $lateBadge($order['late_days']) ?></div>
                            <div class="ra-c-action ra-actions">
                                <?php if ($isOwner && $order['so_id']): ?><a class="btn btn-outline-secondary btn-sm" href="<?= site_url('admin/orders/' . $order['so_id']) ?>" aria-label="View order <?= esc($order['so_id'], 'attr') ?>">View order</a><?php endif; ?>
                                <?php // Staff: the order's status (same names as the Orders page), read-only. ?>
                                <?php if (! $isOwner && ! empty($order['status'])): ?><span class="ra-order-status"><?= esc($order['status']) ?></span><?php endif; ?>
                            </div>
                        </div>
                        <?php foreach ($order['sizes'] as $r): ?>
                            <?php $sizeText = trim((string) ($r['size'] ?? '')); ?>
                            <div class="ra-row ra-grid ra-size-row"<?= $rowAttrs($r) ?>>
                                <div class="ra-c-item">
                                    <span class="ra-size-label"><?= esc($r['item_name']) ?></span>
                                    <?php if ($sizeText !== ''): ?><span class="ra-sub ra-inline-size">Size <?= esc($sizeText) ?></span><?php endif; ?>
                                </div>
                                <div class="ra-c-size"><?= esc($sizeText !== '' ? $sizeText : '—') ?></div>
                                <div class="ra-c-qty ra-num"><?= $r['ordered_qty'] !== null ? (int) $r['ordered_qty'] : '—' ?></div>
                                <div class="ra-c-expected"></div>
                                <div class="ra-c-action"></div>
                            </div>
                        <?php endforeach; ?>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <?php // One card per item; the header row sticks under the page header while the list scrolls. ?>
            <div class="ra-items" data-items<?= $tabs[$key] === [] ? ' hidden' : '' ?>>
                <div class="ra-head ra-grid">
                    <span class="ra-c-item">Item</span>
                    <span class="ra-c-size">Size</span>
                    <span class="ra-c-onhand">On hand</span>
                    <span class="ra-c-rop">Reorder at</span>
                    <span class="ra-c-status">Status</span>
                    <span class="ra-c-action">Action</span>
                </div>
                <?php foreach ($cards[$key] as $card): ?>
                    <?php
                    $sizesHere   = $card['sizes'];
                    $first       = $sizesHere[0];
                    $multi       = $card['total'] > 1;
                    $supplierIds = array_map(static fn ($r) => $r['supplier_id'] ?: 'none', $sizesHere);
                    $oneSupplier = count(array_unique($supplierIds)) === 1;
                    // Sizes "Order all sizes" would include: the most urgent size's supplier only.
                    $sameSupplier = count(array_keys($supplierIds, $supplierIds[0], true));
                    ?>
                    <article class="ra-item" data-ra-card data-total="<?= $card['total'] ?>"<?= $multi ? ' data-multi' : '' ?>>
                        <?php if ($multi): ?>
                            <div class="ra-row ra-grid ra-item-head">
                                <div class="ra-c-item">
                                    <span class="ra-name"<?= $oneSupplier ? $nameTip($first) : ' data-tooltip="Several suppliers" data-tooltip-pos="above" tabindex="0"' ?>><?= esc($card['item_name']) ?></span>
                                    <span class="ra-sub ra-inline-size" data-size-count><?= esc($sizeCount(count($sizesHere), $card['total'])) ?></span>
                                </div>
                                <div class="ra-c-size" data-size-count><?= esc($sizeCount(count($sizesHere), $card['total'])) ?></div>
                                <div class="ra-c-onhand"></div>
                                <div class="ra-c-rop"></div>
                                <div class="ra-c-status"></div>
                                <div class="ra-c-action ra-actions">
                                    <?php if ($isOwner && $key === 'order'): ?>
                                        <?php // Shown (by assets/js/reorder-alerts.js) when 2+ shown sizes share the most urgent size's supplier; orders just those. ?>
                                        <button type="button" class="btn btn-outline-success btn-sm" data-order-all<?= $sameSupplier < 2 ? ' hidden' : '' ?>>Order all sizes</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php foreach ($sizesHere as $r): ?>
                            <?php $sizeText = trim((string) ($r['size'] ?? '')); $name = \App\Models\ProductModel::nameWithSize($r); ?>
                            <div class="ra-row ra-grid<?= $multi ? ' ra-size-row' : '' ?>"<?= $rowAttrs($r) ?>>
                                <div class="ra-c-item">
                                    <?php if ($multi): ?>
                                        <span class="ra-inline-size ra-size-label"><?= esc($sizeText !== '' ? 'Size ' . $sizeText : 'No size') ?><?= $r['is_new'] ? $newTag : '' ?></span>
                                    <?php else: ?>
                                        <span class="ra-name"<?= $nameTip($r) ?>><?= esc($r['item_name']) ?><?= $r['is_new'] ? $newTag : '' ?></span>
                                        <?php if ($sizeText !== ''): ?><span class="ra-sub ra-inline-size">Size <?= esc($sizeText) ?></span><?php endif; ?>
                                    <?php endif; ?>
                                    <?= $narrowMeta($r) ?>
                                </div>
                                <div class="ra-c-size"><?= esc($sizeText !== '' ? $sizeText : '—') ?><?= $multi && $r['is_new'] ? $newTag : '' ?></div>
                                <div class="ra-c-onhand"><?= $onHand($r) ?></div>
                                <div class="ra-c-rop ra-num"><?= $rop($r) ?></div>
                                <div class="ra-c-status"><?= $statusPill($r) ?></div>
                                <div class="ra-c-action ra-actions">
                                    <?php if (! $isOwner && $key !== 'order'): ?>
                                        <?php // Dismissed: view-only for Staff, said the same way as on Staff Inventory. ?>
                                        <span class="notify-dismissed">Dismissed by owner</span>
                                    <?php elseif (! $isOwner): ?>
                                        <?php $notice = $notices[$r['item_id']] ?? null; ?>
                                        <?php if ($notice !== null): ?>
                                            <?php // Already notified for this size (any staff account or page): no second notification. ?>
                                            <span class="ra-notified">
                                                <button type="button" class="btn btn-success btn-sm notify-btn" disabled>Owner notified</button>
                                                <span class="ra-notified-by"><?= esc(\App\Models\ReorderNoticeModel::summary($notice)) ?></span>
                                            </span>
                                        <?php elseif (! empty($r['alert_id'])): ?>
                                            <form method="post" action="<?= site_url('staff/reorder-alerts/notify/' . $r['alert_id']) ?>">
                                                <?= csrf_field() ?>
                                                <button type="submit" class="btn btn-success btn-sm notify-btn" aria-label="Notify Owner: <?= esc($name, 'attr') ?>">Notify Owner</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php elseif ($key === 'order'): ?>
                                        <button type="button"
                                                class="btn btn-success btn-sm open-order-modal"
                                                aria-label="Order <?= esc((string) ($r['recommended_eoq'] ?? ''), 'attr') ?>: <?= esc($name, 'attr') ?>"
                                                data-alert-id="<?= esc((string) ($r['alert_id'] ?? ''), 'attr') ?>"
                                                data-line="<?= $orderLine($r) ?>">Order<?= ! empty($r['recommended_eoq']) ? ' ' . (int) $r['recommended_eoq'] : '' ?></button>
                                        <?php if (! empty($r['alert_id'])): ?>
                                            <form method="post" action="<?= site_url('admin/reorder-alerts/dismiss/' . $r['alert_id']) ?>" data-keep-view>
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="return_query" value="">
                                                <button type="submit" class="btn btn-outline-secondary btn-sm ra-icon-btn" data-tooltip="Dismiss until restocked" data-tooltip-pos="above" aria-label="Dismiss until restocked"><i class="bi bi-bell-slash" aria-hidden="true"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    <?php elseif (! empty($r['alert_id'])): ?>
                                        <form method="post" action="<?= site_url('admin/reorder-alerts/restore/' . $r['alert_id']) ?>" data-keep-view>
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="return_query" value="">
                                            <button type="submit" class="btn btn-outline-secondary btn-sm" aria-label="Restore: <?= esc($name, 'attr') ?>">Restore</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>

<script src="/assets/js/reorder-alerts.js" defer></script>
