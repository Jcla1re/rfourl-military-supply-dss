<?php // app/Views/admin/dashboard.php ?>
<?= $this->extend('layouts/admin_layout') ?>
<?= $this->section('content') ?>

<style>
.dash-alert {
    background: #fff;
    border: 1px solid var(--accent-maroon);
    border-radius: var(--radius-card);
    padding: 18px 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 20px;
    animation: dashAlertGlow 1.8s ease-out infinite;
}
.dash-alert .icon {
    color: var(--accent-maroon); font-size: 28px; flex-shrink: 0;
    display: inline-block;
    animation: dashAlertPulse 1s ease-in-out infinite;
}
.dash-alert .title { font-weight: 800; font-size: 18px; }
.dash-alert .sub { color: #666; font-size: 13px; }

@keyframes dashAlertPulse {
    0%, 100% { transform: scale(1); }
    50%      { transform: scale(1.35) rotate(-4deg); }
}
@keyframes dashAlertGlow {
    0%   { box-shadow: 0 0 0 0 rgba(122, 32, 32, 0.35); }
    70%  { box-shadow: 0 0 0 10px rgba(122, 32, 32, 0); }
    100% { box-shadow: 0 0 0 0 rgba(122, 32, 32, 0); }
}

.dash-stat {
    background: #fff;
    border-radius: var(--radius-card);
    padding: 20px 22px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
    height: 100%;
    text-decoration: none;
    color: inherit;
    transition: box-shadow 0.15s ease, transform 0.15s ease;
}
a.dash-stat:hover { box-shadow: 0 4px 10px rgba(0,0,0,.1); transform: translateY(-1px); cursor: pointer; }
.dash-stat .icon { font-size: 28px; flex-shrink: 0; }
.dash-stat .label { color: #444; font-size: 15px; }
.dash-stat .value { font-size: 30px; font-weight: 800; line-height: 1.15; font-variant-numeric: tabular-nums; }
.dash-stat .trend { margin-left: auto; text-align: right; font-size: 12px; color: #666; }
.dash-stat .trend .pct { color: #2f6431; font-weight: 700; }
.dash-stat .trend .pct.down { color: var(--accent-maroon); }

.chart-card {
    background: #fff;
    border-radius: var(--radius-card);
    padding: 20px 22px;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
    height: 100%;
}
.chart-card h6 { font-weight: 700; font-size: 15px; margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #eee; }

.top-product-row { display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 4px; }
.top-product-track { height: 8px; background: #e4e2dc; border-radius: 4px; overflow: hidden; margin-bottom: 14px; }
.top-product-track > span {
    display: block; height: 100%; width: var(--w);
    background: linear-gradient(90deg, #a8e063 0%, #56ab2f 50%, #2e7d32 100%); border-radius: 4px;
    /* Opening animation: grows from 0 to its value, one bar after another. */
    transition: width 0.9s ease-out;
    transition-delay: calc(var(--i, 0) * 80ms);
}
.top-products.is-animating .top-product-track > span { width: 0; transition: none; }
@media (prefers-reduced-motion: reduce) {
    .top-product-track > span { transition: none; }
}
.sales-chart-wrap { position: relative; }
.sales-chart-empty {
    position: absolute; inset: 0;
    display: flex; align-items: center; justify-content: center;
    margin: 0; font-size: 14px; color: #5C6152; pointer-events: none;
}
</style>

<div class="page-wrap">

    <?php $needs = (int) ($reorderSummary['needs'] ?? 0); $onOrder = (int) ($reorderSummary['on_order'] ?? 0); ?>
    <?php if ($needs > 0 || $onOrder > 0): ?>
    <div class="dash-alert">
        <div class="d-flex align-items-center gap-3">
            <span class="icon"><i class="bi bi-exclamation-triangle"></i></span>
            <div>
                <div class="title"><?= $needs ?> item<?= $needs === 1 ? '' : 's' ?> need<?= $needs === 1 ? 's' : '' ?> reordering &middot; <?= $onOrder ?> on order</div>
                <div class="sub"><?= $needs > 0 ? 'Immediate restocking recommended' : 'Everything below its reorder point is already on order' ?></div>
            </div>
        </div>
        <a href="<?= site_url('admin/reorder-alerts') ?>" class="btn btn-maroon">Review Alerts</a>
    </div>
    <?php endif; ?>

    <?php
        $salesTrendPct  = $salesTrendPct ?? '0%';
        $salesTrendUp   = strpos($salesTrendPct, '-') !== 0;
        $salesTrendAbs  = ltrim($salesTrendPct, '-');
    ?>
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <a href="<?= site_url('admin/inventory') ?>" class="dash-stat">
                <span class="icon"><i class="bi bi-box-seam"></i></span>
                <div>
                    <div class="label">Total Stock on hand</div>
                    <div class="value" data-count="<?= (int) round((float) ($totalStock ?? 0)) ?>" aria-hidden="true"><?= number_format($totalStock ?? 0) ?></div>
                    <span class="visually-hidden"><?= number_format($totalStock ?? 0) ?></span>
                </div>
                <div class="trend">
                    <span class="pct">▲ <?= esc($stockTrendPct ?? '0%') ?></span><br>this week
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="<?= site_url('admin/sales') . '?tab=receipts' ?>" class="dash-stat">
                <span class="icon"><i class="bi bi-cash-coin"></i></span>
                <div>
                    <div class="label">Sales Today</div>
                    <div class="value" data-count="<?= (int) round((float) ($salesToday ?? 0)) ?>" data-prefix="₱" aria-hidden="true">₱<?= number_format($salesToday ?? 0, 0) ?></div>
                    <span class="visually-hidden">₱<?= number_format($salesToday ?? 0, 0) ?></span>
                </div>
                <div class="trend">
                    <span class="pct <?= $salesTrendUp ? '' : 'down' ?>"><?= $salesTrendUp ? '▲' : '▼' ?> <?= esc($salesTrendAbs) ?></span><br>vs yesterday
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="<?= site_url('admin/sales') . '?tab=receipts&date=' . date('Y-m-d', strtotime('-1 day')) ?>" class="dash-stat">
                <span class="icon"><i class="bi bi-calendar2-minus"></i></span>
                <div>
                    <div class="label">Sales Yesterday</div>
                    <div class="value" data-count="<?= (int) round((float) ($salesYesterday ?? 0)) ?>" data-prefix="₱" aria-hidden="true">₱<?= number_format($salesYesterday ?? 0, 0) ?></div>
                    <span class="visually-hidden">₱<?= number_format($salesYesterday ?? 0, 0) ?></span>
                </div>
            </a>
        </div>
    </div>

    <div class="chart-card mb-3">
        <h6>Weekly Sales Trend</h6>
        <div class="sales-chart-wrap">
            <canvas id="weeklySalesChart" height="80"></canvas>
            <?php if (array_sum(array_map('floatval', $weekData ?? [])) == 0): ?>
                <p class="sales-chart-empty">No sales this week.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="chart-card">
                <h6>Items by Category</h6>
                <div class="chart-fill"><canvas id="seasonChart"></canvas></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="chart-card">
                <h6>Top Sellers (All Time)</h6>
                <?php if (empty($topProducts)): ?>
                    <div class="text-muted small">No sales recorded yet.</div>
                <?php else: ?>
                    <?php $maxUnits = max(array_column($topProducts, 'units_sold')) ?: 1; ?>
                    <div class="top-products">
                    <?php foreach ($topProducts as $i => $p): ?>
                        <div class="top-product-row">
                            <span><?= esc($p['item_name']) ?></span>
                            <strong><?= number_format($p['units_sold']) ?></strong>
                        </div>
                        <div class="top-product-track">
                            <span style="--w: <?= round(($p['units_sold'] / $maxUnits) * 100) ?>%; --i: <?= (int) $i ?>"></span>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="/assets/js/count-up.js"></script>
<script src="/assets/js/chart-reveal.js"></script>
<script>
// Opening animations play once, on page load only. Chart.js can redraw a
// chart without animation while the page is still laying out (a resize
// during creation), so the opening animation is replayed one frame later,
// once the layout has settled. Animation is then turned off, so later
// updates (new data, filters) redraw instantly.
// Users who ask for reduced motion get no animation at all.
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const animateFirstLoadOnly = chart => {
    if (reduceMotion) return chart;
    requestAnimationFrame(() => {
        chart.reset();   // back to the start state (bars at 0, line not drawn)
        chart.update();  // play the opening animation
        chart.options.animation = false; // the running animation keeps going; future updates are instant
    });
    return chart;
};

// Weekly Sales Trend: revealed left to right by revealPlugin
// (assets/js/chart-reveal.js), not Chart.js's per-point animation.
const weekPoints = <?= json_encode(array_values($weekData ?? [0, 0, 0, 0, 0, 0, 0])) ?>;
const peso = value => '₱' + Number(value).toLocaleString('en-PH');

new Chart(document.getElementById('weeklySalesChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($weekLabels ?? ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']) ?>,
        datasets: [
            {
                label: 'This Week',
                data: weekPoints,
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
        animation: false, // the left-to-right reveal plugin does the opening animation
        plugins: {
            legend: { position: 'top', align: 'start', labels: { boxWidth: 24 } },
            tooltip: { callbacks: { label: ctx => `${ctx.dataset.label}: ${peso(ctx.parsed.y)}` } }
        },
        scales: {
            // Never below zero; at least ₱1,000 tall so an empty week isn't a squashed line.
            y: { min: 0, suggestedMax: 1000, grid: { color: '#eee' }, ticks: { callback: peso } },
            x: { grid: { display: false } }
        }
    },
    plugins: [revealPlugin]
});

animateFirstLoadOnly(new Chart(document.getElementById('seasonChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($seasonLabels ?? []) ?>,
        datasets: [{
            data: <?= json_encode($seasonData ?? []) ?>,
            backgroundColor: (ctx) => {
                const { ctx: c, chartArea } = ctx.chart;
                if (!chartArea) return '#56ab2f';
                const g = c.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                g.addColorStop(0, '#2e7d32');
                g.addColorStop(0.5, '#56ab2f');
                g.addColorStop(1, '#a8e063');
                return g;
            },
            borderRadius: 4
        }]
    },
    options: {
        maintainAspectRatio: false, // fills .chart-fill, which grows with the card
        // Bars grow up from 0, one after another.
        animation: reduceMotion ? false : {
            duration: 900,
            easing: 'easeOutQuart',
            delay: ctx => ctx.type === 'data' && ctx.mode === 'default' ? ctx.dataIndex * 80 : 0
        },
        plugins: { legend: { display: false } },
        scales: { y: { grid: { color: '#eee' } }, x: { grid: { display: false } } }
    }
}));

// Top Sellers: bars grow from 0 to their value (CSS transition, 80 ms apart).
(function () {
    const list = document.querySelector('.top-products');
    if (!list || reduceMotion) return;
    list.classList.add('is-animating');
    list.getBoundingClientRect(); // apply the 0-width start state first
    requestAnimationFrame(() => list.classList.remove('is-animating'));
})();

initCountUp('.dash-stat .value');
</script>

<?= $this->endSection() ?>
