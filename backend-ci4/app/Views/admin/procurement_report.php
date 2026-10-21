
<?= $this->extend('layouts/admin_layout') ?>

<?= $this->section('header_actions') ?>
<a href="<?= site_url('admin/reorder-alerts') ?>" class="btn btn-success">Back</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<?php $rows = $rows ?? []; $dss = $dss ?? []; ?>

<style>
.pr-params { background: #fff; border-radius: var(--radius-card); padding: 24px 26px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin-bottom: 20px; }
.pr-params h3 { font-weight: 800; margin-bottom: 18px; }
.pr-param-row { display: flex; flex-wrap: wrap; gap: 40px; margin-bottom: 18px; }
.pr-param-row div span { display: block; color: #777; font-size: 13px; }
.pr-param-row div strong { font-size: 20px; }
.pr-formula { background: #f2f0e9; border-radius: var(--radius-control); padding: 16px 20px; font-size: 15px; line-height: 2; }

.abc-badge {
    display: inline-flex; width: 26px; height: 26px; align-items: center; justify-content: center;
    border-radius: 50%; font-weight: 700; background: var(--red-bg); color: var(--red-text); font-size: 13px;
}
/* Same ABC colours as Trend Analysis: A darkest, B mid, C lightest. */
.abc-badge.cls-a { background: #2C3A1E; color: #fff; }
.abc-badge.cls-b { background: #71AA60; color: #fff; }
.abc-badge.cls-c { background: #E4EFDF; color: #2F4A22; }
.pr-order-btn {
    border: 1px solid #333; background: #fff; border-radius: var(--radius-control); padding: 8px 14px; font-weight: 700; cursor: pointer;
}
/* Short column headers stay on one line ("Safety Stock" wrapped with Poppins at 1366px). */
.page-panel .data-table th { white-space: nowrap; }
</style>

<div class="page-wrap">
    <div class="pr-params">
        <h3>DSS settings <?= \App\Libraries\Glossary::tip('dss') ?></h3>
        <div class="pr-param-row">
            <div><span>Cost per order</span><strong>₱<?= esc($dss['ordering_cost'] ?? 0) ?></strong></div>
            <div><span>Storage cost per item per year</span><strong>₱<?= esc($dss['holding_cost_per_unit'] ?? 0) ?></strong></div>
            <div><span>Default availability target</span><strong><?= esc($dss['service_level_target'] ?? 0) ?>% (Z = <?= esc($dss['z_score'] ?? 0) ?>)</strong></div>
            <div><span>Sales history used</span><strong><?= esc($dss['demand_lookback_days'] ?? 0) ?> days</strong></div>
        </div>
        <p class="mb-0 small text-muted">How are these used? <?= help_tip('How the numbers are worked out', [
            'Order Qty = √(2 × D × S ÷ H). D = yearly sales, S = cost per order, H = storage cost per item per year.',
            'Reorder At = d × L + Safety Stock. d = daily sales, L = delivery time in days.',
            'Safety Stock = Z × σd × √L. Z is the safety factor, σd is how much daily sales go up and down.',
        ]) ?></p>
    </div>

    <div class="page-panel">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Item Name</th>
                    <th>ABC <?= \App\Libraries\Glossary::tip('abc') ?></th>
                    <th>Stock</th>
                    <th><?= \App\Libraries\Glossary::labelWithTip('reorder_at') ?></th>
                    <th>Status</th>
                    <th><?= \App\Libraries\Glossary::labelWithTip('safety_stock') ?></th>
                    <th><?= \App\Libraries\Glossary::labelWithTip('order_qty') ?></th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($rows)): ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $pillClass = \App\Models\ProductModel::statusColor($r['status']);
                        $statusLabel = match ($r['status']) {
                            'Reorder Now'  => 'Reorder Now',
                            'Low Stock'    => 'Low Stock',
                            'Out of Stock' => 'Out of Stock',
                            default        => 'In Stock',
                        };
                        ?>
                        <tr>
                            <td><?= esc($r['item_name']) ?></td>
                            <td>
                                <?php if ($r['abc_category'] !== '—'): ?>
                                    <span class="abc-badge cls-<?= esc(strtolower($r['abc_category']), 'attr') ?>"><?= esc($r['abc_category']) ?></span>
                                    <?php if ($r['z_score'] !== null): ?>
                                        <small class="text-muted d-block">Z=<?= esc($r['z_score']) ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span title="<?= esc(\App\Models\ClusterSegmentModel::UNCLASSIFIED_NOTE) ?>">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td><?= esc($r['stock']) ?></td>
                            <td><?= esc($r['rop']) ?></td>
                            <td><span class="status-pill <?= $pillClass ?>"><?= esc($statusLabel) ?></span></td>
                            <td><?= $r['safety_stock'] !== null ? esc($r['safety_stock']) : '—' ?></td>
                            <td><?= $r['eoq'] !== null ? esc($r['eoq']) : '—' ?></td>
                            <td>
                                <?php if ($r['status'] !== 'In Stock' && $r['eoq'] !== null): ?>
                                    <button type="button"
                                            class="pr-order-btn open-order-modal"
                                            data-item-id="<?= esc($r['item_id']) ?>"
                                            data-item-name="<?= esc($r['item_name']) ?>"
                                            data-supplier-id="<?= esc($r['supplier_id'] ?? '') ?>"
                                            data-unit-cost="<?= esc($r['unit_cost'] ?? 0) ?>"
                                            data-recommended-eoq="<?= esc($r['eoq']) ?>">
                                        Order <?= esc($r['eoq']) ?>
                                    </button>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8" style="text-align:center; color:#666; padding: 24px;">No data yet. <a href="<?= site_url('admin/settings?tab=dss') ?>">Run the calculation</a> in Settings.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
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
                        <?php foreach (($suppliers ?? []) as $s): ?>
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

document.querySelectorAll('.open-order-modal').forEach(button => {
    button.addEventListener('click', () => {
        document.getElementById('orderItemId').value = button.dataset.itemId || '';
        document.getElementById('orderItemName').value = button.dataset.itemName || '';
        document.getElementById('orderSupplierId').value = button.dataset.supplierId || '';
        document.getElementById('orderQuantity').value = button.dataset.recommendedEoq || 1;
        document.getElementById('orderUnitPrice').value = button.dataset.unitCost || 0;
        orderModal.classList.add('show');
    });
});

document.querySelectorAll('.close-order-modal').forEach(button => {
    button.addEventListener('click', () => orderModal.classList.remove('show'));
});
</script>

<?= $this->endSection() ?>
