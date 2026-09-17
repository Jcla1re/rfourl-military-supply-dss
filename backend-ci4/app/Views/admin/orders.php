
<?= $this->extend('layouts/admin_layout') ?>

<?= $this->section('header_actions') ?>
<button type="button" class="btn btn-success" id="openAddModal"><i class="bi bi-plus-lg"></i> Add New Order</button>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<?php
$orders = $orders ?? [];
$historyOrders = $historyOrders ?? [];
$suppliers = $suppliers ?? [];
$products = $products ?? [];
$tab = $tab ?? 'status';
?>

<style>
.ord-tabs { display: flex; border-bottom: 3px solid var(--sidebar-bg); margin-bottom: 20px; border-radius: 10px 10px 0 0; overflow: hidden; }
.ord-tabs a { flex: 1; text-align: center; padding: 16px; font-weight: 700; text-decoration: none; color: #fff; background: var(--sidebar-bg); }
.ord-tabs a.active { background: var(--content-bg); color: #1c1c1c; }

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
    width: 30px; height: 30px; border-radius: 50%; background: #f0dede; color: var(--accent-maroon);
    display: flex; align-items: center; justify-content: center; margin: 0 auto 8px; font-size: 14px;
    border: 2px solid #f0dede;
}
.ord-step.done .dot, .ord-step.current .dot { background: #e8938e; color: #fff; border-color: #e8938e; }
.ord-step span.label { font-size: 12px; color: #555; }
.ord-step.current span.label { color: #1c1c1c; font-weight: 700; }
.ord-line {
    position: absolute; top: 15px; left: -50%; width: 100%; height: 3px; background: #f0dede; z-index: -1;
}
.ord-step:first-child .ord-line { display: none; }
.ord-step.done .ord-line, .ord-step.current .ord-line { background: #e8938e; }

.ord-info-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 18px 0; }
.ord-info-box { background: #fbeeee; border-radius: 8px; padding: 10px 14px; }
.ord-info-box span { display: block; font-size: 12px; color: #8a5a5a; }
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
                                <form method="post" action="<?= site_url('admin/orders/update-status/' . $o['so_id']) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="status" value="<?= $o['status'] === 'Order Confirmed' ? 'Preparing' : ($o['status'] === 'Preparing' ? 'Shipped' : 'Delivered') ?>">
                                    <button type="submit"><?= $o['status'] === 'Shipped' ? 'Mark as Delivered' : 'Advance to ' . ($o['status'] === 'Order Confirmed' ? 'Preparing' : 'Shipped') ?></button>
                                </form>
                                <form method="post" action="<?= site_url('admin/orders/flag-delayed/' . $o['so_id']) ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit"><?= $isOverdue ? 'Delayed' : 'Follow up' ?></button>
                                </form>
                            </div>
                        </div>
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
                            $cls = $dateStr === $todayStr ? 'today' : (in_array($dateStr, $restockDates ?? [], true) ? 'restock' : '');
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
                        <tr><td colspan="5" class="text-center py-4">No completed orders yet.</td></tr>
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

<script>
const products = <?= json_encode(array_map(fn($p) => ['id' => $p['item_id'], 'name' => $p['item_name'], 'cost' => $p['unit_cost']], $products)) ?>;
const itemModal = document.getElementById('itemModal');
const lineItemsBody = document.getElementById('lineItemsBody');

function productOptions() {
    let html = '<option value="">Select item…</option>';
    products.forEach(p => { html += `<option value="${p.id}" data-cost="${p.cost}">${p.name}</option>`; });
    return html;
}

function addLineRow() {
    const row = document.createElement('tr');
    row.innerHTML = `
        <td><select name="item_id[]" class="item-select" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:6px;">${productOptions()}</select></td>
        <td><input type="number" name="quantity[]" min="1" value="1" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:6px;"></td>
        <td><input type="number" name="unit_price[]" min="0" step="0.01" value="0" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:6px;"></td>
        <td><button type="button" class="btn-close remove-line"></button></td>
    `;
    lineItemsBody.appendChild(row);
    row.querySelector('.item-select').addEventListener('change', e => {
        const opt = e.target.selectedOptions[0];
        if (opt && opt.dataset.cost) row.querySelector('input[name="unit_price[]"]').value = opt.dataset.cost;
    });
    row.querySelector('.remove-line').addEventListener('click', () => row.remove());
}

document.getElementById('addLineBtn').addEventListener('click', addLineRow);
document.getElementById('openAddModal').addEventListener('click', () => {
    lineItemsBody.innerHTML = '';
    addLineRow();
    itemModal.classList.add('show');
});
document.getElementById('closeItemModal').addEventListener('click', () => itemModal.classList.remove('show'));
document.getElementById('cancelItemModal').addEventListener('click', () => itemModal.classList.remove('show'));
</script>

<?= $this->endSection() ?>
