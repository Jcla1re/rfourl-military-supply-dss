
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
$followedUpToday = $followedUpToday ?? [];
?>
<link href="/assets/css/item-modal.css" rel="stylesheet">

<style>

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
    border-radius: var(--radius-control) var(--radius-control) 0 0;
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
.ord-grid > :last-child { align-self: stretch; }
@media (max-width: 1100px) { .ord-grid { grid-template-columns: 1fr; } }

.ord-col-label { text-transform: uppercase; letter-spacing: .06em; color: #666; font-size: 13px; font-weight: 700; margin-bottom: 10px; }

.ord-card { background: #fff; border: 1px solid #e2e2e2; border-radius: var(--radius-card); padding: 20px; margin-bottom: 16px; }
.ord-card-top { display: flex; justify-content: space-between; align-items: flex-start; }
.ord-card-top .meta { color: #555; font-size: 13px; }
.ord-card-top .title { font-weight: 600; font-size: 16px; margin: 2px 0; }
.ord-card-top .sub { color: #777; font-size: 13px; }
.ord-pills { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
.priority-pill { background: #e9e7e1; border-radius: var(--radius-pill); padding: 5px 14px; font-size: 12px; font-weight: 700; color: #4a4a4a; white-space: nowrap; }
.priority-pill.overdue { background: var(--red-bg); color: var(--red-text); }

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
.ord-info-box { background: #eef0ea; border-radius: var(--radius-control); padding: 10px 14px; }
.ord-info-box span { display: block; font-size: 12px; color: #3d5230; }
.ord-info-box strong { font-size: 14px; }

.ord-actions { display: flex; gap: 10px; }
.ord-actions form { flex: 1; }
.ord-actions button { width: 100%; padding: 12px; border-radius: var(--radius-control); border: 1px solid #333; background: #fff; font-weight: 700; cursor: pointer; }
.ord-actions button.cancel-order { width: auto; padding: 12px 18px; border-color: #A1261B; color: #A1261B; }
.ord-actions button { white-space: nowrap; }
.ord-actions .ord-follow { display: flex; flex-direction: column; gap: 4px; min-width: 132px; }
.ord-actions button:disabled { opacity: .55; cursor: not-allowed; }
.ord-followed { font-size: 12px; color: #5C6152; text-align: center; }
.ord-note { margin: 10px 0 0; font-size: 13px; color: #777; }
.hist-note { display: block; font-size: 12px; color: #777; margin-top: 4px; }

.cal-card { background: #fff; border: 1px solid #e2e2e2; border-radius: var(--radius-card); padding: 18px; position: sticky; top: calc(var(--sticky-top, 0px) + 16px); }
.cal-nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
.cal-nav a, .cal-nav span { padding: 8px 12px; border: 1px solid #ddd; border-radius: var(--radius-icon); text-decoration: none; color: #333; font-weight: 600; }
.cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; text-align: center; }
.cal-grid .dow { font-size: 12px; color: #888; padding-bottom: 6px; }
.cal-day { padding: 8px 0; border-radius: var(--radius-control); font-size: 14px; }
.cal-day.today { background: var(--sidebar-bg); color: #fff; font-weight: 700; }
.cal-day.restock { background: #dcecdf; font-weight: 700; }
.cal-legend { display: flex; gap: 18px; margin-top: 14px; font-size: 13px; }
.cal-legend span { display: inline-flex; align-items: center; gap: 6px; }
.cal-legend i { width: 12px; height: 12px; border-radius: 3px; display: inline-block; }

.ord-filter-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
.ord-search {
    display: flex; align-items: center; gap: 8px; background: #fff; border: 1px solid #D6D2C6;
    border-radius: var(--radius-control); padding: 10px 14px; max-width: 360px; flex: 1; min-width: 220px;
}
.ord-search i { color: #888; font-size: 16px; flex-shrink: 0; }
.ord-search input { flex: 1; min-width: 0; border: none; outline: none; font-size: 14px; }
.ord-filter-bar input[type="date"] { border: 1px solid #ddd; border-radius: var(--radius-control); padding: 9px 12px; font-size: 14px; }
.ord-filter-bar .sep { color: #888; font-size: 13px; }
</style>

<div class="page-wrap">
    <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>

    <div class="ord-tabs sticky-tabs">
        <a href="<?= site_url('admin/orders') ?>?tab=status" class="<?= $tab === 'status' ? 'active' : '' ?>">Order Status</a>
        <a href="<?= site_url('admin/orders') ?>?tab=history" class="<?= $tab === 'history' ? 'active' : '' ?>">History</a>
    </div>

    <?php if ($tab === 'status'): ?>
        <div class="ord-grid">
            <div>
                <div class="ord-col-label">Order Status</div>

                <?php if (empty($orders)): ?>
                    <div class="empty-state"><i class="bi bi-inbox" aria-hidden="true"></i> No orders yet. <button type="button" class="btn btn-success btn-sm ms-2" onclick="document.getElementById('openAddModal').click()">Add New Order</button></div>
                <?php else: ?>
                    <?php foreach ($orders as $o): ?>
                        <?php
                        $stepIndex = array_search($o['status'], ['Order Confirmed', 'Preparing', 'Shipped', 'Delivered'], true);
                        $priorityLabel = \App\Models\StockOrderModel::priorityLabel($o['priority'] ?? null);
                        $isOverdue = !empty($o['expected_delivery_date']) && strtotime($o['expected_delivery_date']) < strtotime('today') && $o['status'] !== 'Delivered';
                        $followedUp = isset($followedUpToday[$o['so_id']]);
                        ?>
                        <div class="ord-card">
                            <div class="ord-card-top">
                                <div>
                                    <div class="meta">#<?= esc($o['so_id']) ?> &middot; <?= esc($o['company_name'] ?? 'Unassigned') ?></div>
                                    <div class="title"><?= esc($o['primary_item'] ?? 'Stock Order') ?></div>
                                    <div class="sub"><?= esc($o['total_units'] ?? 0) ?> units &middot; PEst: ₱<?= number_format($o['estimated'] ?? 0) ?></div>
                                </div>
                                <div class="ord-pills">
                                    <?php if ($isOverdue): ?><span class="priority-pill overdue">Overdue</span><?php endif; ?>
                                    <span class="priority-pill"><?= esc($priorityLabel) ?></span>
                                </div>
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
                                <?php // Asks the supplier for an update; once per order per day (StockOrderController::flagDelayed). ?>
                                <form method="post" class="ord-follow" action="<?= site_url('admin/orders/flag-delayed/' . $o['so_id']) ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit"<?= $followedUp ? ' disabled aria-describedby="followed-' . esc($o['so_id'], 'attr') . '"' : '' ?>>Follow up</button>
                                    <?php if ($followedUp): ?><span class="ord-followed" id="followed-<?= esc($o['so_id'], 'attr') ?>">Followed up today</span><?php endif; ?>
                                </form>
                                <?php if (\App\Models\StockOrderModel::canCancel($o)): ?>
                                    <button type="button" class="cancel-order" data-cancel-order
                                            data-action="<?= site_url('admin/orders/cancel/' . $o['so_id']) ?>"
                                            data-so="<?= esc($o['so_id'], 'attr') ?>"
                                            data-supplier="<?= esc($o['company_name'] ?? '', 'attr') ?>"
                                            data-items="<?= count($o['lines'] ?? []) ?>"
                                            data-status="<?= esc($o['status'], 'attr') ?>">Cancel</button>
                                <?php endif; ?>
                            </div>
                            <?php if ($o['status'] === 'Shipped'): ?>
                                <p class="ord-note">Already shipped. Contact the supplier to refuse or return it on delivery.</p>
                            <?php endif; ?>
                        </div>

                        <?php if ($o['status'] === 'Shipped'): ?>
                        <div class="inv-modal" id="confirmModal-<?= esc($o['so_id']) ?>">
                            <div class="inv-modal-card" style="max-width:560px;">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h3 class="mb-0">Confirm Delivery &mdash; #<?= esc($o['so_id']) ?></h3>
                                    <button type="button" class="btn-close" onclick="this.closest('.inv-modal').classList.remove('show')"></button>
                                </div>
                                <p class="subtitle">Tick each item that arrived. It should match what the supplier shipped.</p>
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
                <div class="ord-col-label">Order Calendar</div>
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
                                <td>
                                    <span class="status-pill <?= $o['status'] === 'Delivered' ? 'green' : 'red' ?>"><?= esc($o['status']) ?></span>
                                    <?php if ($note = \App\Models\StockOrderModel::cancelNote($o)): ?>
                                        <span class="hist-note"><?= esc($note) ?></span>
                                        <a class="hist-note" href="<?= site_url('admin/orders?reorder=' . $o['so_id']) ?>">Reorder</a>
                                    <?php endif; ?>
                                </td>
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

<?php // "Add New Order" and "Reorder": the shared purchase order dialog, empty start (supplier first, then its items). ?>
<?= view('partials/_purchase_order_dialog', ['suppliers' => $suppliers]) ?>
<script type="application/json" id="orderCatalog"><?= json_encode((object) ($orderCatalog ?? []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script>
(function () {
    const catalog = JSON.parse(document.getElementById('orderCatalog').textContent);
    const openOrder = (lines, extra = {}) => window.PurchaseOrderDialog && window.PurchaseOrderDialog.open(lines, Object.assign({
        mode: 'empty', showPriority: true, returnTo: '/admin/orders', catalog,
    }, extra));

    document.getElementById('openAddModal').addEventListener('click', e => openOrder([], { opener: e.currentTarget }));

    // "Reorder" from a cancelled order: same supplier, items, quantities, unit prices and priority.
    // Nothing is created until the owner submits.
    const reorderPrefill = <?= json_encode($reorderPrefill ?? null, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    if (reorderPrefill) {
        window.addEventListener('DOMContentLoaded', () => openOrder(reorderPrefill.lines, {
            supplierId: reorderPrefill.supplier_id,
            priority: reorderPrefill.priority,
            warning: reorderPrefill.warning,
        }));
    }
})();
</script>

<?= view('admin/_cancel_order_modal') ?>

<?= $this->endSection() ?>
