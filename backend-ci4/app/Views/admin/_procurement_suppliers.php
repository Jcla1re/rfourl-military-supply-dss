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
$url          = static fn (array $params): string => $base . '?' . http_build_query(['tab' => 'suppliers'] + $params);
$date         = static fn (?string $d): string => $d ? date('M j, Y', strtotime($d)) : '—';
$peso         = static fn (float $n): string => '₱' . number_format($n, 2);
$plural       = static fn (int $n, string $word): string => $n . ' ' . $word . ($n === 1 ? '' : 's');
$itemsText    = static fn (int $items, int $sizes): string => $plural($items, 'item') . ' (' . $plural($sizes, 'size') . ')';
// The same two numbers on two lines in the table: "140 items" with "286 sizes" small and grey under it.
$twoLines     = static fn (int $items, int $sizes, string $class = ''): string => '<span class="sp-stack' . ($class !== '' ? ' ' . $class : '') . '">' . esc($plural($items, 'item')) . '</span> <span class="sp-sub sp-line2">' . esc($plural($sizes, 'size')) . '</span>';
$sampleBadge  = ' <span class="sp-badge" title="Sample supplier: its prices are only for comparison">Sample</span>';
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
            <a href="<?= esc($toggle, 'attr') ?>" class="sp-toggle<?= $detail['needs_only'] ? ' active' : '' ?>" id="spNeedsToggle" role="switch" aria-checked="<?= $detail['needs_only'] ? 'true' : 'false' ?>"><i class="bi bi-funnel" aria-hidden="true"></i> Needs action only</a>
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
                        <td class="sp-name"><a class="sp-link" href="<?= esc($url(['supplier' => $s['id'], 'period' => $period['type'], 'date' => $period['anchor']]), 'attr') ?>"><?= esc($s['name']) ?></a><?= $s['is_sample'] ? $sampleBadge : '' ?></td>
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
