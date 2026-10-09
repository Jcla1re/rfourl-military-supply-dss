
<?= $this->extend('layouts/staff_layout') ?>

<?= $this->section('content') ?>

<?php $orders = $orders ?? []; ?>

<style>
.lt-back { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 8px; border: 1px solid #ccc; background: #fff; text-decoration: none; color: #1c1c1c; font-weight: 700; margin-bottom: 18px; }
.lt-label { font-size: 12px; letter-spacing: .05em; color: #666; font-weight: 700; margin-bottom: 12px; }
.lt-layout { display: grid; grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr); gap: 20px; align-items: start; }
@media (max-width: 991px) { .lt-layout { grid-template-columns: 1fr; } }
.lt-form-card { background: #fff; border-radius: 14px; padding: 28px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
.lt-aside { background: #fff; border-radius: 14px; padding: 22px 24px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
.lt-aside-head { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.lt-aside-head .ic { width: 34px; height: 34px; border-radius: 9px; background: var(--green-bg); color: var(--green-text); display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; }
.lt-aside h6 { margin: 0; font-weight: 800; font-size: 15px; }
.lt-tiles { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }
.lt-tile { background: var(--content-bg); border-radius: 10px; padding: 12px 14px; }
.lt-tile .v { font-size: 20px; font-weight: 800; line-height: 1.15; }
.lt-tile .l { font-size: 12px; color: #777; margin-top: 2px; }
.lt-points { list-style: none; padding: 0; margin: 0; }
.lt-points li { display: flex; gap: 8px; font-size: 13.5px; padding: 7px 0; border-top: 1px solid #f2f2f2; }
.lt-points li i { color: var(--green-text); margin-top: 2px; }
.lt-badge { display: inline-block; background: #e9e7e1; border-radius: 999px; padding: 5px 14px; font-size: 12px; font-weight: 700; margin-bottom: 18px; }
.lt-submit { width: 100%; background: #6b8f4e; color: #fff; border: none; padding: 14px; border-radius: 10px; font-weight: 700; margin-top: 18px; }

.restock-check-table { width: 100%; border-collapse: collapse; }
.restock-check-table th { text-align: left; font-size: 11px; text-transform: uppercase; color: #888; padding: 6px 8px; border-bottom: 1px solid #e2e2e2; }
.restock-check-table td { padding: 8px; border-bottom: 1px solid #f2f2f2; font-size: 14px; }
.restock-check-table td.num, .restock-check-table th.num { text-align: right; }
.restock-check-table td.check, .restock-check-table th.check { text-align: center; width: 70px; }
.restock-check-table input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
.restock-check-table tfoot td { font-weight: 700; border-top: 2px solid #ddd; border-bottom: none; }
</style>

<div class="page-wrap">
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>
    <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>

    <a href="<?= site_url('staff/log-transaction') ?>" class="lt-back"><i class="bi bi-arrow-left"></i> Back</a>

    <div class="lt-label">CONFIRM A SUPPLIER DELIVERY</div>

    <?php
    $orderValue = 0; $itemCount = 0;
    foreach ($orders as $o) {
        foreach (($o['lines'] ?? []) as $l) {
            $orderValue += (float) ($l['shipped_quantity'] ?? $l['order_quantity']) * (float) $l['unit_price'];
            $itemCount++;
        }
    }
    ?>
    <div class="lt-layout">
    <div>
    <?php if (empty($orders)): ?>
        <div class="empty-state"><i class="bi bi-inbox"></i>No shipped orders waiting for confirmation right now.</div>
    <?php else: ?>
        <?php foreach ($orders as $o): ?>
            <div class="lt-form-card">
                <span class="lt-badge">#<?= esc($o['so_id']) ?> &middot; <?= esc($o['company_name'] ?? 'Unassigned') ?></span>

                <form method="post" action="<?= site_url('staff/log-transaction/restock-confirm/' . $o['so_id']) ?>">
                    <?= csrf_field() ?>
                    <table class="restock-check-table">
                        <thead>
                            <tr><th>Item</th><th class="num">Shipped Qty</th><th class="num">Unit Price</th><th class="num">Line Total</th><th class="check">Received</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach (($o['lines'] ?? []) as $l): ?>
                                <?php $shippedQty = $l['shipped_quantity'] ?? $l['order_quantity']; ?>
                                <tr>
                                    <td><?= esc($l['item_name'] ?? $l['item_id']) ?></td>
                                    <td class="num"><?= esc((string) $shippedQty) ?></td>
                                    <td class="num">₱<?= number_format((float) $l['unit_price'], 2) ?></td>
                                    <td class="num">₱<?= number_format((float) $shippedQty * (float) $l['unit_price'], 2) ?></td>
                                    <td class="check"><input type="checkbox" required title="Confirm this item was physically received"></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr><td colspan="3">Order Total</td><td class="num">₱<?= number_format(array_sum(array_map(fn ($l) => ($l['shipped_quantity'] ?? $l['order_quantity']) * $l['unit_price'], $o['lines'] ?? [])), 2) ?></td><td></td></tr>
                        </tfoot>
                    </table>
                    <button type="submit" class="lt-submit">Confirm Delivery Received</button>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>

    <aside class="lt-aside">
        <div class="lt-aside-head"><span class="ic"><i class="bi bi-box-arrow-in-down"></i></span><h6>Awaiting confirmation</h6></div>
        <div class="lt-tiles">
            <div class="lt-tile"><div class="v"><?= count($orders) ?></div><div class="l">Shipped orders</div></div>
            <div class="lt-tile"><div class="v"><?= $itemCount ?></div><div class="l">Item lines</div></div>
            <div class="lt-tile" style="grid-column: 1 / -1;"><div class="v">₱<?= number_format($orderValue, 2) ?></div><div class="l">Total value on the way</div></div>
        </div>
        <ul class="lt-points">
            <li><i class="bi bi-check-circle-fill"></i><span>Tick each item once you have physically received it.</span></li>
            <li><i class="bi bi-check-circle-fill"></i><span>Confirming adds the delivered quantities to inventory.</span></li>
            <li><i class="bi bi-check-circle-fill"></i><span>The Owner is notified that the delivery was confirmed.</span></li>
        </ul>
    </aside>
    </div>
</div>

<?= $this->endSection() ?>
