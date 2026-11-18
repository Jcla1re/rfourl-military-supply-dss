<?php
// app/Views/admin/_procurement_comparison.php
// Procurement Report > Cost comparison: 4 cards, one sortable table and (below it) the cost curve of the
// size picked in the table. Rendered by the full page and by the live search (ReorderAlertController::comparison()
// returns just this partial for an AJAX request), so it is wrapped in #procurementResults like the other tab.
// Read-only. The chart and the sorting are in admin/procurement_report.php.
$rows         = $rows ?? [];
$totals       = $totals ?? ['owner_cost' => 0, 'sys_cost' => 0, 'savings' => 0, 'savings_pct' => 0, 'items' => 0];
$ownerQty     = (int) ($ownerQty ?? 20);
$searchTerm   = (string) ($search ?? '');
$classLevels  = \App\Models\ClusterSegmentModel::SERVICE_LEVELS;
$peso         = static fn (float $n): string => ($n < 0 ? '−₱' : '₱') . number_format(abs($n));
$signedPct    = static fn (float $n): string => ($n > 0 ? '+' : ($n < 0 ? '−' : '')) . number_format(abs($n), 1) . '%';
$howTo        = [
    "Yearly cost = D ÷ Q × S + Q ÷ 2 × H.",
    "D = yearly sales of the size, S = cost per order, H = storage cost per item per year, Q = how much you order at a time.",
    "The first part is what ordering costs in a year (fewer, bigger orders cost less). The second is what keeping the stock costs (bigger orders cost more).",
    "Owner's usual qty is {$ownerQty} (Settings). The system's Order Qty is the one with the lowest total cost, never below the minimum order.",
    "Savings = yearly cost at your usual qty − yearly cost at the system's Order Qty.",
];
?>
<div id="procurementResults" class="pc-wrap" data-owner-qty="<?= $ownerQty ?>" data-ordering-cost="<?= esc((string) ($orderingCost ?? 0), 'attr') ?>" data-holding-cost="<?= esc((string) ($holdingCost ?? 0), 'attr') ?>">
    <div class="pc-cards" role="group" aria-label="Cost comparison summary">
        <div class="pc-card" id="pcCardOwner">
            <span class="pc-card-label">Yearly cost, ordering <?= $ownerQty ?> pcs</span>
            <strong class="pc-card-value"><?= $peso($totals['owner_cost']) ?></strong>
        </div>
        <div class="pc-card" id="pcCardSystem">
            <span class="pc-card-label">Yearly cost with PDSS</span>
            <strong class="pc-card-value"><?= $peso($totals['sys_cost']) ?></strong>
        </div>
        <div class="pc-card<?= $totals['savings'] < 0 ? ' is-negative' : ' is-saving' ?>" id="pcCardSavings">
            <span class="pc-card-label">Estimated savings <?= help_tip('How is this worked out?', $howTo) ?></span>
            <strong class="pc-card-value"><?= $peso($totals['savings']) ?> <small>(<?= $signedPct($totals['savings_pct']) ?>)</small></strong>
        </div>
        <div class="pc-card" id="pcCardItems">
            <span class="pc-card-label">Items analysed</span>
            <strong class="pc-card-value"><?= number_format($totals['items']) ?></strong>
        </div>
    </div>

    <div class="inv-table-card pc-table-card">
        <div class="pc-scroll">
            <table class="data-table inv-table pc-table" id="pcTable">
                <thead>
                    <tr>
                        <th><button type="button" class="pc-sort" data-sort="text" data-col="0">Item</button></th>
                        <th class="col-center"><button type="button" class="pc-sort" data-sort="text" data-col="1">Size</button></th>
                        <th class="col-center"><button type="button" class="pc-sort" data-sort="text" data-col="2">Class</button></th>
                        <th class="col-num"><button type="button" class="pc-sort" data-sort="num" data-col="3">Yearly demand</button></th>
                        <th class="col-num"><button type="button" class="pc-sort" data-sort="num" data-col="4">Owner's qty</button></th>
                        <th class="col-num"><button type="button" class="pc-sort" data-sort="num" data-col="5">PDSS qty</button></th>
                        <th class="col-num"><button type="button" class="pc-sort" data-sort="num" data-col="6">Yearly cost (owner's qty)</button></th>
                        <th class="col-num"><button type="button" class="pc-sort" data-sort="num" data-col="7">Yearly cost (PDSS qty)</button></th>
                        <th class="col-num"><button type="button" class="pc-sort" data-sort="num" data-col="8">Savings</button></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr class="pc-row" tabindex="0" role="button" aria-label="Show the cost curve of <?= esc($r['item_name'] . ($r['size'] !== null ? ' ' . $r['size'] : ''), 'attr') ?>"
                            data-item="<?= esc($r['item_name'] . ($r['size'] !== null ? ' · ' . $r['size'] : ''), 'attr') ?>"
                            data-d="<?= (int) $r['demand'] ?>" data-q-owner="<?= (int) $r['q_owner'] ?>" data-q-sys="<?= (int) $r['q_sys'] ?>"
                            data-orders-owner="<?= esc((string) $r['orders_owner'], 'attr') ?>" data-orders-sys="<?= esc((string) $r['orders_sys'], 'attr') ?>">
                            <td data-v="<?= esc(strtolower($r['item_name']), 'attr') ?>"><strong><?= esc($r['item_name']) ?></strong></td>
                            <td class="col-center" data-v="<?= esc((string) ($r['size'] ?? ''), 'attr') ?>"><?= $r['size'] !== null ? esc($r['size']) : '&mdash;' ?></td>
                            <td class="col-center" data-v="<?= esc((string) ($r['class'] ?? 'Z'), 'attr') ?>">
                                <?php if ($r['class'] !== null): ?><span class="abc-badge cls-<?= esc(strtolower($r['class']), 'attr') ?>"><?= esc($r['class']) ?></span><?php else: ?>&mdash;<?php endif; ?>
                            </td>
                            <td class="col-num" data-v="<?= (int) $r['demand'] ?>"><?= number_format($r['demand']) ?></td>
                            <td class="col-num" data-v="<?= (int) $r['q_owner'] ?>"><?= (int) $r['q_owner'] ?></td>
                            <td class="col-num" data-v="<?= (int) $r['q_sys'] ?>"><?= (int) $r['q_sys'] ?></td>
                            <td class="col-num" data-v="<?= $r['tc_owner'] ?>"><?= $peso($r['tc_owner']) ?></td>
                            <td class="col-num" data-v="<?= $r['tc_sys'] ?>"><?= $peso($r['tc_sys']) ?></td>
                            <td class="col-num pc-savings<?= $r['savings'] < 0 ? ' is-negative' : '' ?>" data-v="<?= $r['savings'] ?>"><?= $peso($r['savings']) ?> <small><?= $signedPct($r['savings_pct']) ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="9" class="inv-empty-state">
                            <?php if ($searchTerm !== '' || ($class ?? '') !== '' || ($status ?? '') !== ''): ?>
                                <div class="inv-empty-title">No sizes with sales match these filters</div>
                            <?php else: ?>
                                <div class="inv-empty-title">No sizes with sales to compare yet</div>
                                <p class="inv-empty-line">A size needs yearly sales from the DSS run. <a href="<?= site_url('admin/settings?tab=dss') ?>">Run the calculation</a> in Settings.</p>
                            <?php endif; ?>
                        </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="pc-chart-card" id="pcChartCard">
        <div class="pc-chart-head">
            <strong id="pcChartTitle">Cost over order size</strong>
            <span class="pc-chart-sub" id="pcChartSub">Click a row to see its yearly cost for every order size.</span>
        </div>
        <div class="pc-chart-box"><canvas id="pcChart" aria-label="Yearly cost by order quantity" role="img"></canvas></div>
        <p class="pc-chart-orders" id="pcChartOrders"></p>
    </div>
</div>
