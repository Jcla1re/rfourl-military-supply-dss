<?php
/**
 * Variables injected by TrendAnalysisController::index() via CodeIgniter's
 * extract()-based view rendering. Declared here so static analysis can
 * resolve their types (extract() makes them invisible to it otherwise) —
 * purely a documentation aid, no runtime effect.
 *
 * @var string   $title
 * @var string   $active
 * @var int[]    $years
 * @var int      $selectedYear
 * @var array[]  $products
 * @var string   $selectedItem
 * @var string[] $annualLabels
 * @var int[]    $annualData
 * @var int[]    $annualDataPrev
 * @var int      $annualPeak
 * @var string[] $weekLabels
 * @var int[]    $weekData
 * @var int[]    $weekDataPrev
 * @var string   $weekAsOf
 * @var array[]  $topSellers
 * @var int      $maxUnits
 * @var array[]  $variability
 * @var array{A: float, B: float, C: float} $abcClassTotals
 * @var array[]  $abcTopItems
 * @var array{A: array[], B: array[], C: array[]} $abcByClass
 * @var array{A: int, B: int, C: int} $abcClassCounts
 * @var int      $totalStock
 * @var float    $salesToday
 */
?>
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

<?php
$selectedItem = $selectedItem ?? '';
$products     = $products ?? [];
$maxUnits     = $maxUnits ?? 1;
$selectedYear = $selectedYear ?? (int) date('Y');
$weekAsOf     = $weekAsOf ?? '';

$selectedItemName = '';
if ($selectedItem !== '') {
    $itemNamesById     = array_column($products, 'item_name', 'item_id');
    $selectedItemName  = (string) ($itemNamesById[$selectedItem] ?? '');
}

$selectedYearLabel = is_scalar($selectedYear) ? (string) $selectedYear : '';
?>

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

