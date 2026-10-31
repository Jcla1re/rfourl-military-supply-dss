<?php
/**
 * Saved copy of sales older than 3 years (PDF or print).
 * @var array  $summary
 * @var array  $report   year => [monthly, top, receipts]
 * @var string $mode     'pdf' | 'print'
 */
$months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$peso   = fn ($n) => 'PHP ' . number_format((float) $n, 2);
?><!DOCTYPE html>
<html>
<head>
    <link rel="icon" type="image/png" href="/assets/img/favicon/favicon-96x96.png?v=2" sizes="96x96" />
    <link rel="shortcut icon" href="/assets/img/favicon/favicon.ico?v=2" />
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/img/favicon/apple-touch-icon.png?v=2" />
    <meta name="apple-mobile-web-app-title" content="RfourL MS" />
    <link rel="manifest" href="/assets/img/favicon/site.webmanifest?v=2" />
<meta charset="utf-8">
<title>Sales before <?= esc($summary['cutoff']) ?></title>
<style>
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; color: #222; }
    h1 { font-size: 18px; margin: 0 0 4px; }
    h2 { font-size: 14px; margin: 18px 0 6px; border-bottom: 2px solid #2e7d32; padding-bottom: 3px; }
    h3 { font-size: 11px; margin: 12px 0 4px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #eef3ee; text-align: left; }
    th, td { border: 1px solid #ccc; padding: 3px 5px; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .muted { color: #666; }
    .year { page-break-before: always; }
    @media print { .noprint { display: none; } }
</style>
</head>
<body<?= $mode === 'print' ? ' onload="window.print()"' : '' ?>>
<h1>RfourL Military Supply &mdash; Sales Records Older Than 3 Years</h1>
<p class="muted">Sales dated before <?= esc(date('F j, Y', strtotime($summary['cutoff']))) ?> &middot; printed <?= date('F j, Y g:i A') ?></p>

<table style="width:60%">
    <tr><th>Year</th><th class="num">Receipts</th><th class="num">Units</th><th class="num">Sales value</th></tr>
    <?php foreach ($summary['years'] as $y): ?>
        <tr><td><?= esc($y['yr']) ?></td><td class="num"><?= number_format($y['receipts']) ?></td><td class="num"><?= number_format($y['units']) ?></td><td class="num"><?= $peso($y['revenue']) ?></td></tr>
    <?php endforeach; ?>
    <tr><th>Total</th><th class="num"><?= number_format($summary['receipts']) ?></th><th class="num"><?= number_format($summary['units']) ?></th><th class="num"><?= $peso($summary['revenue']) ?></th></tr>
</table>

<?php foreach ($report as $yr => $y): ?>
    <div class="year">
        <h2>Year <?= esc($yr) ?></h2>

        <h3>Trend &mdash; units sold and sales value by month</h3>
        <table>
            <tr><th></th><?php foreach ($months as $m): ?><th class="num"><?= $m ?></th><?php endforeach; ?></tr>
            <tr><td>Units</td><?php for ($i = 1; $i <= 12; $i++): ?><td class="num"><?= number_format($y['monthly'][$i]['units'] ?? 0) ?></td><?php endfor; ?></tr>
            <tr><td>Sales (PHP)</td><?php for ($i = 1; $i <= 12; $i++): ?><td class="num"><?= number_format($y['monthly'][$i]['revenue'] ?? 0) ?></td><?php endfor; ?></tr>
        </table>

        <h3>Best sellers (top 15 by units)</h3>
        <table>
            <tr><th>#</th><th>Item</th><th>Size</th><th class="num">Units</th><th class="num">Sales value</th></tr>
            <?php foreach (array_slice($y['top'] ?? [], 0, 15) as $i => $t): ?>
                <tr><td><?= $i + 1 ?></td><td><?= esc($t['item_name']) ?></td><td><?= esc($t['size']) ?></td><td class="num"><?= number_format($t['units']) ?></td><td class="num"><?= $peso($t['revenue']) ?></td></tr>
            <?php endforeach; ?>
        </table>

        <h3>Sales history &mdash; <?= count($y['receipts'] ?? []) ?> receipts</h3>
        <table>
            <tr><th style="width:70px">Date</th><th style="width:90px">Receipt</th><th>Items</th><th class="num" style="width:80px">Total</th></tr>
            <?php foreach ($y['receipts'] ?? [] as $r): ?>
                <tr><td><?= esc(date('Y-m-d', strtotime($r['sale_date']))) ?></td><td><?= esc($r['receipt_no']) ?></td><td><?= esc($r['items']) ?></td><td class="num"><?= number_format($r['total_amount'], 2) ?></td></tr>
            <?php endforeach; ?>
        </table>
    </div>
<?php endforeach; ?>
</body>
</html>
