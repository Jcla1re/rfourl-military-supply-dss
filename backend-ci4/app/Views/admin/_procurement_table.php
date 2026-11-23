<?php
// app/Views/admin/_procurement_table.php
// Procurement Report table + pagination (same card, table and footer as Inventory). Rendered by
// the full page and by the live search (ReorderAlertController::report() returns just this
// partial for an AJAX request). Read-only: no Order buttons.
//
// Gray values (muted text, nothing else): Reorder At 0 (no reorder point yet: too few sales) and
// an Order Qty that is only the minimum order because the EOQ was lower.
$rows          = $rows ?? [];
$searchTerm    = (string) ($search ?? '');
$minimumOrder  = (int) ($minimumOrder ?? 0);
$classLevels   = \App\Models\ClusterSegmentModel::SERVICE_LEVELS;
$classTarget   = 'Target: ' . implode(' · ', array_map(static fn ($c, $p) => "{$c} {$p}%", array_keys($classLevels), $classLevels));
$clearUrl      = site_url('admin/procurement-report') . (isset($_GET['per_page']) ? '?' . http_build_query(['per_page' => $_GET['per_page']]) : '');
?>
<div id="procurementResults" class="inv-table-card">
    <div class="data-table-wrap">
        <table class="data-table inv-table pr-table">
            <colgroup>
                <col>                  <!-- Item: takes the remaining space -->
                <col class="c-size">   <!-- Size -->
                <col class="c-class">  <!-- Class -->
                <col class="c-onhand"> <!-- On hand -->
                <col class="c-rop">    <!-- Reorder at -->
                <col class="c-status"> <!-- Status -->
                <col class="c-ss">     <!-- Safety stock -->
                <col class="c-eoq">    <!-- Order qty -->
            </colgroup>
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="col-center">Size</th>
                    <th class="col-center">Class <?= help_tip('Class', "Group from K-Means clustering.\n\n" . $classTarget) ?></th>
                    <th class="col-num">On Hand</th>
                    <th class="col-num col-term">Reorder <span class="term-tail">At<?= help_tip('Reorder At', "Order when stock reaches this number.\n\nGray 0: too few sales to set one.") ?></span></th>
                    <th>Status <?= help_tip('Status', \App\Models\ProductModel::STATUS_HELP) ?></th>
                    <th class="col-num">Safety Stock</th>
                    <th class="col-num col-term">Order <span class="term-tail">Qty<?= help_tip('Order Qty', "Suggested amount to order (EOQ).\n\nGray {$minimumOrder}: the minimum order.") ?></span></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows !== []): ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $pillClass = \App\Models\ProductModel::statusColor($r['status']);
                        $pillTitle = \App\Models\ProductModel::statusTitle($r['status']);
                        ?>
                        <tr class="pr-row" data-item-id="<?= esc($r['item_id'], 'attr') ?>">
                            <td><strong><?= esc($r['item_name']) ?></strong><?php if (($r['lead_time_days'] ?? null) !== null && $r['lead_time_days'] >= \App\Libraries\ProcurementReport::LONG_LEAD_TIME_DAYS): ?>
                                <span class="pr-lead-badge" title="Supplier lead time: <?= (int) $r['lead_time_days'] ?> days">Long lead time</span><?php endif; ?><?php if (! empty($r['supplier_note'])): ?>
                                <span class="pr-min-note<?= $r['supplier_note']['level'] === 'warning' ? ' is-warning' : '' ?>"><?= esc($r['supplier_note']['text']) ?></span><?php endif; ?></td>
                            <td class="col-center"><?= $r['size'] !== null ? esc($r['size']) : '&mdash;' ?></td>
                            <td class="col-center">
                                <?php if ($r['class'] !== null): ?>
                                    <span class="abc-badge cls-<?= esc(strtolower($r['class']), 'attr') ?>"><?= esc($r['class']) ?></span>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                            <td class="col-num"><?= (int) $r['stock'] ?></td>
                            <td class="col-num pr-rop<?= $r['rop_default'] ? ' pr-default' : '' ?>"><?= (int) $r['rop'] ?></td>
                            <td class="inv-status-cell"><span class="status-pill <?= $pillClass ?>"<?= $pillTitle !== '' ? ' title="' . esc($pillTitle, 'attr') . '"' : '' ?>><?= esc($r['status']) ?></span></td>
                            <td class="col-num"><?= $r['safety_stock'] !== null ? (int) $r['safety_stock'] : '&mdash;' ?></td>
                            <td class="col-num pr-eoq<?= $r['order_qty_default'] ? ' pr-default' : '' ?>"><?= $r['order_qty'] !== null ? (int) $r['order_qty'] : '&mdash;' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php elseif ($searchTerm !== ''): ?>
                    <tr>
                        <td colspan="8" class="inv-empty-search">
                            <div class="inv-empty-icon" aria-hidden="true"><i class="bi bi-search"></i></div>
                            <div class="title">No items match &ldquo;<?= esc($searchTerm) ?>&rdquo;</div>
                            <p class="hint">Check the spelling or try a shorter word.</p>
                            <button type="button" class="btn-clear-search">Clear search</button>
                        </td>
                    </tr>
                <?php elseif (($class ?? '') !== '' || ($status ?? '') !== ''): ?>
                    <tr>
                        <td colspan="8" class="inv-empty-state is-filters">
                            <div class="inv-empty-icon" aria-hidden="true"><i class="bi bi-funnel"></i></div>
                            <div class="inv-empty-title">No items match these filters</div>
                            <a href="<?= esc($clearUrl, 'attr') ?>" class="inv-empty-clear">Clear filters</a>
                        </td>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="inv-empty-state is-none">
                            <div class="inv-empty-icon" aria-hidden="true"><i class="bi bi-box-seam"></i></div>
                            <div class="inv-empty-title">No items yet</div>
                            <p class="inv-empty-line">Items and their DSS results show here.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($rows !== []): ?>
    <?= view('partials/_pager', [
        'baseUrl'        => site_url('admin/procurement-report'),
        'currentPage'    => $currentPage ?? 1,
        'totalPages'     => $totalPages ?? 1,
        'totalItems'     => $totalItems ?? 0,
        'perPage'        => $perPage ?? 10,
        'perPageOptions' => $perPageOptions ?? [10, 25, 50],
        'shownCount'     => count($rows),
    ]) ?>
    <?php endif; ?>
</div>
