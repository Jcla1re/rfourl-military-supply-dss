<?php
/**
 * Procurement Report PDF (same style as the Reports PDF). Variables from
 * ReorderAlertController::reportPdf(): $rows (every size matching the filters), $basis,
 * $filterLabel, $generatedAt. Defaults print as plain numbers (0, the minimum order).
 */
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
    th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; vertical-align: top; }
    th { background: #3d5230; color: #fff; }
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

    <table>
        <thead>
            <tr>
                <th>Item ID</th><th>Item</th><th class="center">Size</th><th class="center">Class</th>
                <th class="num">On Hand</th><th class="num">Reorder At</th><th>Status</th>
                <th class="num">Safety Stock</th><th class="num">Order Qty</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($rows === []): ?><tr><td colspan="9">No items match.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= esc($r['item_id']) ?></td>
                    <td><?= esc($r['item_name']) ?></td>
                    <td class="center"><?= esc($r['size'] ?? '—') ?></td>
                    <td class="center"><?= esc($r['class'] ?? '—') ?></td>
                    <td class="num"><?= (int) $r['stock'] ?></td>
                    <td class="num"><?= (int) $r['rop'] ?></td>
                    <td><?= esc($r['status']) ?></td>
                    <td class="num"><?= $r['safety_stock'] !== null ? (int) $r['safety_stock'] : '—' ?></td>
                    <td class="num"><?= $r['order_qty'] !== null ? (int) $r['order_qty'] : '—' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

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
