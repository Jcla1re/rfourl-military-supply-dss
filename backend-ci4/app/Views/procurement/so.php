<!DOCTYPE html>
<html>
<head>
<?= view('procurement/_head', [
    'title'  => 'Sales Order',
    'number' => $number,
    'meta'   => [['PO reference', $order['po_number'] ?? ''], ['Accepted', $dates['accepted']]],
]) ?>
</head>
<body>
    <div class="sub" style="margin-bottom: 8px;">The supplier's confirmation of the purchase order below.</div>
    <?= view('procurement/_supplier', ['supplier' => $supplier]) ?>

    <table class="kv" style="margin-bottom: 10px;">
        <tr><td class="k">SO no.</td><td class="v"><strong><?= esc($number) ?></strong></td></tr>
        <tr><td class="k">PO reference</td><td class="v"><?= esc($order['po_number'] ?? '—') ?></td></tr>
        <tr><td class="k">Accepted on</td><td class="v"><?= esc($dates['accepted'] ?: '—') ?></td></tr>
        <tr><td class="k">Delivery date</td><td class="v"><strong><?= esc($dates['expected'] ?: '—') ?></strong></td></tr>
    </table>

    <table class="items">
        <thead>
            <tr><th>#</th><th>Item</th><th>Size</th><th class="num">Accepted qty</th></tr>
        </thead>
        <tbody>
            <?php foreach ($lines as $i => $l): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= esc($l['name']) ?></td>
                    <td><?= esc($l['size'] ?: '—') ?></td>
                    <td class="num"><?= esc((string) $l['ordered']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (! $lines): ?><tr><td colspan="4">No line items recorded for this order.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <table class="sign">
        <tr>
            <td style="width: 45%;"><?= esc($supplier['company_name'] ?? '') ?><div class="line">Accepted by (supplier)</div></td>
        </tr>
    </table>
    <div class="foot">Generated <?= esc(date('Y-m-d g:i A')) ?></div>
</body>
</html>
