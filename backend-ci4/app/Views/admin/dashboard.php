<?php // app/Views/admin/dashboard.php ?>
<?= $this->extend('layouts/admin_layout') ?>
<?= $this->section('content') ?>

<style>
.dash-alert {
    background: #fff;
    border: 1px solid var(--accent-maroon);
    border-radius: 12px;
    padding: 18px 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 20px;
}
.dash-alert .icon { color: var(--accent-maroon); font-size: 28px; flex-shrink: 0; }
.dash-alert .title { font-weight: 800; font-size: 18px; }
.dash-alert .sub { color: #666; font-size: 13px; }

.dash-stat {
    background: #fff;
    border-radius: 14px;
    padding: 20px 22px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
    height: 100%;
}
.dash-stat .icon { font-size: 28px; flex-shrink: 0; }
.dash-stat .label { color: #444; font-size: 15px; }
.dash-stat .value { font-size: 30px; font-weight: 800; line-height: 1.15; }
.dash-stat .trend { margin-left: auto; text-align: right; font-size: 12px; color: #666; }
.dash-stat .trend .pct { color: #2f6431; font-weight: 700; }

.chart-card {
    background: #fff;
    border-radius: 14px;
    padding: 20px 22px;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
    height: 100%;
}
.chart-card h6 { font-weight: 700; font-size: 15px; margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #eee; }

.top-product-row { display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 4px; }
.top-product-track { height: 8px; background: #e4e2dc; border-radius: 4px; overflow: hidden; margin-bottom: 14px; }
.top-product-track > span { display: block; height: 100%; background: var(--accent-maroon); border-radius: 4px; }
</style>

<div class="page-wrap">

    <?php if (!empty($lowStockCount) && $lowStockCount > 0): ?>
    <div class="dash-alert">
        <div class="d-flex align-items-center gap-3">
            <span class="icon"><i class="bi bi-exclamation-triangle"></i></span>
            <div>
                <div class="title"><?= $lowStockCount ?> Item<?= $lowStockCount === 1 ? '' : 's' ?> have reached their Reorder Point (ROP)</div>
                <div class="sub">Immediate restocking recommended</div>
            </div>
        </div>
        <a href="<?= site_url('admin/reorder-alerts') ?>" class="btn btn-maroon">View Alerts</a>
    </div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="dash-stat">
                <span class="icon"><i class="bi bi-box-seam"></i></span>
                <div>
                    <div class="label">Total Stock on hand</div>
                    <div class="value"><?= number_format($totalStock ?? 0) ?></div>
                </div>
                <div class="trend">
                    <span class="pct">▲ <?= esc($stockTrendPct ?? '0%') ?></span><br>this week
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="dash-stat">
                <span class="icon"><i class="bi bi-cash-coin"></i></span>
                <div>
                    <div class="label">Sales Today</div>
                    <div class="value">₱<?= number_format($salesToday ?? 0, 0) ?></div>
                </div>
                <div class="trend">
                    <span class="pct">▲ <?= esc($salesTrendPct ?? '0%') ?></span><br>vs yesterday
                </div>
            </div>
        </div>
    </div>

    <div class="chart-card mb-3">
        <h6>Weekly Sales Trend</h6>
        <canvas id="weeklySalesChart" height="80"></canvas>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="chart-card">
                <h6>Items by Category</h6>
                <canvas id="seasonChart" height="140"></canvas>
            </div>
        </div>
        <div class="col-md-6">
            <div class="chart-card">
                <h6>Top Sellers (All Time)</h6>
                <?php if (empty($topProducts)): ?>
                    <div class="text-muted small">No sales recorded yet.</div>
                <?php else: ?>
                    <?php $maxUnits = max(array_column($topProducts, 'units_sold')) ?: 1; ?>
                    <?php foreach ($topProducts as $p): ?>
                        <div class="top-product-row">
                            <span><?= esc($p['item_name']) ?></span>
                            <strong><?= number_format($p['units_sold']) ?></strong>
                        </div>
                        <div class="top-product-track">
                            <span style="width: <?= round(($p['units_sold'] / $maxUnits) * 100) ?>%"></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
new Chart(document.getElementById('weeklySalesChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($weekLabels ?? ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']) ?>,
        datasets: [
            {
                label: 'This Week',
                data: <?= json_encode($weekData ?? [0,0,0,0,0,0,0]) ?>,
                borderColor: '#3d5230',
                backgroundColor: 'transparent',
                tension: 0.3,
                pointRadius: 2
            },
            {
                label: 'Last Week',
                data: <?= json_encode($lastWeekData ?? [0,0,0,0,0,0,0]) ?>,
                borderColor: '#b9b6ae',
                borderDash: [5, 5],
                backgroundColor: 'transparent',
                tension: 0.3,
                pointRadius: 0
            }
        ]
    },
    options: {
        plugins: { legend: { position: 'top', align: 'start', labels: { boxWidth: 24 } } },
        scales: { y: { grid: { color: '#eee' } }, x: { grid: { display: false } } }
    }
});

new Chart(document.getElementById('seasonChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($seasonLabels ?? []) ?>,
        datasets: [{
            data: <?= json_encode($seasonData ?? []) ?>,
            backgroundColor: '#1c1c1c',
            borderRadius: 4
        }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { grid: { color: '#eee' } }, x: { grid: { display: false } } } }
});
</script>

<?= $this->endSection() ?>
