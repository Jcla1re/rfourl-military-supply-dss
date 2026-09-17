
<?= $this->extend('layouts/admin_layout') ?>

<?= $this->section('header_actions') ?>
<a href="<?= site_url('admin/orders') ?>" class="btn btn-outline-light">
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
</style>

<div class="page-wrap">
    <div class="page-panel mb-3">
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <h2 class="mb-1"><?= esc($order['so_id'] ?? '') ?></h2>
                <span class="status-pill <?= $pillClass ?>"><?= esc($order['status'] ?? '') ?></span>
            </div>
        </div>

        <div class="detail-grid">
            <div class="detail-field">
                <span>Supplier</span>
                <strong><?= esc($order['company_name'] ?? '—') ?></strong>
            </div>
            <div class="detail-field">
                <span>Priority</span>
                <strong><?= esc($order['priority'] ?? '—') ?></strong>
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
        </div>
    </div>

    <div class="page-panel">
        <h5 class="mb-3">Ordered Items</h5>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Quantity</th>
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
                            <td>₱<?= number_format((float) $i['unit_price'], 2) ?></td>
                            <td>₱<?= number_format($i['order_quantity'] * $i['unit_price'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="text-center py-4">No line items recorded for this order.</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($items)): ?>
                <tfoot>
                    <tr>
                        <td colspan="4" class="text-end fw-bold">Total</td>
                        <td class="fw-bold">₱<?= number_format($total, 2) ?></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
