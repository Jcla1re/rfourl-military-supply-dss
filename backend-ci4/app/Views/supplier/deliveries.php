
<?= $this->extend('layouts/supplier_layout') ?>
<?= $this->section('content') ?>

<?php $orders = $orders ?? []; ?>

<style>
.dv-card { background: #fff; border-radius: 14px; margin-bottom: 18px; overflow: hidden; }
.dv-head { display: flex; align-items: center; gap: 10px; padding: 16px 20px; }
.dv-head .badge-pill { background: #e9e7e1; border-radius: 999px; padding: 5px 14px; font-size: 12px; font-weight: 700; }
.dv-head .badge-pill.preparing { background: var(--amber-bg); color: var(--amber-text); }
.dv-head .badge-pill.shipped { background: var(--green-bg); color: var(--green-text); }
.dv-head .dispatched { margin-left: auto; color: #888; font-size: 12px; }
.dv-body { padding: 0 20px 20px; }

.dv-stepper-track { height: 8px; background: #f0dede; border-radius: 4px; margin: 14px 0 8px; overflow: hidden; }
.dv-stepper-track > span { display: block; height: 100%; background: var(--green-text); }
.dv-stepper-labels { display: flex; justify-content: space-between; font-size: 12px; color: #555; }
.dv-stepper-labels .done { color: var(--green-text); font-weight: 700; }

.dv-info-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin: 16px 0; }
.dv-info-box { border: 1px solid #eee; border-radius: 8px; padding: 10px 14px; }
.dv-info-box span { display: block; font-size: 12px; color: #888; }
.dv-info-box input { border: none; background: transparent; font-weight: 600; width: 100%; padding: 0; }
</style>

<div class="page-wrap">
    <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>

    <?php if (empty($orders)): ?>
        <div class="empty-state">No active shipments right now.</div>
    <?php else: ?>
        <?php foreach ($orders as $o): ?>
            <?php
            $stepIndex = $o['status'] === 'Shipped' ? 2 : 1;
            $pct = $stepIndex === 1 ? 50 : 75;
            $isOverdue = !empty($o['expected_delivery_date']) && strtotime($o['expected_delivery_date']) < strtotime('today');
            ?>
            <div class="dv-card">
                <div class="dv-head">
                    <span class="badge-pill <?= $o['status'] === 'Preparing' ? 'preparing' : 'shipped' ?>"><?= $o['status'] === 'Preparing' ? 'Preparing' : 'In Transit' ?></span>
                    <span class="fw-bold">#<?= esc($o['so_id']) ?></span>
                    <?php if ($isOverdue): ?><span class="badge-pill" style="background:var(--red-bg); color:var(--red-text);">OVERDUE</span>
                    <?php elseif ($o['expected_delivery_date'] === date('Y-m-d')): ?><span class="badge-pill" style="background:var(--green-bg); color:var(--green-text);">DUE TODAY</span><?php endif; ?>
                    <span class="dispatched">Delivery due: <?= !empty($o['expected_delivery_date']) ? esc(date('M j, Y', strtotime($o['expected_delivery_date']))) : '—' ?></span>
                </div>
                <div class="dv-body">
                    <div><strong>Item ordered:</strong> <?php foreach ($o['lines'] as $l): ?><?= esc($l['item_name'] ?? $l['item_id']) ?> &times; <?= esc($l['order_quantity']) ?> <?php endforeach; ?> (<?= esc($o['total_units']) ?> total)</div>

                    <div class="dv-stepper-track"><span style="width: <?= $pct ?>%"></span></div>
                    <div class="dv-stepper-labels">
                        <span class="done">&check; Order confirmed</span>
                        <span class="<?= $stepIndex >= 1 ? 'done' : '' ?>"><?= $stepIndex >= 1 ? '&check;' : '' ?> Preparing</span>
                        <span class="<?= $stepIndex >= 2 ? 'done' : '' ?>"><?= $stepIndex >= 2 ? '&check;' : '&bull;' ?> Shipped out</span>
                        <span>Delivered</span>
                    </div>

                    <?php if ($o['status'] === 'Preparing'): ?>
                        <form method="post" action="<?= site_url('supplier/deliveries/ship/' . $o['so_id']) ?>">
                            <?= csrf_field() ?>
                            <div class="dv-info-row">
                                <div class="dv-info-box"><span>Delivery Due</span><strong><?= !empty($o['expected_delivery_date']) ? esc(date('M j, Y', strtotime($o['expected_delivery_date']))) : '—' ?></strong></div>
                                <div class="dv-info-box"><span>Tracking No.</span><input name="tracking_no" value="<?= esc($o['tracking_no'] ?? '') ?>" placeholder="e.g. TRK-2026-0001"></div>
                            </div>
                            <button type="submit" class="btn btn-success">Shipped out</button>
                        </form>
                    <?php else: ?>
                        <div class="dv-info-row">
                            <div class="dv-info-box"><span>Delivery Due</span><strong><?= !empty($o['expected_delivery_date']) ? esc(date('M j, Y', strtotime($o['expected_delivery_date']))) : '—' ?></strong></div>
                            <div class="dv-info-box"><span>Tracking No.</span><strong><?= esc($o['tracking_no'] ?? '—') ?></strong></div>
                        </div>
                        <form method="post" action="<?= site_url('supplier/deliveries/deliver/' . $o['so_id']) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-success">Mark as delivered</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
