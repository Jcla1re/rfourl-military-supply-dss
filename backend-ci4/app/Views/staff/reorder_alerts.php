
<?= $this->extend('layouts/staff_layout') ?>

<?= $this->section('content') ?>

<?php $alerts = $alerts ?? []; $dss = $dss ?? []; ?>

<style>
.ra-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 20px; }
@media (max-width: 900px) { .ra-summary { grid-template-columns: 1fr; } }

.ra-empty { padding: 70px 20px; border: 1px dashed #c9c9c9; border-radius: var(--radius-card); text-align: center; color: #666; font-size: 16px; }

.ra-card { background: #fff; border: 1px solid #e2e2e2; border-radius: var(--radius-card); margin-top: 16px; overflow: hidden; }
.ra-card-head { display: flex; align-items: center; gap: 16px; padding: 18px 20px; border-bottom: 1px solid #eee; }
.ra-card-head .icon { font-size: 26px; }
.ra-card-head.critical .icon { color: var(--accent-maroon); }
.ra-card-head h4 { margin: 0; font-weight: 800; font-size: 19px; }
.ra-card-head span.sub { display: block; color: #555; font-size: 14px; }
.ra-card-head .notify-btn { margin-left: auto; border: 0; padding: 10px 18px; cursor: pointer; } /* colors: shared primary-button rules */
.ra-card-head .notify-done-box { margin-left: auto; text-align: right; }
.ra-card-head .notify-done-box small { display: block; margin-top: 4px; color: #5C6152; font-size: 12px; }
.ra-card-head .notify-btn:disabled { cursor: default; }

.ra-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; padding: 18px 20px 0; }
.ra-stat-box { border: 1px solid #e2e2e2; border-radius: var(--radius-control); padding: 14px; text-align: center; }
.ra-stat-box span { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #777; }
.ra-stat-box strong { display: block; font-size: 26px; margin-top: 4px; }
.ra-stat-box small { color: #888; font-size: 12px; }
.ra-stat-box.stock strong { color: var(--accent-maroon); }
.ra-stat-box.eoq strong { color: #a08217; }

.ra-basis { margin: 18px 20px 20px; border: 1px solid #e2e2e2; border-radius: var(--radius-control); padding: 16px 18px; }
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
                <div><span>At 0 units</span><strong><?= esc($counts['at_zero']) ?></strong></div>
            </div>
            <div class="icon-stat-card">
                <span class="icon-box"><i class="bi bi-lightning-fill"></i></span>
                <div>
                    <span>Reorder Now</span><strong><?= esc($counts['items']) ?></strong>
                    <small class="text-muted"><?= esc($counts['sizes']) ?> <?= $counts['sizes'] === 1 ? 'size' : 'sizes' ?></small>
                </div>
            </div>
            <div class="icon-stat-card">
                <span class="icon-box"><i class="bi bi-check-lg"></i></span>
                <div><span>Received This Week</span><strong><?= esc($receivedCount ?? 0) ?></strong></div>
            </div>
        </div>

        <p class="text-muted small mb-1"><?= esc($counts['needs']) ?> need action + <?= esc($counts['on_order']) ?> on order = <?= esc($counts['items']) ?> Reorder Now in Inventory.</p>
        <p class="text-muted small mb-3">What do these numbers mean? <?= help_tip('Reading these numbers', [
            'Reorder Now counts items, the same as Inventory. An item with several sizes counts once; the line under the number says how many sizes that is.',
            'At 0 units counts the sizes that need reordering and have nothing on hand.',
            'Need action plus on order adds up to Reorder Now in Inventory.',
            'Dismissed sizes still count. They stay hidden from the list until they are no longer Reorder Now.',
            'Low Stock items are not alerts yet.',
            'Items with no sales in 2 years are not counted.',
        ]) ?></p>
        <?php if ($dismissedCount > 0): ?>
            <p class="text-muted small mb-3">
                <?= esc($dismissedCount) ?> dismissed (<?= $showDismissed ? 'shown' : 'hidden' ?>) &middot;
                <a href="<?= site_url('staff/reorder-alerts') . ($showDismissed ? '' : '?dismissed=1') ?>"><?= $showDismissed ? 'Hide' : 'Show' ?></a>
            </p>
        <?php endif; ?>

        <?php $listed = array_merge($alerts, $dismissedAlerts); ?>
        <?php if (empty($listed)): ?>
            <div class="ra-empty"><i class="bi bi-check-circle" aria-hidden="true"></i> <?= $counts['items'] > 0 ? 'Nothing to act on here right now.' : 'All stocked up. Nothing to reorder.' ?> <a href="<?= site_url('staff/inventory') ?>">View Inventory</a></div>
        <?php else: ?>
            <?php foreach ($listed as $alert): ?>
                <?php
                $computation = $alert['computation'] ?? null;
                ?>
                <div class="ra-card">
                    <div class="ra-card-head critical">
                        <span class="icon"><i class="bi bi-exclamation-triangle"></i></span>
                        <div>
                            <h4>Reorder Now — order now</h4>
                            <span class="sub"><?= esc($alert['item_name'] ?? 'Unknown item') ?><?= !empty($alert['size']) ? ' - Size ' . esc($alert['size']) : '' ?></span>
                        </div>
                        <?php $notice = $notices[$alert['item_id']] ?? null; ?>
                        <?php if ($notice !== null): ?>
                            <?php /* Already notified for this size (any staff account or page): no second notification. */ ?>
                            <div class="notify-done-box">
                                <button type="button" class="notify-btn" disabled>Owner notified</button>
                                <small><?= esc(\App\Models\ReorderNoticeModel::summary($notice)) ?></small>
                            </div>
                        <?php else: ?>
                        <form method="post" action="<?= site_url('staff/reorder-alerts/notify/' . $alert['alert_id']) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="notify-btn">Notify Owner</button>
                        </form>
                        <?php endif; ?>
                    </div>

                    <div class="ra-stats">
                        <div class="ra-stat-box stock">
                            <span>Current Stock</span>
                            <strong><?= esc($alert['current_stock'] ?? 0) ?></strong>
                            <small>units</small>
                        </div>
                        <div class="ra-stat-box eoq">
                            <span><?= \App\Libraries\Glossary::labelWithTip('order_qty') ?></span>
                            <strong><?= esc($alert['recommended_eoq'] ?? '—') ?></strong>
                            <small>units to order</small>
                        </div>
                        <div class="ra-stat-box">
                            <span>Delivery time</span>
                            <strong><?= esc($alert['lead_time_days'] ?? '—') ?></strong>
                            <small>days (<?= esc($alert['supplier_name'] ?? 'Unassigned') ?>)</small>
                        </div>
                    </div>

                    <div class="ra-basis">
                        <strong class="title">How this was worked out <?= help_tip('How this was worked out', [
                                'Daily sales: the average number sold each day.',
                                'Availability target: how often you want it in stock.',
                                'Safety Stock: extra units kept for busy days.',
                            ]) ?></strong>
                        <div class="ra-basis-grid">
                            <span>Daily sales:<b><?= esc($computation['avg_daily_demand'] ?? '—') ?></b></span>
                            <span>Availability target:<b><?= esc($alert['service_level'] ?? $dss['service_level_target'] ?? '—') ?>%<?= $alert['abc_class'] ? ' (' . esc($alert['abc_class']) . ' – ' . esc(['A' => 'Best seller', 'B' => 'Steady', 'C' => 'Slow'][$alert['abc_class']] ?? '') . ')' : '' ?></b></span>
                            <span>Safety Stock <?= \App\Libraries\Glossary::tip('safety_stock') ?>:<b><?= esc($computation['safety_stock'] ?? '—') ?></b></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?= $this->endSection() ?>
