
<?= $this->extend('layouts/staff_layout') ?>

<?= $this->section('content') ?>

<?php $types = $types ?? []; $logs = $logs ?? []; ?>

<style>
.lt-label { font-size: 12px; letter-spacing: .05em; color: #666; font-weight: 700; margin-bottom: 12px; }
.lt-types { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 28px; }
@media (max-width: 900px) { .lt-types { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) { .lt-types { grid-template-columns: 1fr; } }
.lt-type-card {
    background: #fff; border: 1px solid #e2e2e2; border-radius: 12px; padding: 22px 16px; text-align: center;
    text-decoration: none; color: #1c1c1c; transition: box-shadow .15s;
}
.lt-type-card:hover { box-shadow: 0 2px 10px rgba(0,0,0,.08); color: #1c1c1c; }
.lt-type-card .icon { font-size: 26px; margin-bottom: 10px; }
.lt-type-card .name { font-weight: 700; }
.lt-type-card .sub { color: #888; font-size: 12px; margin-top: 4px; }

.lt-log { background: #fff; border-radius: 12px; padding: 16px 20px; display: flex; gap: 14px; margin-bottom: 12px; }
.lt-log .dot { width: 34px; height: 34px; border-radius: 50%; background: #ececec; flex-shrink: 0; }
.lt-log .title { font-weight: 700; }
.lt-log .badge-pill { background: #e9e7e1; border-radius: 999px; padding: 3px 10px; font-size: 11px; font-weight: 700; margin-left: 8px; }
.lt-log .meta { color: #888; font-size: 12px; margin-top: 3px; }
</style>

<div class="page-wrap">
    <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>

    <div class="lt-label">SELECT TRANSACTION TYPE</div>
    <div class="lt-types">
        <?php
        $icons = ['restock' => 'bi-box-arrow-in-down', 'return' => 'bi-arrow-90deg-left', 'damaged' => 'bi-exclamation-triangle', 'adjustment' => 'bi-sliders'];
        $subs  = ['restock' => 'Supplier delivery arrived', 'return' => 'Item returned after inspection', 'damaged' => 'Remove from inventory', 'adjustment' => 'Correct after physical audit'];
        ?>
        <?php foreach ($types as $key => $t): ?>
            <a href="<?= site_url('staff/log-transaction/' . $key) ?>" class="lt-type-card">
                <div class="icon"><i class="bi <?= esc($icons[$key] ?? 'bi-clipboard') ?>"></i></div>
                <div class="name"><?= esc($t['label']) ?></div>
                <div class="sub"><?= esc($subs[$key] ?? '') ?></div>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="lt-label">RECENT LOGS</div>
    <?php if (empty($logs)): ?>
        <div class="empty-state">No transactions logged yet.</div>
    <?php else: ?>
        <?php foreach ($logs as $log): ?>
            <?php $qty = abs((int) $log['quantity_changed']); ?>
            <div class="lt-log">
                <span class="dot"></span>
                <div>
                    <div class="title">
                        <?= esc($log['item_name'] ?? $log['item_id']) ?> x <?= esc($qty) ?> item<?= $qty === 1 ? '' : 's' ?>
                        <span class="badge-pill"><?= esc($log['log_type']) ?></span>
                    </div>
                    <div class="meta">
                        <?= esc($log['notes'] ?? '') ?>
                        &middot; <?= esc(date('M j, g:i A', strtotime($log['timestamp']))) ?>
                        &middot; by <?= esc($log['staff_name'] ?? 'Unknown') ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
