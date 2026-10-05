
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

.dv-stepper-track { height: 8px; background: #e4e2dc; border-radius: 4px; margin: 14px 0 8px; overflow: hidden; }
.dv-stepper-track > span { display: block; height: 100%; background: var(--green-text); }
.dv-stepper-labels { display: flex; justify-content: space-between; font-size: 12px; color: #555; }
.dv-stepper-labels .done { color: var(--green-text); font-weight: 700; }

.dv-info-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin: 16px 0; }
.dv-info-box { border: 1px solid #eee; border-radius: 8px; padding: 10px 14px; }
.dv-info-box span { display: block; font-size: 12px; color: #888; }
.dv-info-box input { border: none; background: transparent; font-weight: 600; width: 100%; padding: 0; }

.dv-items-table { width: 100%; border-collapse: collapse; margin: 14px 0; }
.dv-items-table th { text-align: left; font-size: 11px; text-transform: uppercase; color: #888; padding: 6px 8px; border-bottom: 1px solid #e2e2e2; }
.dv-items-table td { padding: 8px; border-bottom: 1px solid #f2f2f2; font-size: 14px; vertical-align: middle; }
.dv-items-table td.num, .dv-items-table th.num { text-align: right; }
.dv-items-table td.check, .dv-items-table th.check { text-align: center; width: 70px; }
.dv-items-table input[type="number"] { width: 80px; padding: 6px 8px; border: 1px solid #ccc; border-radius: 6px; text-align: right; }
.dv-items-table input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
.dv-items-table tfoot td { font-weight: 700; border-top: 2px solid #ddd; border-bottom: none; }
.dv-awaiting-note { color: #888; font-size: 13px; font-style: italic; margin-top: 4px; }
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
                            <table class="dv-items-table">
                                <thead>
                                    <tr><th>Item</th><th class="num">Ordered</th><th class="num">Qty to Ship</th><th class="num">Unit Price</th><th class="num">Line Total</th><th class="check">Verified</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($o['lines'] as $l): ?>
                                        <tr>
                                            <td><?= esc($l['item_name'] ?? $l['item_id']) ?></td>
                                            <td class="num"><?= esc((string) $l['order_quantity']) ?></td>
                                            <td class="num">
                                                <input type="number" name="shipped_qty[<?= esc((string) $l['so_item_id']) ?>]" min="0" value="<?= esc((string) $l['order_quantity']) ?>" required>
                                            </td>
                                            <td class="num">₱<?= number_format((float) $l['unit_price'], 2) ?></td>
                                            <td class="num">₱<?= number_format((float) $l['order_quantity'] * (float) $l['unit_price'], 2) ?></td>
                                            <td class="check"><input type="checkbox" required title="Confirm this line is accurate before shipping"></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr><td colspan="4">Order Total</td><td class="num">₱<?= number_format(array_sum(array_map(fn ($l) => $l['order_quantity'] * $l['unit_price'], $o['lines'])), 2) ?></td><td></td></tr>
                                </tfoot>
                            </table>
                            <div class="dv-info-row">
                                <div class="dv-info-box"><span>Delivery Due</span><strong><?= !empty($o['expected_delivery_date']) ? esc(date('M j, Y', strtotime($o['expected_delivery_date']))) : '—' ?></strong></div>
                                <div class="dv-info-box"><span>Tracking No.</span><input name="tracking_no" value="<?= esc($o['tracking_no'] ?? '') ?>" placeholder="e.g. TRK-2026-0001"></div>
                            </div>
                            <button type="submit" class="btn btn-success">Confirm &amp; Ship</button>
                        </form>
                    <?php else: ?>
                        <div class="dv-info-row">
                            <div class="dv-info-box"><span>Delivery Due</span><strong><?= !empty($o['expected_delivery_date']) ? esc(date('M j, Y', strtotime($o['expected_delivery_date']))) : '—' ?></strong></div>
                            <div class="dv-info-box"><span>Tracking No.</span><strong><?= esc($o['tracking_no'] ?? '—') ?></strong></div>
                        </div>
                        <table class="dv-items-table">
                            <thead>
                                <tr><th>Item</th><th class="num">Shipped Qty</th><th class="num">Unit Price</th><th class="num">Line Total</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($o['lines'] as $l): ?>
                                    <?php $shippedQty = $l['shipped_quantity'] ?? $l['order_quantity']; ?>
                                    <tr>
                                        <td><?= esc($l['item_name'] ?? $l['item_id']) ?></td>
                                        <td class="num"><?= esc((string) $shippedQty) ?></td>
                                        <td class="num">₱<?= number_format((float) $l['unit_price'], 2) ?></td>
                                        <td class="num">₱<?= number_format((float) $shippedQty * (float) $l['unit_price'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr><td colspan="3">Order Total</td><td class="num">₱<?= number_format(array_sum(array_map(fn ($l) => ($l['shipped_quantity'] ?? $l['order_quantity']) * $l['unit_price'], $o['lines'])), 2) ?></td></tr>
                            </tfoot>
                        </table>
                        <div class="dv-awaiting-note">Shipped — awaiting confirmation of receipt from the shop. No further action needed on your end.</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
