<?php
// app/Views/admin/_procurement_suppliers.php
// Procurement Report > Suppliers: one row per active supplier, a supplier's detail (?supplier=ID), and the weekly /
// monthly report of real orders. Variables from ReorderAlertController::suppliersTab(): $detail, $supplierRows,
// $period, $periodReport. Read-only. The numbers come from App\Libraries\SupplierProcurement (which reuses the
// Items tab's rows: no formula is repeated here).
$detail       = $detail ?? null;
$supplierRows = $supplierRows ?? [];
$period       = $period ?? \App\Libraries\SupplierProcurement::period(null, null);
$base         = site_url('admin/procurement-report');
$scores       = $scores ?? [];
$scorePeriod  = $scorePeriod ?? \App\Libraries\SupplierScorecard::period(null);
// ?score= (3, 6, 12 months or all) stays on every link of this tab; 12 is the default.
$url          = static fn (array $params): string => $base . '?' . http_build_query(['tab' => 'suppliers'] + $params + ($scorePeriod['key'] !== \App\Libraries\SupplierScorecard::DEFAULT_PERIOD ? ['score' => $scorePeriod['key']] : []));
$date         = static fn (?string $d): string => $d ? date('M j, Y', strtotime($d)) : '—';
$peso         = static fn (float $n): string => '₱' . number_format($n, 2);
$plural       = static fn (int $n, string $word): string => $n . ' ' . $word . ($n === 1 ? '' : 's');
$itemsText    = static fn (int $items, int $sizes): string => $plural($items, 'item') . ' (' . $plural($sizes, 'size') . ')';
// The same two numbers on two lines in the table: "140 items" with "286 sizes" small and grey under it.
$twoLines     = static fn (int $items, int $sizes, string $class = ''): string => '<span class="sp-stack' . ($class !== '' ? ' ' . $class : '') . '">' . esc($plural($items, 'item')) . '</span> <span class="sp-sub sp-line2">' . esc($plural($sizes, 'size')) . '</span>';
$sampleBadge  = ' <span class="sp-badge" title="Sample supplier: its prices are only for comparison">Sample</span>';
// "Create PO for low items": one purchase order for everything a real supplier's items are low on (SupplierPoController).
// $poCounts: supplier id => ['now' => sizes at Reorder Now, 'low' => sizes at Low Stock, both not already on order, 'on_order' => ...].
$poCounts     = $poCounts ?? [];
$poButton     = static function (string $supplierId) use ($poCounts): string {
    $c     = $poCounts[$supplierId] ?? ['now' => 0, 'low' => 0, 'on_order' => 0];
    $ready = $c['now'] + $c['low'] > 0;
    $label = 'Create PO for low items' . ($c['now'] > 0 ? ' <span class="sp-po-count">' . $c['now'] . '</span>' : '');
    if (! $ready) {
        $why = $c['on_order'] > 0 ? 'Everything low is already on an open order.' : 'Nothing is at or below its reorder point.';

        return '<span class="sp-po-btn is-disabled" role="link" aria-disabled="true" title="' . esc($why, 'attr') . '">' . $label . '</span>';
    }
    // The number on the button is not "Needs action" (which also counts Low Stock and items already on order).
    $title = 'Items at or below the reorder point that are not already on order. Low Stock items can be added on the next page.';

    return '<a class="sp-po-btn" href="' . esc(site_url('admin/procurement-report/po/' . rawurlencode($supplierId)), 'attr') . '" title="' . esc($title, 'attr') . '">' . $label . '</a>';
};
// The scorecard's period switch (App\Libraries\SupplierScorecard): the same links on the list and on a supplier's page.
$scoreSeg     = static function (array $params) use ($url, $scorePeriod): string {
    $html = '<div class="sp-score-seg" role="group" aria-label="Scorecard period">';
    foreach (\App\Libraries\SupplierScorecard::PERIODS as $key => $label) {
        $active = $scorePeriod['key'] === (string) $key;
        $html  .= '<a href="' . esc($url($params + ['score' => (string) $key]), 'attr') . '" class="' . ($active ? 'active' : '') . '"' . ($active ? ' aria-current="true"' : '') . '>' . esc($label) . '</a>';
    }

    return $html . '</div>';
};
?>
<div id="supplierTab">
<?php if ($detail !== null): ?>

    <?php // ------------------------------------------------------------ one supplier ?>
    <?php $c = $detail['counts'] ?? null; ?>
    <div class="sp-detail-head">
        <div>
            <a href="<?= esc($url(['period' => $period['type'], 'date' => $period['anchor']]), 'attr') ?>" class="lt-back" id="spBack"><i class="bi bi-arrow-left"></i> All suppliers</a>
            <h2><?= esc($detail['name']) ?><?= $detail['is_sample'] ? $sampleBadge : '' ?></h2>
        </div>
        <?php if (! $detail['is_sample']): ?>
            <?php $toggle = $url(['supplier' => $detail['id']] + ($detail['needs_only'] ? [] : ['needs' => 1])); ?>
            <div class="sp-detail-actions">
                <?= $poButton((string) $detail['id']) ?>
                <a href="<?= esc($toggle, 'attr') ?>" class="sp-toggle<?= $detail['needs_only'] ? ' active' : '' ?>" id="spNeedsToggle" role="switch" aria-checked="<?= $detail['needs_only'] ? 'true' : 'false' ?>"><i class="bi bi-funnel" aria-hidden="true"></i> Needs action only</a>
            </div>
        <?php endif; ?>
    </div>
    <div class="sp-facts">
        <?php if ($detail['category'] !== ''): ?><span><b>Category</b> <?= esc($detail['category']) ?></span><?php endif; ?>
        <span><b>Delivery time</b> <?= (int) $detail['lead_time_days'] ?> days</span>
        <span><b>Payment terms</b> <?= $detail['payment_terms'] !== null ? esc($detail['payment_terms']) : '—' ?></span>
        <?php if ($c !== null): ?>
            <span><b>Items</b> <?= esc($itemsText($c['items'], $c['sizes'])) ?></span>
            <span><b>Needs action</b> <span class="<?= $c['needs_items'] > 0 ? 'sp-needs' : '' ?>"><?= esc($itemsText($c['needs_items'], $c['needs_sizes'])) ?></span></span>
            <span><b>Stock on hand</b> <?= number_format($c['stock']) ?></span>
        <?php endif; ?>
    </div>

    <?php // ------------------------------------------------------------ the scorecard (App\Libraries\SupplierScorecard) ?>
    <?php
    $card  = $scores[$detail['id']] ?? null;
    $tile  = static fn (string $key, string $label, string $value, string $sub = '', string $tip = ''): string => '<div class="sp-tile" data-tile="' . esc($key, 'attr') . '"><div class="sp-tile-label">' . esc($label) . $tip . '</div><div class="sp-tile-value">' . $value . '</div>' . ($sub !== '' ? '<div class="sp-tile-sub">' . esc($sub) . '</div>' : '') . '</div>';
    $na    = '<span class="sp-muted">Not enough orders yet</span>';
    ?>
    <?php if ($card !== null): ?>
    <section class="sp-section sp-score" id="supplierScorecard" aria-labelledby="spScoreTitle">
        <div class="sp-score-head">
            <h3 id="spScoreTitle">Scorecard</h3>
            <?php if ($card['status'] === 'ok'): ?><?= $scoreSeg(['supplier' => $detail['id']] + ($detail['needs_only'] ? ['needs' => 1] : [])) ?><?php endif; ?>
        </div>
        <?php if ($card['status'] === 'sample'): ?>
            <p class="sp-sample-note" id="spScoreSample"><?= esc($card['message']) ?></p>
        <?php else: ?>
            <?php $n = $card['counts']; ?>
            <p class="sp-sub">Real orders placed in the <?= esc(strtolower($card['period']['label'])) ?>, from the PO to the received date. Nothing here is typed in or estimated.</p>
            <?php if (! $card['enough']): ?>
                <p class="sp-score-note" id="spScoreNotEnough" role="note"><?= esc($card['message']) ?>. The counts and the ordered value are shown; percentages and averages need at least <?= (int) $card['min_received'] ?> received orders in the period.</p>
            <?php endif; ?>
            <div class="sp-score-grid">
                <?= $tile('placed', 'Orders placed', number_format($n['placed'])) ?>
                <?= $tile('received', 'Received', number_format($n['received']), 'of ' . number_format($n['placed']) . ' placed') ?>
                <?= $tile('in_progress', 'In progress', number_format($n['in_progress']), 'Order Confirmed, Preparing or Shipped') ?>
                <?= $tile('cancelled', 'Cancelled by RfourL', number_format($n['cancelled_by_owner']), $n['cancelled_after_accept'] > 0 ? $n['cancelled_after_accept'] . ' after the supplier accepted it' : '') ?>
                <?= $tile('declined', 'Declined by supplier', number_format($n['declined'])) ?>
                <?= $tile('on_time', 'On-time delivery', $card['on_time']['pct'] !== null ? $card['on_time']['pct'] . '%' : $na, $card['on_time']['pct'] !== null ? $card['on_time']['on_time'] . ' of ' . $card['on_time']['of'] . ' received on or before the promised date' : '', help_tip('On-time delivery', 'Received on or before the promised date: the PO\'s expected delivery date, or the PO date plus the supplier\'s stated delivery time when the PO has none. The same day is on time.')) ?>
                <?= $tile('late', 'Received late', number_format($n['received_late']), 'after the promised date') ?>
                <?= $tile('overdue', 'Open and overdue now', number_format($n['open_overdue']), 'not received and past the promised date') ?>
                <?= $tile('lead', 'Average actual lead time', $card['lead']['avg'] !== null ? rtrim(rtrim(number_format($card['lead']['avg'], 1), '0'), '.') . ' days' : $na, 'Stated delivery time: ' . (int) $card['lead']['stated'] . ' days' . ($card['lead']['diff'] !== null ? ' (' . ($card['lead']['diff'] > 0 ? '+' : '') . rtrim(rtrim(number_format($card['lead']['diff'], 1), '0'), '.') . ' days)' : '')) ?>
                <?= $tile('fill', 'Shipped vs ordered', $card['fill']['pct'] !== null ? $card['fill']['pct'] . '%' : $na, $card['fill']['pct'] !== null ? number_format($card['fill']['filled']) . ' of ' . number_format($card['fill']['ordered']) . ' ordered units shipped' : '', help_tip('Shipped vs ordered', 'Receiving records the quantity the supplier says it shipped (and adds exactly that to stock), so this compares what the supplier shipped with what was ordered. It is not a separate count at the door.')) ?>
                <?= $tile('value', 'Ordered value', $peso($card['ordered_value']), 'orders placed in the period, cancelled ones left out') ?>
            </div>
            <?php $notes = array_filter([
                $card['notes']['no_received_date'] > 0 ? $plural((int) $card['notes']['no_received_date'], 'order') . ' received with no date, left out of the timing' : null,
                $card['notes']['date_problems'] > 0 ? $plural((int) $card['notes']['date_problems'], 'order') . ' dated received before they were ordered, left out of the timing' : null,
                $card['notes']['no_promised_date'] > 0 ? $plural((int) $card['notes']['no_promised_date'], 'order') . ' with no promised date, not judged on time' : null,
                $card['fill']['left_out'] > 0 ? $plural((int) $card['fill']['left_out'], 'order') . ' with no shipped quantity recorded, left out of Shipped vs ordered' : null,
            ]); ?>
            <p class="sp-foot" id="spScoreFoot">The supplier's stated delivery time is its current value, so past comparisons move if it is edited.<?= $notes !== [] ? ' ' . esc(implode('; ', $notes)) . '.' : '' ?></p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($detail['is_sample']): ?>
        <p class="sp-sample-note" id="spSampleNote">Sample supplier: these are price-list offers for comparison. It has no items of its own, no orders and no stock.</p>
        <div class="inv-table-card"><div class="data-table-wrap sp-scroll">
            <table class="data-table inv-table sp-table" id="supplierOffers">
                <thead><tr><th>Item</th><th class="col-center">Size</th><th class="col-num">Main supplier's cost</th><th class="col-num">Offer price</th><th class="col-num">Delivery days</th><th class="col-num">Minimum order</th></tr></thead>
                <tbody>
                    <?php foreach ($detail['offers'] as $o): ?>
                        <tr class="sp-offer">
                            <td><strong><?= esc($o['item_name']) ?></strong></td>
                            <td class="col-center"><?= $o['size'] !== '' ? esc($o['size']) : '&mdash;' ?></td>
                            <td class="col-num"><?= $peso($o['main_cost']) ?></td>
                            <td class="col-num"><?= $peso($o['unit_price']) ?></td>
                            <td class="col-num"><?= (int) $o['lead_time_days'] ?></td>
                            <td class="col-num"><?= $o['min_order_qty'] !== null ? number_format($o['min_order_qty']) : 'None' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($detail['offers'] === []): ?><tr><td colspan="6" class="text-center py-4 sp-muted">No offers on this price list.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div></div>

    <?php else: ?>
        <div class="inv-table-card"><div class="data-table-wrap sp-scroll">
            <table class="data-table inv-table sp-table" id="supplierItems">
                <thead>
                    <tr><th>Item</th><th class="col-center">Size</th><th class="col-center">Class</th><th class="col-num">Unit cost</th><th class="col-num">On hand</th><th class="col-num">Reorder at</th><th>Status</th><th class="col-num">Order qty</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($detail['sizes'] as $r): ?>
                        <tr class="sp-size" data-item-id="<?= esc($r['item_id'], 'attr') ?>">
                            <td class="sp-name"><strong><?= esc($r['item_name']) ?></strong></td>
                            <td class="col-center"><?= $r['size'] !== null ? esc($r['size']) : '&mdash;' ?></td>
                            <td class="col-center"><?php if ($r['class'] !== null): ?><span class="abc-badge cls-<?= esc(strtolower($r['class']), 'attr') ?>"><?= esc($r['class']) ?></span><?php else: ?>&mdash;<?php endif; ?></td>
                            <td class="col-num"><?= $peso($r['unit_cost']) ?></td>
                            <td class="col-num"><?= (int) $r['stock'] ?></td>
                            <td class="col-num<?= $r['rop_default'] ? ' pr-default' : '' ?>"><?= (int) $r['rop'] ?></td>
                            <td class="inv-status-cell"><span class="status-pill <?= \App\Models\ProductModel::statusColor($r['status']) ?>"><?= esc($r['status']) ?></span></td>
                            <td class="col-num<?= $r['order_qty_default'] ? ' pr-default' : '' ?>"><?= $r['order_qty'] !== null ? (int) $r['order_qty'] : '&mdash;' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($detail['sizes'] === []): ?>
                        <tr><td colspan="8" class="text-center py-4 sp-muted"><?= $detail['needs_only'] ? 'Nothing needs action for this supplier.' : 'No items have this supplier as their main supplier.' ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div></div>
    <?php endif; ?>

