<?php
/**
 * The Reports PDF (same style as the sales PDF). Variables from ReportsController::pdf():
 * $type, $from, $to, $report, $generatedAt.
 */
use App\Libraries\AccountingReports;

$peso       = static fn (float $n): string => '₱' . number_format($n, 2);
$periodText = date('F j, Y', strtotime($from)) . ($from === $to ? '' : ' to ' . date('F j, Y', strtotime($to)));
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
    tr.sub-row td { background: #f7f6f2; font-weight: bold; }
    tr.total-row td { background: #e4efdf; font-weight: bold; font-size: 11px; }
    .note { border: 1px solid #ead9a0; background: #fff8e5; padding: 8px 10px; font-weight: bold; margin-bottom: 12px; }
    .summary { width: 70%; }
    .summary td { padding: 7px 9px; font-size: 11px; }
    .rp-dup { background: #fde8e8; color: #8a1c1c; font-size: 7px; font-weight: bold; text-transform: uppercase; padding: 1px 4px; }
    .foot { margin-top: 14px; color: #777; font-size: 9px; }
</style>
</head>
<body>
    <h1>RfourL Military Supply &mdash; <?= esc(AccountingReports::TYPES[$type]) ?></h1>
    <div class="sub">Period: <?= esc($periodText) ?> &middot; Generated <?= esc($generatedAt) ?></div>

    <?php if ($type === 'sales'): ?>
        <?php $days = array_column($report['days'], null, 'date'); $rows = $report['rows']; ?>
        <table>
            <thead>
                <tr><th>Receipt No.</th><th>OR No.</th><th>Date &amp; Time</th><th>Cashier</th><th class="num">Gross</th><th class="num">Discount</th><th class="num">Net</th><th class="num">Cash</th><th class="num">GCash</th></tr>
            </thead>
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
                    <?php if (! isset($rows[$i + 1]) || $rows[$i + 1]['date'] !== $r['date']): ?>
                        <?php $d = $days[$r['date']]; ?>
                        <tr class="sub-row">
                            <td colspan="4">Subtotal &middot; <?= esc(date('M j, Y', strtotime($r['date']))) ?> (<?= (int) $d['count'] ?> receipt<?= $d['count'] === 1 ? '' : 's' ?>)</td>
                            <td class="num"><?= $peso($d['gross']) ?></td><td class="num"><?= $peso($d['discount']) ?></td><td class="num"><?= $peso($d['net']) ?></td><td class="num"><?= $peso($d['cash']) ?></td><td class="num"><?= $peso($d['gcash']) ?></td>
                        </tr>
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
        <table>
            <thead>
                <tr><th>PO No.</th><th>DR No.</th><th>Supplier</th><th>Date Received</th><th class="num">Items</th><th class="num">Amount</th></tr>
            </thead>
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
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="4">Period total (<?= (int) $report['total']['count'] ?> order<?= $report['total']['count'] === 1 ? '' : 's' ?>)</td>
                    <td></td>
                    <td class="num"><?= $peso($report['total']['amount']) ?></td>
                </tr>
            </tbody>
        </table>

    <?php else: ?>
        <div class="note"><?= esc(AccountingReports::SUMMARY_LABEL) ?></div>
        <table class="summary">
            <tbody>
                <?php foreach ([
                    ['Total sales (gross)', $peso($report['sales'])],
                    ['Discounts', $peso($report['discounts'])],
                    ['Net sales', $peso($report['net_sales'])],
                    ['Cash', $peso($report['cash'])],
                    ['GCash', $peso($report['gcash'])],
                    ['Total purchases', $peso($report['purchases'])],
                    ['Number of receipts', number_format($report['receipts'])],
                    ['Number of orders received', number_format($report['orders'])],
                ] as [$label, $value]): ?>
                    <tr><td><?= esc($label) ?></td><td class="num"><strong><?= esc($value) ?></strong></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="foot">Figures are taken from the system's receipts and received stock orders.</div>
</body>
</html>
