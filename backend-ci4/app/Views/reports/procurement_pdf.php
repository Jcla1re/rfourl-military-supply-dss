<?php
/**
 * Procurement Report PDF (same style as the Reports PDF). Variables from
 * ReorderAlertController::reportPdf(): $rows (every size matching the filters), $basis,
 * $filterLabel, $generatedAt. Defaults print as plain numbers (0, the minimum order).
 *
 * Kept light for Dompdf, which holds every page in memory until the end: one small table per
 * page (ROWS_PER_PAGE rows, then a page break) instead of one long table split across ~35
 * pages, and only bottom rules (no full cell borders). ~890 rows: ~380MB → ~170MB.
 */
$rowsPerPage = 25; // fits one landscape A4 page with the heading, even when a long name wraps
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1c1c1c; }
    h1 { font-size: 16px; margin: 0 0 2px; }
    h2 { font-size: 12px; margin: 16px 0 6px; }
    .sub { color: #555; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 3px 6px; text-align: left; vertical-align: top; border-bottom: 1px solid #ccc; }
    th { background: #3d5230; color: #fff; border-bottom: 0; }
    .page { page-break-before: always; }
    .num { text-align: right; white-space: nowrap; }
    .center { text-align: center; }
    .basis { width: 60%; }
    .basis td { padding: 5px 8px; }
    .basis td:first-child { width: 40%; color: #555; }
    .foot { margin-top: 14px; color: #777; font-size: 9px; }
</style>
</head>
<body>
    <h1>RfourL Military Supply &mdash; Procurement Report</h1>
    <div class="sub">
        <?= $filterLabel !== '' ? 'Filters: ' . esc($filterLabel) . ' &middot; ' : '' ?><?= number_format(count($rows)) ?> item<?= count($rows) === 1 ? '' : 's' ?> &middot; Generated <?= esc($generatedAt) ?>
    </div>

    <?php $head = '<thead><tr><th>Item ID</th><th>Item</th><th class="center">Size</th><th class="center">Class</th>'
        . '<th class="num">On Hand</th><th class="num">Reorder At</th><th>Status</th>'
        . '<th class="num">Safety Stock</th><th class="num">Order Qty</th></tr></thead>'; ?>
    <?php if ($rows === []): ?>
        <table><?= $head ?><tbody><tr><td colspan="9">No items match.</td></tr></tbody></table>
    <?php endif; ?>
    <?php foreach (array_chunk($rows, $rowsPerPage) as $i => $pageRows): ?>
        <table<?= $i > 0 ? ' class="page"' : '' ?>><?= $head ?><tbody>
        <?php foreach ($pageRows as $r): ?><tr><td><?= esc($r['item_id']) ?></td><td><?= esc($r['item_name']) ?></td><td class="center"><?= esc($r['size'] ?? '—') ?></td><td class="center"><?= esc($r['class'] ?? '—') ?></td><td class="num"><?= (int) $r['stock'] ?></td><td class="num"><?= (int) $r['rop'] ?></td><td><?= esc($r['status']) ?></td><td class="num"><?= $r['safety_stock'] !== null ? (int) $r['safety_stock'] : '—' ?></td><td class="num"><?= $r['order_qty'] !== null ? (int) $r['order_qty'] : '—' ?></td></tr><?php endforeach; ?>
        </tbody></table>
    <?php endforeach; ?>

    <h2>Basis</h2>
    <table class="basis">
        <tbody>
            <?php foreach ($basis as [$label, $value]): ?>
                <tr><td><?= esc($label) ?></td><td><?= esc($value) ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="foot">Suggestions from the DSS (sales history, K-Means classes, EOQ). You decide what to order.</div>
</body>
</html>