.abc-subtitle { font-size: 13px; color: #888; font-weight: 400; margin-bottom: 14px; }
.abc-stack { display: flex; height: 10px; border-radius: 5px; overflow: hidden; margin-bottom: 12px; background: #eee; }
.abc-stack > span { display: block; height: 100%; }
.abc-stack .cls-a { background: #2e5339; }
.abc-stack .cls-b { background: #6b9b6f; }
.abc-stack .cls-c { background: #bfe0c3; }
.abc-legend { display: flex; gap: 18px; flex-wrap: wrap; font-size: 13px; font-weight: 600; margin-bottom: 16px; }
.abc-legend .swatch { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 6px; }
.abc-legend .swatch.cls-a { background: #2e5339; }
.abc-legend .swatch.cls-b { background: #6b9b6f; }
.abc-legend .swatch.cls-c { background: #bfe0c3; }
.abc-row { display: flex; align-items: center; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f2f2f2; font-size: 14px; }
.abc-row:last-child { border-bottom: none; }
.abc-row .abc-right { display: flex; align-items: center; gap: 10px; }
.abc-badge { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; font-size: 12px; font-weight: 700; }
.abc-badge.cls-a { background: #f7d0d3; color: #a13a41; }
.abc-badge.cls-b { background: #f7e2b0; color: #8a6512; }
.abc-badge.cls-c { background: #e5e5e5; color: #666; }

.abc-tabs { display: flex; gap: 6px; margin-bottom: 10px; }
.abc-tab { border: 1px solid #e0ddd4; background: #fff; border-radius: 8px; padding: 5px 12px; font-size: 13px; font-weight: 600; color: #666; cursor: pointer; }
.abc-tab:hover { background: #f7f6f2; }
.abc-tab.active { background: #1c1c1c; border-color: #1c1c1c; color: #fff; }
</style>

<div class="page-wrap">
    <div class="row g-3 mb-1">
        <div class="col-md-6"><div class="ta-stat"><span class="icon"><i class="bi bi-box-seam"></i></span><div><div class="text-muted small">Total Stock on hand</div><div class="value"><?= number_format($totalStock ?? 0) ?></div></div></div></div>
        <div class="col-md-6"><div class="ta-stat"><span class="icon"><i class="bi bi-cash-coin"></i></span><div><div class="text-muted small">Sales Today</div><div class="value">₱<?= number_format($salesToday ?? 0, 0) ?></div></div></div></div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-lg-8">
            <div class="chart-card">
                <h6>Annual Demand &mdash; Item Sold Per Month (<?= $selectedItem !== '' ? esc($selectedItemName) : 'All Products' ?>)<br><small class="text-muted fw-normal"><?= esc($selectedYearLabel) ?> vs <?= esc((string) ((int) $selectedYear - 1)) ?></small></h6>
                <canvas id="annualChart" height="90"></canvas>
            </div>
            <div class="chart-card">
                <h6>Weekly Sales Trend<br><small class="text-muted fw-normal">7 days ending <?= esc($weekAsOf) ?><?= $selectedItem !== '' ? ' — ' . esc($selectedItemName) : '' ?></small></h6>
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
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-lg-6">
            <div class="chart-card">
                <h6>ABC Classification</h6>
                <div class="abc-subtitle">Based on % contribution to total sales value (<?= esc($selectedYearLabel) ?>)</div>
                <?php if (empty($abcTopItems)): ?>
                    <div class="text-muted small">No sales recorded for <?= esc($selectedYearLabel !== '' ? $selectedYearLabel : 'this period') ?> yet.</div>
                <?php else: ?>
                    <?php
                    $ac             = $abcClassTotals ?? ['A' => 0, 'B' => 0, 'C' => 0];
                    $abcByClass     = $abcByClass ?? ['A' => [], 'B' => [], 'C' => []];
                    $abcClassCounts = $abcClassCounts ?? ['A' => 0, 'B' => 0, 'C' => 0];
                    $renderAbcRows  = function (array $items) {
                        foreach ($items as $item) {
                            ?>
                            <div class="abc-row">
                                <span><?= esc($item['item_name']) ?></span>
                                <span class="abc-right">
                                    <span class="text-muted"><?= round($item['pct']) ?>%</span>
                                    <span class="abc-badge cls-<?= strtolower($item['class']) ?>"><?= esc($item['class']) ?></span>
                                </span>
                            </div>
                            <?php
                        }
                    };
                    ?>
                    <div class="abc-stack">
                        <span class="cls-a" style="width: <?= round($ac['A']) ?>%"></span>
                        <span class="cls-b" style="width: <?= round($ac['B']) ?>%"></span>
                        <span class="cls-c" style="width: <?= round($ac['C']) ?>%"></span>
                    </div>
                    <div class="abc-legend">
                        <span><span class="swatch cls-a"></span>A &middot; <?= round($ac['A']) ?>%</span>
                        <span><span class="swatch cls-b"></span>B &middot; <?= round($ac['B']) ?>%</span>
                        <span><span class="swatch cls-c"></span>C &middot; <?= round($ac['C']) ?>%</span>
                    </div>

                    <div class="abc-tabs">
                        <button type="button" class="abc-tab active" data-tab="all">All</button>
                        <button type="button" class="abc-tab" data-tab="A">A (<?= (int) $abcClassCounts['A'] ?>)</button>
                        <button type="button" class="abc-tab" data-tab="B">B (<?= (int) $abcClassCounts['B'] ?>)</button>
                        <button type="button" class="abc-tab" data-tab="C">C (<?= (int) $abcClassCounts['C'] ?>)</button>
                    </div>

                    <div class="abc-list" data-tab-panel="all">
                        <?php $renderAbcRows($abcTopItems); ?>
                    </div>
                    <div class="abc-list" data-tab-panel="A" style="display:none;">
                        <?php empty($abcByClass['A']) ? print('<div class="text-muted small">No Class A items this period.</div>') : $renderAbcRows($abcByClass['A']); ?>
                    </div>
                    <div class="abc-list" data-tab-panel="B" style="display:none;">
                        <?php empty($abcByClass['B']) ? print('<div class="text-muted small">No Class B items this period.</div>') : $renderAbcRows($abcByClass['B']); ?>
                    </div>
                    <div class="abc-list" data-tab-panel="C" style="display:none;">
                        <?php empty($abcByClass['C']) ? print('<div class="text-muted small">No Class C items this period.</div>') : $renderAbcRows($abcByClass['C']); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="chart-card">
                <h6>Demand Variability<br><small class="text-muted fw-normal">Probabilistic Basis &mdash; Top sellers of <?= esc($selectedYearLabel) ?></small></h6>
                <?php if (empty($variability)): ?>
                    <div class="text-muted small">No PDSS computations yet for this period's best sellers. This populates once EOQ/ROP has run for an item.</div>
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
function peakLabelPlugin() {
    return {
        id: 'peakLabel',
        afterDatasetsDraw(chart) {
            const data = chart.data.datasets[0].data;
            if (!data || !data.length || Math.max(...data) <= 0) return;

            let maxIdx = 0;
            for (let i = 1; i < data.length; i++) {
                if (data[i] > data[maxIdx]) maxIdx = i;
            }
            const meta = chart.getDatasetMeta(0);
            const point = meta.data[maxIdx];
            if (!point) return;

            const ctx = chart.ctx;
            const label = 'Peak: ' + Number(data[maxIdx]).toLocaleString();
            ctx.save();
            ctx.font = 'bold 12px sans-serif';
            const textWidth = ctx.measureText(label).width;
            const boxW = textWidth + 20, boxH = 24;
            const x = Math.min(Math.max(point.x - boxW / 2, 4), chart.width - boxW - 4);
            const y = Math.max(point.y - boxH - 12, 4);

            ctx.fillStyle = '#3d5230';
            if (ctx.roundRect) {
                ctx.beginPath();
                ctx.roundRect(x, y, boxW, boxH, 5);
                ctx.fill();
            } else {
                ctx.fillRect(x, y, boxW, boxH);
            }
            ctx.fillStyle = '#fff';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(label, x + boxW / 2, y + boxH / 2);
            ctx.restore();
        }
    };
}

new Chart(document.getElementById('annualChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($annualLabels ?? []) ?>,
        datasets: [
            {
                label: 'This Year',
                data: <?= json_encode($annualData ?? []) ?>,
                borderColor: '#3d5230',
                backgroundColor: 'transparent',
                tension: 0.3,
                pointRadius: 2
            },
            {
                label: 'Last Year',
                data: <?= json_encode($annualDataPrev ?? []) ?>,
                borderColor: '#b9b6ae',
                borderDash: [5, 5],
                backgroundColor: 'transparent',
                tension: 0.3,
                pointRadius: 0
            }
        ]
    },
    options: {
        plugins: { legend: { position: 'top', align: 'end', labels: { boxWidth: 24 } } },
        scales: { y: { grid: { color: '#eee' } }, x: { grid: { display: false } } },
        layout: { padding: { top: 30 } }
    },
    plugins: [peakLabelPlugin()]
});

new Chart(document.getElementById('weeklyChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($weekLabels ?? []) ?>,
        datasets: [
            {
                label: 'This Week',
                data: <?= json_encode($weekData ?? []) ?>,
                borderColor: '#1c1c1c',
                backgroundColor: 'transparent',
                tension: 0.3,
                pointRadius: 2
            },
            {
                label: 'Last Week',
                data: <?= json_encode($weekDataPrev ?? []) ?>,
                borderColor: '#b9b6ae',
                borderDash: [5, 5],
                backgroundColor: 'transparent',
                tension: 0.3,
                pointRadius: 0
            }
        ]
    },
    options: {
        plugins: { legend: { position: 'top', align: 'end', labels: { boxWidth: 24 } } },
        scales: { y: { grid: { color: '#eee' } }, x: { grid: { display: false } } },
        layout: { padding: { top: 30 } }
    },
    plugins: [peakLabelPlugin()]
});

document.querySelectorAll('.abc-tab').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var tab = btn.dataset.tab;
        document.querySelectorAll('.abc-tab').forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
        document.querySelectorAll('.abc-list').forEach(function (panel) {
            panel.style.display = panel.dataset.tabPanel === tab ? '' : 'none';
        });
    });
});
</script>

<?= $this->endSection() ?>
