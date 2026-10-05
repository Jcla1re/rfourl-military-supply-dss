
<?= $this->extend('layouts/admin_layout') ?>

<?= $this->section('header_actions') ?>
<button type="button" class="btn btn-success" id="openAddModal"><i class="bi bi-plus-lg"></i> Add New Order</button>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<?php
$orders = $orders ?? [];
$historyOrders = $historyOrders ?? [];
$historySearch = $historySearch ?? '';
$historyDateFrom = $historyDateFrom ?? '';
$historyDateTo = $historyDateTo ?? '';
$suppliers = $suppliers ?? [];
$products = $products ?? [];
$tab = $tab ?? 'status';
$calMonth = $calMonth ?? (int) date('n');
$calYear = $calYear ?? (int) date('Y');
$restockDates = $restockDates ?? [];
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap');

.ord-tabs {
    display: flex;
    gap: 2px;
    background: #4B6B42;
    padding: 0;
    margin: -24px -24px 20px -24px;
}
.ord-tabs a {
    flex: 1;
    height: 44px;
    box-sizing: border-box;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    text-decoration: none;
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
    font-size: 15px;
    font-weight: 400;
    color: #fff;
    background: #4B6B42;
    border: 1px solid rgba(255, 255, 255, 0.45);
    border-bottom: none;
    border-radius: 6px 6px 0 0;
    transition: background-color 0.15s ease;
}
.ord-tabs a:hover:not(.active) {
    background: rgba(255, 255, 255, 0.08);
}
.ord-tabs a.active {
    background: #F3F1EB;
    color: #1E2616;
    font-weight: 500;
    border: none;
}

.ord-grid { display: grid; grid-template-columns: 1.6fr 1fr; gap: 20px; align-items: start; }
@media (max-width: 1100px) { .ord-grid { grid-template-columns: 1fr; } }

