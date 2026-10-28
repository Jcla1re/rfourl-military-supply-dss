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
 * @var array[]  $abcTopItems  (top N, PDF only)
 * @var array[]  $abcItems     every item, ranked by sales value
 * @var array{A: array[], B: array[], C: array[]} $abcByClass
 * @var array{A: int, B: int, C: int} $abcClassCounts
 */
$selectedItem = $selectedItem ?? '';
$products = $products ?? [];
$selectedYear = (int) ($selectedYear ?? date('Y'));

// Item picker labels: name, plus size when there is one, so same-name items
// in different sizes can be told apart.
$itemLabel = static fn(array $p): string => $p['item_name'] . (!empty($p['size']) ? ' · ' . $p['size'] : '');
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
    <a class="btn btn-topbar"
        href="<?= site_url('admin/trend-analysis/pdf') . '?year=' . esc((string) ($selectedYear ?? date('Y'))) . '&item=' . esc((string) ($selectedItem ?? '')) ?>"><i
            class="bi bi-file-earmark-pdf"></i> Export PDF</a>
</form>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<style>

    .page-wrap.ta-page {
        font-family: 'Poppins', sans-serif;
    }

    /* Header filters (sit in the green top bar) */
    .ta-filters {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    .ta-filters .ta-year {
        width: auto;
        min-width: 96px;
    }

    .ta-item-picker {
        position: relative;
        width: 240px;
    }

    .ta-item-picker i {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #8A8D7E;
        font-size: 14px;
        pointer-events: none;
    }

    .ta-item-picker input {
        padding-left: 34px;
    }

    /* Trend cards */
    .ta-card {
        background: #fff;
        border-radius: var(--radius-card);
        padding: 18px 20px;
        height: 100%;
        box-shadow: 0 1px 3px rgba(0, 0, 0, .06);
    }

    .ta-card .label {
        font-size: 13px;
        color: #5C6152;
    }

    .ta-card .value {
        margin-top: 4px;
        font-size: 26px;
        font-weight: 600;
        color: #1F2617;
        line-height: 1.2;
        overflow-wrap: anywhere;
        font-variant-numeric: tabular-nums;
    }

    .ta-card .value.is-text {
        font-size: 20px;
    }

    .ta-card .sub {
        margin-top: 4px;
        font-size: 13px;
        color: #5C6152;
    }

    .ta-change {
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .ta-change.up {
        color: #3B7D2C;
    }

    .ta-change.down {
        color: #B42318;
    }

    .ta-change.flat {
        color: #5C6152;
    }

    /* Cards */
    .chart-card {
        background: #fff;
        border-radius: var(--radius-card);
        padding: 20px 22px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, .06);
        margin-bottom: 16px;
    }

    .chart-card h6 {
        font-size: 15px;
        font-weight: 600;
        color: #1F2617;
        margin: 0;
    }

    .card-sub {
        font-size: 13px;
        color: #5C6152;
        margin: 2px 0 0;
    }

    .card-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 14px;
        flex-wrap: wrap;
    }

    /* Monthly / Weekly segmented control */
    .ta-segment {
        display: inline-flex;
        padding: 3px;
        background: #F5F3EE;
        border: 1px solid #E8E5DC;
        border-radius: var(--radius-control);
    }

    .ta-segment button {
        border: 0;
        background: transparent;
        padding: 6px 14px;
        border-radius: 8px;
        font: inherit;
        font-size: 13px;
        font-weight: 500;
        color: #5C6152;
        cursor: pointer;
        transition: background-color 0.15s ease, color 0.15s ease;
    }

    .ta-segment button:hover {
        color: #2C3A1E;
    }

    .ta-segment button[aria-pressed="true"] {
        background: #fff;
        color: #2C3A1E;
        box-shadow: 0 1px 2px rgba(0, 0, 0, .08);
    }

    .ta-segment button:focus-visible {
        outline: 3px solid var(--focus-ring);
        outline-offset: 2px;
    }

    /* Product performance */
    .perf-card {
        display: flex;
        flex-direction: column;
    }

    .perf-list {
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }

    .perf-row {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        font-size: 13px;
        margin-bottom: 6px;
    }

    .perf-row span {
        color: #2C3A1E;
        overflow-wrap: anywhere;
    }

    .perf-row strong {
        font-weight: 600;
        color: #1F2617;
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
    }

    .perf-track {
        height: 8px;
        background: #E8E5DC;
        border-radius: 4px;
        overflow: hidden;
        margin-bottom: 16px;
    }

    .perf-track>span {
        display: block;
        height: 100%;
        width: var(--w);
        background: linear-gradient(90deg, #8CC27A 0%, #4F8542 100%);
        border-radius: 4px;
        transition: width 0.9s ease-out;
        transition-delay: calc(var(--i, 0) * 80ms);
    }

    .perf-list.is-animating .perf-track>span {
        width: 0;
        transition: none;
    }

    /* ABC classification */
    .abc-stack {
        display: flex;
        height: 10px;
        border-radius: 5px;
        overflow: hidden;
        margin: 4px 0 12px;
        background: #F5F3EE;
    }

    .abc-stack>span {
        display: block;
        height: 100%;
    }

    .cls-a-fill {
        background: #2C3A1E;
    }

    .cls-b-fill {
        background: #71AA60;
    }

    .cls-c-fill {
        background: #C5DDBB;
    }

    .cls-n-fill {
        background: #D9D6CC;
    }

    .abc-bar-note {
        margin: -8px 0 10px;
        font-size: 12px;
        color: #6B7062;
    }

    .abc-badge.cls-n {
        background: #ECEAE2;
        color: #6B7062;
    }

    .abc-none {
        color: #8A8F80;
    }

    .abc-meanings {
        list-style: none;
        margin: 0 0 16px;
        padding: 0;
        display: grid;
        gap: 6px;
    }

    .abc-meanings li {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 13px;
        color: #5C6152;
    }

    .abc-meanings strong {
        color: #1F2617;
        font-weight: 600;
        min-width: 34px;
    }

    .abc-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 22px;
        height: 22px;
        border-radius: 50%;
        font-size: 12px;
        font-weight: 600;
        flex-shrink: 0;
    }

    .abc-badge.cls-a {
        background: #2C3A1E;
        color: #fff;
    }

    .abc-badge.cls-b {
        background: #71AA60;
        color: #fff;
    }

    .abc-badge.cls-c {
        background: #E4EFDF;
        color: #2F4A22;
    }

    .abc-tabs {
        display: flex;
        gap: 6px;
        margin-bottom: 10px;
        flex-wrap: wrap;
    }

    .abc-tab {
        border: 1px solid #E8E5DC;
        background: #fff;
        border-radius: var(--radius-pill);
        padding: 5px 12px;
        font: inherit;
        font-size: 13px;
        font-weight: 500;
        color: #5C6152;
        cursor: pointer;
        transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease;
    }

    .abc-tab:hover {
        background: #F5F3EE;
        color: #2C3A1E;
    }

    .abc-tab.active {
        background: #2C3A1E;
        border-color: #2C3A1E;
        color: #fff;
    }

    .abc-tab:focus-visible {
        outline: 3px solid var(--focus-ring);
        outline-offset: 2px;
    }

    /* Rows have one fixed height so the list can be exactly 10 rows tall. */
    .abc-list {
        --abc-row-h: 40px;
    }

    .abc-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        height: var(--abc-row-h);
        box-sizing: border-box;
        padding: 0;
        border-bottom: 1px solid #F0EEE8;
        font-size: 14px;
        color: #2C3A1E;
    }

    .abc-rank {
        width: 28px;
        flex-shrink: 0;
        text-align: right;
        color: #8A8F80;
        font-variant-numeric: tabular-nums;
    }

    .abc-name {
        flex: 1;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .abc-code {
        margin-left: 6px;
        font-size: 11px;
        color: #8A8F80;
    }

    /* Only the rows scroll: exactly 10 visible, the rest inside the card. Lists with 10 or
       fewer rows are shorter than the limit, so they have no scrollbar or empty space. */
    .abc-scroll {
        position: relative;
    }

    .abc-list {
        max-height: calc(10 * var(--abc-row-h));
        overflow-y: auto;
        overscroll-behavior: contain;
        scrollbar-width: thin;
        scrollbar-color: #D2CEC2 transparent;
    }

    .abc-list::-webkit-scrollbar {
        width: 6px;
    }

    .abc-list::-webkit-scrollbar-thumb {
        background: #D2CEC2;
        border-radius: 3px;
    }

    .abc-list::-webkit-scrollbar-thumb:hover {
        background: #B9B5A8;
    }

    /* Soft fade at the bottom edge while more rows are below. */
    .abc-scroll::after {
        content: "";
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        height: 18px;
        background: linear-gradient(to bottom, rgba(255, 255, 255, 0), #fff);
        pointer-events: none;
        opacity: 0;
        transition: opacity 0.15s ease;
    }

    .abc-scroll.can-scroll::after {
        opacity: 1;
    }

    .abc-row:last-child {
        border-bottom: none;
    }

    .abc-row .abc-right,
    .abc-head .abc-right {
        display: flex;
        align-items: center;
        gap: 10px;
        color: #5C6152;
        font-variant-numeric: tabular-nums;
    }

    /* Column headers over the item list; Share and Class columns have fixed
   widths so the header text lines up with the values and badges below. */
    .abc-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 4px 0 8px;
        border-bottom: 1px solid #E8E5DC;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: #4A5040;
    }

    .abc-head .abc-right {
        color: #4A5040;
    }

    .abc-share {
        min-width: 48px;
        text-align: right;
    }

    .abc-cls {
        width: 44px;
        display: flex;
        justify-content: center;
    }

    /* Demand variability */
    .var-table {
        width: 100%;
        border-collapse: collapse;
    }

    .var-table th {
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .02em;
        color: #5C6152;
        padding: 0 0 8px;
        border-bottom: 1px solid #E8E5DC;
        text-align: left;
    }

    .var-table td {
        padding: 10px 0;
        border-bottom: 1px solid #F0EEE8;
        font-size: 14px;
        color: #2C3A1E;
    }

    /* The list scrolls inside the card: exactly 10 rows below the header, which stays put.
       Rows and header have fixed heights so the limit is exact; 10 rows or fewer have no scrollbar. */
    .var-wrap {
        position: relative;
        --var-row-h: 40px;
        --var-head-h: 34px;
    }

    .var-scroll {
        max-height: calc(var(--var-head-h) + 10 * var(--var-row-h));
        overflow-y: auto;
        overscroll-behavior: contain;
        scrollbar-width: thin;
        scrollbar-color: #D2CEC2 transparent;
    }

    .var-scroll::-webkit-scrollbar {
        width: 6px;
    }

    .var-scroll::-webkit-scrollbar-thumb {
        background: #D2CEC2;
        border-radius: 3px;
    }

    .var-scroll::-webkit-scrollbar-thumb:hover {
        background: #B9B5A8;
    }

    /* Separate borders so the sticky header has no hairline seams. */
    .var-scroll .var-table {
        border-collapse: separate;
        border-spacing: 0;
    }

    .var-scroll .var-table thead {
        position: sticky;
        top: 0;
        z-index: 2;
        background: #fff;
    }

    .var-scroll .var-table th {
        height: var(--var-head-h);
        padding: 0;
        vertical-align: middle;
        background: #fff;
    }

    .var-scroll .var-table td {
        height: var(--var-row-h);
        padding: 0;
        vertical-align: middle;
    }

    .var-table .vrank {
        width: 28px;
        text-align: right;
        padding-right: 12px;
        color: #8A8F80;
        font-variant-numeric: tabular-nums;
    }

    .var-table th.vrank {
        color: #5C6152;
    }

    /* Fixed column widths so the name column gets the leftover space and ellipsizes. */
    .var-scroll .var-table {
        table-layout: fixed;
        width: 100%;
    }

    .var-scroll .var-table .vrank { width: 36px; padding-right: 12px; }
    .var-scroll .var-table th.num:nth-of-type(3) { width: 116px; }
    .var-scroll .var-table th.num:nth-of-type(4) { width: 118px; }
    .var-scroll .var-table th.cls { width: 84px; }

    .var-table .vname {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .var-wrap::after {
        content: "";
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        height: 18px;
        background: linear-gradient(to bottom, rgba(255, 255, 255, 0), #fff);
        pointer-events: none;
        opacity: 0;
        transition: opacity 0.15s ease;
    }

    .var-wrap.can-scroll::after {
        opacity: 1;
    }

    /* ABC classification and Demand variability sit side by side at the same height. */
    @media (min-width: 992px) {
        .ta-pair>div>.chart-card {
            height: 100%;
            margin-bottom: 0;
        }
    }

    .var-table .num {
        text-align: right;
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
        padding-left: 12px;
    }

    .var-table .cls {
        text-align: center;
        width: 64px;
        padding-left: 12px;
    }

    .var-note {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        margin: 12px 0 0;
        font-size: 13px;
        line-height: 1.5;
        color: #5C6152;
    }

    .var-note i {
        font-size: 16px;
        line-height: 1.25;
        flex-shrink: 0;
    }

    @media (prefers-reduced-motion: reduce) {
        .perf-track>span {
            transition: none;
        }
    }

    @media (max-width: 768px) {
        .ta-item-picker {
            width: 180px;
        }
    }
</style>

<div class="page-wrap ta-page">
    <?php
    $yearLabel = (string) $selectedYear;
    $changePct = $changePct ?? null;
    $changeDir = $changePct === null ? 'flat' : ($changePct > 0.05 ? 'up' : ($changePct < -0.05 ? 'down' : 'flat'));
    ?>
    <div class="row g-3 mb-1">
        <div class="col-sm-6 col-xl-3">
            <div class="ta-card">
                <div class="label">Items sold in <?= esc($yearLabel) ?></div>
                <div class="value" data-count="<?= (int) ($unitsYtd ?? 0) ?>" aria-hidden="true"><?= number_format($unitsYtd ?? 0) ?></div>
                <span class="visually-hidden"><?= number_format($unitsYtd ?? 0) ?></span>
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
                        <?php elseif ($changeDir === 'down'): ?><i class="bi bi-arrow-down"
                                aria-hidden="true"></i><?php endif; ?>
                        <span data-count="<?= number_format(abs($changePct), 1, '.', '') ?>"
                            data-decimals="1" data-suffix="%"
                            data-prefix="<?= $changeDir === 'up' ? '+' : ($changeDir === 'down' ? '-' : '') ?>"
                            aria-hidden="true"><?= ($changeDir === 'up' ? '+' : '') . number_format($changePct, 1) ?>%</span>
                        <span class="visually-hidden"><?= ($changeDir === 'up' ? '+' : '') . number_format($changePct, 1) ?>%</span>
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
                        <h6 id="trendTitle">Items sold per
                            month<?= $selectedItemLabel !== '' ? ' · ' . esc($selectedItemLabel) : '' ?></h6>
                        <p class="card-sub" id="trendSubtitle"><?= esc($yearLabel) ?> compared with
                            <?= (int) $selectedYear - 1 ?>
                        </p>
                    </div>
                    <div class="ta-segment" role="group" aria-label="Chart period">
                        <button type="button" data-view="monthly" aria-pressed="true">Monthly</button>
                        <button type="button" data-view="weekly" aria-pressed="false">Weekly</button>
                    </div>
                </div>
                <div class="chart-fill"><canvas id="trendChart" aria-label="Items sold chart" role="img"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="chart-card h-100 perf-card">
                <div class="card-head">
                    <div>
                        <h6>Product performance</h6>
                        <p class="card-sub">Items sold, all time</p>
                    </div>
                </div>
                <?php if (empty($topSellers)): ?>
                    <div class="text-muted small">No sales recorded yet.</div>
                <?php else: ?>
                    <div class="perf-list">
                        <?php foreach ($topSellers as $i => $t): ?>
                            <div>
                                <div class="perf-row">
                                    <span><?= esc($t['item_name'] ?? $t['item_id']) ?></span><strong><?= number_format((int) $t['units_sold']) ?></strong>
                                </div>
                                <div class="perf-track"><span
                                        style="--w: <?= round(($t['units_sold'] / ($maxUnits ?: 1)) * 100) ?>%; --i: <?= (int) $i ?>"></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1 ta-pair">
        <div class="col-lg-6">
            <div class="chart-card">
                <div class="card-head">
                    <div>
                        <h6>ABC classification (K-Means) <?= \App\Libraries\Glossary::tip('abc') ?></h6>
                        <p class="card-sub">Grouped by cost, demand, demand variability and lead time.</p>
                    </div>
                </div>
                <?php if (empty($abcItems)): ?>
                    <div class="text-muted small"><i class="bi bi-graph-up" aria-hidden="true"></i> No sales in <?= esc($yearLabel) ?> yet.</div>
                <?php else: ?>
                    <?php
                    $abcUnclassified = $abcUnclassified ?? 0;
                    $classedTotal = array_sum($abcClassCounts ?? []);
                    $itemTotal = max(1, $classedTotal + $abcUnclassified);
                    $svc = \App\Models\ClusterSegmentModel::SERVICE_LEVELS;
                    $abcByClass = $abcByClass ?? ['A' => [], 'B' => [], 'C' => []];
                    $abcClassCounts = $abcClassCounts ?? ['A' => 0, 'B' => 0, 'C' => 0];
                    $renderAbcRows = function (array $items) {
                        foreach ($items as $item) {
                            ?>
                            <?php
                            $share = round($item['pct'], 1);
                            $shareText = $share < 0.1 ? '<0.1%' : ($share < 10 ? number_format($share, 1) : (string) round($item['pct'])) . '%';
                            ?>
                            <div class="abc-row">
                                <span class="abc-rank"><?= (int) $item['rank'] ?></span>
                                <span class="abc-name" title="<?= esc($item['label']) ?>"><?= esc($item['label']) ?><?php if (!empty($item['show_code'])): ?><span class="abc-code"><?= esc($item['code']) ?></span><?php endif; ?></span>
                                <span class="abc-right">
                                    <span class="abc-share"><?= esc($shareText) ?></span>
                                    <span class="abc-cls"><?php if ($item['class']): ?><span class="abc-badge cls-<?= strtolower($item['class']) ?>"
                                            title="Class <?= esc($item['class']) ?>, <?= (int) $item['service_level'] ?>% service level"
                                            aria-label="Class <?= esc($item['class']) ?>, <?= (int) $item['service_level'] ?>% service level"><?= esc($item['class']) ?></span><?php else: ?><span class="abc-none" title="<?= esc(\App\Models\ClusterSegmentModel::UNCLASSIFIED_NOTE) ?>" aria-label="<?= esc(\App\Models\ClusterSegmentModel::UNCLASSIFIED_NOTE) ?>">&mdash;</span><?php endif; ?></span>
                                </span>
                            </div>
                            <?php
                        }
                    };
                    ?>
                    <div class="abc-stack" role="img"
                        aria-label="Items per class: A <?= (int) $abcClassCounts['A'] ?>, B <?= (int) $abcClassCounts['B'] ?>, C <?= (int) $abcClassCounts['C'] ?><?= $abcUnclassified ? ', not classified ' . (int) $abcUnclassified : '' ?>">
                        <span class="cls-a-fill" style="width: <?= round($abcClassCounts['A'] / $itemTotal * 100, 2) ?>%"></span>
                        <span class="cls-b-fill" style="width: <?= round($abcClassCounts['B'] / $itemTotal * 100, 2) ?>%"></span>
                        <span class="cls-c-fill" style="width: <?= round($abcClassCounts['C'] / $itemTotal * 100, 2) ?>%"></span>
                        <?php if ($abcUnclassified): ?><span class="cls-n-fill" style="width: <?= round($abcUnclassified / $itemTotal * 100, 2) ?>%"></span><?php endif; ?>
                    </div>
                    <p class="abc-bar-note">Number of items in each class</p>
                    <ul class="abc-meanings">
                        <?php foreach (['A', 'B', 'C'] as $k): ?>
                            <li><span class="abc-badge cls-<?= strtolower($k) ?>"><?= $k ?></span><strong><?= number_format((int) $abcClassCounts[$k]) ?> items</strong>
                                <?= esc(\App\Models\ClusterSegmentModel::PRIORITY[$k]) ?>, <?= (int) $svc[$k] ?>% service level</li>
                        <?php endforeach; ?>
                        <?php if ($abcUnclassified): ?>
                            <li><span class="abc-badge cls-n">&mdash;</span><strong><?= number_format((int) $abcUnclassified) ?> items</strong>
                                Not classified yet. <?= esc(\App\Models\ClusterSegmentModel::UNCLASSIFIED_NOTE) ?></li>
                        <?php endif; ?>
                    </ul>

                    <div class="abc-tabs" role="group" aria-label="Filter by class">
                        <button type="button" class="abc-tab active" data-tab="all" aria-pressed="true">All</button>
                        <button type="button" class="abc-tab" data-tab="A" aria-pressed="false">A
                            (<?= (int) $abcClassCounts['A'] ?>)</button>
                        <button type="button" class="abc-tab" data-tab="B" aria-pressed="false">B
                            (<?= (int) $abcClassCounts['B'] ?>)</button>
                        <button type="button" class="abc-tab" data-tab="C" aria-pressed="false">C
                            (<?= (int) $abcClassCounts['C'] ?>)</button>
                    </div>

                    <div class="abc-head" aria-hidden="true">
                        <span class="abc-rank">#</span>
                        <span class="abc-name">Item</span>
                        <span class="abc-right"><span class="abc-share">Sales share</span><span
                                class="abc-cls">Class</span></span>
                    </div>
                    <div class="abc-scroll" id="abcScroll">
                    <div class="abc-list" data-tab-panel="all">
                        <?php $renderAbcRows($abcItems); ?>
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
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="chart-card">
                <div class="card-head">
                    <div>
                        <h6>Daily demand <?= help_tip('Daily demand', [
                                'Items ranked by how many sell each day.',
                                'Safety Stock is extra stock kept for busy days. It comes from how much daily sales go up and down.',
                            ]) ?></h6>
                        <p class="card-sub">Items ranked by daily sales.</p>
                    </div>
                </div>
                <?php if (empty($variabilityAll)): ?>
                    <div class="text-muted small"><i class="bi bi-calculator" aria-hidden="true"></i> No order calculations yet. <a href="<?= site_url('admin/settings?tab=dss') ?>">Run the calculation</a></div>
                <?php else: ?>
                    <div class="var-wrap" id="varWrap">
                    <div class="var-scroll" id="varScroll">
                    <table class="var-table">
                        <thead>
                            <tr>
                                <th class="vrank">#</th>
                                <th>Item</th>
                                <th class="num">Daily sales</th>
                                <th class="num"><?= \App\Libraries\Glossary::labelWithTip('safety_stock') ?></th>
                                <th class="cls">Class <?= help_tip('Class and service level', [
                                    'The K-Means class decides the service level: A 95%, B 90%, C 85%.',
                                    'That class sets the Z-score used for this item\'s Safety Stock.',
                                ]) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($variabilityAll as $v): ?>
                                <?php $ss = (int) round((float) $v['safety_stock']); ?>
                                <tr>
                                    <td class="vrank"><?= (int) $v['rank'] ?></td>
                                    <td class="vname" title="<?= esc($v['label']) ?>"><?= esc($v['label']) ?><?php if (!empty($v['show_code'])): ?><span class="abc-code"><?= esc($v['code']) ?></span><?php endif; ?></td>
                                    <td class="num"><?= number_format((float) $v['avg_daily_demand'], 2) ?> / day</td>
                                    <td class="num"><?= number_format($ss) ?> <?= $ss === 1 ? 'unit' : 'units' ?></td>
                                    <td class="cls">
                                        <?php if (!empty($v['class'])): ?>
                                            <span class="abc-badge cls-<?= strtolower($v['class']) ?>"
                                                title="Class <?= esc($v['class']) ?>, <?= (int) $v['service_level'] ?>% service level (used for this item's Safety Stock)"
                                                aria-label="Class <?= esc($v['class']) ?>, <?= (int) $v['service_level'] ?>% service level"><?= esc($v['class']) ?></span>
                                        <?php else: ?><span class="abc-none" title="<?= esc(\App\Models\ClusterSegmentModel::UNCLASSIFIED_NOTE) ?>" aria-label="<?= esc(\App\Models\ClusterSegmentModel::UNCLASSIFIED_NOTE) ?>">&mdash;</span><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="/assets/js/count-up.js"></script>
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

    document.fonts.load('600 12px Poppins').catch(() => { }).finally(() => {
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
                panel.scrollTop = 0;
            });
            updateAbcScroll();
        });
    });

    // ---- ABC list: bottom fade while more rows are below, header kept in line with the rows ----
    const abcScroll = document.getElementById('abcScroll');
    const abcHead = document.querySelector('.abc-head');
    function updateAbcScroll() {
        if (!abcScroll) return;
        const panel = Array.from(abcScroll.querySelectorAll('.abc-list')).find(p => p.style.display !== 'none');
        if (!panel) return;
        abcScroll.classList.toggle('can-scroll', panel.scrollHeight - panel.scrollTop - panel.clientHeight > 1);
        // A scrollbar narrows the rows; give the header the same right padding so the columns stay aligned.
        if (abcHead) abcHead.style.paddingRight = (panel.offsetWidth - panel.clientWidth) + 'px';
    }
    document.querySelectorAll('.abc-list').forEach(panel => panel.addEventListener('scroll', updateAbcScroll, { passive: true }));
    window.addEventListener('resize', updateAbcScroll);
    updateAbcScroll();

    // ---- Demand variability list: bottom fade while more rows are below ----
    const varWrap = document.getElementById('varWrap');
    const varScroll = document.getElementById('varScroll');
    function updateVarScroll() {
        if (!varWrap || !varScroll) return;
        varWrap.classList.toggle('can-scroll', varScroll.scrollHeight - varScroll.scrollTop - varScroll.clientHeight > 1);
    }
    varScroll?.addEventListener('scroll', updateVarScroll, { passive: true });
    window.addEventListener('resize', updateVarScroll);
    updateVarScroll();

    initCountUp('.ta-card [data-count]');
</script>

<?= $this->endSection() ?>