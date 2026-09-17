
<?= $this->extend('layouts/staff_layout') ?>

<?= $this->section('content') ?>

<?php $products = $products ?? []; $type = $type ?? ''; $typeInfo = $typeInfo ?? ['label' => '']; ?>

<style>
.lt-back { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 8px; border: 1px solid #ccc; background: #fff; text-decoration: none; color: #1c1c1c; font-weight: 700; margin-bottom: 18px; }
.lt-label { font-size: 12px; letter-spacing: .05em; color: #666; font-weight: 700; margin-bottom: 12px; }
.lt-form-card { background: #fff; border-radius: 14px; padding: 28px; max-width: 640px; }
.lt-badge { display: inline-block; background: #e9e7e1; border-radius: 999px; padding: 5px 14px; font-size: 12px; font-weight: 700; margin-bottom: 22px; }
.lt-field { margin-bottom: 18px; }
.lt-field label { font-weight: 700; display: block; margin-bottom: 6px; font-size: 14px; }
.lt-field input, .lt-field select, .lt-field textarea {
    width: 100%; padding: 12px 14px; border: 1px solid #ccc; border-radius: 8px; background: #fff;
}
.lt-field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 560px) { .lt-field-row { grid-template-columns: 1fr; } }
.lt-submit { width: 100%; background: #6b8f4e; color: #fff; border: none; padding: 14px; border-radius: 10px; font-weight: 700; margin-top: 6px; }
</style>

<div class="page-wrap">
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>

    <a href="<?= site_url('staff/log-transaction') ?>" class="lt-back"><i class="bi bi-arrow-left"></i> Back</a>

    <div class="lt-label">FILL IN DETAILS</div>

    <div class="lt-form-card">
        <span class="lt-badge"><?= esc($typeInfo['label']) ?></span>

        <form method="post" action="<?= site_url('staff/log-transaction/store') ?>" id="ltForm">
            <?= csrf_field() ?>
            <input type="hidden" name="type" value="<?= esc($type) ?>">

            <div class="lt-field">
                <label>Item / SKU</label>
                <select name="item_id" id="itemSelect" required>
                    <option value="">Search product....</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= esc($p['item_id']) ?>" data-stock="<?= esc($p['current_stock']) ?>">
                            <?= esc($p['item_name']) ?><?= !empty($p['size']) ? ' - ' . esc($p['size']) : '' ?> (Stock: <?= esc($p['current_stock']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($type === 'restock'): ?>
                <div class="lt-field"><label>Quantity</label><input type="number" name="quantity" min="1" required></div>
                <div class="lt-field"><label>Supplier</label><input type="text" name="supplier" placeholder="e.g. Supplier A"></div>

            <?php elseif ($type === 'return'): ?>
                <div class="lt-field-row">
                    <div class="lt-field"><label>Qty Returned</label><input type="number" name="qty_returned" min="1" required></div>
                    <div class="lt-field"><label>Original Sale Ref.</label><input type="text" name="original_sale_ref" placeholder="e.g TXN-0042"></div>
                </div>
                <div class="lt-field">
                    <label>Item Condition</label>
                    <select name="item_condition">
                        <option value="">Select condition.....</option>
                        <option>Passed Inspection</option>
                        <option>Damaged</option>
                        <option>Wrong Item / Size</option>
                    </select>
                </div>
                <div class="lt-field">
                    <label>Reason for Return</label>
                    <select name="reason">
                        <option value="">Select reason.....</option>
                        <option>Wrong Size</option>
                        <option>Changed Mind</option>
                        <option>Defective</option>
                        <option>Other</option>
                    </select>
                </div>
                <div class="lt-field">
                    <label>Refund Action</label>
                    <select name="refund_action">
                        <option value="">Select action.....</option>
                        <option>Restock to Inventory</option>
                        <option>Refund Issued</option>
                        <option>Exchange</option>
                    </select>
                </div>

            <?php elseif ($type === 'damaged'): ?>
                <div class="lt-field-row">
                    <div class="lt-field"><label>Qty Affected</label><input type="number" name="qty_affected" min="1" required></div>
                    <div class="lt-field"><label>Est. Loss (₱)</label><input type="number" step="0.01" name="est_loss" placeholder="0.00"></div>
                </div>
                <div class="lt-field">
                    <label>Item Condition</label>
                    <select name="item_condition">
                        <option value="">Select condition.....</option>
                        <option>Damaged</option>
                        <option>Lost</option>
                        <option>Expired</option>
                    </select>
                </div>
                <div class="lt-field"><label>Incident Date</label><input type="date" name="incident_date"></div>
                <div class="lt-field"><label>Notes / Remarks</label><textarea name="notes" rows="2" placeholder="Describe what happened..."></textarea></div>

            <?php elseif ($type === 'adjustment'): ?>
                <div class="lt-field-row">
                    <div class="lt-field"><label>System Qty</label><input type="text" id="systemQty" readonly placeholder="auto-filled"></div>
                    <div class="lt-field"><label>Actual Counted Qty</label><input type="number" name="actual_counted_qty" min="0" required></div>
                </div>
                <div class="lt-field">
                    <label>Adjustment Reason</label>
                    <select name="adjustment_reason">
                        <option value="">Select reason...</option>
                        <option>Physical Count Mismatch</option>
                        <option>Data Entry Error</option>
                        <option>Theft / Loss</option>
                    </select>
                </div>
                <div class="lt-field"><label>Adjustment Date</label><input type="date" name="adjustment_date" value="<?= date('Y-m-d') ?>"></div>
                <div class="lt-field"><label>Staff name or ID</label><input type="text" name="staff_ref" placeholder="Staff name or ID..."></div>
            <?php endif; ?>

            <button type="submit" class="lt-submit">Confirm Transaction</button>
        </form>
    </div>
</div>

<script>
const itemSelect = document.getElementById('itemSelect');
const systemQty = document.getElementById('systemQty');
if (itemSelect && systemQty) {
    itemSelect.addEventListener('change', () => {
        const opt = itemSelect.options[itemSelect.selectedIndex];
        systemQty.value = opt ? (opt.dataset.stock || '0') : '';
    });
}
</script>

<?= $this->endSection() ?>
