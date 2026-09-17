
<?= $this->extend('layouts/admin_layout') ?>

<?= $this->section('header_actions') ?>
<form method="get" class="d-flex gap-2">
    <select name="year" class="form-select" onchange="this.form.submit()" style="width:auto;">
        <?php foreach (($years ?? []) as $y): ?>
            <option value="<?= $y ?>" <?= $y == ($selectedYear ?? 0) ? 'selected' : '' ?>>Year: <?= $y ?></option>
        <?php endforeach; ?>
    </select>
    <select name="item" class="form-select" onchange="this.form.submit()" style="width:auto;">
        <option value="">Item: All</option>
        <?php foreach (($products ?? []) as $p): ?>
            <option value="<?= esc($p['item_id']) ?>" <?= $p['item_id'] === ($selectedItem ?? '') ? 'selected' : '' ?>><?= esc($p['item_name']) ?></option>
        <?php endforeach; ?>
    </select>
</form>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<style>
.ta-stat { background: #fff; border-radius: 14px; padding: 18px 20px; display: flex; align-items: center; gap: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.06); height: 100%; }
.ta-stat .icon { font-size: 26px; }
.ta-stat .value { font-size: 26px; font-weight: 800; }
.chart-card { background: #fff; border-radius: 14px; padding: 20px 22px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin-bottom: 16px; }
.chart-card h6 { font-weight: 700; margin-bottom: 10px; }
.perf-bar-row { font-size: 13px; margin-bottom: 4px; font-weight: 600; }
.perf-track { height: 10px; background: #e4e2dc; border-radius: 5px; overflow: hidden; margin-bottom: 14px; }
.perf-track > span { display: block; height: 100%; background: #1c1c1c; border-radius: 5px; }
.var-table { width: 100%; }
.var-table th { text-align: left; font-size: 12px; text-transform: uppercase; color: #777; padding-bottom: 8px; border-bottom: 1px solid #eee; }
.var-table td { padding: 10px 0; border-bottom: 1px solid #f2f2f2; }
</style>

<div class="page-wrap">
    <div class="row g-3 mb-1">
        <div class="col-md-6"><div class="ta-stat"><span class="icon"><i class="bi bi-box-seam"></i></span><div><div class="text-muted small">Total Stock on hand</div><div class="value"><?= number_format($totalStock ?? 0) ?></div></div></div></div>
        <div class="col-md-6"><div class="ta-stat"><span class="icon"><i class="bi bi-cash-coin"></i></span><div><div class="text-muted small">Sales Today</div><div class="value">₱<?= number_format($salesToday ?? 0, 0) ?></div></div></div></div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-lg-8">
            <div class="chart-card">
                <h6>Annual Demand &mdash; Item Sold Per Month (<?= $selectedItem ? esc(array_column($products, 'item_name', 'item_id')[$selectedItem] ?? '') : 'All Products' ?>)</h6>
                <canvas id="annualChart" height="90"></canvas>
            </div>
            <div class="chart-card">
                <h6>Weekly Sales Trend</h6>
                <canvas id="weeklyChart" height="90"></canvas>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="chart-card">
                <h6>Product Performance (Items Sold)</h6>
                <?php if (empty($topSellers)): ?>
                    <div class="text-muted small">No sales recorded yet.</div>
                <?php else: ?>
                    <?php foreach ($topSellers as $t): ?>
                        <div class="perf-bar-row"><?= esc($t['item_name'] ?? $t['item_id']) ?></div>
                        <div class="perf-track"><span style="width: <?= round(($t['units_sold'] / $maxUnits) * 100) ?>%"></span></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="chart-card">
                <h6>Demand Variability<br><small class="text-muted fw-normal">Probabilistic Basis</small></h6>
                <?php if (empty($variability)): ?>
                    <div class="text-muted small">No PDSS computations yet. This populates once EOQ/ROP has run for an item.</div>
                <?php else: ?>
                    <table class="var-table">
                        <thead><tr><th>Item</th><th>Avg/Day</th><th>Safety stock</th></tr></thead>
                        <tbody>
                            <?php foreach ($variability as $v): ?>
                                <tr><td><?= esc($v['item_name']) ?></td><td><?= esc($v['avg_daily_demand']) ?></td><td><?= esc($v['safety_stock']) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
new Chart(document.getElementById('annualChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($annualLabels ?? []) ?>,
        datasets: [{
            label: '<?= esc($selectedYear ?? '') ?>',
            data: <?= json_encode($annualData ?? []) ?>,
            borderColor: '#3d5230',
            backgroundColor: 'transparent',
            tension: 0.3
        }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { grid: { color: '#eee' } }, x: { grid: { display: false } } } }
});

new Chart(document.getElementById('weeklyChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($weekLabels ?? []) ?>,
        datasets: [{
            label: 'This Week',
            data: <?= json_encode($weekData ?? []) ?>,
            borderColor: '#1c1c1c',
            backgroundColor: 'transparent',
            tension: 0.3
        }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { grid: { color: '#eee' } }, x: { grid: { display: false } } } }
});
</script>

<?= $this->endSection() ?>