<?php else: ?>

    <?php // ------------------------------------------------------------ the list ?>
    <div class="sp-score-bar"><span class="sp-score-label">Scorecard <?= help_tip('Scorecard', 'How each supplier did on the real orders placed in this period. Fewer than 5 received orders: not enough to show percentages.') ?></span> <?= $scoreSeg([]) ?></div>
    <div class="inv-table-card"><div class="data-table-wrap sp-scroll">
        <table class="data-table inv-table sp-table" id="supplierList">
            <thead>
                <tr>
                    <th class="sp-c-supplier">Supplier</th>
                    <th>Category</th>
                    <th>Items</th>
                    <th>Needs action <?= help_tip('Needs action', 'Items with a size at Reorder Now or Low Stock. An item needs action when any of its sizes does.') ?></th>
                    <th class="col-num">Stock on hand</th>
                    <th class="col-num" title="Orders not delivered yet: Order Confirmed, Preparing or Shipped">In progress</th>
                    <th>Last order</th>
                    <th class="col-num">Delivery</th>
                    <th>Terms</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($supplierRows as $s): ?>
                    <tr class="sp-row<?= $s['is_sample'] ? ' is-sample' : '' ?>" data-supplier="<?= esc($s['id'], 'attr') ?>">
                        <td class="sp-name"><a class="sp-link" href="<?= esc($url(['supplier' => $s['id'], 'period' => $period['type'], 'date' => $period['anchor']]), 'attr') ?>"><?= esc($s['name']) ?></a><?= $s['is_sample'] ? $sampleBadge : '' ?><?php if (! $s['is_sample']): ?><span class="sp-po-wrap"><?= $poButton((string) $s['id']) ?></span><?php endif; ?><?php if (isset($scores[$s['id']])): ?> <span class="sp-score-wrap"><span class="sp-score-line<?= $scores[$s['id']]['status'] === 'ok' && $scores[$s['id']]['enough'] ? '' : ' is-muted' ?>"><?= esc(\App\Libraries\SupplierScorecard::summary($scores[$s['id']])) ?></span></span><?php endif; ?></td>
                        <td><?= $s['category'] !== '' ? esc($s['category']) : '&mdash;' ?></td>
                        <?php if ($s['is_sample']): ?>
                            <td colspan="5" class="sp-sample-note">Sample offers: <?= $plural($s['offers'], 'item') ?></td>
                        <?php else: ?>
                            <?php $c = $s['counts']; ?>
                            <td class="sp-items"><?= $twoLines($c['items'], $c['sizes']) ?></td>
                            <td class="sp-needs-cell"><?= $twoLines($c['needs_items'], $c['needs_sizes'], $c['needs_items'] > 0 ? 'sp-needs' : 'sp-muted') ?></td>
                            <td class="col-num"><?= number_format($c['stock']) ?></td>
                            <td class="col-num"><?= (int) $s['orders_in_progress'] ?></td>
                            <td><?= $date($s['last_order']) ?></td>
                        <?php endif; ?>
                        <td class="col-num"><?= (int) $s['lead_time_days'] ?> days</td>
                        <td><?= $s['payment_terms'] !== null ? esc($s['payment_terms']) : '&mdash;' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($supplierRows === []): ?>
                    <tr><td colspan="9" class="text-center py-4 sp-muted">No active suppliers yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div></div>

    <?php // ------------------------------------------------------------ the weekly / monthly report ?>
    <?php $report = $periodReport ?? ['suppliers' => [], 'grand' => ['lines' => 0, 'quantity' => 0, 'received' => 0, 'amount' => 0.0]]; ?>
    <section class="sp-section" id="supplierReport" aria-labelledby="spReportTitle">
        <h3 id="spReportTitle">Supplier report</h3>
        <div class="sp-period">
            <div class="sp-seg" role="group" aria-label="Report period">
                <?php foreach (\App\Libraries\SupplierProcurement::PERIODS as $key => $label): ?>
                    <a href="<?= esc($url(['period' => $key]), 'attr') ?>" class="<?= $period['type'] === $key ? 'active' : '' ?>"<?= $period['type'] === $key ? ' aria-current="true"' : '' ?>><?= esc($label) ?></a>
                <?php endforeach; ?>
            </div>
            <div class="sp-nav">
                <a href="<?= esc($url(['period' => $period['type'], 'date' => $period['prev']]), 'attr') ?>" aria-label="Previous <?= $period['type'] === 'monthly' ? 'month' : 'week' ?>"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
                <strong id="spPeriodLabel"><?= esc($period['label']) ?></strong>
                <a href="<?= esc($url(['period' => $period['type'], 'date' => $period['next']]), 'attr') ?>" aria-label="Next <?= $period['type'] === 'monthly' ? 'month' : 'week' ?>"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
            </div>
            <?php if (! $period['is_current']): ?><a class="sp-toggle" href="<?= esc($url(['period' => $period['type']]), 'attr') ?>">This <?= $period['type'] === 'monthly' ? 'month' : 'week' ?></a><?php endif; ?>
        </div>
        <p class="sp-sub">Real stock orders dated in this period, one line per item. Cancelled orders are left out. Quantity is what was ordered; Received shows for Delivered orders.</p>

        <?php foreach ($report['suppliers'] as $s): ?>
            <div class="sp-supplier" data-supplier="<?= esc($s['id'], 'attr') ?>">
                <h4><?= esc($s['name']) ?></h4>
                <div class="sp-scroll">
                    <table class="data-table inv-table sp-table sp-lines">
                        <thead>
                            <tr><th class="sp-c-order">Order</th><th class="sp-c-date">Date</th><th class="sp-c-item">Item</th><th class="col-center">Size</th><th class="col-num">Qty</th><th class="col-num">Price per piece</th><th class="col-num">Line total</th><th>Status</th><th class="col-num">Received</th><th class="col-num">Stock now</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($s['lines'] as $l): ?>
                                <tr class="sp-line">
                                    <td class="sp-c-order"><?= esc($l['order']) ?></td>
                                    <td class="sp-c-date"><?= $date($l['date']) ?></td>
                                    <td class="sp-c-item"><?= esc($l['item']) ?></td>
                                    <td class="col-center"><?= $l['size'] !== '' ? esc($l['size']) : '&mdash;' ?></td>
                                    <td class="col-num"><?= number_format($l['quantity']) ?></td>
                                    <td class="col-num"><?= $peso($l['price']) ?></td>
                                    <td class="col-num"><?= $peso($l['total']) ?></td>
                                    <td><?= esc($l['status']) ?></td>
                                    <td class="col-num"><?= $l['received'] !== null ? number_format($l['received']) : '&mdash;' ?></td>
                                    <td class="col-num"><?= $l['stock'] !== null ? number_format($l['stock']) : '&mdash;' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php $t = $s['totals']; ?>
                            <tr class="sp-subtotal">
                                <td colspan="4">Total &middot; <?= esc($s['name']) ?> (<?= $plural($t['lines'], 'line') ?>)</td>
                                <td class="col-num"><?= number_format($t['quantity']) ?></td>
                                <td></td>
                                <td class="col-num"><?= $peso($t['amount']) ?></td>
                                <td></td>
                                <td class="col-num"><?= number_format($t['received']) ?></td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>

        <?php $g = $report['grand']; ?>
        <?php if ($report['suppliers'] === []): ?>
            <p class="sp-muted" id="spNoOrders">No orders were placed in this period.</p>
        <?php endif; ?>
        <div class="sp-scroll"><table class="data-table inv-table sp-table" id="spGrandTotal"><tbody>
            <tr class="sp-total">
                <td>Grand total &middot; <?= esc($period['label']) ?> (<?= $plural($g['lines'], 'line') ?>)</td>
                <td class="col-num">Qty <?= number_format($g['quantity']) ?></td>
                <td class="col-num">Received <?= number_format($g['received']) ?></td>
                <td class="col-num"><?= $peso($g['amount']) ?></td>
            </tr>
        </tbody></table></div>
    </section>

<?php endif; ?>
</div>
