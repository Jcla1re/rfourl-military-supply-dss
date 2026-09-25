<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1c1c1c; }
    h1 { font-size: 16px; margin-bottom: 2px; }
    .sub { color: #555; margin-bottom: 14px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
    th { background: #3d5230; color: #fff; }
    tr:nth-child(even) td { background: #f7f6f2; }
    .num { text-align: right; }
    .totals { margin-top: 14px; text-align: right; font-size: 13px; font-weight: bold; }
</style>
</head>
<body>
    <h1>RfourL Military Supply &mdash; Sales Report</h1>
    <div class="sub">Date: <?= esc(date('F j, Y', strtotime($date))) ?> &middot; Generated <?= esc(date('Y-m-d g:i A')) ?></div>

    <table>
        <thead>
            <tr>
                <th>Receipt #</th>
                <th>Date &amp; Time</th>
                <th>Items</th>
                <th>Qty</th>
                <th>Unit Price</th>
                <th>Cash</th>
                <th>GCash</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($transactions)): ?>
                <tr><td colspan="8">No sales transactions recorded this period.</td></tr>
            <?php else: ?>
                <?php foreach ($transactions as $t): ?>
                    <tr>
                        <td>#<?= esc($t['receipt_no']) ?></td>
                        <td><?= esc(date('M j, Y g:i A', strtotime($t['sale_date']))) ?></td>
                        <td>
                            <?php foreach (($t['items'] ?? []) as $line): ?>
                                <?= esc($line['item_name'] ?? $line['item_id']) ?><br>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <?php foreach (($t['items'] ?? []) as $line): ?>
                                <?= esc($line['quantity_sold']) ?><br>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <?php foreach (($t['items'] ?? []) as $line): ?>
                                ₱<?= number_format((float) $line['selling_price'], 2) ?><br>
                            <?php endforeach; ?>
                        </td>
                        <td class="num"><?= (float) ($t['cash_amount'] ?? 0) > 0 ? '₱' . number_format((float) $t['cash_amount'], 2) : '—' ?></td>
                        <td class="num"><?= (float) ($t['gcash_amount'] ?? 0) > 0 ? '₱' . number_format((float) $t['gcash_amount'], 2) : '—' ?></td>
                        <td class="num">₱<?= number_format((float) $t['total_amount'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="totals">Total for period: ₱<?= number_format((float) ($totalSales ?? 0), 2) ?></div>
</body>
</html>
