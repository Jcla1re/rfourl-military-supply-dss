<?php
/**
 * The Reports PDF (same style as the sales PDF). Variables from ReportsController::pdf():
 * $type, $from, $to, $report, $generatedAt.
 *
 * The journals are one small table per page ($rowsPerPage rows, then $newPage() starts the next
 * page with the header again), not one long table split across pages: Dompdf holds the whole
 * document in memory and a long split table costs far more (a year's Sales Journal ~120MB,
 * several years over 512MB).
 */
use App\Libraries\AccountingReports;

$peso        = static fn (float $n): string => '₱' . number_format($n, 2);
$periodText  = date('F j, Y', strtotime($from)) . ($from === $to ? '' : ' to ' . date('F j, Y', strtotime($to)));
$rowsPerPage = 22; // fits one landscape A4 page with the heading, even with void labels
$newPage     = static fn (string $head): string => '</tbody></table><table class="page">' . $head . '<tbody>';
$n           = 0; // rows printed so far
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1c1c1c; }
    h1 { font-size: 16px; margin: 0 0 2px; }
    .sub { color: #555; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; vertical-align: top; }
    th { background: #3d5230; color: #fff; }
    .num { text-align: right; white-space: nowrap; }
    .page { page-break-before: always; }
    tr.sub-row td { background: #f7f6f2; font-weight: bold; }
    tr.total-row td { background: #e4efdf; font-weight: bold; font-size: 11px; }
    /* Period Summary: one column of groups, plain tables only (no flex / grid) so Dompdf lays it out reliably */
    .ps-label { font-size: 10.5px; font-weight: normal; color: #3A4030; margin-top: 10px; }
    .ps-group { margin-top: 20px; }
    .ps-heading { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: .06em; color: #5C6152; padding-bottom: 5px; border-bottom: 1px solid #9A9C8F; }
    .ps-subhead { margin-top: 12px; }
    table.ps td { border: 0; border-bottom: 1px solid #E3E0D7; padding: 7px 0; font-weight: normal; }
    table.ps td.ps-value { width: 40%; text-align: right; white-space: nowrap; }
    table.ps td.ps-indent { padding-left: 14px; }
    table.ps tr.ps-last td { border-bottom: 0; }
    table.ps tr.ps-total td { border-top: 1px solid #1F2617; border-bottom: 0; font-weight: bold; }
    table.ps tr.ps-bold td { font-weight: bold; }
    .rp-dup { background: #fde8e8; color: #8a1c1c; font-size: 7px; font-weight: bold; text-transform: uppercase; padding: 1px 4px; }
    .foot { margin-top: 14px; color: #777; font-size: 9px; }
</style>
</head>
<body>
    <h1>RfourL Military Supply &mdash; <?= esc(AccountingReports::TYPES[$type]) ?></h1>
    <div class="sub">Period: <?= esc($periodText) ?> &middot; Generated <?= esc($generatedAt) ?></div>

    <?php if ($type === 'sales'): ?>
        <?php $days = array_column($report['days'], null, 'date'); $rows = $report['rows']; ?>
        <?php $head = '<thead><tr><th>Receipt No.</th><th>OR No.</th><th>Date &amp; Time</th><th>Cashier</th><th class="num">Gross</th><th class="num">Discount</th><th class="num">Net</th><th class="num">Cash</th><th class="num">GCash</th></tr></thead>'; ?>
        <table>
            <?= $head ?>
            <tbody>
                <?php if ($rows === []): ?><tr><td colspan="9">No sales in this period.</td></tr><?php endif; ?>
                <?php foreach ($rows as $i => $r): ?>
                    <?php $strike = $r['voided'] !== null ? ' style="text-decoration:line-through;color:#8a8a8a"' : ''; ?>
                    <tr>
                        <td>#<?= esc($r['receipt_no']) ?><?= view('partials/_void_label', ['voidLabel' => $r['voided']]) ?></td>
                        <td><?= $r['or_number'] !== null ? esc($r['or_number']) : '—' ?><?php if (! empty($r['duplicate'])): ?> <span class="rp-dup">duplicate</span><?php endif; ?></td>
                        <td><?= esc(date('M j, Y', strtotime($r['date']))) ?> <?= esc($r['time']) ?></td>
                        <td><?= esc($r['cashier'] !== '' ? $r['cashier'] : '—') ?></td>
                        <td class="num"<?= $strike ?>><?= $peso($r['gross']) ?></td><td class="num"<?= $strike ?>><?= $peso($r['discount']) ?></td><td class="num"<?= $strike ?>><?= $peso($r['net']) ?></td><td class="num"<?= $strike ?>><?= $peso($r['cash']) ?></td><td class="num"<?= $strike ?>><?= $peso($r['gcash']) ?></td>
                    </tr>
                    <?php if (++$n % $rowsPerPage === 0): ?><?= $newPage($head) ?><?php endif; ?>
                    <?php if (! isset($rows[$i + 1]) || $rows[$i + 1]['date'] !== $r['date']): ?>
                        <?php $d = $days[$r['date']]; ?>
                        <tr class="sub-row">
                            <td colspan="4">Subtotal &middot; <?= esc(date('M j, Y', strtotime($r['date']))) ?> (<?= (int) $d['count'] ?> receipt<?= $d['count'] === 1 ? '' : 's' ?>)</td>
                            <td class="num"><?= $peso($d['gross']) ?></td><td class="num"><?= $peso($d['discount']) ?></td><td class="num"><?= $peso($d['net']) ?></td><td class="num"><?= $peso($d['cash']) ?></td><td class="num"><?= $peso($d['gcash']) ?></td>
                        </tr>
                        <?php if (++$n % $rowsPerPage === 0): ?><?= $newPage($head) ?><?php endif; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php $t = $report['total']; ?>
                <tr class="total-row">
                    <td colspan="4">Period total (<?= (int) $t['count'] ?> receipt<?= $t['count'] === 1 ? '' : 's' ?>)</td>
                    <td class="num"><?= $peso($t['gross']) ?></td><td class="num"><?= $peso($t['discount']) ?></td><td class="num"><?= $peso($t['net']) ?></td><td class="num"><?= $peso($t['cash']) ?></td><td class="num"><?= $peso($t['gcash']) ?></td>
                </tr>
            </tbody>
        </table>

    <?php elseif ($type === 'purchases'): ?>
        <?php $head = '<thead><tr><th>PO No.</th><th>DR No.</th><th>Supplier</th><th>Date Received</th><th class="num">Items</th><th class="num">Amount</th></tr></thead>'; ?>
        <table>
            <?= $head ?>
            <tbody>
                <?php if ($report['rows'] === []): ?><tr><td colspan="6">No orders were received in this period.</td></tr><?php endif; ?>
                <?php foreach ($report['rows'] as $r): ?>
                    <tr>
                        <td><?= esc($r['po_number'] ?? '—') ?></td>
                        <td><?= esc($r['dr_number'] ?? '—') ?></td>
                        <td><?= esc($r['supplier'] !== '' ? $r['supplier'] : '—') ?></td>
                        <td><?= esc(date('M j, Y', strtotime($r['received']))) ?></td>
                        <td class="num"><?= (int) $r['items'] ?></td>
                        <td class="num"><?= $peso($r['amount']) ?></td>
                    </tr>
                    <?php if (++$n % $rowsPerPage === 0): ?><?= $newPage($head) ?><?php endif; ?>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="4">Period total (<?= (int) $report['total']['count'] ?> order<?= $report['total']['count'] === 1 ? '' : 's' ?>)</td>
                    <td></td>
                    <td class="num"><?= $peso($report['total']['amount']) ?></td>
                </tr>
            </tbody>
        </table>

    <?php else: ?>
        <?php
        // One row of a group: the label from AccountingReports::SUMMARY_LINES, the value, and row / label classes.
        $psRow = static fn (string $key, string $value, string $row = '', string $label = ''): string =>
            '<tr' . ($row !== '' ? ' class="' . $row . '"' : '') . '><td' . ($label !== '' ? ' class="' . $label . '"' : '') . '>'
            . esc(AccountingReports::SUMMARY_LINES[$key]) . '</td><td class="ps-value">' . esc($value) . '</td></tr>';
        ?>
        <div class="ps-label"><?= esc(AccountingReports::SUMMARY_LABEL) ?></div>

        <div class="ps-group">
            <div class="ps-heading">Sales</div>
            <table class="ps"><tbody>
                <?= $psRow('sales', $peso($report['sales'])) ?>
                <?= $psRow('discounts', $peso($report['discounts']), 'ps-last') ?>
                <?= $psRow('net_sales', $peso($report['net_sales']), 'ps-total') ?>
            </tbody></table>
            <div class="ps-heading ps-subhead">Net sales received as</div>
            <table class="ps"><tbody>
                <?= $psRow('cash', $peso($report['cash']), '', 'ps-indent') ?>
                <?= $psRow('gcash', $peso($report['gcash']), 'ps-last', 'ps-indent') ?>
            </tbody></table>
        </div>

        <div class="ps-group">
            <div class="ps-heading">Purchases</div>
            <table class="ps"><tbody>
                <?= $psRow('purchases', $peso($report['purchases']), 'ps-last ps-bold') ?>
            </tbody></table>
        </div>

        <div class="ps-group">
            <div class="ps-heading">Counts</div>
            <table class="ps"><tbody>
                <?= $psRow('receipts', number_format($report['receipts'])) ?>
                <?= $psRow('orders', number_format($report['orders']), 'ps-last') ?>
            </tbody></table>
        </div>
    <?php endif; ?>

    <div class="foot">Figures are taken from the system's receipts and received stock orders.</div>
</body>
</html>
