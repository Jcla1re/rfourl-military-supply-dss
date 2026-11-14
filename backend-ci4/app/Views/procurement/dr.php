<!DOCTYPE html>
<html>
<head>
<?= view('procurement/_head', [
    'title'  => 'Delivery Receipt',
    'number' => $number,
    'meta'   => [['PO reference', $order['po_number'] ?? ''], ['SO reference', $order['so_number'] ?? ''], ['Shipped', $dates['shipped']]],
]) ?>
</head>
<body>
    <?= view('procurement/_supplier', ['supplier' => $supplier]) ?>

    <table class="kv" style="margin-bottom: 10px;">
        <tr><td class="k">DR no.</td><td class="v"><strong><?= esc($number) ?></strong></td></tr>
        <tr><td class="k">PO reference</td><td class="v"><?= esc($order['po_number'] ?? '—') ?></td></tr>
        <tr><td class="k">SO reference</td><td class="v"><?= esc($order['so_number'] ?? '—') ?></td></tr>
        <tr><td class="k">Courier</td><td class="v"><?= esc($supplier['preferred_courier'] ?? '') ?: '—' ?></td></tr>
        <tr><td class="k">Tracking no.</td><td class="v"><?= esc($order['tracking_no'] ?? '') ?: '—' ?></td></tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>#</th><th>Item</th><th>Size</th>
                <th class="num">Qty shipped</th>
                <?php if ($received): ?><th class="num">Qty received</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lines as $i => $l): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= esc($l['name']) ?></td>
                    <td><?= esc($l['size'] ?: '—') ?></td>
                    <td class="num"><?= esc((string) ($l['shipped'] ?? $l['ordered'])) ?></td>
                    <?php if ($received): ?><td class="num"><?= esc((string) $l['received']) ?></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (! $lines): ?><tr><td colspan="<?= $received ? 5 : 4 ?>">No line items recorded for this order.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <?php if ($received): ?>
        <table class="kv" style="margin-top: 12px;">
            <tr><td class="k">Received by</td><td class="v"><strong><?= esc($received_by ?: '—') ?></strong></td></tr>
            <tr><td class="k">Received on</td><td class="v"><strong><?= esc($received_at ?: '—') ?></strong></td></tr>
        </table>
    <?php endif; ?>

    <table class="sign">
        <tr>
            <td style="width: 45%;"><?= $received ? esc($received_by ?: '') : '' ?>&nbsp;<div class="line">Received by (signature over printed name)</div></td>
            <td style="width: 45%;">&nbsp;<div class="line">Date received</div></td>
        </tr>
    </table>
    <div class="foot">Generated <?= esc(date('Y-m-d g:i A')) ?></div>
</body>
</html>
