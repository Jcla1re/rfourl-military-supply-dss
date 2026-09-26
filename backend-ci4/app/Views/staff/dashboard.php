<?php // app/Views/staff/dashboard.php ?>
<?= $this->extend('layouts/staff_layout') ?>

<?= $this->section('header_actions') ?>
<div style="min-width:260px;">
    <div class="input-group">
        <span class="input-group-text bg-white border-0"><i class="bi bi-search"></i></span>
        <input type="search" class="form-control border-0" placeholder="Quick Search">
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<style>
.dash-alert {
    background: #fff; border: 1px solid var(--accent-maroon); border-radius: 12px;
    padding: 18px 22px; display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 20px;
}
.dash-alert .icon { color: var(--accent-maroon); font-size: 28px; flex-shrink: 0; }
.dash-alert .title { font-weight: 800; font-size: 16px; }

.dash-stat {
    background: #fff; border-radius: 14px; padding: 20px 22px; display: flex; align-items: center; gap: 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,.06); height: 100%; text-decoration: none; color: inherit;
    transition: box-shadow 0.15s ease, transform 0.15s ease;
}
a.dash-stat:hover { box-shadow: 0 4px 10px rgba(0,0,0,.1); transform: translateY(-1px); cursor: pointer; }
.dash-stat .icon { font-size: 26px; flex-shrink: 0; }
.dash-stat .label { color: #444; font-size: 14px; }
.dash-stat .value { font-size: 26px; font-weight: 800; line-height: 1.15; }
.dash-stat .trend { margin-left: auto; text-align: right; font-size: 12px; color: #666; }
.dash-stat .trend .pct { color: #2f6431; font-weight: 700; }

.panel-box { background: #fff; border-radius: 14px; padding: 20px 22px; box-shadow: 0 1px 3px rgba(0,0,0,.06); height: 100%; }
.panel-box h6 { font-weight: 800; font-size: 15px; margin-bottom: 14px; }

.sold-row { display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 4px; }
.sold-track { height: 8px; background: #e4e2dc; border-radius: 4px; overflow: hidden; margin-bottom: 14px; }
.sold-track > span { display: block; height: 100%; background: var(--green-text); border-radius: 4px; }

.lowstock-row { display: flex; justify-content: space-between; align-items: center; padding: 9px 0; border-bottom: 1px solid #f2f2f2; }
.lowstock-row:last-child { border-bottom: none; }
.lowstock-row .name { font-weight: 600; font-size: 14px; }
.lowstock-row .stock { color: #888; font-size: 12px; }

.recent-row { display: flex; justify-content: space-between; align-items: center; padding: 9px 0; border-bottom: 1px solid #f2f2f2; }
.recent-row:last-child { border-bottom: none; }
.recent-row .name { font-weight: 700; font-size: 14px; }
.recent-row .sub { color: #888; font-size: 12px; }
.recent-row .amt { text-align: right; font-weight: 700; }
.recent-row .amt small { display: block; font-weight: 400; color: #888; font-size: 11px; }

.qa-btn { display: block; width: 100%; text-align: center; padding: 14px; border-radius: 10px; font-weight: 700; text-decoration: none; margin-bottom: 10px; }
.qa-btn.primary { background: #6b8f4e; color: #fff; }
.qa-btn.outline { background: #fff; border: 1px solid #ccc; color: #1c1c1c; }
</style>

<div class="page-wrap">
    <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>

    <?php if (!empty($alertItem)): ?>
    <div class="dash-alert">
        <div class="d-flex align-items-center gap-3">
            <span class="icon"><i class="bi bi-exclamation-triangle"></i></span>
            <div class="title">
                <?= esc($openAlertCount) ?> item<?= $openAlertCount === 1 ? '' : 's' ?> need<?= $openAlertCount === 1 ? 's' : '' ?> attention today.
                <?= esc($alertItem['item_name'] ?? 'An item') ?> has reached its Reorder Point.
            </div>
        </div>
        <form method="post" action="<?= site_url('staff/dashboard/notify') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="item_id" value="<?= esc($alertItem['item_id'] ?? '') ?>">
            <input type="hidden" name="item_name" value="<?= esc($alertItem['item_name'] ?? '') ?>">
            <button type="submit" class="btn btn-dark">Notify Admin</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <a href="<?= site_url('staff/inventory') ?>" class="dash-stat">
                <span class="icon"><i class="bi bi-box-seam"></i></span>
                <div><div class="label">Total Stock on hand</div><div class="value"><?= number_format($totalStock ?? 0) ?></div></div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="<?= site_url('staff/sales') . '?tab=receipts' ?>" class="dash-stat">
                <span class="icon"><i class="bi bi-cash-coin"></i></span>
                <div><div class="label">Sales Today</div><div class="value">₱<?= number_format($salesToday ?? 0, 0) ?></div></div>
                <div class="trend"><span class="pct">▲ <?= esc($salesTrendPct ?? '0%') ?></span><br>vs yesterday</div>
            </a>
        </div>
        <div class="col-md-4">
            <div class="dash-stat">
                <span class="icon"><i class="bi bi-calendar-check"></i></span>
                <div><div class="label">Transaction Today</div><div class="value"><?= esc($txnToday ?? 0) ?></div></div>
                <div class="trend"><?= ($txnDelta ?? 0) >= 0 ? '+' . esc($txnDelta) . ' more' : esc(abs($txnDelta)) . ' fewer' ?><br>than yesterday</div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="panel-box">
                <h6>Items Sold Today</h6>
                <?php if (empty($itemsSoldToday)): ?>
                    <div class="text-muted small">No sales recorded yet today.</div>
                <?php else: ?>
                    <?php foreach ($itemsSoldToday as $row): ?>
                        <div class="sold-row"><span><?= esc($row['item_name'] ?? $row['item_id']) ?> x<?= esc($row['qty']) ?></span><strong>₱<?= number_format($row['line_total'], 0) ?></strong></div>
                        <div class="sold-track"><span style="width: <?= round(($row['line_total'] / $maxLine) * 100) ?>%"></span></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="panel-box">
                <h6>Recent Transactions</h6>
                <?php if (empty($recentTransactions)): ?>
                    <div class="text-muted small">No transactions recorded yet.</div>
                <?php else: ?>
                    <?php foreach ($recentTransactions as $t): ?>
                        <div class="recent-row">
                            <div>
                                <div class="name"><?php foreach ($t['items'] as $l): ?><?= esc($l['item_name'] ?? $l['item_id']) ?> x<?= esc($l['quantity_sold']) ?> <?php endforeach; ?></div>
                                <div class="sub">Walk-in Customer</div>
                            </div>
                            <div class="amt">₱<?= number_format($t['total_amount'], 0) ?><small><?= esc(date('g:i A', strtotime($t['sale_date']))) ?></small></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="panel-box">
                <h6>Low Stock Items</h6>
                <?php if (empty($lowStockItems)): ?>
                    <div class="text-muted small">All items are healthily stocked.</div>
                <?php else: ?>
                    <?php foreach ($lowStockItems as $li): ?>
                        <div class="lowstock-row">
                            <div>
                                <div class="name"><?= esc($li['item_name']) ?></div>
                                <div class="stock">Stock: <?= esc($li['current_stock']) ?></div>
                            </div>
                            <span class="status-pill <?= $li['status_label'] === 'Critical' ? 'red' : 'amber' ?>"><?= esc($li['status_label']) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="panel-box">
                <h6>Quick Actions</h6>
                <a href="<?= site_url('staff/log-transaction') ?>" class="qa-btn primary">Log New Transaction</a>
                <a href="<?= site_url('staff/inventory') ?>" class="qa-btn outline">Check Inventory</a>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
