<?php // app/Views/admin/dashboard.php ?>
<?= $this->extend('layouts/admin_layout') ?>
<?= $this->section('content') ?>

<div class="topbar d-flex justify-content-between align-items-center">
    <h4 class="mb-0">Dashboard</h4>
</div>

<div class="p-4">

    <?php if (!empty($lowStockCount) && $lowStockCount > 0): ?>
    <div class="alert-banner mb-4">
        <div>
            <strong><?= $lowStockCount ?> Items have reached their Reorder Point (ROP)</strong>
            <div class="text-muted small">Immediate restocking recommended</div>
        </div>
        <a href="/admin/reorder-alerts" class="btn btn-maroon">View Alerts</a>
    </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="stat-card">
                <div class="text-muted small">Total Stock on hand</div>
                <div class="fs-3 fw-bold"><?= number_format($totalStock ?? 0) ?></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="stat-card">
                <div class="text-muted small">Sales Today</div>
                <div class="fs-3 fw-bold">₱<?= number_format($salesToday ?? 0, 2) ?></div>
            </div>
        </div>
    </div>

    <div class="panel-card mb-4">
        <h6>Weekly Sales Trend</h6>
        <canvas id="weeklySalesChart" height="90"></canvas>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="panel-card">
                <h6>High Demand Seasons</h6>
                <canvas id="seasonChart" height="120"></canvas>
            </div>
        </div>
        <div class="col-md-6">
            <div class="panel-card">
                <h6>Top Product this month</h6>
                <?php foreach (($topProducts ?? []) as $p): ?>
                    <div class="d-flex justify-content-between small mb-1">
                        <span><?= esc($p['item_name']) ?></span>
                        <span><?= number_format($p['units_sold']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
new Chart(document.getElementById('weeklySalesChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($weekLabels ?? ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']) ?>,
        datasets: [{
            label: 'This Week',
            data: <?= json_encode($weekData ?? [0,0,0,0,0,0,0]) ?>,
            borderColor: '#2f3b24',
            tension: 0.3
        }]
    }
});

new Chart(document.getElementById('seasonChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($seasonLabels ?? ['Jan','Feb','Mar','Apr','May','Jun']) ?>,
        datasets: [{
            data: <?= json_encode($seasonData ?? [0,0,0,0,0,0]) ?>,
            backgroundColor: '#2f3b24'
        }]
    },
    options: { plugins: { legend: { display: false } } }
});
</script>

<?= $this->endSection() ?>