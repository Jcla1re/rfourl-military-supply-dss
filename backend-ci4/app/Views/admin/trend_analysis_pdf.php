<!DOCTYPE html>
<html>
<head>
    <link rel="icon" type="image/png" href="/assets/img/favicon/favicon-96x96.png?v=2" sizes="96x96" />
    <link rel="shortcut icon" href="/assets/img/favicon/favicon.ico?v=2" />
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/img/favicon/apple-touch-icon.png?v=2" />
    <meta name="apple-mobile-web-app-title" content="RfourL MS" />
    <link rel="manifest" href="/assets/img/favicon/site.webmanifest?v=2" />
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1c1c1c; }
    h1 { font-size: 16px; margin-bottom: 2px; }
    h2 { font-size: 13px; margin: 18px 0 6px; }
    .sub { color: #555; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
    th { background: #3d5230; color: #fff; }
    .num { text-align: right; }
</style>
</head>
<body>
<?php
$itemLabel = 'All items';
foreach ($products as $p) {
    if ($selectedItem && $p['item_id'] === $selectedItem) {
        $itemLabel = $p['item_name'] . ($p['size'] ? ' (' . $p['size'] . ')' : '');
    }
}
$classes = ['A', 'B', 'C'];
?>
<h1>Trend Analysis Report</h1>
<div class="sub">RfourL Military Apparel &middot; Year <?= esc($selectedYear) ?> &middot; <?= esc($itemLabel) ?> &middot; generated <?= date('M j, Y g:i A') ?></div>

<h2>Units sold per month</h2>
<table>
    <tr><th>Month</th><th class="num"><?= esc($selectedYear) ?></th><th class="num"><?= esc($selectedYear - 1) ?></th></tr>
    <?php foreach ($annualLabels as $i => $label): ?>
        <tr><td><?= esc($label) ?></td><td class="num"><?= (int) $annualData[$i] ?></td><td class="num"><?= (int) $annualDataPrev[$i] ?></td></tr>
    <?php endforeach; ?>
    <tr><th>Total</th><th class="num"><?= (int) array_sum($annualData) ?></th><th class="num"><?= (int) array_sum($annualDataPrev) ?></th></tr>
</table>

<h2>ABC classification (K-Means), sales share (<?= esc($selectedYear) ?>)</h2>
<table>
    <tr><th>Class</th><th class="num">Items</th><th class="num">Share of sales</th></tr>
    <?php foreach ($classes as $cls): ?>
        <tr><td><?= $cls ?></td><td class="num"><?= (int) ($abcClassCounts[$cls] ?? 0) ?></td><td class="num"><?= number_format((float) ($abcClassTotals[$cls] ?? 0), 1) ?>%</td></tr>
    <?php endforeach; ?>
</table>

<h2>Top items by sales value</h2>
<table>
    <tr><th>Item</th><th>Class</th><th class="num">Share</th></tr>
    <?php foreach ($abcTopItems as $it): ?>
        <tr><td><?= esc($it['item_name']) ?></td><td><?= esc($it['class'] ?? '—') ?></td><td class="num"><?= number_format($it['pct'], 1) ?>%</td></tr>
    <?php endforeach; ?>
</table>

<h2>Top sellers (units)</h2>
<table>
    <tr><th>Item</th><th class="num">Units sold</th></tr>
    <?php foreach ($topSellers as $s): ?>
        <tr><td><?= esc($s['item_name'] ?? $s['item_id']) ?></td><td class="num"><?= (int) $s['units_sold'] ?></td></tr>
    <?php endforeach; ?>
</table>

<h2>Daily demand</h2>
<table>
    <tr><th>Item</th><th class="num">Daily sales</th><th class="num">Safety stock</th></tr>
    <?php foreach ($variability as $v): ?>
        <tr><td><?= esc($v['item_name']) ?></td><td class="num"><?= number_format((float) $v['avg_daily_demand'], 2) ?></td><td class="num"><?= (int) $v['safety_stock'] ?></td></tr>
    <?php endforeach; ?>
</table>
</body>
</html>
