<?php
/**
 * Trend Analysis PDF report (Dompdf). Only lays out $report, which App\Libraries\TrendReport
 * built from the page's own data; the charts are SVG images drawn by App\Libraries\PdfCharts.
 *
 * @var array $report
 */
$r        = $report;
$year     = $r['year'];
$scoped   = $r['scoped'];
$notFiltered = $scoped ? '<p class="note">Not filtered by item: this section shows all items, like the page.</p>' : '';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Trend Analysis <?= esc($year) ?> | RfourL</title>
<style>
    @page { margin: 34px 36px 52px 36px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1F2617; line-height: 1.45; }
    h1 { font-size: 20px; margin: 0 0 2px; color: #2C3A1E; }
    h2 { font-size: 13px; margin: 20px 0 6px; padding-bottom: 3px; border-bottom: 2px solid #71AA60; color: #2C3A1E; page-break-after: avoid; }
    h3 { font-size: 10.5px; margin: 12px 0 4px; color: #2C3A1E; page-break-after: avoid; }
    p { margin: 4px 0; }
    .store { font-size: 11px; color: #5C6152; margin-bottom: 8px; }
    .note { font-size: 8.5px; color: #8A8F80; margin: 4px 0; }
    .muted { color: #5C6152; }
    .keep { page-break-inside: avoid; }
    .page-break { page-break-before: always; }
    img { display: block; }

    table { width: 100%; border-collapse: collapse; }
    table.meta td { padding: 2px 8px 2px 0; border: 0; vertical-align: top; }
    table.meta td.k { width: 120px; color: #5C6152; }
    table.data { margin: 6px 0; page-break-inside: avoid; }
    table.data th { background: #2C3A1E; color: #fff; font-size: 9px; padding: 5px 6px; text-align: left; border: 1px solid #2C3A1E; }
    table.data td { padding: 4px 6px; border: 1px solid #D9D6CC; font-size: 9px; }
    table.data tr.alt td { background: #F5F3EE; }
    table.data tr.total td { background: #E4EFDF; font-weight: bold; }
    table.long { page-break-inside: auto; }
    table.long tr { page-break-inside: avoid; }
    thead { display: table-header-group; }
    .num { text-align: right; }
    .up { color: #3B7D2C; }
    .down { color: #B42318; }

    .cards-wrap { margin: 0 -6px; }
    table.cards { border-collapse: separate; border-spacing: 6px 0; width: 100%; page-break-inside: avoid; }
    table.cards td { width: 25%; border: 1px solid #D9D6CC; border-radius: 6px; padding: 8px 9px; vertical-align: top; background: #fff; }
    .card-label { font-size: 8.5px; color: #5C6152; }
    .card-value { font-size: 19px; font-weight: bold; color: #1F2617; margin: 3px 0 2px; }
    .card-value.text { font-size: 12.5px; padding: 3px 0 2px; }
    .card-sub { font-size: 8.5px; color: #5C6152; }
    .says { margin: 8px 0 0; padding: 0; list-style: none; }
    .says li { margin: 0 0 3px; font-size: 9.5px; }
    .says b { color: #2C3A1E; }

    .box { border: 1px solid #C5DDBB; background: #F4F9F1; border-radius: 6px; padding: 7px 10px; margin: 8px 0; page-break-inside: avoid; }
    .box h3 { margin: 0 0 3px; }
    .box ul { margin: 2px 0 0 14px; padding: 0; }
    .chart { margin: 6px 0; page-break-inside: avoid; }
    .badge { font-weight: bold; }
    .cols td { vertical-align: top; border: 1px solid #D9D6CC; padding: 5px 7px; font-size: 8.5px; }
    .cols th { background: #2C3A1E; color: #fff; font-size: 9px; padding: 4px 7px; text-align: left; }
    .howto { margin: 4px 0 8px; padding-left: 14px; font-size: 9px; }
</style>
</head>
<body>

<h1>Trend Analysis Report</h1>
<div class="store"><?= esc($r['header']['store']) ?></div>
<table class="meta">
    <tr><td class="k">Year</td><td><?= esc($year) ?></td></tr>
    <tr><td class="k">Item filter</td><td><?= esc($r['header']['item']) ?></td></tr>
    <tr><td class="k">Period</td><td><?= esc($r['header']['coverage']) ?></td></tr>
    <tr><td class="k">Generated</td><td><?= esc($r['header']['generated']) ?></td></tr>
    <tr><td class="k">Last recorded sale</td><td><?= esc($r['header']['lastSale']) ?></td></tr>
</table>

<h2>Summary</h2>
<div class="cards-wrap"><table class="cards">
    <tr>
        <?php foreach ($r['cards'] as $card): ?>
            <td>
                <div class="card-label"><?= esc($card['label']) ?></div>
                <div class="card-value<?= strlen($card['value']) > 9 ? ' text' : '' ?> <?= $card['tone'] === 'up' ? 'up' : ($card['tone'] === 'down' ? 'down' : '') ?>"><?= esc($card['value']) ?></div>
                <div class="card-sub"><?= esc($card['sub']) ?></div>
            </td>
        <?php endforeach; ?>
    </tr>
</table></div>
<ul class="says">
    <?php foreach ($r['cards'] as $card): ?>
        <li><b><?= esc($card['label']) ?>:</b> <?= esc($card['sentence']) ?></li>
    <?php endforeach; ?>
</ul>

<h2><?= esc($r['monthly']['title']) ?></h2>
<div class="chart"><img src="<?= $r['monthly']['chart'] ?>" width="680" height="250" alt="Items sold per month, <?= esc($year) ?> compared with <?= esc($year - 1) ?>"></div>
<table class="data">
    <thead>
        <tr><th style="width:130px;">Month</th><th class="num"><?= esc($year) ?></th><th class="num"><?= esc($year - 1) ?></th><th class="num">Difference</th><th class="num">% change</th></tr>
    </thead>
    <?php foreach ($r['monthly']['rows'] as $i => $row): ?>
        <tr class="<?= $i % 2 ? 'alt' : '' ?>">
            <td><?= esc($row['month']) ?></td>
            <td class="num"><?= esc($row['this']) ?></td>
            <td class="num"><?= esc($row['prev']) ?></td>
            <td class="num"><?= esc($row['diff']) ?></td>
            <td class="num"><?= esc($row['pct']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php foreach ($r['monthly']['totals'] as $row): ?>
        <tr class="total">
            <td><?= esc($row['label']) ?></td>
            <td class="num"><?= esc($row['this']) ?></td>
            <td class="num"><?= esc($row['prev']) ?></td>
            <td class="num"><?= esc($row['diff']) ?></td>
            <td class="num"><?= esc($row['pct']) ?></td>
        </tr>
    <?php endforeach; ?>
</table>
<p class="note"><?= esc($r['monthly']['footnote']) ?></p>
<div class="box">
    <h3>What this shows</h3>
    <ul>
        <?php foreach ($r['monthly']['insights'] as $line): ?><li><?= esc($line) ?></li><?php endforeach; ?>
    </ul>
</div>

<h2>Weekly view<?= $scoped ? ' · ' . esc($r['itemLabel']) : '' ?></h2>
<div class="chart"><img src="<?= $r['weekly']['chart'] ?>" width="680" height="200" alt="Items sold in each of the last 12 weeks"></div>
<table class="data" style="width:60%;">
    <thead><tr><th>Week</th><th class="num">Items sold</th></tr></thead>
    <?php foreach ($r['weekly']['rows'] as $i => $row): ?>
        <tr class="<?= $i % 2 ? 'alt' : '' ?>"><td><?= esc($row['range']) ?></td><td class="num"><?= esc($row['units']) ?></td></tr>
    <?php endforeach; ?>
</table>
<p><?= esc($r['weekly']['sentence']) ?></p>

<h2>Product performance</h2>
<p class="muted">Items sold, all time: the five best sellers.</p>
<?php if ($r['products']['chart']): ?>
    <div class="chart"><img src="<?= $r['products']['chart'] ?>" width="680" alt="Top 5 items sold, all time"></div>
<?php endif; ?>
<p><?= esc($r['products']['sentence']) ?></p>
<?= $notFiltered ?>

<h2>ABC classification (K-Means)</h2>
<p><?= esc($r['abc']['sentence']) ?></p>
<?php if (! $r['abc']['any']): ?>
    <p class="muted">No sales in <?= esc($year) ?> yet, so there is nothing to classify.</p>
<?php else: ?>
    <div class="chart"><img src="<?= $r['abc']['chart'] ?>" width="680" alt="Number of items in each ABC class"></div>
    <table class="data">
        <thead>
            <tr><th>Class</th><th class="num">Items</th><th>Priority</th><th class="num">Service level</th><th class="num">Share of sales (<?= esc($year) ?>)</th><th>Meaning</th></tr>
        </thead>
        <?php foreach ($r['abc']['classes'] as $i => $c): ?>
            <tr class="<?= $i % 2 ? 'alt' : '' ?>">
                <td><span class="badge"><?= esc($c['class']) ?></span></td>
                <td class="num"><?= esc($c['items']) ?></td>
                <td><?= esc($c['priority']) ?></td>
                <td class="num"><?= esc($c['service']) ?></td>
                <td class="num"><?= esc($c['share']) ?></td>
                <td><?= esc($c['meaning']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <p class="note"><?= esc($r['abc']['shareNote']) ?><?= $r['abc']['unclassedNote'] !== '' ? ' ' . esc($r['abc']['unclassedNote']) : '' ?></p>

    <h3>Top 5 items by sales share in each class</h3>
    <table class="cols keep">
        <tr>
            <?php foreach ($r['abc']['classes'] as $c): ?><th>Class <?= esc($c['class']) ?></th><?php endforeach; ?>
        </tr>
        <tr>
            <?php foreach ($r['abc']['classes'] as $c): ?>
                <td>
                    <?php if (! $c['top']): ?>
                        <span class="muted">No items</span>
                    <?php else: ?>
                        <?php foreach ($c['top'] as $it): ?>
                            <div><?= esc($it['rank']) ?>. <?= esc($it['label']) ?> · <b><?= esc($it['pct']) ?></b></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
            <?php endforeach; ?>
        </tr>
    </table>
    <p class="note">The number before each item is its rank by sales share across all classes in <?= esc($year) ?>.</p>
<?php endif; ?>
<?= $notFiltered ?>

<h2>Daily demand</h2>
<ul class="howto">
    <?php foreach ($r['demand']['howTo'] as $column => $text): ?>
        <li><b><?= esc($column) ?></b> is <?= esc($text) ?></li>
    <?php endforeach; ?>
</ul>
<?php if (! $r['demand']['rows']): ?>
    <p class="muted">No item sold in <?= esc($year) ?> has a DSS calculation yet.</p>
<?php else: ?>
    <table class="data long">
        <thead>
            <tr><th class="num" style="width:28px;">#</th><th>Item</th><th class="num" style="width:110px;">Daily sales (per day)</th><th class="num" style="width:110px;">Safety stock (units)</th><th style="width:44px;">Class</th></tr>
        </thead>
        <?php foreach ($r['demand']['rows'] as $i => $row): ?>
            <tr class="<?= $i % 2 ? 'alt' : '' ?>">
                <td class="num"><?= esc($row['rank']) ?></td>
                <td><?= esc($row['item']) ?></td>
                <td class="num"><?= esc($row['daily']) ?></td>
                <td class="num"><?= esc($row['safety']) ?></td>
                <td><?= esc($row['class']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
<?= $notFiltered ?>

<h2>Notes</h2>
<ul class="howto">
    <?php foreach ($r['notes']['lines'] as $line): ?><li><?= esc($line) ?></li><?php endforeach; ?>
</ul>
<p><?= esc($r['notes']['dss']) ?></p>

</body>
</html>
