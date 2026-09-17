
<?= $this->extend('layouts/admin_layout') ?>

<?= $this->section('header_actions') ?>
<a href="<?= site_url('admin/reorder-alerts') ?>" class="btn btn-success">Back</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<?php $rows = $rows ?? []; $dss = $dss ?? []; ?>

<style>
.pr-params { background: #fff; border-radius: 14px; padding: 24px 26px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin-bottom: 20px; }
.pr-params h3 { font-weight: 800; margin-bottom: 18px; }
.pr-param-row { display: flex; flex-wrap: wrap; gap: 40px; margin-bottom: 18px; }
.pr-param-row div span { display: block; color: #777; font-size: 13px; }
.pr-param-row div strong { font-size: 20px; }
.pr-formula { background: #f2f0e9; border-radius: 10px; padding: 16px 20px; font-size: 15px; line-height: 2; }

.abc-badge {
    display: inline-flex; width: 26px; height: 26px; align-items: center; justify-content: center;
    border-radius: 50%; font-weight: 700; background: var(--red-bg); color: var(--red-text); font-size: 13px;
}
.pr-order-btn {
    border: 1px solid #333; background: #fff; border-radius: 8px; padding: 8px 14px; font-weight: 700; cursor: pointer;
}
</style>

<div class="page-wrap">
    <div class="pr-params">
        <h3>DSS parameter</h3>
        <div class="pr-param-row">
            <div><span>Ordering cost (S)</span><strong>₱<?= esc($dss['ordering_cost'] ?? 0) ?></strong></div>
            <div><span>Holding cost (H)</span><strong>₱<?= esc($dss['holding_cost_per_unit'] ?? 0) ?>/unit/y</strong></div>
            <div><span>Service level target</span><strong><?= esc($dss['service_level_target'] ?? 0) ?>% (Z = <?= esc($dss['z_score'] ?? 0) ?>)</strong></div>
            <div><span>Demand Lookback</span><strong><?= esc($dss['demand_lookback_days'] ?? 0) ?> days</strong></div>
        </div>
        <div class="pr-formula">
            EOQ = √( 2 × D<sub>annual</sub> × S / H ) &middot; D<sub>annual</sub> = d × 365<br>
            ROP = d × L + Safety stock &middot; SS = Z × &sigma;d × √L
        </div>
    </div>

    <div class="page-panel">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Item Name</th>
                    <th>ABC</th>
                    <th>Stock</th>
                    <th>ROP</th>
                    <th>Status</th>
                    <th>Safety Stock</th>
                    <th>EOQ</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($rows)): ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $pillClass = match ($r['status']) {
                            'Reorder Now' => 'red',
                            'Low Stock'   => 'amber',
                            default       => 'green',
                        };
                        $statusLabel = match ($r['status']) {
                            'Reorder Now' => 'At ROP',
                            'Low Stock'   => 'Approaching',
                            default       => 'Sufficient',
                        };
                        ?>
                        <tr>
                            <td><?= esc($r['item_name']) ?></td>
                            <td><?= $r['abc_category'] !== '—' ? '<span class="abc-badge">' . esc($r['abc_category']) . '</span>' : '—' ?></td>
                            <td><?= esc($r['stock']) ?></td>
                            <td><?= esc($r['rop']) ?></td>
                            <td><span class="status-pill <?= $pillClass ?>"><?= esc($statusLabel) ?></span></td>
                            <td><?= $r['safety_stock'] !== null ? esc($r['safety_stock']) : '—' ?></td>
                            <td><?= $r['eoq'] !== null ? esc($r['eoq']) : '—' ?></td>
                            <td>
                                <?php if ($r['status'] !== 'In Stock' && $r['eoq'] !== null): ?>
                                    <a href="<?= site_url('admin/orders') ?>" class="pr-order-btn">Order <?= esc($r['eoq']) ?></a>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8" style="text-align:center; color:#666; padding: 24px;">No procurement data available yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
