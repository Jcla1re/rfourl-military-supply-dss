
<?= $this->extend('layouts/supplier_layout') ?>
<?= $this->section('content') ?>

<?php $orders = $orders ?? []; $range = $range ?? 'month'; ?>

<style>
.cp-tabs { display: flex; gap: 10px; margin-bottom: 20px; }
.cp-tabs a { padding: 10px 18px; border-radius: 8px; border: 1px solid #ddd; background: #fff; text-decoration: none; color: #1c1c1c; font-weight: 600; }
.cp-tabs a.active { background: var(--sidebar-bg); color: #fff; border-color: var(--sidebar-bg); }
.cp-stat { background: #fff; border-radius: 14px; padding: 18px; display: flex; align-items: center; gap: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.06); height: 100%; }
.cp-stat .icon-box { width: 44px; height: 44px; border-radius: 10px; background: var(--green-bg); color: var(--green-text); display: flex; align-items: center; justify-content: center; }
.cp-stat .value { font-size: 24px; font-weight: 800; }
</style>

<div class="page-wrap">
    <div class="cp-tabs">
        <a href="?range=month" class="<?= $range === 'month' ? 'active' : '' ?>">This Month</a>
        <a href="?range=3months" class="<?= $range === '3months' ? 'active' : '' ?>">Last 3 Months</a>
        <a href="?range=all" class="<?= $range === 'all' ? 'active' : '' ?>">All Time</a>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="cp-stat"><span class="icon-box"><i class="bi bi-check-lg"></i></span><div><div class="value"><?= esc($completedCount ?? 0) ?></div><div class="text-muted small">Completed Orders</div></div></div></div>
        <div class="col-md-4"><div class="cp-stat"><span class="icon-box"><i class="bi bi-box-seam"></i></span><div><div class="value"><?= number_format($itemsDelivered ?? 0) ?></div><div class="text-muted small">Items Delivered</div></div></div></div>
        <div class="col-md-4"><div class="cp-stat"><span class="icon-box"><i class="bi bi-clock-history"></i></span><div><div class="value"><?= esc($onTimeRate ?? 0) ?>%</div><div class="text-muted small">On-Time Rate (<?= esc($onTimeCount ?? 0) ?>/<?= count($orders) ?>)</div></div></div></div>
    </div>

    <div class="page-panel">
        <table class="data-table">
            <thead><tr><th>Order ID</th><th>Items</th><th>Units</th><th>Delivered</th><th>Status</th></tr></thead>
            <tbody>
                <?php if (!empty($orders)): ?>
                    <?php foreach ($orders as $o): ?>
                        <tr>
                            <td><strong>#<?= esc($o['so_id']) ?></strong></td>
                            <td><?php foreach ($o['lines'] as $l): ?><?= esc($l['item_name'] ?? $l['item_id']) ?><?= end($o['lines']) === $l ? '' : ', ' ?><?php endforeach; ?></td>
                            <td><?= esc($o['total_units']) ?></td>
                            <td><?= esc(date('M j, Y', strtotime($o['actual_delivery_date']))) ?></td>
                            <td>
                                <?php if ($o['on_time']): ?>
                                    <span class="status-pill green">On Time</span>
                                <?php else: ?>
                                    <span class="status-pill red"><?= $o['days_late'] >= 7 ? round($o['days_late'] / 7) . ' week' . (round($o['days_late'] / 7) === 1 ? '' : 's') : $o['days_late'] . ' day' . ($o['days_late'] === 1 ? '' : 's') ?> late</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="text-center py-4">No completed deliveries in this range.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
