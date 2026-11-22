<?php
/**
 * Procurement Report > Suppliers: the weekly / monthly supplier report as a PDF (same style as the other procurement
 * PDFs). Variables from ReorderAlertController::reportPdf(): $period, $report, $generatedAt.
 */
$peso   = static fn (float $n): string => '₱' . number_format($n, 2);
$date   = static fn (string $d): string => date('M j, Y', strtotime($d));
$plural = static fn (int $n, string $word): string => $n . ' ' . $word . ($n === 1 ? '' : 's');
$head   = '<thead><tr><th class="nw">Order</th><th class="nw">Date</th><th>Item</th><th class="center">Size</th><th class="num">Qty</th><th class="num">Price per piece</th>'
    . '<th class="num">Line total</th><th class="nw">Status</th><th class="num">Received</th><th class="num">Stock now</th></tr></thead>';
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
    .num { text-align: right; white-space: nowrap; }
    .center { text-align: center; }
    .nw { white-space: nowrap; } /* a PO number, a date or a status never wraps into the next column */
    tr.sub td { background: #f7f6f2; font-weight: bold; }
    tr.grand td { background: #e4efdf; font-weight: bold; font-size: 11px; }
    .foot { margin-top: 14px; color: #777; font-size: 9px; }
</style>
</head>
<body>
    <h1>RfourL Military Supply &mdash; Supplier report</h1>
    <div class="sub"><?= esc(ucfirst($period['type'])) ?> &middot; <?= esc($period['label']) ?> &middot; Generated <?= esc($generatedAt) ?></div>

    <?php if ($report['suppliers'] === []): ?>
        <p>No orders were placed in this period.</p>
    <?php endif; ?>

    <?php foreach ($report['suppliers'] as $s): ?>
        <h2><?= esc($s['name']) ?></h2>
        <table><?= $head ?><tbody>
            <?php foreach ($s['lines'] as $l): ?>
                <tr>
                    <td class="nw"><?= esc($l['order']) ?></td>
                    <td class="nw"><?= $date($l['date']) ?></td>
                    <td><?= esc($l['item']) ?></td>
                    <td class="center"><?= $l['size'] !== '' ? esc($l['size']) : '—' ?></td>
                    <td class="num"><?= number_format($l['quantity']) ?></td>
                    <td class="num"><?= $peso($l['price']) ?></td>
                    <td class="num"><?= $peso($l['total']) ?></td>
                    <td class="nw"><?= esc($l['status']) ?></td>
                    <td class="num"><?= $l['received'] !== null ? number_format($l['received']) : '—' ?></td>
                    <td class="num"><?= $l['stock'] !== null ? number_format($l['stock']) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php $t = $s['totals']; ?>
            <tr class="sub">
                <td colspan="4">Total &middot; <?= esc($s['name']) ?> (<?= $plural($t['lines'], 'line') ?>)</td>
                <td class="num"><?= number_format($t['quantity']) ?></td>
                <td></td>
                <td class="num"><?= $peso($t['amount']) ?></td>
                <td></td>
                <td class="num"><?= number_format($t['received']) ?></td>
                <td></td>
            </tr>
        </tbody></table>
    <?php endforeach; ?>

    <?php $g = $report['grand']; ?>
    <h2>Grand total</h2>
    <table><tbody>
        <tr class="grand">
            <td>Grand total &middot; <?= esc($period['label']) ?> (<?= $plural($g['lines'], 'line') ?>)</td>
            <td class="num">Qty <?= number_format($g['quantity']) ?></td>
            <td class="num">Received <?= number_format($g['received']) ?></td>
            <td class="num"><?= $peso($g['amount']) ?></td>
        </tr>
    </tbody></table>

    <div class="foot">Real stock orders dated in the period, one line per item; Cancelled orders are left out. Quantity is what was ordered; Received is shown for Delivered orders (what the supplier shipped). Sample suppliers have no orders and are not included.</div>
</body>
</html>
