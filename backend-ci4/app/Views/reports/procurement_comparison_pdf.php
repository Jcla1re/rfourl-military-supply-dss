<?php
/**
 * Procurement Report > Cost comparison PDF (same style as the Procurement Report PDF). Variables from
 * ReorderAlertController::reportPdf(): $rows, $totals, $ownerQty, $basis, $filterLabel, $generatedAt.
 * One small table per page (like the other PDF) so Dompdf does not keep one very long table in memory.
 */
$rowsPerPage = 25;
$peso        = static fn (float $n): string => ($n < 0 ? '-₱' : '₱') . number_format(abs($n));
$pct         = static fn (float $n): string => ($n > 0 ? '+' : ($n < 0 ? '-' : '')) . number_format(abs($n), 1) . '%';
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
    .cards td { border: 1px solid #ccc; padding: 8px 10px; width: 25%; }
    .cards .l { color: #555; font-size: 9px; }
    .cards .v { font-size: 15px; font-weight: bold; }
    .neg { color: #8a1c1c; }
    .basis { width: 70%; }
    .basis td { padding: 5px 8px; }
    .basis td:first-child { width: 30%; color: #555; }
    .foot { margin-top: 14px; color: #777; font-size: 9px; }
</style>
</head>
<body>
    <h1>RfourL Military Supply &mdash; Cost comparison</h1>
    <div class="sub">
        Yearly cost of ordering <?= (int) $ownerQty ?> pieces at a time against the system's Order Qty. <?= $filterLabel !== '' ? 'Filters: ' . esc($filterLabel) . ' &middot; ' : '' ?><?= number_format(count($rows)) ?> size<?= count($rows) === 1 ? '' : 's' ?> &middot; Generated <?= esc($generatedAt) ?>
    </div>

    <table class="cards">
        <tr>
            <td><div class="l">Yearly cost, ordering <?= (int) $ownerQty ?> pcs</div><div class="v"><?= $peso($totals['owner_cost']) ?></div></td>
            <td><div class="l">Yearly cost with PDSS</div><div class="v"><?= $peso($totals['sys_cost']) ?></div></td>
            <td><div class="l">Estimated savings</div><div class="v<?= $totals['savings'] < 0 ? ' neg' : '' ?>"><?= $peso($totals['savings']) ?> (<?= $pct($totals['savings_pct']) ?>)</div></td>
            <td><div class="l">Items analysed</div><div class="v"><?= number_format($totals['items']) ?></div></td>
        </tr>
    </table>

    <?php $head = '<thead><tr><th>Item</th><th class="center">Size</th><th class="center">Class</th><th class="num">Yearly demand</th>'
        . '<th class="num">Owner\'s qty</th><th class="num">PDSS qty</th><th class="num">Cost (owner\'s qty)</th><th class="num">Cost (PDSS qty)</th><th class="num">Savings</th></tr></thead>'; ?>
    <?php if ($rows === []): ?>
        <table><?= $head ?><tbody><tr><td colspan="9">No sizes with sales to compare.</td></tr></tbody></table>
    <?php endif; ?>
    <?php foreach (array_chunk($rows, $rowsPerPage) as $i => $pageRows): ?>
        <table<?= $i > 0 ? ' class="page"' : ' style="margin-top: 14px;"' ?>><?= $head ?><tbody>
        <?php foreach ($pageRows as $r): ?>
            <tr>
                <td><?= esc($r['item_name']) ?></td>
                <td class="center"><?= esc($r['size'] ?? '—') ?></td>
                <td class="center"><?= esc($r['class'] ?? '—') ?></td>
                <td class="num"><?= number_format($r['demand']) ?></td>
                <td class="num"><?= (int) $r['q_owner'] ?></td>
                <td class="num"><?= (int) $r['q_sys'] ?></td>
                <td class="num"><?= $peso($r['tc_owner']) ?></td>
                <td class="num"><?= $peso($r['tc_sys']) ?></td>
                <td class="num<?= $r['savings'] < 0 ? ' neg' : '' ?>"><?= $peso($r['savings']) ?> (<?= $pct($r['savings_pct']) ?>)</td>
            </tr>
        <?php endforeach; ?>
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
    <div class="foot">Yearly cost = D / Q x S + Q / 2 x H. Savings = cost at the owner's usual qty - cost at the system's Order Qty.</div>
</body>
</html>
