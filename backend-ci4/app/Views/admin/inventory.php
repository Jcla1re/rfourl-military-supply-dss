
<?= $this->extend('layouts/admin_layout') ?>

<?= $this->section('header_actions') ?>
<button type="button" class="btn btn-success" id="openAddModal">
    <i class="bi bi-plus-lg"></i> Add Item
</button>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<?php
$filterCategory = $category ?? 'All';
$filterSize = $size ?? '';
$filterStatus = $status ?? '';
$filterSearch = $search ?? '';
$categories = $categories ?? ['Clothing', 'Accessories', 'Military gear'];
$statuses = $statuses ?? ['In Stock', 'Low Stock', 'Reorder Now'];
?>

<style>
.inventory-page {
    padding: 24px;
    background: #f3f1ed;
    min-height: calc(100vh - 85px);
}

.inventory-panel {
    background: #fff;
    border-radius: 14px;
    padding: 22px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
}

.inventory-stats {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    margin-bottom: 22px;
}

.inventory-stat {
    padding: 14px 20px;
    border: 1px solid #d4d4d4;
    border-radius: 10px;
    background: #fafafa;
    font-weight: 600;
}

.inventory-toolbar {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.inventory-toolbar input,
.inventory-toolbar select {
    min-height: 42px;
    padding: 8px 12px;
    border: 1px solid #c9c9c9;
    border-radius: 8px;
    background: #fff;
}

.inventory-toolbar input {
    flex: 1;
    min-width: 220px;
}

.inventory-toolbar select {
    min-width: 150px;
}

.inventory-tabs {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 14px;
    border-bottom: 1px solid #ddd;
}

.inventory-tab {
    border: 0;
    background: transparent;
    padding: 10px 14px;
    cursor: pointer;
    color: #555;
}

.inventory-tab.active {
    color: #2f5138;
    border-bottom: 3px solid #5d965c;
    font-weight: 700;
}

.inventory-table-wrap {
    overflow-x: auto;
}

.inventory-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}

.inventory-table th {
    background: #e6e1da;
    padding: 13px 10px;
    text-align: left;
    font-size: 12px;
    text-transform: uppercase;
}

.inventory-table td {
    padding: 13px 10px;
    border-bottom: 1px solid #e2e2e2;
}

.inventory-status {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}

.status-in-stock {
    color: #256b35;
    background: #ddf1df;
}

.status-low-stock {
    color: #8a6417;
    background: #f8edc9;
}

.status-reorder {
    color: #a52e2e;
    background: #f7dddd;
}

.inventory-action {
    border: 0;
    border-radius: 7px;
    padding: 7px 12px;
    background: #dcecdf;
    color: #24472d;
    font-weight: 700;
    cursor: pointer;
}

.inventory-modal {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,.35);
}

.inventory-modal.show {
    display: flex;
}

.inventory-modal-card {
    width: min(680px, 92vw);
    max-height: 90vh;
    overflow-y: auto;
    padding: 24px;
    border-radius: 14px;
    background: #fff;
}

.inventory-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.inventory-form-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.inventory-form-field input,
.inventory-form-field select {
    padding: 11px;
    border: 1px solid #ccc;
    border-radius: 7px;
}

.inventory-modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 22px;
}

