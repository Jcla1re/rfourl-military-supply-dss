
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
$categories ??= \App\Models\ProductModel::CATEGORIES;
$statuses ??= ['In Stock', 'Low Stock', 'Reorder Now'];
$suppliers ??= [];
$lowStockCount ??= 0;
$totalItems ??= 0;
$success ??= null;
$error ??= null;
$inStockCount ??= 0;
$reorderCount ??= 0;
$sizes ??= [];
$products ??= [];
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap');

.inv-toolbar {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin: 18px 0;
}
.inv-toolbar select {
    min-height: 46px;
    padding: 8px 34px 8px 14px;
    border: 1px solid #d8d5cd;
    border-radius: 10px;
    background: #fff;
    min-width: 150px;
    font-family: 'Poppins', sans-serif;
    color: #888;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'><path d='M1 1l5 5 5-5' stroke='%23888888' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>");
    background-repeat: no-repeat;
    background-position: right 14px center;
}
.inv-toolbar select.has-value {
    color: var(--text-dark);
}
.inv-search {
    flex: 1;
    min-width: 220px;
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 46px;
    padding: 8px 14px;
    border: 1px solid #d8d5cd;
    border-radius: 10px;
    background: #fff;
}
.inv-search i { color: #888; font-size: 16px; flex-shrink: 0; }
.inv-search input {
    flex: 1;
    min-width: 0;
    border: none;
    outline: none;
    background: transparent;
    padding: 0;
}

.inv-action-btn {
    border: 0;
    border-radius: 10px;
    padding: 8px 20px;
    background: #71AA60;
    color: #fff;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
}
.inv-more-btn {
    border: 0;
    background: transparent;
    font-size: 20px;
    color: #666;
    cursor: pointer;
    padding: 0 6px;
}

.data-table td.inv-actions-cell {
    display: grid;
    grid-template-columns: 1fr auto;
    align-items: center;
    gap: 10px;
    padding-right: 40px;
}
.inv-actions-cell .inv-action-btn {
    grid-column: 1;
    justify-self: end;
}
.inv-actions-cell .inv-more-btn {
    grid-column: 2;
}
</style>

<div class="page-wrap">
    <div class="page-panel">

        <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>

        <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between">
            <div class="d-flex flex-wrap gap-3">
                <span class="stat-pill green"><i class="bi bi-check-square-fill"></i> In Stock: <?= esc((string) ($inStockCount ?? 0)) ?></span>
                <span class="stat-pill amber"><i class="bi bi-lightning-fill"></i> Low Stock: <?= esc((string) (is_array($lowStockCount ?? null) ? count((array) ($lowStockCount ?? [])) : ($lowStockCount ?? 0))) ?></span>
                <span class="stat-pill red"><i class="bi bi-exclamation-triangle-fill"></i> Reorder Now: <?= esc((string) (isset($reorderCount) ? (is_array($reorderCount) ? count($reorderCount) : $reorderCount) : 0)) ?></span>
            </div>
        </div>

        <div class="inv-toolbar">
            <div class="inv-search">
                <i class="bi bi-search"></i>
                <input id="searchInput" type="search" value="<?= esc($filterSearch) ?>" placeholder="Search...">
            </div>

            <select id="categoryFilter" class="<?= $filterCategory !== 'All' ? 'has-value' : '' ?>">
                <option value="All">Category</option>
                <?php foreach ($categories as $itemCategory): ?>
                    <option value="<?= esc($itemCategory) ?>" <?= $filterCategory === $itemCategory ? 'selected' : '' ?>><?= esc($itemCategory) ?></option>
                <?php endforeach; ?>
            </select>

            <select id="sizeFilter" class="<?= $filterSize !== '' ? 'has-value' : '' ?>">
                <option value="">Size</option>
                <?php foreach (($sizes ?? []) as $itemSize): ?>
                    <option value="<?= esc($itemSize) ?>" <?= $filterSize === $itemSize ? 'selected' : '' ?>><?= esc($itemSize) ?></option>
                <?php endforeach; ?>
            </select>

            <select id="statusFilter" class="<?= $filterStatus !== '' ? 'has-value' : '' ?>">
                <option value="">Status</option>
                <?php foreach ($statuses as $itemStatus): ?>
                    <option value="<?= esc($itemStatus) ?>" <?= $filterStatus === $itemStatus ? 'selected' : '' ?>><?= esc($itemStatus) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="underline-tabs">
            <a href="<?= site_url('admin/inventory') ?>" class="<?= $filterCategory === 'All' ? 'active' : '' ?>">All Items (<?= esc((string) (is_array($totalItems ?? null) ? count((array) $totalItems) : ($totalItems ?? 0))) ?>)</a>
            <?php $categoryStockTotals = $categoryStockTotals ?? []; ?>
            <?php foreach ($categories as $itemCategory): ?>
                <a href="<?= site_url('admin/inventory') . '?category=' . urlencode($itemCategory) ?>" class="<?= $filterCategory === $itemCategory ? 'active' : '' ?>"><?= esc($itemCategory) ?> (<?= number_format($categoryStockTotals[$itemCategory] ?? 0) ?>)</a>
            <?php endforeach; ?>
        </div>

        <div class="data-table-wrap">
            <table class="data-table" style="min-width: 980px;">
                <thead>
                    <tr>
                        <th>Item ID</th>
                        <th>Item Name</th>
                        <th>Size</th>
                        <th>Type</th>
                        <th>On Hand</th>
                        <th>Rop</th>
                        <th>EOQ</th>
                        <th>Stock Level</th>
                        <th>Status</th>
                        <th>Last Updated</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($products)): ?>
                        <?php foreach ($products as $group): ?>
                            <?php
                            $variants = $group['variants'];
                            $default  = $variants[0];
                            $itemStatus = $default['status']['label'] ?? 'In Stock';
                            $pillClass = match ($itemStatus) {
                                'Low Stock' => 'amber',
                                'Reorder Now' => 'red',
                                default => 'green',
                            };
                            $rop = max((int) ($default['manual_rop_warning'] ?? 0), 1);
                            $pct = min(100, round(((int) $default['current_stock'] / ($rop * 2)) * 100));

                            // Lean payload embedded on the row — switching the
                            // Size dropdown swaps every other cell (and the
                            // Order/Edit buttons' data) to that size's own
                            // underlying product row, client-side, no reload.
                            $variantsPayload = array_map(fn ($v) => [
                                'item_id'            => $v['item_id'],
                                'item_name'          => $v['item_name'],
                                'category'           => $v['category'] ?? '',
                                'size'               => $v['size'] ?? '',
                                'supplier_id'        => $v['supplier_id'] ?? '',
                                'unit_cost'          => (float) ($v['unit_cost'] ?? 0),
                                'selling_price'      => (float) ($v['selling_price'] ?? 0),
                                'current_stock'      => (int) ($v['current_stock'] ?? 0),
                                'manual_rop_warning' => (int) ($v['manual_rop_warning'] ?? 0),
                                'eoq_value'          => $v['eoq_value'],
                                'status_label'       => $v['status']['label'] ?? 'In Stock',
                                'updated_at_display'=> date('M j, Y', strtotime($v['updated_at'] ?? 'now')),
                            ], $variants);
                            ?>
                            <tr data-category="<?= esc($group['category'] ?? '') ?>" data-variants="<?= esc(json_encode($variantsPayload), 'attr') ?>">
                                <td class="inv-item-id"><?= esc($default['item_id'] ?? '—') ?></td>
                                <td><strong><?= esc($group['item_name'] ?? 'Unknown item') ?></strong></td>
                                <td class="inv-size-cell">
                                    <?php if (count($variants) > 1): ?>
                                        <select class="inv-size-select">
                                            <?php foreach ($variants as $i => $v): ?>
                                                <option value="<?= $i ?>"><?= esc($v['size'] ?? '—') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <?= esc($default['size'] ?? '—') ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= esc($group['category'] ?? '') ?></td>
                                <td class="inv-stock-cell"><?= esc($default['current_stock'] ?? 0) ?></td>
                                <td class="inv-rop-cell"><?= esc($default['manual_rop_warning'] ?? 0) ?></td>
                                <td class="inv-eoq-cell"><?= $default['eoq_value'] !== null ? esc($default['eoq_value']) : '—' ?></td>
                                <td class="inv-bar-cell">
                                    <div class="stock-bar <?= $pillClass ?>"><span style="width: <?= $pct ?>%"></span></div>
                                </td>
                                <td class="inv-status-cell"><span class="status-pill <?= $pillClass ?>"><?= esc($itemStatus) ?></span></td>
                                <td class="inv-updated-cell"><?= esc(date('M j, Y', strtotime($default['updated_at'] ?? 'now'))) ?></td>
                                <td class="inv-actions-cell">
                                    <button type="button"
                                            class="inv-action-btn open-order-modal"
                                            style="<?= $itemStatus === 'Reorder Now' ? '' : 'display:none;' ?>"
                                            data-item-id="<?= esc($default['item_id']) ?>"
                                            data-item-name="<?= esc($default['item_name'] ?? '') ?>"
                                            data-supplier-id="<?= esc($default['supplier_id'] ?? '') ?>"
                                            data-unit-cost="<?= esc($default['unit_cost'] ?? 0) ?>"
                                            data-recommended-eoq="<?= esc($default['eoq_value'] ?? 1) ?>">
                                        Order
                                    </button>
                                    <button type="button" class="inv-more-btn edit-row" data-item="<?= esc(json_encode($variantsPayload[0]), 'attr') ?>">&hellip;</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="11" class="text-center py-4">No inventory items found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php $totalPages = max(1, (int) ($totalPages ?? 1)); ?>
        <div class="d-flex justify-content-between align-items-center mt-3">
            <span class="text-muted small">Showing <?= count($products ?? []) ?> of <?= esc((string) ($totalItems ?? 0)) ?> Items</span>
            <?php if (($totalPages ?? 1) > 1): ?>
                <div class="admin-pagination">
                    <?php
                    $currentPage = max(1, (int) ($currentPage ?? 1));
                    $prevPage = max(1, $currentPage - 1);
                    $nextPage = min((int) $totalPages, (int) $currentPage + 1);
                    $params = $_GET;

                    // Only ever show a sliding window of PAGER_WINDOW page
                    // buttons (centered on the current page) instead of one
                    // link per page — with 562 items that was 57 buttons wide.
                    $pagerWindow = 10;
                    $windowStart = max(1, $currentPage - intdiv($pagerWindow, 2));
                    $windowEnd   = min($totalPages, $windowStart + $pagerWindow - 1);
                    $windowStart = max(1, $windowEnd - $pagerWindow + 1);

                    $params['page'] = $prevPage;
                    ?>
                    <a href="<?= site_url('admin/inventory') . '?' . http_build_query($params) ?>">&larr; Prev</a>
                    <?php for ($i = $windowStart; $i <= $windowEnd; $i++): $params['page'] = $i; ?>
                        <a href="<?= site_url('admin/inventory') . '?' . http_build_query($params) ?>" class="<?= $i === (int) $currentPage ? 'active' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    <?php $params['page'] = $nextPage; ?>
                    <a href="<?= site_url('admin/inventory') . '?' . http_build_query($params) ?>">Next &rarr;</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="inv-modal" id="itemModal">
    <div class="inv-modal-card">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <h3 id="modalTitle" class="mb-0">Add Item</h3>
                <div class="subtitle" id="modalSubtitle"></div>
            </div>
            <button type="button" class="btn-close" id="closeItemModal"></button>
        </div>

        <form id="itemForm" method="post" action="<?= site_url('admin/inventory/store') ?>">
            <?= csrf_field() ?>
            <div class="inv-form-grid">
                <div class="inv-form-field">
                    <label>Item Name</label>
                    <input id="item_name" name="item_name" required>
                </div>
                <div class="inv-form-field" id="sizeLetterField">
                    <label>Size</label>
                    <select id="sizeLetter" name="size">
                        <option value="">— None —</option>
                        <option value="XS">XS</option>
                        <option value="S">S</option>
                        <option value="M">M</option>
                        <option value="L">L</option>
                        <option value="XL">XL</option>
                        <option value="XXL">XXL</option>
                    </select>
                </div>
                <div class="inv-form-field" id="sizeNumericField" style="display:none;">
                    <label>Size (US)</label>
                    <input id="sizeNumeric" name="size" type="number" step="0.5" min="0" placeholder="e.g. 9">
                </div>
                <div class="inv-form-field">
                    <label>Type</label>
                    <select id="category" name="category" required>
                        <?php foreach ($categories as $itemCategory): ?>
                            <option value="<?= esc($itemCategory) ?>"><?= esc($itemCategory) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="inv-form-field">
                    <label>On hand</label>
                    <input id="current_stock" name="current_stock" type="number" min="0" value="0">
                </div>
                <div class="inv-form-field">
                    <label>ROP Warning</label>
                    <input id="manual_rop_warning" name="manual_rop_warning" type="number" min="0" value="0">
                </div>
                <div class="inv-form-field">
                    <label>Supplier</label>
                    <select id="supplier_id" name="supplier_id">
                        <option value="">— Unassigned —</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= esc($s['supplier_id']) ?>"><?= esc($s['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="inv-form-field">
                    <label>Unit Cost (₱)</label>
                    <input id="unit_cost" name="unit_cost" type="number" step="0.01" min="0" value="0" required>
                </div>
                <div class="inv-form-field">
                    <label>Selling Price (₱)</label>
                    <input id="selling_price" name="selling_price" type="number" step="0.01" min="0" value="0" required>
                </div>
            </div>

            <hr class="inv-hr">

            <div class="danger-zone" id="deleteZone" style="display:none;">
                <div>
                    <strong>Archive this item</strong>
                    <small>It will be hidden from active inventory. This can be reversed in the database.</small>
                </div>
                <button type="button" class="btn btn-maroon" id="archiveBtn">Archive</button>
            </div>

            <div class="inv-modal-actions">
                <button type="button" class="btn btn-secondary" id="cancelItemModal">Cancel</button>
                <button type="submit" class="btn btn-success" id="submitItemBtn">Add Item</button>
            </div>
        </form>
    </div>
