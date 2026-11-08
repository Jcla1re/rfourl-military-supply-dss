
<?= $this->extend('layouts/admin_layout') ?>

<?= $this->section('content') ?>

<?php
$tabs          = $tabs ?? ['order' => [], 'ordered' => [], 'dismissed' => []];
$suppliers     = $suppliers ?? [];
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
    'order'     => 'Sorted by stock left: out of stock first, then lowest compared to Reorder at. The black tick on each bar marks Reorder at.',
    'ordered'   => 'Late deliveries first, then by expected arrival. Items leave this tab when the delivery is received.',
    'dismissed' => "Dismissed alerts stay here until the item's stock is above Reorder at again.",
];
$emptyTexts    = [
    'order'     => $sizes === 0 ? 'All stocked up. Nothing is at Reorder Now.' : 'Nothing left to order. Every size at Reorder Now is ordered or dismissed.',
    'ordered'   => 'Nothing on order. Sizes move here when you order them.',
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
$statusPill   = static fn (array $r): string => match (true) {
    $r['tab'] === 'dismissed' => '<span class="status-pill gray">Dismissed</span>',
    $r['out']                 => '<span class="status-pill ra-pill-out">Out of stock</span>',
    default                   => '<span class="status-pill red">Reorder Now</span>',
};
// On hand: the number, then a bar (on hand ÷ (Reorder at + order qty)) with a tick at Reorder at.
$onHand = static fn (array $r): string => '<span class="ra-num">' . (int) $r['current_stock'] . '</span>'
    . '<span class="ra-bar"><span class="stock-bar ' . ($r['tab'] === 'dismissed' ? 'gray' : 'red') . ($r['out'] ? ' is-empty' : '') . '"><span style="width: ' . $r['bar']['pct'] . '%"></span></span>'
    . ($r['bar']['tick'] !== null ? '<span class="ra-tick" style="left: ' . $r['bar']['tick'] . '%" data-tick></span>' : '') . '</span>';
$rop = static fn (array $r): int => (int) ($r['manual_rop_warning'] ?? 0);
// Shown under the name when the Reorder at and Status columns don't fit (narrow content).
$narrowMeta = static fn (array $r): string => '<span class="ra-narrow">' . $statusPill($r) . '<span>Reorder at ' . $rop($r) . '</span></span>';
?>

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
        <a href="<?= site_url('admin/procurement-report') ?>" class="btn btn-outline-success ra-report-btn">Ordering Report</a>
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
            <a href="<?= site_url('admin/reorder-alerts') . '?tab=' . $key ?>" role="tab" data-tab-link="<?= $key ?>"
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
                            <?php // New = the alert was raised after the Owner's previous login. ?>
                            <button type="button" data-only="new" class="<?= in_array('new', $filters['only'], true) ? 'active' : '' ?>" aria-pressed="<?= in_array('new', $filters['only'], true) ? 'true' : 'false' ?>">New since <?= esc(date('M j, g:i A', strtotime($previousLogin))) ?> &middot; <span data-chip-count="new"><?= $newCount ?></span></button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <p class="ra-caption"><?= esc($captions[$key]) ?></p>

            <div class="ra-empty" data-tab-empty<?= $tabs[$key] === [] ? '' : ' hidden' ?>>
                <i class="bi bi-check-circle" aria-hidden="true"></i> <?= esc($emptyTexts[$key]) ?>
                <?php if ($key === 'order' && $sizes === 0): ?><a href="<?= site_url('admin/inventory') ?>">View Inventory</a><?php endif; ?>
            </div>
            <div class="ra-empty" data-no-results hidden>
                <span>No sizes match these filters.</span>
                <button type="button" class="btn btn-outline-secondary" data-clear-all>Clear all</button>
            </div>

            <?php if ($key === 'ordered'): ?>
            <div class="ra-list">
            <?php foreach ($tabs['ordered'] as $alert): ?>
                <?php // Ordered tab: replaced in Phase 4. ?>
                <div class="ra-card ra-ordered-card"<?= $rowAttrs($alert) ?>>
                    <div class="ra-card-head">
                        <div>
                            <h4><?= esc($alert['item_name'] ?? 'Unknown item') ?></h4>
                            <span class="sub"><?= esc(implode(' · ', array_filter([! empty($alert['size']) ? 'Size ' . $alert['size'] : null, $alert['category'] ?? null, $alert['item_id']]))) ?></span>
                        </div>
                        <div class="ra-ordered-meta">
                            <?php if (! empty($alert['so_id'])): ?><span>Order <?= esc($alert['so_id']) ?></span><?php endif; ?>
                            <span>Expected <?= ! empty($alert['expected_date']) ? esc(date('M j, Y', strtotime($alert['expected_date']))) : '—' ?></span>
                            <?php if ($alert['late_days'] > 0): ?><span class="status-pill red">Late &middot; <?= esc($plural((int) $alert['late_days'], 'day')) ?></span><?php endif; ?>
                            <?php if (! empty($alert['so_id'])): ?><a class="inv-view-order btn btn-outline-secondary btn-sm" href="<?= site_url('admin/orders/' . $alert['so_id']) ?>">View order</a><?php endif; ?>
                        </div>
                    </div>
                </div>
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
                                    <span class="ra-name"><?= esc($card['item_name']) ?></span>
                                    <span class="ra-sub"><?= esc(implode(' · ', array_filter([$card['category'], ($first['product_code'] ?? '') ?: $first['item_id'], $oneSupplier ? $supplierLine($first) : 'Several suppliers']))) ?></span>
                                    <span class="ra-sub ra-inline-size" data-size-count><?= esc($sizeCount(count($sizesHere), $card['total'])) ?></span>
                                </div>
                                <div class="ra-c-size" data-size-count><?= esc($sizeCount(count($sizesHere), $card['total'])) ?></div>
                                <div class="ra-c-onhand"></div>
                                <div class="ra-c-rop"></div>
                                <div class="ra-c-status"></div>
                                <div class="ra-c-action ra-actions">
                                    <?php if ($key === 'order'): ?>
                                        <?php // Shown (by assets/js/reorder-alerts.js) when 2+ shown sizes share a supplier. Ordering them together comes in Phase 4. ?>
                                        <button type="button" class="btn btn-outline-success btn-sm" data-order-all disabled<?= $sameSupplier < 2 ? ' hidden' : '' ?>>Order all sizes</button>
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
                                        <span class="ra-name"><?= esc($r['item_name']) ?><?= $r['is_new'] ? $newTag : '' ?></span>
                                        <?php if ($sizeText !== ''): ?><span class="ra-sub ra-inline-size">Size <?= esc($sizeText) ?></span><?php endif; ?>
                                        <span class="ra-sub"><?= esc(implode(' · ', array_filter([$r['category'] ?? null, $r['item_id']]))) ?></span>
                                        <span class="ra-sub"><?= esc($supplierLine($r)) ?></span>
                                    <?php endif; ?>
                                    <?= $narrowMeta($r) ?>
                                </div>
                                <div class="ra-c-size"><?= esc($sizeText !== '' ? $sizeText : '—') ?><?= $multi && $r['is_new'] ? $newTag : '' ?></div>
                                <div class="ra-c-onhand"><?= $onHand($r) ?></div>
                                <div class="ra-c-rop ra-num"><?= $rop($r) ?></div>
                                <div class="ra-c-status"><?= $statusPill($r) ?></div>
                                <div class="ra-c-action ra-actions">
                                    <?php if ($key === 'order'): ?>
                                        <button type="button"
                                                class="btn btn-success btn-sm open-order-modal"
                                                aria-label="Order <?= esc((string) ($r['recommended_eoq'] ?? ''), 'attr') ?>: <?= esc($name, 'attr') ?>"
                                                data-alert-id="<?= esc((string) ($r['alert_id'] ?? ''), 'attr') ?>"
                                                data-item-id="<?= esc($r['item_id'], 'attr') ?>"
                                                data-item-name="<?= esc($name, 'attr') ?>"
                                                data-supplier-id="<?= esc((string) ($r['supplier_id'] ?? ''), 'attr') ?>"
                                                data-recommended-eoq="<?= esc((string) ($r['recommended_eoq'] ?? 1), 'attr') ?>">Order<?= ! empty($r['recommended_eoq']) ? ' ' . (int) $r['recommended_eoq'] : '' ?></button>
                                        <?php if (! empty($r['alert_id'])): ?>
                                            <form method="post" action="<?= site_url('admin/reorder-alerts/dismiss/' . $r['alert_id']) ?>" data-keep-view>
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="return_query" value="">
                                                <button type="submit" class="btn btn-outline-secondary btn-sm ra-icon-btn" title="Dismiss until restocked" aria-label="Dismiss until restocked"><i class="bi bi-bell-slash" aria-hidden="true"></i></button>
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

<div class="order-modal" id="orderModal">
    <div class="order-modal-card">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="mb-0">New Stock Order</h3>
            <button type="button" class="btn-close close-order-modal"></button>
        </div>

        <form method="post" action="<?= site_url('admin/orders/store') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="alert_id" id="orderAlertId">
            <input type="hidden" name="item_id[]" id="orderItemId">

            <div class="order-form-grid">
                <div class="order-field">
                    <label>Item</label>
                    <input id="orderItemName" readonly>
                </div>

                <div class="order-field">
                    <label>Supplier</label>
                    <select name="supplier_id" id="orderSupplierId" required>
                        <option value="">Select Supplier...</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= esc($s['supplier_id']) ?>"><?= esc($s['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="order-field">
                    <label>Item Quantity</label>
                    <input name="quantity[]" id="orderQuantity" type="number" min="1" value="1" required>
                </div>

                <div class="order-field">
                    <label>Price Per Item (₱)</label>
                    <input name="unit_price[]" id="orderUnitPrice" type="number" min="0" step="0.01" value="0" required>
                </div>

                <div class="order-field">
                    <label>Priority</label>
                    <select name="priority">
                        <option value="Urgent">Urgent</option>
                        <option value="Order" selected>Order</option>
                        <option value="Planned">Planned</option>
                    </select>
                </div>

                <div class="order-field">
                    <label>Expected Delivery Date</label>
                    <input name="expected_delivery_date" type="date">
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-4">
                <button type="button" class="btn btn-secondary close-order-modal">Cancel</button>
                <button type="submit" class="btn btn-success">Submit</button>
            </div>
        </form>
    </div>
</div>

<style>
.order-modal {
    position: fixed; inset: 0; z-index: 1000; display: none;
    align-items: center; justify-content: center; background: rgba(0,0,0,.4);
}
.order-modal.show { display: flex; }
.order-modal-card { width: min(760px, 92vw); max-height: 90vh; overflow-y: auto; padding: 26px; border-radius: var(--radius-card); background: #fff; }
.order-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.order-field { display: flex; flex-direction: column; gap: 6px; }
.order-field input, .order-field select { padding: 12px; border: 1px solid #ccc; border-radius: var(--radius-control); }
@media (max-width: 700px) { .order-form-grid { grid-template-columns: 1fr; } }
</style>

<script>
const orderModal = document.getElementById('orderModal');
const productUnitCost = <?= json_encode(array_column($products ?? [], 'unit_cost', 'item_id')) ?>;

document.querySelectorAll('.open-order-modal').forEach(button => {
    button.addEventListener('click', () => {
        document.getElementById('orderAlertId').value = button.dataset.alertId || '';
        document.getElementById('orderItemId').value = button.dataset.itemId || '';
        document.getElementById('orderItemName').value = button.dataset.itemName || '';
        document.getElementById('orderSupplierId').value = button.dataset.supplierId || '';
        document.getElementById('orderQuantity').value = button.dataset.recommendedEoq || 1;
        document.getElementById('orderUnitPrice').value = productUnitCost[button.dataset.itemId] || 0;
        orderModal.classList.add('show');
    });
});

document.querySelectorAll('.close-order-modal').forEach(button => {
    button.addEventListener('click', () => orderModal.classList.remove('show'));
});
</script>

<?= $this->endSection() ?>
