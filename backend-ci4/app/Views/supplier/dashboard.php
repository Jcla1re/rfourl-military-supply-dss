
<?= $this->extend('layouts/supplier_layout') ?>
<?= $this->section('content') ?>

<?php $alerts = $alerts ?? []; $schedule = $schedule ?? []; $pendingOrders = $pendingOrders ?? []; ?>

<style>
.sd-alert { background: #fff; border-radius: 12px; padding: 16px 20px; display: flex; align-items: center; gap: 16px; margin-bottom: 14px; }
.sd-alert .icon { font-size: 24px; }
.sd-alert .title { font-weight: 800; }
.sd-alert .sub { color: #666; font-size: 13px; }
.sd-alert form { margin-left: auto; }

.sd-stat { background: #fff; border-radius: 14px; padding: 16px 18px; display: flex; align-items: center; gap: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.06); height: 100%; }
.sd-stat .icon-box { width: 44px; height: 44px; border-radius: 10px; background: var(--green-bg); display: flex; align-items: center; justify-content: center; font-size: 18px; color: var(--green-text); flex-shrink: 0; }
.sd-stat .value { font-size: 24px; font-weight: 800; }
.sd-stat .label { color: #555; font-size: 13px; }

.sd-panel { background: #fff; border-radius: 14px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.06); height: 100%; }
.sd-panel h6 { font-weight: 700; margin-bottom: 14px; }

.sd-notif-card { border: 1px solid #eee; border-radius: 10px; padding: 14px; margin-bottom: 12px; }
.sd-notif-card .icon-badge { width: 34px; height: 34px; border-radius: 8px; background: var(--red-bg); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.sd-notif-card .title { font-weight: 700; }
.sd-notif-card .meta { color: #888; font-size: 12px; margin-top: 6px; }

.sd-schedule-row { display: flex; align-items: flex-start; gap: 10px; padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
.sd-schedule-row:last-child { border-bottom: none; }
.sd-schedule-dot { width: 10px; height: 10px; border-radius: 50%; background: var(--green-text); margin-top: 6px; flex-shrink: 0; }
.sd-schedule-row .badge-pill { margin-left: auto; background: var(--green-bg); color: var(--green-text); border-radius: 999px; padding: 4px 10px; font-size: 11px; font-weight: 700; align-self: center; }
.sd-schedule-row .badge-pill.pending { background: #e9e7e1; color: #4a4a4a; }

.sd-perf-track { height: 10px; background: #e4e2dc; border-radius: 5px; overflow: hidden; margin-top: 6px; }
.sd-perf-track > span { display: block; height: 100%; background: var(--green-text); }
</style>

<div class="page-wrap">
    <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>

    <?php foreach ($alerts as $a): ?>
        <div class="sd-alert" style="border-left: 4px solid <?= $a['expected_delivery_date'] === $today ? 'var(--accent-maroon)' : '#d39b28' ?>;">
            <span class="icon"><i class="bi <?= $a['expected_delivery_date'] === $today ? 'bi-exclamation-triangle text-danger' : 'bi-clock text-warning' ?>"></i></span>
            <div>
                <div class="title">
                    <?= $a['expected_delivery_date'] === $today ? 'Delivery Due TODAY' : 'Delivery Due Soon' ?> &mdash; Order #<?= esc($a['so_id']) ?>
                </div>
                <div class="sub"><?= esc($a['total_units']) ?> items scheduled for delivery (<?= esc(date('M j', strtotime($a['expected_delivery_date']))) ?>).</div>
            </div>
            <?php if ($a['status'] === 'Order Confirmed'): ?>
                <form method="post" action="<?= site_url('supplier/new-orders/accept/' . $a['so_id']) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-dark btn-sm">Accept Order</button>
                </form>
            <?php elseif ($a['status'] === 'Preparing'): ?>
                <form method="post" action="<?= site_url('supplier/deliveries/ship/' . $a['so_id']) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-success btn-sm">Shipped</button>
                </form>
            <?php else: ?>
                <form method="post" action="<?= site_url('supplier/deliveries/deliver/' . $a['so_id']) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-success btn-sm">Mark Delivered</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="row g-3 mb-3">
        <div class="col-md-3 col-6"><div class="sd-stat"><span class="icon-box"><i class="bi bi-clipboard"></i></span><div><div class="value"><?= esc($newOrdersCount ?? 0) ?></div><div class="label">New Orders</div></div></div></div>
        <div class="col-md-3 col-6"><div class="sd-stat"><span class="icon-box"><i class="bi bi-truck"></i></span><div><div class="value"><?= esc($inTransitCount ?? 0) ?></div><div class="label">In Transit</div></div></div></div>
        <div class="col-md-3 col-6"><div class="sd-stat"><span class="icon-box"><i class="bi bi-check-lg"></i></span><div><div class="value"><?= esc($completedMonth ?? 0) ?></div><div class="label">Completed (Month)</div></div></div></div>
        <div class="col-md-3 col-6"><div class="sd-stat"><span class="icon-box"><i class="bi bi-box-seam"></i></span><div><div class="value"><?= number_format($totalUnits ?? 0) ?></div><div class="label">Total Units Supplied</div></div></div></div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="sd-panel mb-3">
                <h6><i class="bi bi-envelope"></i> Incoming Order Notification</h6>
                <?php if (empty($pendingOrders)): ?>
                    <div class="empty-state">No new orders right now.</div>
                <?php else: ?>
                    <?php foreach (array_slice($pendingOrders, 0, 3) as $o): ?>
                        <div class="sd-notif-card d-flex gap-3">
                            <span class="icon-badge"><i class="bi bi-exclamation-lg text-danger"></i></span>
                            <div class="flex-grow-1">
                                <div class="title">New <?= $o['priority'] === 'Urgent' ? 'Urgent ' : '' ?>Order &mdash; #<?= esc($o['so_id']) ?></div>
                                <div class="small">RfourL Military Apparel has placed a new stock order.</div>
                                <div class="small"><strong>Items:</strong> <?php foreach ($o['lines'] as $l): ?><?= esc($l['item_name'] ?? $l['item_id']) ?> &times;<?= esc($l['order_quantity']) ?> <?php endforeach; ?></div>
                                <div class="small"><strong>Requested delivery:</strong> <?= !empty($o['expected_delivery_date']) ? esc(date('M j, Y', strtotime($o['expected_delivery_date']))) : '—' ?></div>
                                <div class="d-flex gap-2 mt-2">
                                    <form method="post" action="<?= site_url('supplier/new-orders/accept/' . $o['so_id']) ?>"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-success">Accept</button></form>
                                    <form method="post" action="<?= site_url('supplier/new-orders/decline/' . $o['so_id']) ?>"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-danger">Decline</button></form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="sd-panel">
                <h6>My Performance</h6>
                <div class="d-flex justify-content-between small"><span>On Time Delivery Rate</span><strong><?= esc($onTimeRate ?? 0) ?>%</strong></div>
                <div class="sd-perf-track"><span style="width: <?= esc($onTimeRate ?? 0) ?>%"></span></div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="sd-panel">
                <h6><i class="bi bi-calendar3"></i> My Delivery Schedule</h6>
                <?php if (empty($schedule)): ?>
                    <div class="empty-state">Nothing scheduled.</div>
                <?php else: ?>
                    <?php foreach ($schedule as $s): ?>
                        <div class="sd-schedule-row">
                            <span class="sd-schedule-dot"></span>
                            <div>
                                <strong>#<?= esc($s['so_id']) ?> &mdash; <?= esc($s['status']) ?></strong><br>
                                <span class="small text-muted">Due: <?= !empty($s['expected_delivery_date']) ? esc(date('M j', strtotime($s['expected_delivery_date']))) : '—' ?> &middot; <?= esc($s['total_units']) ?> items</span>
                            </div>
                            <span class="badge-pill <?= $s['expected_delivery_date'] === $today ? '' : 'pending' ?>"><?= $s['expected_delivery_date'] === $today ? 'TODAY' : strtoupper($s['status'] === 'Order Confirmed' ? 'PENDING' : $s['status']) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
