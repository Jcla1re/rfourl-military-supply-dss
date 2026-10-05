
<?= $this->extend('layouts/staff_layout') ?>

<?= $this->section('content') ?>

<?php $orders = $orders ?? []; ?>

<style>
.lt-back { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 8px; border: 1px solid #ccc; background: #fff; text-decoration: none; color: #1c1c1c; font-weight: 700; margin-bottom: 18px; }
.lt-label { font-size: 12px; letter-spacing: .05em; color: #666; font-weight: 700; margin-bottom: 12px; }
.lt-form-card { background: #fff; border-radius: 14px; padding: 28px; max-width: 640px; margin-bottom: 20px; }
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

    <?php if (empty($orders)): ?>
        <div class="empty-state">No shipped orders waiting for confirmation right now.</div>
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

<?= $this->endSection() ?>
