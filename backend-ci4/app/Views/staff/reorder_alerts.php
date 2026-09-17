
<?= $this->extend('layouts/staff_layout') ?>

<?= $this->section('header_actions') ?>
<span class="text-white-50 small me-2">Last updated: <?= esc($lastUpdated ?? 'just now') ?></span>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<?php $alerts = $alerts ?? []; $dss = $dss ?? []; ?>

<style>
.ra-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 20px; }
@media (max-width: 900px) { .ra-summary { grid-template-columns: 1fr; } }

.ra-empty { padding: 70px 20px; border: 1px dashed #c9c9c9; border-radius: 12px; text-align: center; color: #666; font-size: 16px; }

.ra-card { background: #fff; border: 1px solid #e2e2e2; border-radius: 14px; margin-top: 16px; overflow: hidden; }
.ra-card-head { display: flex; align-items: center; gap: 16px; padding: 18px 20px; border-bottom: 1px solid #eee; }
.ra-card-head .icon { font-size: 26px; }
.ra-card-head.critical .icon { color: var(--accent-maroon); }
.ra-card-head.warning .icon { color: #d39b28; }
.ra-card-head h4 { margin: 0; font-weight: 800; font-size: 19px; }
.ra-card-head span.sub { display: block; color: #555; font-size: 14px; }
.ra-card-head .notify-btn { margin-left: auto; border: 0; border-radius: 8px; padding: 10px 18px; background: #1c1c1c; color: #fff; font-weight: 700; cursor: pointer; }

.ra-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; padding: 18px 20px 0; }
.ra-stat-box { border: 1px solid #e2e2e2; border-radius: 10px; padding: 14px; text-align: center; }
.ra-stat-box span { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #777; }
.ra-stat-box strong { display: block; font-size: 26px; margin-top: 4px; }
.ra-stat-box small { color: #888; font-size: 12px; }
.ra-stat-box.stock strong { color: var(--accent-maroon); }
.ra-stat-box.eoq strong { color: #a08217; }

.ra-basis { margin: 18px 20px 20px; border: 1px solid #e2e2e2; border-radius: 10px; padding: 16px 18px; }
.ra-basis strong.title { display: block; margin-bottom: 10px; }
.ra-basis-grid { display: flex; flex-wrap: wrap; gap: 24px; font-size: 14px; }
.ra-basis-grid b { margin-left: 6px; }

@media (max-width: 700px) { .ra-stats { grid-template-columns: 1fr; } }
</style>

<div class="page-wrap">
    <div class="page-panel">
        <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>

        <div class="ra-summary">
            <div class="icon-stat-card">
                <span class="icon-box"><i class="bi bi-exclamation-triangle"></i></span>
                <div><span>Critical (At ROP)</span><strong><?= esc($criticalCount ?? 0) ?></strong></div>
            </div>
            <div class="icon-stat-card">
                <span class="icon-box"><i class="bi bi-lightning-fill"></i></span>
                <div><span>Low Stock Warning</span><strong><?= esc($lowStockCount ?? 0) ?></strong></div>
            </div>
            <div class="icon-stat-card">
                <span class="icon-box"><i class="bi bi-check-lg"></i></span>
                <div><span>Received This Week</span><strong><?= esc($receivedCount ?? 0) ?></strong></div>
            </div>
        </div>

        <?php if (empty($alerts)): ?>
            <div class="ra-empty">No reorder alerts right now. Alerts appear automatically once stock falls at or below an item's Reorder Point (ROP).</div>
        <?php else: ?>
            <?php foreach ($alerts as $alert): ?>
                <?php
                $isCritical = (int) $alert['stock_at_trigger'] <= 0 || $alert['trigger_reason'] === 'At ROP';
                $computation = $alert['computation'] ?? null;
                ?>
                <div class="ra-card">
                    <div class="ra-card-head <?= $isCritical ? 'critical' : 'warning' ?>">
                        <span class="icon"><i class="bi <?= $isCritical ? 'bi-exclamation-triangle' : 'bi-exclamation-circle' ?>"></i></span>
                        <div>
                            <h4><?= $isCritical ? 'CRITICAL — Reorder Point Reached' : 'WARNING — Approaching Reorder' ?></h4>
                            <span class="sub"><?= esc($alert['item_name'] ?? 'Unknown item') ?><?= !empty($alert['size']) ? ' - Size ' . esc($alert['size']) : '' ?></span>
                        </div>
                        <form method="post" action="<?= site_url('staff/reorder-alerts/notify/' . $alert['alert_id']) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="notify-btn">Notify Admin</button>
                        </form>
                    </div>

                    <div class="ra-stats">
                        <div class="ra-stat-box stock">
                            <span>Current Stock</span>
                            <strong><?= esc($alert['stock_at_trigger'] ?? $alert['current_stock'] ?? 0) ?></strong>
                            <small>units</small>
                        </div>
                        <div class="ra-stat-box eoq">
                            <span>EOQ Suggest</span>
                            <strong><?= esc($alert['recommended_eoq'] ?? '—') ?></strong>
                            <small>units to order</small>
                        </div>
                        <div class="ra-stat-box">
                            <span>Lead Time</span>
                            <strong><?= esc($alert['lead_time_days'] ?? '—') ?></strong>
                            <small>days (<?= esc($alert['supplier_name'] ?? 'Unassigned') ?>)</small>
                        </div>
                    </div>

                    <div class="ra-basis">
                        <strong class="title">Probabilistic Basis</strong>
                        <div class="ra-basis-grid">
                            <span>Avg Daily Demand:<b><?= esc($computation['avg_daily_demand'] ?? '—') ?></b></span>
                            <span>Service Level:<b><?= esc($dss['service_level_target'] ?? '—') ?>%</b></span>
                            <span>Safety Stock:<b><?= esc($computation['safety_stock'] ?? '—') ?></b></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?= $this->endSection() ?>
