
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
$rowAttrs = static fn (array $r): string => ' data-ra-row data-tab="' . esc($r['tab'], 'attr') . '"'
    . ' data-search="' . esc($searchText($r), 'attr') . '"'
    . ' data-category="' . esc($r['category'] ?? '', 'attr') . '"'
    . ' data-supplier="' . esc($r['supplier_id'] ?: 'none', 'attr') . '"'
    . ' data-out="' . ($r['out'] ? 1 : 0) . '" data-new="' . ($r['is_new'] ? 1 : 0) . '" data-late="' . ($r['late_days'] > 0 ? 1 : 0) . '"';
?>

<link href="/assets/css/reorder-alerts.css" rel="stylesheet">

<style>
/* Item cards: replaced in Phase 3 (To order, Dismissed) and Phase 4 (Ordered). */
.ra-card { background: #fff; border: 1px solid #e2e2e2; border-radius: var(--radius-card); margin-top: 16px; overflow: hidden; }
.ra-card-head { display: flex; align-items: center; gap: 16px; padding: 18px 20px; border-bottom: 1px solid #eee; }
.ra-card-head .icon { font-size: 26px; }
.ra-card-head.critical .icon { color: var(--accent-maroon); }
.ra-card-head h4 { margin: 0; font-weight: 800; font-size: 19px; }
.ra-card-head span.sub { display: block; color: #555; font-size: 14px; }
.ra-card-head .order-btn { margin-left: auto; }

.ra-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; padding: 18px 20px 0; }
.ra-stat-box { border: 1px solid #e2e2e2; border-radius: var(--radius-control); padding: 14px; text-align: center; }
.ra-stat-box span { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #777; }
.ra-stat-box strong { display: block; font-size: 26px; margin-top: 4px; }
.ra-stat-box small { color: #888; font-size: 12px; }
.ra-stat-box.stock strong { color: var(--accent-maroon); }
.ra-stat-box.eoq strong { color: #a08217; }

.ra-basis { margin: 18px 20px 20px; border: 1px solid #e2e2e2; border-radius: var(--radius-control); padding: 16px 18px; }
.ra-basis strong.title { display: block; margin-bottom: 10px; }
.ra-basis-grid { display: flex; flex-wrap: wrap; gap: 24px; font-size: 14px; }
.ra-basis-grid b { margin-left: 6px; }

.ra-card-actions { display: flex; gap: 8px; padding: 12px 20px 0; }
.ra-card-actions form { margin: 0; }
.ra-status-badge { display: inline-flex; align-items: center; background: #eef3ee; color: #3d5230; border-radius: var(--radius-pill); padding: 4px 10px; font-size: 12px; font-weight: 700; }

.ra-ordered-card .ra-card-head { border-bottom: 0; flex-wrap: wrap; }
.ra-ordered-meta { margin-left: auto; display: flex; align-items: center; flex-wrap: wrap; gap: 6px 14px; font-size: 14px; color: #3A4030; }
</style>

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

            <div class="ra-list">
            <?php foreach ($tabs[$key] as $alert): ?>
                <?php if ($key === 'ordered'): ?>
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
                    <?php continue; ?>
                <?php endif; ?>
                <?php $computation = $alert['computation'] ?? null; ?>
                <div class="ra-card"<?= $rowAttrs($alert) ?>>
                    <div class="ra-card-head critical">
                        <span class="icon"><i class="bi bi-exclamation-triangle"></i></span>
                        <div>
                            <h4>Reorder Now — order now</h4>
                            <span class="sub"><?= esc($alert['item_name'] ?? 'Unknown item') ?><?= !empty($alert['size']) ? ' - Size ' . esc($alert['size']) : '' ?></span>
                        </div>
                        <button type="button"
                                class="btn btn-success order-btn open-order-modal"
                                data-alert-id="<?= esc($alert['alert_id'] ?? '') ?>"
                                data-item-id="<?= esc($alert['item_id']) ?>"
                                data-item-name="<?= esc(($alert['item_name'] ?? '') . (! empty($alert['size']) ? ' · Size ' . $alert['size'] : '')) ?>"
                                data-supplier-id="<?= esc($alert['supplier_id'] ?? '') ?>"
                                data-recommended-eoq="<?= esc($alert['recommended_eoq'] ?? 1) ?>">
                            Order
                        </button>
                    </div>

                    <div class="ra-card-actions">
                        <?php if ($key === 'dismissed'): ?>
                            <span class="ra-status-badge">Dismissed</span>
                        <?php elseif (! empty($alert['alert_id'])): ?>
                        <form method="post" action="<?= site_url('admin/reorder-alerts/dismiss/' . $alert['alert_id']) ?>" data-confirm="This alert will be dismissed without creating an order. It stays hidden until its stock recovers."
                              data-confirm-title="Dismiss <?= esc($alert['item_name'] ?? 'this item', 'attr') ?> alert?"
                              data-confirm-ok="Dismiss" data-confirm-variant="primary">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-dark">Dismiss</button>
                        </form>
                        <?php endif; ?>
                    </div>

                    <div class="ra-stats">
                        <div class="ra-stat-box stock">
                            <span>Current Stock</span>
                            <strong><?= esc($alert['current_stock'] ?? 0) ?></strong>
                            <small>units</small>
                        </div>
                        <div class="ra-stat-box eoq">
                            <span><?= \App\Libraries\Glossary::labelWithTip('order_qty') ?></span>
                            <strong><?= esc($alert['recommended_eoq'] ?? '—') ?></strong>
                            <small>units to order</small>
                        </div>
                        <div class="ra-stat-box">
                            <span>Delivery time</span>
                            <strong><?= esc($alert['lead_time_days'] ?? '—') ?></strong>
                            <small>days (<?= esc($alert['supplier_name'] ?? 'Unassigned') ?>)</small>
                        </div>
                    </div>

                    <div class="ra-basis">
                        <strong class="title">How this was worked out <?= help_tip('How this was worked out', [
                                'Daily sales: the average number sold each day.',
                                'Availability target: how often you want it in stock.',
                                'Safety Stock: extra units kept for busy days.',
                            ]) ?></strong>
                        <div class="ra-basis-grid">
                            <span>Daily sales:<b><?= esc($computation['avg_daily_demand'] ?? '—') ?></b></span>
                            <span>Availability target:<b><?= esc($alert['service_level'] ?? $dss['service_level_target'] ?? '—') ?>%<?= $alert['abc_class'] ? ' (' . esc($alert['abc_class']) . ' – ' . esc(['A' => 'Best seller', 'B' => 'Steady', 'C' => 'Slow'][$alert['abc_class']] ?? '') . ')' : '' ?></b></span>
                            <span>Safety Stock <?= \App\Libraries\Glossary::tip('safety_stock') ?>:<b><?= esc($computation['safety_stock'] ?? '—') ?></b></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
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
