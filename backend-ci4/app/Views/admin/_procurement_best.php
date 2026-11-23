<?php
// app/Views/admin/_procurement_best.php
// Procurement Report > Best supplier: a read-only comparison of the suppliers that offer each size (App\Libraries\BestSupplier).
// List: $rows (the page's results), $bestShow, $bestCounts, filters, pager. Detail (?item=ID): $detail, one size's comparison.
// Nothing here changes an item's main supplier, Safety Stock, Reorder Point, EOQ or K-Means: the main supplier still drives the DSS.
use App\Libraries\BestSupplier;

$detail      = $detail ?? null;
$rows        = $rows ?? [];
$bestShow    = $bestShow ?? 'other';
$bestCounts  = $bestCounts ?? [];
$class       = $class ?? '';
$searchInput = $searchInput ?? '';
$searchError = $searchError ?? null;
$peso        = static fn (?float $n): string => $n === null ? '—' : '₱' . number_format($n, 2);
$base        = site_url('admin/procurement-report');
// The list's own filters, kept when opening an item and coming back.
$keep        = array_filter(['show' => $bestShow !== 'other' ? $bestShow : '', 'search' => (string) ($search ?? ''), 'class' => $class, 'per_page' => $_GET['per_page'] ?? '', 'page' => $_GET['page'] ?? ''], static fn ($v) => $v !== '' && $v !== null);
$listUrl     = static fn (array $params = []): string => $base . '?' . http_build_query(['tab' => 'best'] + $params);
$sampleBadge = ' <span class="sp-badge" title="Sample supplier: its prices are only for comparison. You cannot order from it.">Sample</span>';
$nameOf      = static function (array $result, ?string $supplierId) use ($sampleBadge): string {
    foreach ($result['rows'] as $r) {
        if ($r['supplier_id'] === $supplierId) {
            return esc($r['name']) . ($r['is_sample'] ? $sampleBadge : '');
        }
    }

    return '—';
};
$tagClass = ['Recommended' => 'is-recommended', 'Cheapest' => 'is-cheapest', 'Fastest' => 'is-fastest'];
?>
<style>
.bs-toolbar { display: flex; flex-wrap: wrap; gap: 10px 14px; align-items: center; margin: 0 0 14px; }
.bs-seg { display: inline-flex; border: 1px solid #D6D2C6; border-radius: 10px; overflow: hidden; background: #fff; }
.bs-seg a { padding: 7px 14px; font-size: 13px; font-weight: 600; color: #3A4030; text-decoration: none; border-right: 1px solid #D6D2C6; white-space: nowrap; }
.bs-seg a:last-child { border-right: 0; }
.bs-seg a.active { background: #3d5230; color: #fff; }
.bs-seg a:focus-visible { outline: 2px solid #4b6a3f; outline-offset: -2px; }
.bs-seg .bs-n { opacity: .75; font-weight: 500; }
.bs-toolbar input[type=search], .bs-toolbar select { padding: 7px 10px; border: 1px solid #D6D2C6; border-radius: 8px; font-size: 13px; background: #fff; }
.bs-toolbar input[type=search] { min-width: 220px; }
.bs-error { color: #8A1C1C; font-size: 12.5px; margin: -8px 0 10px; }
.bs-table { width: 100%; table-layout: auto; }
.bs-table td, .bs-table th { vertical-align: middle; }
.bs-table td.bs-msg { color: #6B6E60; font-style: italic; }
.bs-link { font-weight: 600; color: #1F2617; text-decoration: none; }
.bs-link:hover { text-decoration: underline; color: #2F5A27; }
.bs-warn { display: inline-block; margin-left: 2px; padding: 1px 8px; border-radius: 999px; background: #FBEDED; color: #8A1C1C; font-size: 11px; font-weight: 700; white-space: nowrap; cursor: help; }
.bs-saving { color: #2F5A27; font-weight: 700; }
.bs-muted { color: #8A8F85; }
.bs-tag { display: inline-block; margin: 1px 4px 1px 0; padding: 1px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 700; white-space: nowrap; }
.bs-tag.is-recommended { background: #3d5230; color: #fff; }
.bs-tag.is-cheapest { background: #E4EFDF; color: #2F5A27; }
.bs-tag.is-fastest { background: #F3E9CF; color: #6B4E0E; }
.bs-main { display: inline-block; margin-left: 6px; padding: 1px 8px; border-radius: 999px; background: #EEF0EA; color: #3A4030; font-size: 11px; font-weight: 700; }
.bs-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin: 4px 0 8px; }
.bs-head h2 { font-size: 20px; font-weight: 800; margin: 0; }
.bs-facts { display: flex; flex-wrap: wrap; gap: 6px 22px; color: #3A4030; font-size: 13.5px; margin: 0 0 12px; }
.bs-facts b { color: #6B6E60; font-weight: 600; margin-right: 4px; }
.bs-callout { border: 1px solid #D6D2C6; background: #fff; border-radius: 12px; padding: 10px 14px; margin: 0 0 10px; font-size: 13.5px; }
.bs-callout.is-warning { border-color: #E0B4B4; background: #FBEDED; color: #8A1C1C; font-weight: 600; }
.bs-callout.is-good { border-color: #BFD5B6; background: #EEF5EA; color: #2F5A27; }
.bs-callout.is-info { color: #3A4030; }
.bs-foot { color: #6B6E60; font-size: 12.5px; margin: 10px 0 0; }
.bs-row-main td { background: #FAF9F5; }
</style>

<div id="bestTab">
<?php if ($detail !== null): ?>

    <?php // ------------------------------------------------------------ one size ?>
    <?php
    $in       = $detail['inputs'];
    $name     = $detail['item_name'] . ($detail['size'] !== null ? ' · Size ' . $detail['size'] : '');
    $cannot   = $detail['status'] !== 'ok';
    $hasSample = $detail['sample_count'] > 0;
    $recommended = null;
    foreach ($detail['rows'] as $r) {
        $recommended = $r['supplier_id'] === $detail['recommended'] ? $r : $recommended;
    }
    ?>
    <div class="bs-head">
        <div>
            <a href="<?= esc($listUrl($keep), 'attr') ?>" class="lt-back" id="bsBack"><i class="bi bi-arrow-left"></i> All items</a>
            <h2><?= esc($name) ?></h2>
        </div>
    </div>
    <div class="bs-facts">
        <span><b>Class</b> <?= $in['class'] !== null ? esc($in['class']) : '—' ?></span>
        <span><b>Yearly demand (D)</b> <?= number_format($in['annual_demand']) ?></span>
        <span><b>Demand spread (σ)</b> <?= number_format($in['std_dev'], 3) ?></span>
        <span><b>Z-score</b> <?= number_format($in['z'], 3) ?></span>
        <span><b>EOQ</b> <?= number_format($in['eoq']) ?></span>
        <span><b>Ordering cost (S)</b> <?= $peso($in['ordering_cost']) ?></span>
        <span><b>Storage cost per piece a year (H)</b> <?= $peso($in['holding_cost']) ?></span>
        <span><b>System minimum</b> <?= number_format($in['minimum_order_qty']) ?></span>
    </div>

    <?php if ($detail['status'] === 'no_sales'): ?>
        <div class="bs-callout is-info" id="bsNoSales" role="note"><?= esc(BestSupplier::NO_SALES) ?></div>
    <?php elseif ($detail['status'] === 'no_prices'): ?>
        <div class="bs-callout is-info" id="bsNoPrices" role="note"><?= esc(BestSupplier::NO_PRICES) ?></div>
    <?php endif; ?>
    <?php if ($detail['only_supplier']): ?>
        <div class="bs-callout is-info" id="bsOnlySupplier" role="note"><strong>Only supplier.</strong> Nobody else offers this item, so there is nothing to compare it with.</div>
    <?php endif; ?>
    <?php if ($detail['single_source_warning'] !== null): ?>
        <div class="bs-callout is-warning" id="bsSingleSource" role="alert"><?= esc($detail['single_source_warning']) ?></div>
    <?php endif; ?>
    <?php if ($recommended !== null && $detail['saving_vs_main'] !== null): ?>
        <div class="bs-callout <?= $detail['saving_vs_main'] > 0 ? 'is-good' : 'is-info' ?>" id="bsSaving" role="note">
            <?php if ($recommended['is_main']): ?>
                The main supplier is already the lowest yearly cost.
            <?php else: ?>
                <strong><?= esc($recommended['name']) ?></strong> would cost <strong><?= $peso($detail['saving_vs_main']) ?></strong> a year less than the main supplier.<?= $recommended['is_sample'] ? ' It is a sample supplier, so you cannot order from it.' : '' ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="inv-table-card"><div class="data-table-wrap">
        <table class="data-table inv-table bs-table" id="bestCompare">
            <thead>
                <tr>
                    <th>Supplier</th>
                    <th class="col-num">Unit price</th>
                    <th class="col-num">Lead time</th>
                    <th class="col-num">Minimum</th>
                    <th class="col-num">Order qty (Q)</th>
                    <th class="col-num">Safety stock (SS)</th>
                    <th class="col-num">Yearly cost</th>
                    <th>Tags</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($detail['rows'] as $r): ?>
                    <tr class="bs-offer<?= $r['is_main'] ? ' bs-row-main' : '' ?>" data-supplier="<?= esc($r['supplier_id'], 'attr') ?>">
                        <td><strong><?= esc($r['name']) ?></strong><?= $r['is_main'] ? ' <span class="bs-main">Main supplier</span>' : '' ?><?= $r['is_sample'] ? $sampleBadge : '' ?></td>
                        <td class="col-num"><?= $r['priced'] ? $peso($r['unit_price']) : '<span class="bs-muted">No price set</span>' ?></td>
                        <td class="col-num"><?= (int) $r['lead_time_days'] ?> days</td>
                        <td class="col-num"><?= $r['min_order_qty'] !== null ? number_format($r['min_order_qty']) : '—' ?></td>
                        <td class="col-num"><?= $r['q'] !== null ? number_format($r['q']) : '—' ?></td>
                        <td class="col-num"><?= $r['ss'] !== null ? number_format($r['ss']) : '—' ?></td>
                        <td class="col-num"><?= $r['yearly_cost'] !== null ? $peso($r['yearly_cost']) : '—' ?></td>
                        <td>
                            <?php foreach ($r['tags'] as $tag): ?><span class="bs-tag <?= esc($tagClass[$tag], 'attr') ?>"><?= esc($tag) ?></span> <?php endforeach; ?>
                            <?php if ($r['is_sample']): ?><span class="bs-muted" title="You cannot order from a sample supplier.">can't be ordered from</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($detail['rows'] === []): ?>
                    <tr><td colspan="8" class="bs-msg text-center py-4">This size has no supplier yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div></div>

    <p class="bs-foot">
        Yearly cost = D × unit price + (D ÷ Q) × S + (Q ÷ 2 + SS) × H, where Q = the larger of the EOQ, the system minimum and the supplier's own minimum, and SS = Z × σ × √lead time.
        Comparison only: nothing here changes the main supplier, Safety Stock, Reorder Point or the Order Qty.<?= $hasSample ? ' Sample suppliers are example data for comparison.' : '' ?>
    </p>

<?php else: ?>

    <?php // ------------------------------------------------------------ the list ?>
    <form method="get" action="<?= esc($base, 'attr') ?>" class="bs-toolbar" id="bestFilters" role="search">
        <input type="hidden" name="tab" value="best">
        <?php if ($bestShow !== 'other'): ?><input type="hidden" name="show" value="<?= esc($bestShow, 'attr') ?>"><?php endif; ?>
        <div class="bs-seg" role="group" aria-label="Which items">
            <?php foreach (BestSupplier::SHOW as $key => $label): ?>
                <a href="<?= esc($listUrl(array_filter(['show' => $key !== 'other' ? $key : '', 'search' => (string) ($search ?? ''), 'class' => $class])), 'attr') ?>" class="<?= $bestShow === $key ? 'active' : '' ?>"<?= $bestShow === $key ? ' aria-current="true"' : '' ?>><?= esc($label) ?> <span class="bs-n"><?= (int) ($bestCounts[$key] ?? 0) ?></span></a>
            <?php endforeach; ?>
        </div>
        <input type="search" name="search" id="bestSearch" value="<?= esc((string) $searchInput, 'attr') ?>" placeholder="Search by item name or ID…" maxlength="50" aria-label="Search by item name or ID"<?= $searchError ? ' aria-invalid="true"' : '' ?>>
        <select name="class" id="bestClass" aria-label="Class" onchange="this.form.submit()">
            <option value="">All classes</option>
            <?php foreach (\App\Libraries\ProcurementReport::CLASSES as $c): ?>
                <option value="<?= esc($c, 'attr') ?>"<?= $class === $c ? ' selected' : '' ?>>Class <?= esc($c) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline-secondary">Search</button>
        <?php if ($class !== '' || (string) ($search ?? '') !== ''): ?>
            <a href="<?= esc($listUrl($bestShow !== 'other' ? ['show' => $bestShow] : []), 'attr') ?>" class="clear-filters" id="bestClear">Clear filters</a>
        <?php endif; ?>
    </form>
    <?php if ($searchError): ?><p class="bs-error" role="alert"><?= esc($searchError) ?></p><?php endif; ?>

    <div class="inv-table-card" id="bestResults"><div class="data-table-wrap">
        <table class="data-table inv-table bs-table" id="bestList">
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="col-center">Size</th>
                    <th class="col-center">Class</th>
                    <th class="col-center">Suppliers</th>
                    <th>Recommended</th>
                    <th>Cheapest</th>
                    <th>Fastest</th>
                    <th class="col-num">Saving a year <?= help_tip('Saving a year', 'What the recommended supplier would save a year against the main supplier. A comparison only: nothing is changed.') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php $open = $listUrl(['item' => $r['item_id']] + $keep); ?>
                    <tr class="bs-item" data-item-id="<?= esc($r['item_id'], 'attr') ?>">
                        <td><a class="bs-link" href="<?= esc($open, 'attr') ?>"><?= esc($r['item_name']) ?></a></td>
                        <td class="col-center"><?= $r['size'] !== null ? esc($r['size']) : '&mdash;' ?></td>
                        <td class="col-center"><?php if ($r['class'] !== null): ?><span class="abc-badge cls-<?= esc(strtolower($r['class']), 'attr') ?>"><?= esc($r['class']) ?></span><?php else: ?>&mdash;<?php endif; ?></td>
                        <td class="col-center"><?= (int) $r['offer_count'] ?><?php if ($r['single_source_warning'] !== null): ?> <span class="bs-warn" title="<?= esc($r['single_source_warning'], 'attr') ?>">Single source</span><?php endif; ?></td>
                        <?php if ($r['status'] !== 'ok' || $r['only_supplier']): ?>
                            <td colspan="3" class="bs-msg"><?= esc(BestSupplier::note($r)) ?></td>
                            <td class="col-num bs-muted">—</td>
                        <?php else: ?>
                            <td class="bs-rec"><?= $nameOf($r, $r['recommended']) ?></td>
                            <td><?= $nameOf($r, $r['cheapest']) ?></td>
                            <td><?= $nameOf($r, $r['fastest']) ?></td>
                            <td class="col-num"><?php if (($r['saving_vs_main'] ?? 0) > 0): ?><span class="bs-saving"><?= $peso($r['saving_vs_main']) ?></span><?php else: ?><span class="bs-muted">—</span><?php endif; ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8" class="bs-msg text-center py-4">
                        <?php if ($bestShow === 'warn'): ?>No class A item has a single real supplier.
                        <?php elseif ($bestShow === 'other'): ?>No item has another supplier's price yet.
                        <?php else: ?>No items match.<?php endif; ?>
                    </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($rows !== []): ?>
        <?= view('partials/_pager', [
            'baseUrl'        => $base,
            'currentPage'    => $currentPage ?? 1,
            'totalPages'     => $totalPages ?? 1,
            'totalItems'     => $totalItems ?? 0,
            'perPage'        => $perPage ?? 10,
            'perPageOptions' => $perPageOptions ?? [10, 25, 50],
            'shownCount'     => count($rows),
        ]) ?>
    <?php endif; ?>
    </div>
    <p class="bs-foot">Comparison only: nothing here changes an item's main supplier, Safety Stock, Reorder Point or Order Qty. Sample suppliers are example data and cannot be ordered from.</p>

<?php endif; ?>
</div>