.ord-col-label { text-transform: uppercase; letter-spacing: .06em; color: #666; font-size: 13px; font-weight: 700; margin-bottom: 10px; }

.ord-card { background: #fff; border: 1px solid #e2e2e2; border-radius: 14px; padding: 20px; margin-bottom: 16px; }
.ord-card-top { display: flex; justify-content: space-between; align-items: flex-start; }
.ord-card-top .meta { color: #555; font-size: 13px; }
.ord-card-top .title { font-weight: 800; font-size: 16px; margin: 2px 0; }
.ord-card-top .sub { color: #777; font-size: 13px; }
.priority-pill { background: #e9e7e1; border-radius: 999px; padding: 5px 14px; font-size: 12px; font-weight: 700; color: #4a4a4a; }

.ord-stepper { display: flex; align-items: center; margin: 22px 6px 6px; }
.ord-step { flex: 1; text-align: center; position: relative; }
.ord-step .dot {
    width: 30px; height: 30px; border-radius: 50%; background: #e4e2dc; color: #6b6b6b;
    display: flex; align-items: center; justify-content: center; margin: 0 auto 8px; font-size: 14px;
    border: 2px solid #e4e2dc;
}
.ord-step.done .dot, .ord-step.current .dot { background: var(--green-text); color: #fff; border-color: var(--green-text); }
.ord-step span.label { font-size: 12px; color: #555; }
.ord-step.current span.label { color: #1c1c1c; font-weight: 700; }
.ord-line {
    position: absolute; top: 15px; left: -50%; width: 100%; height: 3px; background: #e4e2dc; z-index: -1;
}
.ord-step:first-child .ord-line { display: none; }
.ord-step.done .ord-line, .ord-step.current .ord-line { background: var(--green-text); }

.ord-info-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 18px 0; }
.ord-info-box { background: #eef0ea; border-radius: 8px; padding: 10px 14px; }
.ord-info-box span { display: block; font-size: 12px; color: #3d5230; }
.ord-info-box strong { font-size: 14px; }

.ord-actions { display: flex; gap: 10px; }
.ord-actions form { flex: 1; }
.ord-actions button { width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #333; background: #fff; font-weight: 700; cursor: pointer; }

.cal-card { background: #fff; border: 1px solid #e2e2e2; border-radius: 14px; padding: 18px; }
.cal-nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
.cal-nav a, .cal-nav span { padding: 8px 12px; border: 1px solid #ddd; border-radius: 8px; text-decoration: none; color: #333; font-weight: 600; }
.cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; text-align: center; }
.cal-grid .dow { font-size: 12px; color: #888; padding-bottom: 6px; }
.cal-day { padding: 8px 0; border-radius: 8px; font-size: 14px; }
.cal-day.today { background: var(--sidebar-bg); color: #fff; font-weight: 700; }
.cal-day.restock { background: #dcecdf; font-weight: 700; }
.cal-legend { display: flex; gap: 18px; margin-top: 14px; font-size: 13px; }
.cal-legend span { display: inline-flex; align-items: center; gap: 6px; }
.cal-legend i { width: 12px; height: 12px; border-radius: 3px; display: inline-block; }

.ord-filter-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
.ord-search {
    display: flex; align-items: center; gap: 8px; background: #fff; border: 1px solid #ddd;
    border-radius: 10px; padding: 10px 14px; max-width: 360px; flex: 1; min-width: 220px;
}
.ord-search i { color: #888; font-size: 16px; flex-shrink: 0; }
.ord-search input { flex: 1; min-width: 0; border: none; outline: none; font-size: 14px; }
.ord-filter-bar input[type="date"] { border: 1px solid #ddd; border-radius: 10px; padding: 9px 12px; font-size: 14px; }
.ord-filter-bar .sep { color: #888; font-size: 13px; }

</style>

<div class="page-wrap">
    <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>

    <div class="ord-tabs">
        <a href="<?= site_url('admin/orders') ?>?tab=status" class="<?= $tab === 'status' ? 'active' : '' ?>">Order Status</a>
        <a href="<?= site_url('admin/orders') ?>?tab=history" class="<?= $tab === 'history' ? 'active' : '' ?>">History</a>
    </div>

    <?php if ($tab === 'status'): ?>
        <div class="ord-grid">
            <div>
                <div class="ord-col-label">Order Status</div>

                <?php if (empty($orders)): ?>
                    <div class="empty-state">No active orders. Create one from Reorder Alerts or the button above.</div>
                <?php else: ?>
                    <?php foreach ($orders as $o): ?>
                        <?php
                        $stepIndex = array_search($o['status'], ['Order Confirmed', 'Preparing', 'Shipped', 'Delivered'], true);
                        $priorityLabel = match ($o['priority']) {
                            'Urgent' => 'Urgent', 'Planned' => 'Planned', default => 'Upcoming',
                        };
                        $isOverdue = !empty($o['expected_delivery_date']) && strtotime($o['expected_delivery_date']) < strtotime('today') && $o['status'] !== 'Delivered';
                        ?>
                        <div class="ord-card">
                            <div class="ord-card-top">
                                <div>
                                    <div class="meta">#<?= esc($o['so_id']) ?> &middot; <?= esc($o['company_name'] ?? 'Unassigned') ?></div>
                                    <div class="title"><?= esc($o['primary_item'] ?? 'Stock Order') ?></div>
                                    <div class="sub"><?= esc($o['total_units'] ?? 0) ?> units &middot; PEst: ₱<?= number_format($o['estimated'] ?? 0) ?></div>
                                </div>
                                <span class="priority-pill"><?= esc($priorityLabel) ?></span>
                            </div>

                            <div class="ord-stepper">
                                <?php foreach (['Order Confirmed', 'Preparing', 'Shipped', 'Delivered'] as $i => $label): ?>
                                    <div class="ord-step <?= $i < $stepIndex ? 'done' : ($i === $stepIndex ? 'current' : '') ?>">
                                        <div class="ord-line"></div>
                                        <div class="dot"><?= $i <= $stepIndex ? '<i class="bi bi-check-lg"></i>' : ($i + 1) ?></div>
                                        <span class="label"><?= esc($label) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="ord-info-row">
                                <div class="ord-info-box"><span>Delivery Due</span><strong><?= !empty($o['expected_delivery_date']) ? esc(date('M j, Y', strtotime($o['expected_delivery_date']))) : '—' ?></strong></div>
                                <div class="ord-info-box"><span>Order Date</span><strong><?= esc(date('M j, Y', strtotime($o['order_date']))) ?></strong></div>
                                <div class="ord-info-box"><span>Tracking no.</span><strong><?= esc($o['tracking_no'] ?? '—') ?></strong></div>
                            </div>

                            <div class="ord-actions">
                                <!-- Order Confirmed -> Preparing -> Shipped are the supplier's own
                                     progress, updated from their portal (Supplier\NewOrdersController::accept,
                                     Supplier\DeliveriesController::ship). Admin no longer force-advances
                                     those intermediate steps — only closes out a fully received order,
                                     or follows up with the supplier for a status update. Confirming
                                     receipt requires reviewing the supplier's declared shipped
                                     quantities first (the checklist modal below), so this only
                                     unlocks once the order has actually been shipped. -->
                                <?php if ($o['status'] === 'Shipped'): ?>
                                    <button type="button" onclick="document.getElementById('confirmModal-<?= esc($o['so_id']) ?>').classList.add('show')">Mark as Done</button>
                                <?php else: ?>
                                    <button type="button" disabled title="Available once the supplier ships this order">Mark as Done</button>
                                <?php endif; ?>
                                <form method="post" action="<?= site_url('admin/orders/flag-delayed/' . $o['so_id']) ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit"><?= $isOverdue ? 'Delayed' : 'Follow up' ?></button>
                                </form>
                            </div>
                        </div>

                        <?php if ($o['status'] === 'Shipped'): ?>
                        <div class="inv-modal" id="confirmModal-<?= esc($o['so_id']) ?>">
                            <div class="inv-modal-card" style="max-width:560px;">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h3 class="mb-0">Confirm Delivery &mdash; #<?= esc($o['so_id']) ?></h3>
                                    <button type="button" class="btn-close" onclick="this.closest('.inv-modal').classList.remove('show')"></button>
                                </div>
                                <p class="subtitle">Check each item against what physically arrived — this should match what the supplier declared when they shipped it.</p>
                                <form method="post" action="<?= site_url('admin/orders/update-status/' . $o['so_id']) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="status" value="Delivered">
                                    <table style="width:100%; border-collapse:collapse; margin-bottom:18px;">
                                        <thead>
                                            <tr>
                                                <th style="text-align:left; font-size:11px; text-transform:uppercase; color:#888; padding:6px 8px; border-bottom:1px solid #e2e2e2;">Item</th>
                                                <th style="text-align:right; font-size:11px; text-transform:uppercase; color:#888; padding:6px 8px; border-bottom:1px solid #e2e2e2;">Shipped Qty</th>
                                                <th style="text-align:right; font-size:11px; text-transform:uppercase; color:#888; padding:6px 8px; border-bottom:1px solid #e2e2e2;">Unit Price</th>
                                                <th style="text-align:right; font-size:11px; text-transform:uppercase; color:#888; padding:6px 8px; border-bottom:1px solid #e2e2e2;">Line Total</th>
                                                <th style="text-align:center; width:80px; font-size:11px; text-transform:uppercase; color:#888; padding:6px 8px; border-bottom:1px solid #e2e2e2;">Received</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (($o['lines'] ?? []) as $l): ?>
                                                <?php $shippedQty = $l['shipped_quantity'] ?? $l['order_quantity']; ?>
                                                <tr>
                                                    <td style="padding:8px; border-bottom:1px solid #f2f2f2;"><?= esc($l['item_name'] ?? $l['item_id']) ?></td>
                                                    <td style="padding:8px; border-bottom:1px solid #f2f2f2; text-align:right;"><?= esc((string) $shippedQty) ?></td>
                                                    <td style="padding:8px; border-bottom:1px solid #f2f2f2; text-align:right;">₱<?= number_format((float) $l['unit_price'], 2) ?></td>
                                                    <td style="padding:8px; border-bottom:1px solid #f2f2f2; text-align:right;">₱<?= number_format((float) $shippedQty * (float) $l['unit_price'], 2) ?></td>
                                                    <td style="padding:8px; border-bottom:1px solid #f2f2f2; text-align:center;"><input type="checkbox" required title="Confirm this item was physically received"></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <td colspan="3" style="padding:8px; font-weight:700;">Order Total</td>
                                                <td style="padding:8px; font-weight:700; text-align:right;">₱<?= number_format(array_sum(array_map(fn ($l) => ($l['shipped_quantity'] ?? $l['order_quantity']) * $l['unit_price'], $o['lines'] ?? [])), 2) ?></td>
                                                <td></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                    <div class="inv-modal-actions">
                                        <button type="button" class="btn btn-secondary" onclick="this.closest('.inv-modal').classList.remove('show')">Cancel</button>
                                        <button type="submit" class="btn btn-success">Confirm Delivery Received</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div>
                <div class="ord-col-label">Procurement Calendar</div>
                <div class="cal-card">
                    <?php
                    $monthTs = mktime(0, 0, 0, $calMonth, 1, $calYear);
                    $prevM = $calMonth - 1; $prevY = $calYear;
                    $nextM = $calMonth + 1; $nextY = $calYear;
                    $daysInMonth = (int) date('t', $monthTs);
                    $startDow = (int) date('w', $monthTs);
                    $todayStr = date('Y-m-d');
                    ?>
                    <div class="cal-nav">
                        <a href="?tab=status&month=<?= $prevM ?>&year=<?= $prevY ?>">&larr; <?= date('M', mktime(0,0,0,$prevM,1,$prevY)) ?></a>
                        <span><?= esc(date('F Y', $monthTs)) ?></span>
                        <a href="?tab=status&month=<?= $nextM ?>&year=<?= $nextY ?>"><?= date('M', mktime(0,0,0,$nextM,1,$nextY)) ?> &rarr;</a>
                    </div>
                    <div class="cal-grid">
                        <?php foreach (['S','M','T','W','Th','F','S'] as $dow): ?><div class="dow"><?= $dow ?></div><?php endforeach; ?>
                        <?php for ($i = 0; $i < $startDow; $i++): ?><div></div><?php endfor; ?>
                        <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                            <?php
                            $dateStr = sprintf('%04d-%02d-%02d', $calYear, $calMonth, $d);
                            $cls = $dateStr === $todayStr ? 'today' : (in_array($dateStr, $restockDates, true) ? 'restock' : '');
                            ?>
                            <div class="cal-day <?= $cls ?>"><?= $d ?></div>
                        <?php endfor; ?>
                    </div>
                    <div class="cal-legend">
                        <span><i style="background: var(--sidebar-bg)"></i> Today</span>
                        <span><i style="background:#dcecdf"></i> Restock Event</span>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <form method="get" class="ord-filter-bar">
            <input type="hidden" name="tab" value="history">
            <div class="ord-search">
                <i class="bi bi-search"></i>
                <input type="search" name="search" value="<?= esc($historySearch) ?>" placeholder="Search by Order ID or Supplier...">
            </div>
            <input type="date" name="date_from" value="<?= esc($historyDateFrom) ?>" onchange="this.form.submit()">
            <span class="sep">to</span>
            <input type="date" name="date_to" value="<?= esc($historyDateTo) ?>" onchange="this.form.submit()">
        </form>
        <div class="page-panel">
            <table class="data-table">
                <thead>
                    <tr><th>Order ID</th><th>Supplier</th><th>Order Date</th><th>Delivered</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php if (!empty($historyOrders)): ?>
                        <?php foreach ($historyOrders as $o): ?>
                            <tr>
                                <td><strong><?= esc($o['so_id']) ?></strong></td>
                                <td><?= esc($o['company_name'] ?? '—') ?></td>
                                <td><?= esc(date('M j, Y', strtotime($o['order_date']))) ?></td>
                                <td><?= !empty($o['actual_delivery_date']) ? esc(date('M j, Y', strtotime($o['actual_delivery_date']))) : '—' ?></td>
                                <td><span class="status-pill <?= $o['status'] === 'Delivered' ? 'green' : 'red' ?>"><?= esc($o['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center py-4"><?= ($historySearch !== '' || $historyDateFrom !== '' || $historyDateTo !== '') ? 'No orders match your filters.' : 'No completed orders yet.' ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="inv-modal" id="itemModal">
    <div class="inv-modal-card" style="max-width: 760px;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="mb-0">New Stock Order</h3>
            <button type="button" class="btn-close" id="closeItemModal"></button>
        </div>

        <form method="post" action="<?= site_url('admin/orders/store') ?>">
            <?= csrf_field() ?>
            <div class="inv-form-grid">
                <div class="inv-form-field">
                    <label>Supplier</label>
                    <select name="supplier_id" required>
                        <option value="">Select supplier…</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= esc($s['supplier_id']) ?>"><?= esc($s['company_name']) ?> (<?= esc($s['lead_time_days']) ?>d lead time)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="inv-form-field">
                    <label>Priority</label>
                    <select name="priority">
                        <?php foreach (($priorities ?? []) as $p): ?><option value="<?= esc($p) ?>"><?= esc($p) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="inv-form-field">
                    <label>Expected Delivery Date</label>
                    <input name="expected_delivery_date" type="date">
                </div>
                <div class="inv-form-field">
                    <label>Tracking Number (optional)</label>
                    <input name="tracking_no" placeholder="e.g. TRK-2026-0001">
                </div>
            </div>

            <div class="mt-3">
                <label class="fw-bold">Items</label>
                <table class="line-items-table" id="lineItemsTable" style="width:100%; border-collapse: collapse; margin-top: 8px;">
                    <thead>
                        <tr>
                            <th style="width:45%; text-align:left; font-size:11px; text-transform:uppercase; color:#666;">Item</th>
                            <th style="width:20%; text-align:left; font-size:11px; text-transform:uppercase; color:#666;">Quantity</th>
                            <th style="width:25%; text-align:left; font-size:11px; text-transform:uppercase; color:#666;">Unit Price (₱)</th>
                            <th style="width:10%"></th>
                        </tr>
                    </thead>
                    <tbody id="lineItemsBody"></tbody>
                </table>
                <button type="button" style="border: 1px dashed #999; background: transparent; border-radius: 8px; padding: 8px 14px; margin-top: 8px; cursor:pointer;" id="addLineBtn">+ Add item</button>
            </div>

            <div class="inv-modal-actions">
                <button type="button" class="btn btn-secondary" id="cancelItemModal">Cancel</button>
                <button type="submit" class="btn btn-success">Create Order</button>
            </div>
        </form>
    </div>
</div>

<div class="inv-modal" id="itemPickerModal">
    <div class="inv-modal-card" style="max-width:480px;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="mb-0" id="pickerTitle">Select Item</h3>
            <button type="button" class="btn-close" id="closeItemPicker"></button>
        </div>
        <div id="pickerStep1">
            <input type="search" id="pickerSearch" placeholder="Search items..." style="width:100%; padding:10px 14px; border:1px solid #ccc; border-radius:8px; margin-bottom:12px;">
            <div id="pickerGroupList" style="max-height:360px; overflow-y:auto; display:flex; flex-direction:column; gap:6px;"></div>
        </div>
        <div id="pickerStep2" style="display:none;">
            <button type="button" id="pickerBackBtn" style="border:none; background:none; color:#666; padding:0; margin-bottom:12px; cursor:pointer; font-weight:600;">&larr; Back</button>
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="text-align:left; font-size:11px; text-transform:uppercase; color:#888; padding-bottom:6px;">Size</th>
                        <th style="text-align:left; font-size:11px; text-transform:uppercase; color:#888; padding-bottom:6px;">Qty to Order</th>
                        <th style="text-align:left; font-size:11px; text-transform:uppercase; color:#888; padding-bottom:6px;">Unit Cost (₱)</th>
                    </tr>
                </thead>
                <tbody id="pickerVariantBody"></tbody>
            </table>
            <button type="button" class="btn btn-success mt-3" id="pickerAddBtn" style="width:100%;">Add to Order</button>
        </div>
    </div>
</div>

<style>
.picker-group-btn {
    display: flex; justify-content: space-between; align-items: center; width: 100%;
    padding: 12px 14px; border: 1px solid #e2e2e2; border-radius: 8px; background: #fff;
    cursor: pointer; text-align: left; font-size: 14px;
}
.picker-group-btn:hover { border-color: #3d5230; background: #f7f9f5; }
.picker-group-btn .muted { color: #888; font-size: 12px; }
</style>

<script>
// Grouped by item_name+category — e.g. "Goa Pants" size S, M, and L are
// three fully independent product rows (own item_id, stock, ROP), but the
// picker shows them as one entry with a size step underneath. An item with
// only one size skips straight past that step.
const productGroups = <?= json_encode(array_map(fn ($g) => [
    'item_name' => $g['item_name'],
    'variants'  => array_map(fn ($v) => [
        'item_id'   => $v['item_id'],
        'size'      => $v['size'] ?? '',
        'unit_cost' => (float) $v['unit_cost'],
    ], $g['variants']),
], $productGroups ?? [])) ?>;

const itemModal = document.getElementById('itemModal');
const lineItemsBody = document.getElementById('lineItemsBody');
const itemPickerModal = document.getElementById('itemPickerModal');
const pickerStep1 = document.getElementById('pickerStep1');
const pickerStep2 = document.getElementById('pickerStep2');
const pickerGroupList = document.getElementById('pickerGroupList');
const pickerVariantBody = document.getElementById('pickerVariantBody');
let activeGroup = null;

function renderGroupList(filterText) {
    const term = (filterText || '').toLowerCase();
    const matches = productGroups.filter(g => g.item_name.toLowerCase().includes(term));
    pickerGroupList.innerHTML = matches.length
        ? matches.map(g => `
            <button type="button" class="picker-group-btn" data-name="${g.item_name}">
                <span>${g.item_name}</span>
                <span class="muted">${g.variants.length > 1 ? g.variants.length + ' sizes' : ''}</span>
            </button>
        `).join('')
        : '<div class="text-muted small p-2">No items found.</div>';

    pickerGroupList.querySelectorAll('.picker-group-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            activeGroup = productGroups.find(g => g.item_name === btn.dataset.name);
            if (activeGroup) openSizeStep(activeGroup);
        });
    });
}

function openSizeStep(group) {
    document.getElementById('pickerTitle').textContent = group.item_name;

    pickerVariantBody.innerHTML = group.variants.map((v, i) => `
        <tr>
            <td style="padding:6px 8px;">${v.size || '—'}</td>
            <td style="padding:6px 8px;"><input type="number" min="0" value="0" data-idx="${i}" class="picker-qty" style="width:90px; padding:6px 8px; border:1px solid #ccc; border-radius:6px;"></td>
            <td style="padding:6px 8px;"><input type="number" min="0" step="0.01" value="${v.unit_cost}" data-idx="${i}" class="picker-cost" style="width:100px; padding:6px 8px; border:1px solid #ccc; border-radius:6px;"></td>
        </tr>
    `).join('');

    pickerStep1.style.display = 'none';
    pickerStep2.style.display = '';
}

function resetPickerToList() {
    pickerStep2.style.display = 'none';
    pickerStep1.style.display = '';
    document.getElementById('pickerSearch').value = '';
    renderGroupList();
}

document.getElementById('pickerBackBtn').addEventListener('click', resetPickerToList);
document.getElementById('pickerSearch').addEventListener('input', e => renderGroupList(e.target.value));

document.getElementById('pickerAddBtn').addEventListener('click', () => {
    if (!activeGroup) return;

    const qtyInputs  = pickerVariantBody.querySelectorAll('.picker-qty');
    const costInputs = pickerVariantBody.querySelectorAll('.picker-cost');

    qtyInputs.forEach((qtyInput, i) => {
        const qty = parseInt(qtyInput.value, 10) || 0;
        if (qty <= 0) return;

        const v    = activeGroup.variants[i];
        const cost = parseFloat(costInputs[i].value) || 0;
        const label = activeGroup.item_name + (v.size ? ' — ' + v.size : '');

        const row = document.createElement('tr');
        row.innerHTML = `
            <td style="padding:8px;">
                <input type="hidden" name="item_id[]" value="${v.item_id}">
                ${label}
            </td>
            <td><input type="number" name="quantity[]" min="1" value="${qty}" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:6px;"></td>
            <td><input type="number" name="unit_price[]" min="0" step="0.01" value="${cost}" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:6px;"></td>
            <td><button type="button" class="btn-close remove-line"></button></td>
        `;
        lineItemsBody.appendChild(row);
        row.querySelector('.remove-line').addEventListener('click', () => row.remove());
    });

    itemPickerModal.classList.remove('show');
    itemModal.classList.add('show');
    resetPickerToList();
});

document.getElementById('closeItemPicker').addEventListener('click', () => {
    itemPickerModal.classList.remove('show');
    itemModal.classList.add('show');
});

document.getElementById('addLineBtn').addEventListener('click', () => {
    itemModal.classList.remove('show');
    resetPickerToList();
    itemPickerModal.classList.add('show');
});

document.getElementById('openAddModal').addEventListener('click', () => {
    lineItemsBody.innerHTML = '';
    itemModal.classList.add('show');
});
document.getElementById('closeItemModal').addEventListener('click', () => itemModal.classList.remove('show'));
document.getElementById('cancelItemModal').addEventListener('click', () => itemModal.classList.remove('show'));
</script>

<?= $this->endSection() ?>
