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
 * @var array    $annualData      This year per month; null for months not reached yet
 * @var int[]    $annualDataPrev
 * @var int      $monthsSoFar     How many months of the selected year have happened
 * @var string[] $weekLabels
 * @var int[]    $weekData
 * @var int[]    $weekDataPrev
 * @var string   $weekAsOf
 * @var string   $weekRangeLabel
 * @var int      $unitsYtd
 * @var int      $unitsPrev
 * @var float|null $changePct
 * @var string   $periodLabel
 * @var array|null $bestMonth
 * @var array|null $topItem
 * @var array[]  $topSellers
 * @var int      $maxUnits
 * @var array[]  $variability
 * @var array{A: float, B: float, C: float} $abcClassTotals
 * @var array[]  $abcTopItems
 * @var array{A: array[], B: array[], C: array[]} $abcByClass
 * @var array{A: int, B: int, C: int} $abcClassCounts
 */
$selectedItem = $selectedItem ?? '';
$products     = $products ?? [];
$selectedYear = (int) ($selectedYear ?? date('Y'));

// Item picker labels: name, plus size when there is one, so same-name items
// in different sizes can be told apart.
$itemLabel = static fn (array $p): string => $p['item_name'] . (! empty($p['size']) ? ' · ' . $p['size'] : '');
$selectedItemLabel = '';
foreach ($products as $p) {
    if ($p['item_id'] === $selectedItem) {
        $selectedItemLabel = $itemLabel($p);
        break;
    }
}
?>
<?= $this->extend('layouts/admin_layout') ?>

<?= $this->section('header_actions') ?>
<form method="get" class="ta-filters" id="taFilters">
    <select name="year" class="form-select ta-year" onchange="this.form.submit()" aria-label="Year">
        <?php foreach (($years ?? []) as $y): ?>
            <option value="<?= $y ?>" <?= $y == $selectedYear ? 'selected' : '' ?>><?= $y ?></option>
        <?php endforeach; ?>
    </select>
    <input type="hidden" name="item" id="taItemValue" value="<?= esc($selectedItem) ?>">
    <div class="ta-item-picker">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" id="taItemSearch" class="form-control" list="taItemOptions" autocomplete="off"
               placeholder="All items" aria-label="Item (type to search)" value="<?= esc($selectedItemLabel) ?>">
    </div>
    <datalist id="taItemOptions">
        <option value="All items"></option>
        <?php foreach ($products as $p): ?>
            <option value="<?= esc($itemLabel($p)) ?>" data-id="<?= esc($p['item_id']) ?>"></option>
        <?php endforeach; ?>
    </datalist>
</form>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap');

.page-wrap.ta-page { font-family: 'Poppins', sans-serif; }

/* Header filters (sit in the green top bar) */
.ta-filters { display: flex; gap: 8px; align-items: center; }
.ta-filters .ta-year { width: auto; min-width: 96px; }
.ta-item-picker { position: relative; width: 240px; }
.ta-item-picker i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #8A8D7E; font-size: 14px; pointer-events: none; }
.ta-item-picker input { padding-left: 34px; }

