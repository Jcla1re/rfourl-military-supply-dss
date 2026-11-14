<?php
/**
 * A small "Documents: PO · SO · DR" line for an order, one link per document the order has reached.
 * Each opens the PDF in a new tab. Expects $order (a stock_order row) and $base ('supplier' or 'staff').
 */
$docs = \App\Libraries\ProcurementDocs::available($order);
if ($docs === []) {
    return;
}
?>
<span class="order-docs" style="font-size:12px;color:#666;margin-left:6px;">Documents:
    <?php foreach ($docs as $i => $type): ?><?= $i ? ' &middot; ' : '' ?><a href="<?= site_url($base . '/orders/' . $order['so_id'] . '/document/' . $type) ?>" target="_blank" rel="noopener" title="<?= esc(\App\Libraries\ProcurementDocs::TYPES[$type]['label'], 'attr') ?> <?= esc($order[\App\Libraries\ProcurementDocs::TYPES[$type]['column']], 'attr') ?>"><?= strtoupper($type) ?></a><?php endforeach; ?>
</span>
