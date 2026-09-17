
<?= $this->extend('layouts/admin_layout') ?>

<?= $this->section('header_actions') ?>
<span class="text-white-50 small me-2">Last updated: <?= esc($lastUpdated ?? 'just now') ?></span>
<a href="<?= site_url('admin/procurement-report') ?>" class="btn btn-success">Procurement Report</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<?php
$alerts = $alerts ?? [];
$suppliers = $suppliers ?? [];
$dss = $dss ?? [];
?>

<style>
.ra-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 20px; }
@media (max-width: 900px) { .ra-summary { grid-template-columns: 1fr; } }

.ra-empty {
    padding: 70px 20px; border: 1px dashed #c9c9c9; border-radius: 12px;
    text-align: center; color: #666; font-size: 16px;
}

.ra-card { background: #fff; border: 1px solid #e2e2e2; border-radius: 14px; margin-top: 16px; overflow: hidden; }
.ra-card-head { display: flex; align-items: center; gap: 16px; padding: 18px 20px; border-bottom: 1px solid #eee; }
.ra-card-head .icon { font-size: 26px; }
.ra-card-head.critical .icon { color: var(--accent-maroon); }
.ra-card-head.warning .icon { color: #d39b28; }
.ra-card-head h4 { margin: 0; font-weight: 800; font-size: 19px; }
.ra-card-head span.sub { display: block; color: #555; font-size: 14px; }
.ra-card-head .order-btn { margin-left: auto; }

.ra-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; padding: 18px 20px 0; }
.ra-stat-box { border: 1px solid #e2e2e2; border-radius: 10px; padding: 14px; text-align: center; }
.ra-stat-box span { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #777; }
.ra-stat-box strong { display: block; font-size: 26px; margin-top: 4px; }
.ra-stat-box small { color: #888; font-size: 12px; }
.ra-stat-box.stock strong { color: var(--accent-maroon); }
.ra-stat-box.eoq strong { color: #a08217; }

.ra-basis { margin: 18px 20px 20px; border: 1px solid #e2e2e2; border-radius: 10px; padding: 16px 18px; }
.ra-basis strong.title { display: block; margin-bottom: 10px; }
.ra-basis-grid { display: flex; flex-wrap: wrap; gap: 24px; font-size: 14px; }
.ra-basis-grid b { margin-left: 6px; }

@media (max-width: 700px) { .ra-stats { grid-template-columns: 1fr; } }
</style>

<div class="page-wrap">
    <div class="page-panel">
        <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>

        <div class="ra-summary">
            <div class="icon-stat-card">
                <span class="icon-box"><i class="bi bi-exclamation-triangle"></i></span>
                <div><span>Critical (At ROP)</span><strong><?= esc($criticalCount ?? 0) ?></strong></div>
            </div>
            <div class="icon-stat-card">
                <span class="icon-box"><i class="bi bi-lightning-fill"></i></span>
                <div><span>Low Stock Warning</span><strong><?= esc($lowStockCount ?? 0) ?></strong></div>
            </div>
            <div class="icon-stat-card">
                <span class="icon-box"><i class="bi bi-check-lg"></i></span>
                <div><span>Received This Week</span><strong><?= esc($receivedCount ?? 0) ?></strong></div>
            </div>
        </div>

        <?php if (!empty($alerts)): ?>
            <form method="post" action="<?= site_url('admin/reorder-alerts/resolve-all') ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-success">Mark All Resolved</button>
            </form>
        <?php endif; ?>

        <?php if (empty($alerts)): ?>
            <div class="ra-empty">No reorder alerts right now. Alerts appear automatically once stock falls at or below an item's Reorder Point (ROP).</div>
        <?php else: ?>
            <?php foreach ($alerts as $alert): ?>
                <?php
                $isCritical = (int) $alert['stock_at_trigger'] <= 0 || $alert['trigger_reason'] === 'At ROP';
                $computation = $alert['computation'] ?? null;
                ?>
                <div class="ra-card">
                    <div class="ra-card-head <?= $isCritical ? 'critical' : 'warning' ?>">
                        <span class="icon"><i class="bi <?= $isCritical ? 'bi-exclamation-triangle' : 'bi-exclamation-circle' ?>"></i></span>
                        <div>
                            <h4><?= $isCritical ? 'CRITICAL — Reorder Point Reached' : 'WARNING — Approaching Reorder' ?></h4>
                            <span class="sub"><?= esc($alert['item_name'] ?? 'Unknown item') ?><?= !empty($alert['size']) ? ' - Size ' . esc($alert['size']) : '' ?></span>
                        </div>
                        <button type="button"
                                class="btn <?= $isCritical ? 'btn-maroon' : 'btn-warning' ?> order-btn open-order-modal"
                                data-alert-id="<?= esc($alert['alert_id']) ?>"
                                data-item-id="<?= esc($alert['item_id']) ?>"
                                data-item-name="<?= esc($alert['item_name'] ?? '') ?>"
                                data-supplier-id="<?= esc($alert['supplier_id'] ?? '') ?>"
                                data-recommended-eoq="<?= esc($alert['recommended_eoq'] ?? 1) ?>">
                            Order
                        </button>
                    </div>

                    <div class="ra-stats">
                        <div class="ra-stat-box stock">
                            <span>Current Stock</span>
                            <strong><?= esc($alert['stock_at_trigger'] ?? $alert['current_stock'] ?? 0) ?></strong>
                            <small>units</small>
                        </div>
                        <div class="ra-stat-box eoq">
                            <span>EOQ Suggest</span>
                            <strong><?= esc($alert['recommended_eoq'] ?? '—') ?></strong>
                            <small>units to order</small>
                        </div>
                        <div class="ra-stat-box">
                            <span>Lead Time</span>
                            <strong><?= esc($alert['lead_time_days'] ?? '—') ?></strong>
                            <small>days (<?= esc($alert['supplier_name'] ?? 'Unassigned') ?>)</small>
                        </div>
                    </div>

                    <div class="ra-basis">
                        <strong class="title">Probabilistic Basis</strong>
                        <div class="ra-basis-grid">
                            <span>Avg Daily Demand:<b><?= esc($computation['avg_daily_demand'] ?? '—') ?></b></span>
                            <span>Service Level:<b><?= esc($dss['service_level_target'] ?? '—') ?>%</b></span>
                            <span>Safety Stock:<b><?= esc($computation['safety_stock'] ?? '—') ?></b></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

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
.order-modal-card { width: min(760px, 92vw); max-height: 90vh; overflow-y: auto; padding: 26px; border-radius: 14px; background: #fff; }
.order-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.order-field { display: flex; flex-direction: column; gap: 6px; }
.order-field input, .order-field select { padding: 12px; border: 1px solid #ccc; border-radius: 7px; }
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
