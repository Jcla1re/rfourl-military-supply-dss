<!DOCTYPE html>
<html>
<head>
<?= view('procurement/_head', [
    'title'  => 'Purchase Order',
    'number' => $number,
    'meta'   => [['PO date', $dates['order']]],
]) ?>
</head>
<body>
    <?= view('procurement/_supplier', ['supplier' => $supplier]) ?>

    <table class="kv" style="margin-bottom: 10px;">
        <tr><td class="k">PO no.</td><td class="v"><strong><?= esc($number) ?></strong></td></tr>
        <tr><td class="k">PO date</td><td class="v"><?= esc($dates['order'] ?: '—') ?></td></tr>
        <tr><td class="k">Expected delivery date</td><td class="v"><strong><?= esc($dates['expected'] ?: '—') ?></strong></td></tr>
        <tr><td class="k">Prepared by</td><td class="v"><?= esc($prepared_by ?: '—') ?></td></tr>
    </table>

    <table class="items">
        <thead>
            <tr><th>#</th><th>Item</th><th>Size</th><th class="num">Qty</th><th class="num">Unit cost</th><th class="num">Line total</th></tr>
        </thead>
        <tbody>
            <?php foreach ($lines as $i => $l): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= esc($l['name']) ?></td>
                    <td><?= esc($l['size'] ?: '—') ?></td>
                    <td class="num"><?= esc((string) $l['ordered']) ?></td>
                    <td class="num">₱<?= number_format($l['unit_cost'], 2) ?></td>
                    <td class="num">₱<?= number_format($l['line'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (! $lines): ?><tr><td colspan="6">No line items recorded for this order.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <div class="totals">Total: ₱<?= number_format($total, 2) ?></div>

    <table class="sign">
        <tr>
            <td style="width: 45%;"><?= esc($prepared_by ?: '') ?><div class="line">Prepared by</div></td>
            <td style="width: 45%;">&nbsp;<div class="line">Authorized signature</div></td>
        </tr>
    </table>
    <div class="foot">Generated <?= esc(date('Y-m-d g:i A')) ?></div>
</body>
</html>