</div>

<form id="deleteForm" method="post" style="display:none;">
    <?= csrf_field() ?>
</form>

<div class="order-modal" id="orderModal">
    <div class="order-modal-card">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="mb-0">New Stock Order</h3>
            <button type="button" class="btn-close close-order-modal"></button>
        </div>

        <form method="post" action="<?= site_url('admin/orders/store') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="item_id[]" id="orderItemId">

            <div class="order-form-grid">
                <div class="order-field">
                    <label>Item</label>
                    <input id="orderItemName" readonly>
                </div>

                <div class="order-field">
                    <label>Supplier</label>
                    <select name="supplier_id" id="orderSupplierId" required>
                        <option value="">Select Supplier...</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= esc($s['supplier_id']) ?>"><?= esc($s['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="order-field">
                    <label>Item Quantity</label>
                    <input name="quantity[]" id="orderQuantity" type="number" min="1" value="1" required>
                </div>

                <div class="order-field">
                    <label>Price Per Item (₱)</label>
                    <input name="unit_price[]" id="orderUnitPrice" type="number" min="0" step="0.01" value="0" required>
                </div>

                <div class="order-field">
                    <label>Priority</label>
                    <select name="priority">
                        <option value="Urgent">Urgent</option>
                        <option value="Order" selected>Order</option>
                        <option value="Planned">Planned</option>
                    </select>
                </div>

                <div class="order-field">
                    <label>Expected Delivery Date</label>
                    <input name="expected_delivery_date" type="date">
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-4">
                <button type="button" class="btn btn-secondary close-order-modal">Cancel</button>
                <button type="submit" class="btn btn-success">Submit</button>
            </div>
        </form>
    </div>
