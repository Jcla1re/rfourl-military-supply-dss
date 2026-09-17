
<?= $this->extend('layouts/supplier_layout') ?>
<?= $this->section('content') ?>

<?php $orders = $orders ?? []; $filter = $filter ?? 'all'; ?>

<style>
.no-filters { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px; }
.no-filters a { padding: 10px 18px; border-radius: 8px; border: 1px solid #ddd; background: #fff; text-decoration: none; color: #1c1c1c; font-weight: 600; }
.no-filters a.active { background: var(--sidebar-bg); color: #fff; border-color: var(--sidebar-bg); }
.no-filters input { padding: 10px 14px; border-radius: 8px; border: 1px solid #ddd; flex: 1; min-width: 200px; }

.no-card { background: #fff; border-radius: 14px; margin-bottom: 16px; overflow: hidden; }
.no-card-head { display: flex; align-items: center; gap: 10px; padding: 16px 20px; background: #f4f2ec; }
.no-card-head .badge-pill { background: #e9e7e1; border-radius: 999px; padding: 5px 14px; font-size: 12px; font-weight: 700; }
.no-card-head .badge-pill.urgent { background: var(--red-bg); color: var(--red-text); }
.no-card-body { padding: 18px 20px; }
.no-card-actions { display: flex; justify-content: flex-end; gap: 10px; padding: 0 20px 18px; }
</style>

<div class="page-wrap">
    <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>

    <div class="no-filters">
        <a href="?filter=all" class="<?= $filter === 'all' ? 'active' : '' ?>">All (<?= count($orders) ?>)</a>
        <a href="?filter=urgent" class="<?= $filter === 'urgent' ? 'active' : '' ?>">Urgent</a>
        <form method="get" style="flex:1; display:flex;">
            <input type="hidden" name="filter" value="<?= esc($filter) ?>">
            <input type="search" name="search" value="<?= esc($search) ?>" placeholder="Search orders...">
        </form>
    </div>

    <?php if (empty($orders)): ?>
        <div class="empty-state">No new orders awaiting your response.</div>
    <?php else: ?>
        <?php foreach ($orders as $o): ?>
            <div class="no-card">
                <div class="no-card-head">
                    <?php if ($o['priority'] === 'Urgent'): ?><span class="badge-pill urgent">Urgent</span><?php endif; ?>
                    <span class="fw-bold">#<?= esc($o['so_id']) ?></span>
                    <span class="badge-pill">Awaiting Confirmation</span>
                </div>
                <div class="no-card-body">
                    <div><strong>Item ordered:</strong> <?php foreach ($o['lines'] as $l): ?><?= esc($l['item_name'] ?? $l['item_id']) ?> &times; <?= esc($l['order_quantity']) ?> items&nbsp; <?php endforeach; ?></div>
                    <div class="mt-1">
                        <strong>Requested delivery:</strong> <?= !empty($o['expected_delivery_date']) ? esc(date('M j, Y', strtotime($o['expected_delivery_date']))) : '—' ?>
                        &nbsp;<strong>Total items:</strong> <?= esc($o['total_units']) ?>
                        &nbsp;<strong>Priority:</strong> <?= esc($o['priority']) ?>
                    </div>
                </div>
                <div class="no-card-actions">
                    <form method="post" action="<?= site_url('supplier/new-orders/decline/' . $o['so_id']) ?>" onsubmit="return confirm('Decline this order?');">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn" style="background: var(--red-bg); color: var(--red-text); font-weight:700;">Decline</button>
                    </form>
                    <form method="post" action="<?= site_url('supplier/new-orders/accept/' . $o['so_id']) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-success fw-bold">Accept Order</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