@media (max-width: 700px) {
    .inventory-form-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="inventory-page">
    <div class="inventory-panel">
        <div class="inventory-stats">
            <div class="inventory-stat">✓ In Stock: <?= esc($inStockCount ?? 0) ?></div>
            <div class="inventory-stat">⚡ Low Stock: <?= esc($lowStockCount ?? 0) ?></div>
            <div class="inventory-stat">⚠ Reorder Now: <?= esc($reorderCount ?? 0) ?></div>
        </div>

        <div class="inventory-toolbar">
            <input id="searchInput" type="search" value="<?= esc($filterSearch) ?>" placeholder="Search inventory...">

            <select id="categoryFilter">
                <option value="All">All Categories</option>
                <?php foreach ($categories as $itemCategory): ?>
                    <option value="<?= esc($itemCategory) ?>" <?= $filterCategory === $itemCategory ? 'selected' : '' ?>>
                        <?= esc($itemCategory) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select id="sizeFilter">
                <option value="">All Sizes</option>
                <?php foreach (($sizes ?? []) as $itemSize): ?>
                    <option value="<?= esc($itemSize) ?>" <?= $filterSize === $itemSize ? 'selected' : '' ?>>
                        <?= esc($itemSize) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select id="statusFilter">
                <option value="">All Statuses</option>
                <?php foreach ($statuses as $itemStatus): ?>
                    <option value="<?= esc($itemStatus) ?>" <?= $filterStatus === $itemStatus ? 'selected' : '' ?>>
                        <?= esc($itemStatus) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="inventory-tabs">
            <button class="inventory-tab active" type="button">
                All Items (<?= esc($totalItems ?? 0) ?>)
            </button>

            <?php foreach ($categories as $itemCategory): ?>
                <button class="inventory-tab" type="button" data-category="<?= esc($itemCategory) ?>">
                    <?= esc($itemCategory) ?>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="inventory-table-wrap">
            <table class="inventory-table">
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Size</th>
                        <th>Category</th>
                        <th>On Hand</th>
                        <th>ROP</th>
                        <th>Status</th>
                        <th>Last Updated</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($products)): ?>
                        <?php foreach ($products as $product): ?>
                            <?php
                            $itemStatus = $product['status']['label'] ?? 'In Stock';
                            $statusClass = match ($itemStatus) {
                                'Low Stock' => 'status-low-stock',
                                'Reorder Now' => 'status-reorder',
                                default => 'status-in-stock',
                            };
                            ?>
                            <tr data-category="<?= esc($product['category'] ?? '') ?>">
                                <td>
                                    <strong><?= esc($product['item_name'] ?? 'Unknown item') ?></strong>
                                </td>
                                <td><?= esc($product['size'] ?? '') ?></td>
                                <td><?= esc($product['category'] ?? '') ?></td>
                                <td><?= esc($product['current_stock'] ?? 0) ?></td>
                                <td><?= esc($product['manual_rop_warning'] ?? 0) ?></td>
                                <td>
                                    <span class="inventory-status <?= $statusClass ?>">
                                        <?= esc($itemStatus) ?>
                                    </span>
                                </td>
                                <td><?= esc(date('M j, Y', strtotime($product['updated_at'] ?? 'now'))) ?></td>
                                <td>
                                    <button
                                        type="button"
                                        class="inventory-action edit-row"
                                        data-item="<?= esc(json_encode($product), 'attr') ?>">
                                        Edit
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-4">No inventory items found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="inventory-modal" id="itemModal">
    <div class="inventory-modal-card">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 id="modalTitle">Add Item</h3>
            <button type="button" class="btn-close" id="closeItemModal"></button>
        </div>

        <form id="itemForm">
            <div class="inventory-form-grid">
                <div class="inventory-form-field">
                    <label>Item Name</label>
                    <input id="item_name" name="item_name" required>
                </div>

                <div class="inventory-form-field">
                    <label>Size</label>
                    <input id="size" name="size">
                </div>

                <div class="inventory-form-field">
                    <label>Category</label>
                    <select id="category" name="category" required>
                        <?php foreach ($categories as $itemCategory): ?>
                            <option value="<?= esc($itemCategory) ?>"><?= esc($itemCategory) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="inventory-form-field">
                    <label>On Hand</label>
                    <input id="current_stock" name="current_stock" type="number" min="0" value="0">
                </div>

                <div class="inventory-form-field">
                    <label>ROP Warning</label>
                    <input id="manual_rop_warning" name="manual_rop_warning" type="number" min="0" value="0">
                </div>

                <div class="inventory-form-field">
                    <label>Status</label>
                    <select id="status" name="status">
                        <?php foreach ($statuses as $itemStatus): ?>
                            <option value="<?= esc($itemStatus) ?>"><?= esc($itemStatus) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="inventory-modal-actions">
                <button type="button" class="btn btn-secondary" id="cancelItemModal">Cancel</button>
                <button type="submit" class="btn btn-success">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
const itemModal = document.getElementById('itemModal');
const itemForm = document.getElementById('itemForm');

document.getElementById('openAddModal').addEventListener('click', () => {
    itemForm.reset();
    document.getElementById('modalTitle').textContent = 'Add Item';
    itemModal.classList.add('show');
});

document.querySelectorAll('.edit-row').forEach(button => {
    button.addEventListener('click', () => {
        const item = JSON.parse(button.dataset.item || '{}');

        document.getElementById('modalTitle').textContent = 'Edit Item';
        document.getElementById('item_name').value = item.item_name || '';
        document.getElementById('size').value = item.size || '';
        document.getElementById('category').value = item.category || '';
        document.getElementById('current_stock').value = item.current_stock || 0;
        document.getElementById('manual_rop_warning').value = item.manual_rop_warning || 0;
        document.getElementById('status').value = item.status?.label || 'In Stock';

        itemModal.classList.add('show');
    });
});

document.getElementById('closeItemModal').addEventListener('click', () => {
    itemModal.classList.remove('show');
});

document.getElementById('cancelItemModal').addEventListener('click', () => {
    itemModal.classList.remove('show');
});

itemForm.addEventListener('submit', event => {
    event.preventDefault();
    itemModal.classList.remove('show');
});

document.querySelectorAll('.inventory-tab[data-category]').forEach(tab => {
    tab.addEventListener('click', () => {
        document.getElementById('categoryFilter').value = tab.dataset.category;
        document.getElementById('categoryFilter').dispatchEvent(new Event('change'));
    });
});

['categoryFilter', 'sizeFilter', 'statusFilter'].forEach(id => {
    document.getElementById(id).addEventListener('change', event => {
        const url = new URL(window.location.href);
        const parameter = id.replace('Filter', '').toLowerCase();

        if (event.target.value && event.target.value !== 'All') {
            url.searchParams.set(parameter, event.target.value);
        } else {
            url.searchParams.delete(parameter);
        }

        window.location.href = url.toString();
    });
});

document.getElementById('searchInput').addEventListener('keydown', event => {
    if (event.key !== 'Enter') return;

    const url = new URL(window.location.href);

    if (event.target.value.trim()) {
        url.searchParams.set('search', event.target.value.trim());
    } else {
        url.searchParams.delete('search');
    }

    window.location.href = url.toString();
});
</script>

<?= $this->endSection() ?>