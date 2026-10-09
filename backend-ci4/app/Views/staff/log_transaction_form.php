
<?= $this->extend('layouts/staff_layout') ?>

<?= $this->section('content') ?>

<?php
$products = $products ?? []; $type = $type ?? ''; $typeInfo = $typeInfo ?? ['label' => '']; $recent = $recent ?? [];

$guide = [
    'return' => [
        'icon' => 'bi-arrow-return-left',
        'about' => 'Use this when a customer brings an item back and it has been inspected.',
        'points' => ['The returned quantity is added back to the item\'s stock.', 'Condition, reason and refund action are saved with the log.', 'The Owner is notified of the return.'],
    ],
    'damaged' => [
        'icon' => 'bi-exclamation-triangle',
        'about' => 'Use this when items are damaged, lost or expired and must come out of inventory.',
        'points' => ['The quantity is removed from stock (never below zero).', 'Add notes and the estimated loss so the Owner can review it.', 'The Owner is notified of the report.'],
    ],
    'adjustment' => [
        'icon' => 'bi-sliders',
        'about' => 'Use this to correct stock after a physical count.',
        'points' => ['Stock is set to the counted quantity you enter.', 'The difference from the system quantity is saved in the log.', 'The Owner is notified of the adjustment.'],
    ],
    'restock' => [
        'icon' => 'bi-box-arrow-in-down',
        'about' => 'Use this to record stock received from a supplier.',
        'points' => ['The quantity is added to the item\'s stock.', 'The Owner is notified.'],
    ],
];
$g = $guide[$type] ?? ['icon' => 'bi-info-circle', 'about' => '', 'points' => []];
?>

<style>
.lt-back { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 8px; border: 1px solid #ccc; background: #fff; text-decoration: none; color: #1c1c1c; font-weight: 700; margin-bottom: 18px; }
.lt-label { font-size: 12px; letter-spacing: .05em; color: #666; font-weight: 700; margin-bottom: 12px; }
.lt-layout { display: grid; grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr); gap: 20px; align-items: start; }
@media (max-width: 991px) { .lt-layout { grid-template-columns: 1fr; } }
.lt-form-card { background: #fff; border-radius: 14px; padding: 28px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
.lt-grid { display: grid; grid-template-columns: 1fr 1fr; column-gap: 16px; }
.lt-grid .full { grid-column: 1 / -1; }
@media (max-width: 560px) { .lt-grid { grid-template-columns: 1fr; } }
.lt-aside { background: #fff; border-radius: 14px; padding: 22px 24px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
.lt-aside-head { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
.lt-aside-head .ic { width: 34px; height: 34px; border-radius: 9px; background: var(--green-bg); color: var(--green-text); display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; }
.lt-aside h6 { margin: 0; font-weight: 800; font-size: 15px; }
.lt-aside p { color: #666; font-size: 13.5px; margin-bottom: 12px; }
.lt-points { list-style: none; padding: 0; margin: 0 0 6px; }
.lt-points li { display: flex; gap: 8px; font-size: 13.5px; padding: 7px 0; border-top: 1px solid #f2f2f2; }
.lt-points li i { color: var(--green-text); margin-top: 2px; }
.lt-recent-title { font-size: 12px; letter-spacing: .05em; color: #666; font-weight: 700; margin: 18px 0 6px; text-transform: uppercase; }
.lt-recent-row { display: flex; justify-content: space-between; gap: 12px; font-size: 13px; padding: 8px 0; border-top: 1px solid #f2f2f2; }
.lt-recent-row .when { color: #888; font-size: 12px; }
.lt-recent-row .qty { font-weight: 800; white-space: nowrap; }
.lt-recent-row .qty.pos { color: var(--green-text); }
.lt-recent-row .qty.neg { color: var(--red-text); }
.lt-recent-empty { color: #888; font-size: 13px; padding: 8px 0; border-top: 1px solid #f2f2f2; }
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

    <div class="lt-layout">
    <div class="lt-form-card">
        <span class="lt-badge"><?= esc($typeInfo['label']) ?></span>

        <form method="post" action="<?= site_url('staff/log-transaction/store') ?>" id="ltForm">
            <?= csrf_field() ?>
            <input type="hidden" name="type" value="<?= esc($type) ?>">

            <div class="lt-grid">
            <div class="lt-field full">
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
                <div class="lt-field"><label>Qty Returned</label><input type="number" name="qty_returned" min="1" required></div>
                <div class="lt-field"><label>Original Sale Ref.</label><input type="text" name="original_sale_ref" placeholder="e.g TXN-0042"></div>
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
                <div class="lt-field full">
                    <label>Refund Action</label>
                    <select name="refund_action">
                        <option value="">Select action.....</option>
                        <option>Restock to Inventory</option>
                        <option>Refund Issued</option>
                        <option>Exchange</option>
                    </select>
                </div>

            <?php elseif ($type === 'damaged'): ?>
                <div class="lt-field"><label>Qty Affected</label><input type="number" name="qty_affected" min="1" required></div>
                <div class="lt-field"><label>Est. Loss (₱)</label><input type="number" step="0.01" name="est_loss" placeholder="0.00"></div>
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
                <div class="lt-field full"><label>Notes / Remarks</label><textarea name="notes" rows="2" placeholder="Describe what happened..."></textarea></div>

            <?php elseif ($type === 'adjustment'): ?>
                <div class="lt-field"><label>System Qty</label><input type="text" id="systemQty" readonly placeholder="auto-filled"></div>
                <div class="lt-field"><label>Actual Counted Qty</label><input type="number" name="actual_counted_qty" min="0" required></div>
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
                <div class="lt-field full"><label>Staff name or ID</label><input type="text" name="staff_ref" placeholder="Staff name or ID..."></div>
            <?php endif; ?>
            </div>

            <button type="submit" class="lt-submit">Confirm Transaction</button>
        </form>
    </div>

    <aside class="lt-aside">
        <div class="lt-aside-head"><span class="ic"><i class="bi <?= esc($g['icon']) ?>"></i></span><h6><?= esc($typeInfo['label']) ?></h6></div>
        <p><?= esc($g['about']) ?></p>
        <ul class="lt-points">
            <?php foreach ($g['points'] as $pt): ?>
                <li><i class="bi bi-check-circle-fill"></i><span><?= esc($pt) ?></span></li>
            <?php endforeach; ?>
        </ul>

        <div class="lt-recent-title">Recent <?= esc(strtolower($typeInfo['label'])) ?> logs</div>
        <?php if (empty($recent)): ?>
            <div class="lt-recent-empty">Nothing logged yet.</div>
        <?php else: ?>
            <?php foreach ($recent as $r): ?>
                <div class="lt-recent-row">
                    <div>
                        <div><?= esc($r['item_name'] ?? $r['item_id']) ?></div>
                        <div class="when"><?= esc(date('M j, g:i A', strtotime($r['timestamp']))) ?></div>
                    </div>
                    <div class="qty <?= (int) $r['quantity_changed'] >= 0 ? 'pos' : 'neg' ?>"><?= (int) $r['quantity_changed'] > 0 ? '+' : '' ?><?= esc((string) $r['quantity_changed']) ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </aside>
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
