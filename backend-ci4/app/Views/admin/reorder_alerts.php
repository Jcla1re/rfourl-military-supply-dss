
<?= $this->extend('layouts/admin_layout') ?>
<?= $this->section('content') ?>

<?php $alerts = $alerts ?? []; ?>

<style>
.reorder-page {
    padding: 24px;
    background: #f3f1ed;
    min-height: calc(100vh - 85px);
}

.reorder-panel {
    background: #fff;
    border-radius: 14px;
    padding: 22px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
}

.reorder-summary {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 20px;
}

.reorder-summary-card {
    padding: 20px;
    border: 1px solid #d5d5d5;
    border-radius: 12px;
    text-align: center;
    background: #fafafa;
}

.reorder-summary-card strong {
    display: block;
    margin-top: 8px;
    font-size: 30px;
}

.reorder-empty {
    padding: 70px 20px;
    border: 1px dashed #c9c9c9;
    border-radius: 12px;
    text-align: center;
    color: #666;
    font-size: 18px;
}

.reorder-alert {
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 20px;
    border: 1px solid #ddd;
    border-radius: 12px;
    margin-top: 14px;
}

.reorder-alert-icon {
    font-size: 30px;
    color: #d39b28;
}

.reorder-alert-content {
    flex: 1;
}

.reorder-alert-content strong {
    display: block;
    font-size: 18px;
}

.order-button {
    border: 0;
    border-radius: 8px;
    padding: 10px 18px;
    background: #d9d4ce;
    font-weight: 700;
    cursor: pointer;
}

.order-modal {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,.4);
}

.order-modal.show {
    display: flex;
}

.order-modal-card {
    width: min(760px, 92vw);
    max-height: 90vh;
    overflow-y: auto;
    padding: 26px;
    border-radius: 14px;
    background: #fff;
}

.order-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.order-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.order-field input,
.order-field select {
    padding: 12px;
    border: 1px solid #ccc;
    border-radius: 7px;
}

@media (max-width: 700px) {
    .reorder-summary,
    .order-form-grid {
        grid-template-columns: 1fr;
    }

    .reorder-alert {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>

<div class="reorder-page">
    <div class="reorder-panel">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">Reorder Alerts</h2>

            <a href="<?= site_url('admin/procurement-report') ?>" class="btn btn-success">
                Procurement Report
            </a>
        </div>

        <div class="reorder-summary">
            <div class="reorder-summary-card">
                <span>Critical</span>
                <strong><?= esc($criticalCount ?? 0) ?></strong>
            </div>

            <div class="reorder-summary-card">
                <span>Low Stock</span>
                <strong><?= esc($lowStockCount ?? 0) ?></strong>
            </div>

            <div class="reorder-summary-card">
                <span>Received This Week</span>
                <strong><?= esc($receivedCount ?? 0) ?></strong>
            </div>
        </div>

        <form method="post" action="<?= site_url('admin/reorder-alerts/resolve-all') ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-success mb-3">
                Mark All Resolved
            </button>
        </form>

        <?php if (empty($alerts)): ?>
            <div class="reorder-empty">
                No reorder alerts
            </div>
        <?php else: ?>
            <?php foreach ($alerts as $alert): ?>
                <div class="reorder-alert">
                    <div class="reorder-alert-icon">⚡</div>

                    <div class="reorder-alert-content">
                        <strong><?= esc($alert['title'] ?? 'Approaching Reorder Point') ?></strong>
                        <span><?= esc($alert['name'] ?? 'Unknown item') ?></span>
                    </div>

                    <button type="button" class="order-button open-order-modal">
                        Order
                    </button>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="order-modal" id="orderModal">
    <div class="order-modal-card">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="mb-0">New Stock Order</h3>
            <button type="button" class="btn-close close-order-modal"></button>
        </div>

        <form method="post" action="<?= site_url('admin/reorder-alerts/order') ?>">
            <?= csrf_field() ?>

            <div class="order-form-grid">
                <div class="order-field">
                    <label>Order ID</label>
                    <input value="ORD-007" readonly>
                </div>

                <div class="order-field">
                    <label>Tracking Number</label>
                    <input value="TRK-2025-0517G" readonly>
                </div>

                <div class="order-field">
                    <label>Item Name</label>
                    <input name="item_name" placeholder="e.g. Uniform Set" required>
                </div>

                <div class="order-field">
                    <label>Category</label>
                    <select name="category" required>
                        <option value="">Select Category...</option>
                        <option>Clothing</option>
                        <option>Accessories</option>
                        <option>Military gear</option>
                    </select>
                </div>

                <div class="order-field">
                    <label>Item Quantity</label>
                    <input name="qty" type="number" min="1" value="1" required>
                </div>

                <div class="order-field">
                    <label>Price Per Item (₱)</label>
                    <input name="unit_price" type="number" min="0" value="0" required>
                </div>

                <div class="order-field">
                    <label>Estimated Lead Time</label>
                    <select name="lead_time">
                        <option>7 days</option>
                        <option>10 days</option>
                        <option>14 days</option>
                    </select>
                </div>

                <div class="order-field">
                    <label>Supplier</label>
                    <select name="supplier">
                        <option>Supplier A</option>
                        <option>Supplier B</option>
                        <option>Supplier C</option>
                    </select>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-4">
                <button type="button" class="btn btn-secondary close-order-modal">
                    Cancel
                </button>
                <button type="submit" class="btn btn-success">
                    Submit
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const orderModal = document.getElementById('orderModal');

document.querySelectorAll('.open-order-modal').forEach(button => {
    button.addEventListener('click', () => {
        orderModal.classList.add('show');
    });
});

document.querySelectorAll('.close-order-modal').forEach(button => {
    button.addEventListener('click', () => {
        orderModal.classList.remove('show');
    });
});
</script>

<?= $this->endSection() ?>