/* Trend cards */
.ta-card {
    background: #fff; border-radius: var(--radius-card); padding: 18px 20px; height: 100%;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
}
.ta-card .label { font-size: 13px; color: #5C6152; }
.ta-card .value { margin-top: 4px; font-size: 26px; font-weight: 600; color: #1F2617; line-height: 1.2; overflow-wrap: anywhere; }
.ta-card .value.is-text { font-size: 20px; }
.ta-card .sub { margin-top: 4px; font-size: 13px; color: #5C6152; }
.ta-change { display: inline-flex; align-items: center; gap: 4px; }
.ta-change.up { color: #3B7D2C; }
.ta-change.down { color: #B42318; }
.ta-change.flat { color: #5C6152; }

/* Cards */
.chart-card { background: #fff; border-radius: var(--radius-card); padding: 20px 22px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin-bottom: 16px; }
.chart-card h6 { font-size: 15px; font-weight: 600; color: #1F2617; margin: 0; }
.card-sub { font-size: 13px; color: #5C6152; margin: 2px 0 0; }
.card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }

/* Monthly / Weekly segmented control */
.ta-segment { display: inline-flex; padding: 3px; background: #F5F3EE; border: 1px solid #E8E5DC; border-radius: var(--radius-control); }
.ta-segment button {
    border: 0; background: transparent; padding: 6px 14px; border-radius: 8px;
    font: inherit; font-size: 13px; font-weight: 500; color: #5C6152; cursor: pointer;
    transition: background-color 0.15s ease, color 0.15s ease;
}
.ta-segment button:hover { color: #2C3A1E; }
.ta-segment button[aria-pressed="true"] { background: #fff; color: #2C3A1E; box-shadow: 0 1px 2px rgba(0,0,0,.08); }
.ta-segment button:focus-visible { outline: 3px solid #71AA60; outline-offset: 2px; }

/* Product performance */
.perf-card { display: flex; flex-direction: column; }
.perf-list { flex: 1; display: flex; flex-direction: column; justify-content: space-between; }
.perf-row { display: flex; justify-content: space-between; gap: 12px; font-size: 13px; margin-bottom: 6px; }
.perf-row span { color: #2C3A1E; overflow-wrap: anywhere; }
.perf-row strong { font-weight: 600; color: #1F2617; font-variant-numeric: tabular-nums; white-space: nowrap; }
.perf-track { height: 8px; background: #E8E5DC; border-radius: 4px; overflow: hidden; margin-bottom: 16px; }
.perf-track > span {
    display: block; height: 100%; width: var(--w);
    background: linear-gradient(90deg, #8CC27A 0%, #4F8542 100%); border-radius: 4px;
    transition: width 0.9s ease-out; transition-delay: calc(var(--i, 0) * 80ms);
}
.perf-list.is-animating .perf-track > span { width: 0; transition: none; }

/* ABC classification */
.abc-stack { display: flex; height: 10px; border-radius: 5px; overflow: hidden; margin: 4px 0 12px; background: #F5F3EE; }
.abc-stack > span { display: block; height: 100%; }
.cls-a-fill { background: #2C3A1E; }
.cls-b-fill { background: #71AA60; }
.cls-c-fill { background: #C5DDBB; }
.abc-meanings { list-style: none; margin: 0 0 16px; padding: 0; display: grid; gap: 6px; }
.abc-meanings li { display: flex; align-items: center; gap: 10px; font-size: 13px; color: #5C6152; }
.abc-meanings strong { color: #1F2617; font-weight: 600; min-width: 34px; }
.abc-badge {
    display: inline-flex; align-items: center; justify-content: center;
    width: 22px; height: 22px; border-radius: 50%; font-size: 12px; font-weight: 600; flex-shrink: 0;
}
.abc-badge.cls-a { background: #2C3A1E; color: #fff; }
.abc-badge.cls-b { background: #71AA60; color: #fff; }
.abc-badge.cls-c { background: #E4EFDF; color: #2F4A22; }
.abc-tabs { display: flex; gap: 6px; margin-bottom: 10px; flex-wrap: wrap; }
.abc-tab {
    border: 1px solid #E8E5DC; background: #fff; border-radius: var(--radius-pill); padding: 5px 12px;
    font: inherit; font-size: 13px; font-weight: 500; color: #5C6152; cursor: pointer;
    transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease;
}
.abc-tab:hover { background: #F5F3EE; color: #2C3A1E; }
.abc-tab.active { background: #2C3A1E; border-color: #2C3A1E; color: #fff; }
.abc-tab:focus-visible { outline: 3px solid #71AA60; outline-offset: 2px; }
.abc-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 8px 0; border-bottom: 1px solid #F0EEE8; font-size: 14px; color: #2C3A1E; }
.abc-row:last-child { border-bottom: none; }
.abc-row .abc-right, .abc-head .abc-right { display: flex; align-items: center; gap: 10px; color: #5C6152; font-variant-numeric: tabular-nums; }
/* Column headers over the item list; Share and Class columns have fixed
   widths so the header text lines up with the values and badges below. */
.abc-head {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 4px 0 8px; border-bottom: 1px solid #E8E5DC;
    font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #4A5040;
}
.abc-head .abc-right { color: #4A5040; }
.abc-share { min-width: 48px; text-align: right; }
.abc-cls { width: 44px; display: flex; justify-content: center; }

/* Demand variability */
.var-table { width: 100%; border-collapse: collapse; }
.var-table th { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .02em; color: #5C6152; padding: 0 0 8px; border-bottom: 1px solid #E8E5DC; text-align: left; }
.var-table td { padding: 10px 0; border-bottom: 1px solid #F0EEE8; font-size: 14px; color: #2C3A1E; }
.var-table .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; padding-left: 12px; }
.var-table .cls { text-align: center; width: 64px; padding-left: 12px; }
.var-note { display: flex; align-items: flex-start; gap: 8px; margin: 12px 0 0; font-size: 13px; line-height: 1.5; color: #5C6152; }
.var-note i { font-size: 16px; line-height: 1.25; flex-shrink: 0; }

@media (prefers-reduced-motion: reduce) {
    .perf-track > span { transition: none; }
}
@media (max-width: 768px) {
    .ta-item-picker { width: 180px; }
}
</style>

<div class="page-wrap ta-page">
    <?php
    $yearLabel  = (string) $selectedYear;
    $changePct  = $changePct ?? null;
    $changeDir  = $changePct === null ? 'flat' : ($changePct > 0.05 ? 'up' : ($changePct < -0.05 ? 'down' : 'flat'));
    ?>
    <div class="row g-3 mb-1">
        <div class="col-sm-6 col-xl-3">
            <div class="ta-card">
                <div class="label">Items sold in <?= esc($yearLabel) ?></div>
                <div class="value"><?= number_format($unitsYtd ?? 0) ?></div>
                <div class="sub"><?= esc($periodLabel ?? '') ?></div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="ta-card">
                <div class="label">vs. same period last year</div>
                <?php if ($changePct === null): ?>
                    <div class="value is-text ta-change flat">No sales last year</div>
                    <div class="sub">Nothing to compare with</div>
                <?php else: ?>
                    <div class="value ta-change <?= $changeDir ?>">
                        <?php if ($changeDir === 'up'): ?><i class="bi bi-arrow-up" aria-hidden="true"></i>
                        <?php elseif ($changeDir === 'down'): ?><i class="bi bi-arrow-down" aria-hidden="true"></i><?php endif; ?>
                        <span><?= ($changeDir === 'up' ? '+' : '') . number_format($changePct, 1) ?>%</span>
                    </div>
                    <div class="sub"><?= number_format($unitsPrev ?? 0) ?> items in <?= (int) $selectedYear - 1 ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="ta-card">
                <div class="label">Best month</div>
                <?php if (empty($bestMonth)): ?>
                    <div class="value is-text">—</div>
                    <div class="sub">No sales yet</div>
                <?php else: ?>
                    <div class="value is-text"><?= esc($bestMonth['name']) ?></div>
                    <div class="sub"><?= number_format($bestMonth['units']) ?> items sold</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="ta-card">
                <div class="label">Top item in <?= esc($yearLabel) ?></div>
                <?php if (empty($topItem)): ?>
                    <div class="value is-text">—</div>
                    <div class="sub">No sales yet</div>
                <?php else: ?>
                    <div class="value is-text"><?= esc($topItem['item_name'] ?? $topItem['item_id']) ?></div>
                    <div class="sub"><?= number_format((int) $topItem['units_sold']) ?> items sold</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-lg-8">
            <div class="chart-card h-100">
                <div class="card-head">
                    <div>
                        <h6 id="trendTitle">Items sold per month<?= $selectedItemLabel !== '' ? ' · ' . esc($selectedItemLabel) : '' ?></h6>
                        <p class="card-sub" id="trendSubtitle"><?= esc($yearLabel) ?> compared with <?= (int) $selectedYear - 1 ?></p>
                    </div>
                    <div class="ta-segment" role="group" aria-label="Chart period">
                        <button type="button" data-view="monthly" aria-pressed="true">Monthly</button>
                        <button type="button" data-view="weekly" aria-pressed="false">Weekly</button>
                    </div>
                </div>
                <div class="chart-fill"><canvas id="trendChart" aria-label="Items sold chart" role="img"></canvas></div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="chart-card h-100 perf-card">
                <div class="card-head"><div><h6>Product performance</h6><p class="card-sub">Items sold, all time</p></div></div>
                <?php if (empty($topSellers)): ?>
                    <div class="text-muted small">No sales recorded yet.</div>
                <?php else: ?>
                    <div class="perf-list">
                    <?php foreach ($topSellers as $i => $t): ?>
                        <div>
                            <div class="perf-row"><span><?= esc($t['item_name'] ?? $t['item_id']) ?></span><strong><?= number_format((int) $t['units_sold']) ?></strong></div>
                            <div class="perf-track"><span style="--w: <?= round(($t['units_sold'] / ($maxUnits ?: 1)) * 100) ?>%; --i: <?= (int) $i ?>"></span></div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-lg-6">
            <div class="chart-card">
                <div class="card-head"><div><h6>ABC classification</h6><p class="card-sub">Share of total sales value in <?= esc($yearLabel) ?></p></div></div>
                <?php if (empty($abcTopItems)): ?>
                    <div class="text-muted small">No sales recorded for <?= esc($yearLabel) ?> yet.</div>
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
                                    <span class="abc-share"><?= round($item['pct']) ?>%</span>
                                    <span class="abc-cls"><span class="abc-badge cls-<?= strtolower($item['class']) ?>" aria-label="Class <?= esc($item['class']) ?>"><?= esc($item['class']) ?></span></span>
                                </span>
                            </div>
                            <?php
                        }
                    };
                    ?>
                    <div class="abc-stack" role="img" aria-label="Class A <?= round($ac['A']) ?>%, B <?= round($ac['B']) ?>%, C <?= round($ac['C']) ?>% of sales value">
                        <span class="cls-a-fill" style="width: <?= round($ac['A']) ?>%"></span>
                        <span class="cls-b-fill" style="width: <?= round($ac['B']) ?>%"></span>
                        <span class="cls-c-fill" style="width: <?= round($ac['C']) ?>%"></span>
                    </div>
                    <ul class="abc-meanings">
                        <li><span class="abc-badge cls-a">A</span><strong><?= round($ac['A']) ?>%</strong> Best sellers. Never run out.</li>
                        <li><span class="abc-badge cls-b">B</span><strong><?= round($ac['B']) ?>%</strong> Steady sellers.</li>
                        <li><span class="abc-badge cls-c">C</span><strong><?= round($ac['C']) ?>%</strong> Slow sellers. Order less.</li>
                    </ul>

                    <div class="abc-tabs" role="group" aria-label="Filter by class">
                        <button type="button" class="abc-tab active" data-tab="all" aria-pressed="true">All</button>
                        <button type="button" class="abc-tab" data-tab="A" aria-pressed="false">A (<?= (int) $abcClassCounts['A'] ?>)</button>
                        <button type="button" class="abc-tab" data-tab="B" aria-pressed="false">B (<?= (int) $abcClassCounts['B'] ?>)</button>
                        <button type="button" class="abc-tab" data-tab="C" aria-pressed="false">C (<?= (int) $abcClassCounts['C'] ?>)</button>
                    </div>

                    <div class="abc-head" aria-hidden="true">
                        <span>Item</span>
                        <span class="abc-right"><span class="abc-share">Share</span><span class="abc-cls">Class</span></span>
                    </div>
                    <div class="abc-list" data-tab-panel="all">
                        <?php $renderAbcRows($abcTopItems); ?>
                    </div>
                    <?php foreach (['A', 'B', 'C'] as $cls): ?>
                        <div class="abc-list" data-tab-panel="<?= $cls ?>" style="display:none;">
                            <?php if (empty($abcByClass[$cls])): ?>
                                <div class="text-muted small">No Class <?= $cls ?> items this period.</div>
                            <?php else: ?>
                                <?php $renderAbcRows($abcByClass[$cls]); ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="chart-card">
                <div class="card-head"><div><h6>Demand variability</h6><p class="card-sub">Highest-demand items in <?= esc($yearLabel) ?>, used to compute safety stock.</p></div></div>
                <?php if (empty($variability)): ?>
                    <div class="text-muted small">No PDSS computations yet for this period's best sellers. This populates once EOQ/ROP has run for an item.</div>
                <?php else: ?>
                    <table class="var-table">
                        <thead><tr><th>Item</th><th class="num">Avg. daily demand</th><th class="num">Safety stock</th><th class="cls">Class</th></tr></thead>
                        <tbody>
                            <?php foreach ($variability as $v): ?>
                                <?php $ss = (int) round((float) $v['safety_stock']); ?>
                                <tr>
                                    <td><?= esc($v['item_name']) ?></td>
                                    <td class="num"><?= number_format((float) $v['avg_daily_demand'], 2) ?> / day</td>
                                    <td class="num"><?= number_format($ss) ?> <?= $ss === 1 ? 'unit' : 'units' ?></td>
                                    <td class="cls">
                                        <?php if (! empty($v['class'])): ?>
                                            <span class="abc-badge cls-<?= strtolower($v['class']) ?>" aria-label="Class <?= esc($v['class']) ?>"><?= esc($v['class']) ?></span>
                                        <?php else: ?>—<?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="var-note"><i class="bi bi-info-circle" aria-hidden="true"></i> Safety stock is extra stock kept to cover days when demand is higher than average.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="/assets/js/chart-reveal.js"></script>
<script>
// ---- Item picker: searchable, submits the item's id ----------------------
(function () {
    const search = document.getElementById('taItemSearch');
    const hidden = document.getElementById('taItemValue');
    const options = [...document.querySelectorAll('#taItemOptions option')];
    const original = search.value;
    search.addEventListener('change', () => {
        const text = search.value.trim();
        if (text === '' || text === 'All items') {
            hidden.value = '';
        } else {
            const match = options.find(o => o.value === text && o.dataset.id);
            if (!match) { search.value = original; return; } // not an item: keep the current selection
            hidden.value = match.dataset.id;
        }
        search.form.submit();
    });
    // Clearing with the × button goes back to all items.
    search.addEventListener('search', () => { if (search.value === '' && original !== '') { hidden.value = ''; search.form.submit(); } });
})();

// ---- Charts ----------------------------------------------------------------
// Same as the Dashboard: the line chart is revealed left to right once on
// page load by revealPlugin (assets/js/chart-reveal.js); reduced-motion
// users see it complete. Charts are created once Poppins has loaded so
// chart text never renders in a fallback font.
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const MONTHLY = {
    labels: <?= json_encode($annualLabels ?? []) ?>,
    current: <?= json_encode(array_values($annualData ?? [])) ?>,
    previous: <?= json_encode(array_values($annualDataPrev ?? [])) ?>,
    currentLabel: <?= json_encode($yearLabel) ?>,
    previousLabel: <?= json_encode((string) ($selectedYear - 1)) ?>,
    title: <?= json_encode('Items sold per month' . ($selectedItemLabel !== '' ? ' · ' . $selectedItemLabel : '')) ?>,
    subtitle: <?= json_encode($yearLabel . ' compared with ' . ($selectedYear - 1)) ?>,
    monthsSoFar: <?= (int) ($monthsSoFar ?? 12) ?>,
};
const WEEKLY = {
    labels: <?= json_encode($weekLabels ?? []) ?>,
    current: <?= json_encode(array_values($weekData ?? [])) ?>,
    previous: <?= json_encode(array_values($weekDataPrev ?? [])) ?>,
    currentLabel: 'This week',
    previousLabel: 'Week before',
    title: <?= json_encode('Items sold per day' . ($selectedItemLabel !== '' ? ' · ' . $selectedItemLabel : '')) ?>,
    subtitle: <?= json_encode(($weekRangeLabel ?? '') . ', compared with the week before') ?>,
    monthsSoFar: null,
};

// Shades months that haven't happened yet, labelled "Not yet".
const futureShadePlugin = {
    id: 'futureShade',
    beforeDatasetsDraw(chart) {
        const soFar = chart.$view && chart.$view.monthsSoFar;
        const count = chart.data.labels.length;
        if (soFar === null || soFar === undefined || soFar >= count) return;
        const { ctx, chartArea, scales: { x } } = chart;
        const left = soFar === 0 ? chartArea.left : (x.getPixelForValue(soFar - 1) + x.getPixelForValue(soFar)) / 2;
        ctx.save();
        ctx.fillStyle = 'rgba(232, 229, 220, 0.45)';
        ctx.fillRect(left, chartArea.top, chartArea.right - left, chartArea.bottom - chartArea.top);
        ctx.fillStyle = '#8A8D7E';
        ctx.font = '500 11px Poppins, sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'top';
        ctx.fillText('Not yet', (left + chartArea.right) / 2, chartArea.top + 8);
        ctx.restore();
    }
};

// "Peak: N" on this period's highest point, always fully inside the chart
// area. Drawn in afterDraw, and only once the reveal has finished.
const peakLabelPlugin = {
    id: 'peakLabel',
    afterDraw(chart) {
        if (chart.$reveal !== 1) return;
        const data = chart.data.datasets[0].data;
        let maxIdx = -1;
        data.forEach((v, i) => { if (v !== null && v > 0 && (maxIdx < 0 || v > data[maxIdx])) maxIdx = i; });
        if (maxIdx < 0) return;
        const point = chart.getDatasetMeta(0).data[maxIdx];
        if (!point || isNaN(point.y)) return;

        const { ctx, chartArea } = chart;
        const label = 'Peak: ' + Number(data[maxIdx]).toLocaleString('en-PH');
        ctx.save();
        ctx.font = '600 12px Poppins, sans-serif';
        const boxW = ctx.measureText(label).width + 16, boxH = 24, gap = 10;
        const x = Math.min(Math.max(point.x - boxW / 2, chartArea.left + 2), chartArea.right - boxW - 2);
        let y = point.y - boxH - gap;
        if (y < chartArea.top + 2) y = Math.min(point.y + gap, chartArea.bottom - boxH - 2); // no room above: go below
        ctx.fillStyle = '#2C3A1E';
        ctx.beginPath();
        if (ctx.roundRect) ctx.roundRect(x, y, boxW, boxH, 6); else ctx.rect(x, y, boxW, boxH);
        ctx.fill();
        ctx.fillStyle = '#FFFFFF';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(label, x + boxW / 2, y + boxH / 2 + 0.5);
        ctx.restore();
    }
};

function datasetsFor(view) {
    return [
        {
            label: view.currentLabel,
            data: view.current,
            borderColor: '#4A6741',
            borderWidth: 3,
            backgroundColor: 'rgba(113, 170, 96, 0.14)',
            fill: 'origin',
            tension: 0.3,
            pointRadius: 2.5,
            pointBackgroundColor: '#4A6741',
            spanGaps: false,
        },
        {
            label: view.previousLabel,
            data: view.previous,
            borderColor: '#A9A493',
            borderWidth: 2,
            borderDash: [6, 5],
            backgroundColor: 'transparent',
            fill: false,
            tension: 0.3,
            pointRadius: 0,
        },
    ];
}

document.fonts.load('600 12px Poppins').catch(() => {}).finally(() => {
    Chart.defaults.font.family = "'Poppins', sans-serif";
    Chart.defaults.color = '#5C6152';

    const chart = new Chart(document.getElementById('trendChart'), {
        type: 'line',
        data: { labels: MONTHLY.labels, datasets: datasetsFor(MONTHLY) },
        options: {
            animation: false, // the left-to-right reveal plugin does the opening animation
            maintainAspectRatio: false, // fills .chart-fill, which grows with the card
            interaction: { mode: 'index', intersect: false },
            layout: { padding: { top: 8 } },
            plugins: {
                legend: {
                    position: 'top', align: 'end',
                    labels: { usePointStyle: true, pointStyle: 'line', pointStyleWidth: 28, padding: 16, font: { size: 12 } }
                },
                tooltip: {
                    titleFont: { family: "'Poppins', sans-serif", weight: '600' },
                    bodyFont: { family: "'Poppins', sans-serif" },
                    callbacks: { label: ctx => ctx.raw === null ? null : `${ctx.dataset.label}: ${Number(ctx.raw).toLocaleString('en-PH')} items` }
                }
            },
            scales: {
                y: { beginAtZero: true, min: 0, grid: { color: '#EEECE5' }, ticks: { precision: 0 } },
                x: { grid: { display: false } }
            }
        },
        // Order matters: the "Not yet" shading draws before the reveal clip,
        // so it's visible from the start like the grid.
        plugins: [futureShadePlugin, revealPlugin, peakLabelPlugin]
    });
    chart.$view = MONTHLY;

    // Monthly / Weekly toggle: same card, same colours, swaps the data.
    document.querySelectorAll('.ta-segment button').forEach(btn => {
        btn.addEventListener('click', () => {
            const view = btn.dataset.view === 'weekly' ? WEEKLY : MONTHLY;
            document.querySelectorAll('.ta-segment button').forEach(b => b.setAttribute('aria-pressed', String(b === btn)));
            document.getElementById('trendTitle').textContent = view.title;
            document.getElementById('trendSubtitle').textContent = view.subtitle;
            chart.$view = view;
            revealNow(chart); // no replay on toggle; never left half-revealed
            chart.data.labels = view.labels;
            chart.data.datasets = datasetsFor(view);
            chart.update();
        });
    });
});

// ---- Product performance: bars grow left to right (80 ms apart) ----------
(function () {
    const list = document.querySelector('.perf-list');
    if (!list || reduceMotion) return;
    list.classList.add('is-animating');
    list.getBoundingClientRect();
    requestAnimationFrame(() => list.classList.remove('is-animating'));
})();

// ---- ABC filter pills ------------------------------------------------------
document.querySelectorAll('.abc-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.abc-tab').forEach(b => { b.classList.toggle('active', b === btn); b.setAttribute('aria-pressed', String(b === btn)); });
        document.querySelectorAll('.abc-list').forEach(panel => {
            panel.style.display = panel.dataset.tabPanel === btn.dataset.tab ? '' : 'none';
        });
    });
});
</script>

<?= $this->endSection() ?>