</div>

<style>
.order-modal {
    position: fixed; inset: 0; z-index: 1000; display: none;
    align-items: center; justify-content: center; background: rgba(0,0,0,.4);
}
.order-modal.show { display: flex; }
.order-modal-card { width: min(760px, 92vw); max-height: 90vh; overflow-y: auto; padding: 26px; border-radius: 14px; background: #fff; }
.order-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.order-field { display: flex; flex-direction: column; gap: 6px; }
.order-field input, .order-field select { padding: 12px; border: 1px solid #ccc; border-radius: 7px; }
@media (max-width: 700px) { .order-form-grid { grid-template-columns: 1fr; } }
</style>

<script>
const orderModal = document.getElementById('orderModal');

document.querySelectorAll('.open-order-modal').forEach(button => {
    button.addEventListener('click', () => {
        document.getElementById('orderItemId').value = button.dataset.itemId || '';
        document.getElementById('orderItemName').value = button.dataset.itemName || '';
        document.getElementById('orderSupplierId').value = button.dataset.supplierId || '';
        document.getElementById('orderQuantity').value = button.dataset.recommendedEoq || 1;
        document.getElementById('orderUnitPrice').value = button.dataset.unitCost || 0;
        orderModal.classList.add('show');
    });
});

document.querySelectorAll('.close-order-modal').forEach(button => {
    button.addEventListener('click', () => orderModal.classList.remove('show'));
});
</script>

<script>
const itemModal = document.getElementById('itemModal');
const itemForm = document.getElementById('itemForm');
const storeUrl = "<?= site_url('admin/inventory/store') ?>";

// Clothes are sized XS-XXL, except "Goa Pants" specifically which uses US
// numeric sizing like Shoes — everything else (Equipment/Patches/Metal/
// Accessories) isn't a sized item at all. Only one of the two size inputs
// is ever enabled at a time since both share name="size" — the disabled
// one is excluded from the submitted form data entirely.
function sizeModeFor(category, itemName) {
    const name = (itemName || '').toLowerCase();
    if (category === 'Shoes') return 'numeric';
    if (category === 'Clothes' && name.includes('goa') && name.includes('pant')) return 'numeric';
    if (category === 'Clothes') return 'letter';
    return 'none';
}

function updateSizeFieldVisibility() {
    const mode = sizeModeFor(document.getElementById('category').value, document.getElementById('item_name').value);
    const letterField = document.getElementById('sizeLetterField');
    const numericField = document.getElementById('sizeNumericField');
    const letterInput = document.getElementById('sizeLetter');
    const numericInput = document.getElementById('sizeNumeric');

    letterField.style.display = mode === 'letter' ? '' : 'none';
    letterInput.disabled = mode !== 'letter';

    numericField.style.display = mode === 'numeric' ? '' : 'none';
    numericInput.disabled = mode !== 'numeric';
}

document.getElementById('category').addEventListener('change', updateSizeFieldVisibility);
document.getElementById('item_name').addEventListener('input', updateSizeFieldVisibility);

document.getElementById('openAddModal').addEventListener('click', () => {
    itemForm.reset();
    itemForm.action = storeUrl;
    document.getElementById('modalTitle').textContent = 'Add Item';
    document.getElementById('modalSubtitle').textContent = '';
    document.getElementById('submitItemBtn').textContent = 'Add Item';
    document.getElementById('deleteZone').style.display = 'none';
    updateSizeFieldVisibility();
    itemModal.classList.add('show');
});

document.querySelectorAll('.edit-row').forEach(button => {
    button.addEventListener('click', () => {
        const item = JSON.parse(button.dataset.item || '{}');

        document.getElementById('modalTitle').textContent = 'Edit Item';
        document.getElementById('modalSubtitle').textContent = `${item.item_name || ''} — Size ${item.size || '—'}`;
        document.getElementById('submitItemBtn').textContent = 'Save Changes';
        document.getElementById('item_name').value = item.item_name || '';
        document.getElementById('category').value = item.category || '';
        updateSizeFieldVisibility();
        const sizeMode = sizeModeFor(item.category || '', item.item_name || '');
        if (sizeMode === 'numeric') {
            document.getElementById('sizeNumeric').value = item.size || '';
        } else if (sizeMode === 'letter') {
            document.getElementById('sizeLetter').value = item.size || '';
        }
        document.getElementById('supplier_id').value = item.supplier_id || '';
        document.getElementById('unit_cost').value = item.unit_cost || 0;
        document.getElementById('selling_price').value = item.selling_price || 0;
        document.getElementById('current_stock').value = item.current_stock || 0;
        document.getElementById('manual_rop_warning').value = item.manual_rop_warning || 0;

        itemForm.action = "<?= site_url('admin/inventory/update') ?>/" + item.item_id;

        const deleteZone = document.getElementById('deleteZone');
        deleteZone.style.display = 'flex';
        document.getElementById('archiveBtn').onclick = () => {
            if (!confirm(`Archive "${item.item_name}"?`)) return;
            const form = document.getElementById('deleteForm');
            form.action = "<?= site_url('admin/inventory/delete') ?>/" + item.item_id;
            form.submit();
        };

        itemModal.classList.add('show');
    });
});

document.getElementById('closeItemModal').addEventListener('click', () => itemModal.classList.remove('show'));
document.getElementById('cancelItemModal').addEventListener('click', () => itemModal.classList.remove('show'));

// Picking a different size in a row's Size dropdown swaps every other cell
// (and the Order/Edit buttons' underlying data) to that size's own real
// product row — each size is independently stock-tracked, this is purely
// a display switch, no page reload.
function pillClassForStatus(label) {
    if (label === 'Low Stock') return 'amber';
    if (label === 'Reorder Now') return 'red';
    return 'green';
}

function applyVariantToRow(row, v) {
    row.querySelector('.inv-item-id').textContent = v.item_id;
    row.querySelector('.inv-stock-cell').textContent = v.current_stock;
    row.querySelector('.inv-rop-cell').textContent = v.manual_rop_warning;
    row.querySelector('.inv-eoq-cell').textContent = (v.eoq_value !== null && v.eoq_value !== undefined) ? v.eoq_value : '—';
    row.querySelector('.inv-updated-cell').textContent = v.updated_at_display || '—';

    const rop = Math.max(v.manual_rop_warning, 1);
    const pct = Math.min(100, Math.round((v.current_stock / (rop * 2)) * 100));
    const cls = pillClassForStatus(v.status_label);

    const bar = row.querySelector('.inv-bar-cell .stock-bar');
    bar.className = 'stock-bar ' + cls;
    bar.querySelector('span').style.width = pct + '%';

    const statusPill = row.querySelector('.inv-status-cell .status-pill');
    statusPill.className = 'status-pill ' + cls;
    statusPill.textContent = v.status_label;

    const orderBtn = row.querySelector('.open-order-modal');
    if (orderBtn) {
        orderBtn.style.display = v.status_label === 'Reorder Now' ? '' : 'none';
        orderBtn.dataset.itemId = v.item_id;
        orderBtn.dataset.itemName = v.item_name;
        orderBtn.dataset.supplierId = v.supplier_id;
        orderBtn.dataset.unitCost = v.unit_cost;
        orderBtn.dataset.recommendedEoq = v.eoq_value || 1;
    }

    const editBtn = row.querySelector('.edit-row');
    if (editBtn) editBtn.dataset.item = JSON.stringify(v);
}

document.querySelectorAll('.inv-size-select').forEach(select => {
    select.addEventListener('change', () => {
        const row = select.closest('tr');
        const variants = JSON.parse(row.dataset.variants || '[]');
        const v = variants[select.value];
        if (v) applyVariantToRow(row, v);
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
        url.searchParams.delete('page');
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
    url.searchParams.delete('page');
    window.location.href = url.toString();
});
</script>

<?= $this->endSection() ?>
