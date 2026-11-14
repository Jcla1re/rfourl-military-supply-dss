
<?= $this->extend('layouts/admin_layout') ?>

<?= $this->section('header_actions') ?>
<a href="<?= site_url('admin/orders') ?>" class="btn btn-topbar">
    <i class="bi bi-arrow-left"></i> Back to Orders
</a>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<?php
$order = $order ?? [];
$items = $items ?? [];
$total = array_sum(array_map(fn ($i) => $i['order_quantity'] * $i['unit_price'], $items));

$pillClass = match ($order['status'] ?? '') {
    'Order Confirmed' => 'gray',
    'Preparing'        => 'amber',
    'Shipped'          => 'blue',
    'Delivered'        => 'green',
    'Cancelled'        => 'red',
    default            => 'gray',
};
?>

<style>
.detail-grid { display:grid; grid-template-columns: repeat(2, 1fr); gap: 16px 32px; }
.detail-field span { display:block; color:#666; font-size:12px; text-transform:uppercase; letter-spacing:.05em; }
.detail-field strong { font-size: 16px; }
.proc-flow { display: flex; align-items: flex-start; list-style: none; margin: 0; padding: 0; }
.proc-step { flex: 1 1 0; min-width: 0; position: relative; text-align: center; padding: 0 6px; color: #8a8f85; }
.proc-step::before { content: ''; position: absolute; top: 17px; left: -50%; width: 100%; height: 3px; background: #dcdad2; z-index: 0; }
.proc-step:first-child::before { display: none; }
.proc-step.done::before { background: #4b6a3f; }
.proc-dot { position: relative; z-index: 1; width: 36px; height: 36px; margin: 0 auto 8px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: #e9e7df; color: #8a8f85; font-size: 16px; }
.proc-step.done .proc-dot { background: #4b6a3f; color: #fff; }
.proc-step.stopped .proc-dot { background: #c0392b; color: #fff; }
.proc-step.done { color: #1c1c1c; }
.proc-step.stopped { color: #c0392b; }
.proc-name { font-weight: 600; font-size: 14px; }
.proc-num { font-size: 12px; font-weight: 600; letter-spacing: .02em; }
.proc-meta { font-size: 12px; color: #666; margin-top: 2px; overflow-wrap: anywhere; }
.proc-step.stopped .proc-meta { color: #c0392b; }
.proc-step .btn { margin-top: 8px; }
@media (max-width: 640px) {
    .proc-flow { flex-direction: column; gap: 14px; }
    .proc-step { display: grid; grid-template-columns: 36px 1fr; column-gap: 12px; text-align: left; padding: 0; }
    .proc-step::before { display: none; }
    .proc-dot { margin: 0; grid-row: 1 / span 5; }
    .proc-step .btn { justify-self: start; min-height: 40px; }
}
</style>

<div class="page-wrap">
    <div class="page-panel mb-3">
        <div class="d-flex justify-content-between align-items-start mb-4 gap-3 flex-wrap">
            <div>
                <h2 class="mb-1"><?= esc(\App\Models\StockOrderModel::label($order)) ?></h2>
                <span class="status-pill <?= $pillClass ?>"><?= esc($order['status'] ?? '') ?></span>
                <?php if ($note = \App\Models\StockOrderModel::cancelNote($order)): ?>
                    <div class="small text-muted mt-2"><?= esc($note) ?></div>
                <?php endif; ?>
            </div>
            <div class="text-end">
                <?php if (\App\Models\StockOrderModel::canCancel($order)): ?>
                    <button type="button" class="btn btn-outline-danger" data-cancel-order
                            data-action="<?= site_url('admin/orders/cancel/' . $order['so_id']) ?>"
                            data-so="<?= esc(\App\Models\StockOrderModel::label($order), 'attr') ?>"
                            data-supplier="<?= esc($order['company_name'] ?? '', 'attr') ?>"
                            data-items="<?= count($items) ?>"
                            data-status="<?= esc($order['status'], 'attr') ?>">Cancel order</button>
                <?php elseif (($order['status'] ?? '') === 'Shipped'): ?>
                    <div class="small text-muted" style="max-width:260px;">Already shipped. Contact the supplier to refuse or return it on delivery.</div>
                <?php elseif (($order['status'] ?? '') === 'Cancelled'): ?>
                    <a class="btn btn-success" href="<?= site_url('admin/orders?reorder=' . $order['so_id']) ?>"><i class="bi bi-arrow-repeat"></i> Reorder</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="detail-grid">
            <div class="detail-field">
                <span>Supplier</span>
                <strong><?= esc($order['company_name'] ?? '—') ?></strong>
            </div>
            <div class="detail-field">
                <span>Priority</span>
                <strong><?= esc(\App\Models\StockOrderModel::priorityLabel($order['priority'] ?? null)) ?></strong>
            </div>
            <div class="detail-field">
                <span>Contact Person</span>
                <strong><?= esc($order['contact_person'] ?? '—') ?></strong>
            </div>
            <div class="detail-field">
                <span>Contact</span>
                <strong><?= esc($order['contact_email'] ?? '—') ?> · <?= esc($order['contact_number'] ?? '—') ?></strong>
            </div>
            <div class="detail-field">
                <span>Order Date</span>
                <strong><?= !empty($order['order_date']) ? esc(date('M j, Y', strtotime($order['order_date']))) : '—' ?></strong>
            </div>
            <div class="detail-field">
                <span>Expected Delivery</span>
                <strong><?= !empty($order['expected_delivery_date']) ? esc(date('M j, Y', strtotime($order['expected_delivery_date']))) : '—' ?></strong>
            </div>
            <div class="detail-field">
                <span>Actual Delivery</span>
                <strong><?= !empty($order['actual_delivery_date']) ? esc(date('M j, Y', strtotime($order['actual_delivery_date']))) : 'Not yet delivered' ?></strong>
            </div>
            <div class="detail-field">
                <span>Tracking Number</span>
                <strong><?= esc($order['tracking_no'] ?? '—') ?></strong>
            </div>
            <?php if (!empty($order['decline_reason'])): ?>
            <div class="detail-field">
                <span>Supplier's Decline Reason</span>
                <strong><?= esc($order['decline_reason']) ?></strong>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php
    $stepIcons = ['po' => 'bi-file-earmark-text', 'so' => 'bi-patch-check', 'dr' => 'bi-truck', 'received' => 'bi-box-seam'];
    ?>
    <div class="page-panel mb-3" id="documents">
        <h5 class="mb-3">Procurement Flow</h5>
        <ol class="proc-flow">
            <?php foreach (($steps ?? []) as $step): ?>
                <li class="proc-step <?= esc($step['state']) ?>" data-step="<?= esc($step['key'], 'attr') ?>" data-state="<?= esc($step['state'], 'attr') ?>">
                    <div class="proc-dot"><i class="bi <?= $step['state'] === 'stopped' ? 'bi-x-lg' : ($step['state'] === 'done' ? 'bi-check-lg' : $stepIcons[$step['key']]) ?>" aria-hidden="true"></i></div>
                    <div class="proc-name"><?= esc($step['label']) ?></div>
                    <?php if ($step['number']): ?><div class="proc-num"><?= esc($step['number']) ?></div><?php endif; ?>
                    <?php if ($step['when']): ?><div class="proc-meta"><?= esc($step['when']) ?></div><?php endif; ?>
                    <?php if ($step['actor']): ?><div class="proc-meta"><?= esc($step['actor']) ?></div><?php endif; ?>
                    <?php if ($step['note']): ?><div class="proc-meta"><strong>Stopped here.</strong> <?= esc($step['note']) ?></div><?php endif; ?>
                    <?php if ($step['doc']): ?>
                        <a class="btn btn-outline-dark btn-sm" target="_blank" rel="noopener" href="<?= site_url('admin/orders/' . $order['so_id'] . '/document/' . $step['doc']) ?>"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> View PDF</a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>

    <div class="page-panel">
        <h5 class="mb-3">Ordered Items</h5>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Ordered Qty</th>
                    <th>Shipped Qty</th>
                    <th>Unit Price</th>
                    <th>Line Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($items)): ?>
                    <?php foreach ($items as $i): ?>
                        <tr>
                            <td><?= esc($i['item_name'] ?? $i['item_id']) ?></td>
                            <td><?= esc($i['category'] ?? '—') ?></td>
                            <td><?= esc($i['order_quantity']) ?></td>
                            <td><?= $i['shipped_quantity'] !== null ? esc((string) $i['shipped_quantity']) : '—' ?></td>
                            <td>₱<?= number_format((float) $i['unit_price'], 2) ?></td>
                            <td>₱<?= number_format($i['order_quantity'] * $i['unit_price'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="text-center py-4">No line items recorded for this order.</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($items)): ?>
                <tfoot>
                    <tr>
                        <td colspan="5" class="text-end fw-bold">Total</td>
                        <td class="fw-bold">₱<?= number_format($total, 2) ?></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>

    <?php if (($order['status'] ?? '') === 'Shipped'): ?>
        <div class="page-panel mt-3">
            <h5 class="mb-1">Confirm Delivery</h5>
            <p class="subtitle">Tick each item that arrived. It should match what the supplier shipped.</p>
            <form method="post" action="<?= site_url('admin/orders/update-status/' . $order['so_id']) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="status" value="Delivered">
                <table class="data-table mb-3">
                    <thead><tr><th>Item</th><th>Shipped Qty</th><th>Unit Price</th><th>Line Total</th><th style="text-align:center;">Received</th></tr></thead>
                    <tbody>
                        <?php foreach ($items as $i): ?>
                            <?php $shippedQty = $i['shipped_quantity'] ?? $i['order_quantity']; ?>
                            <tr>
                                <td><?= esc($i['item_name'] ?? $i['item_id']) ?></td>
                                <td><?= esc((string) $shippedQty) ?></td>
                                <td>₱<?= number_format((float) $i['unit_price'], 2) ?></td>
                                <td>₱<?= number_format((float) $shippedQty * (float) $i['unit_price'], 2) ?></td>
                                <td style="text-align:center;"><input type="checkbox" required title="Confirm this item was physically received"></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="text-end fw-bold">Total (shipped quantities)</td>
                            <td class="fw-bold">₱<?= number_format(array_sum(array_map(fn ($i) => ($i['shipped_quantity'] ?? $i['order_quantity']) * $i['unit_price'], $items)), 2) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
                <button type="submit" class="btn btn-success">Confirm Delivery Received</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<?= view('admin/_cancel_order_modal') ?>

<?= $this->endSection() ?>
