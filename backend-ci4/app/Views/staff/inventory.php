
<?= $this->extend('layouts/staff_layout') ?>

<?= $this->section('content') ?>

<?php
$filterCategory = $category ?? 'All';
$filterSize = $size ?? '';
$filterStatus = $status ?? '';
$filterSearch = $search ?? '';
$categories = $categories ?? \App\Models\ProductModel::CATEGORIES;
$statuses = $statuses ?? ['In Stock', 'Low Stock', 'Reorder Now'];
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap');

.inv-toolbar { display: flex; gap: 12px; flex-wrap: wrap; margin: 18px 0; }
.inv-toolbar select {
    min-height: 46px; padding: 8px 34px 8px 14px; border: 1px solid #d8d5cd; border-radius: 10px; background: #fff; min-width: 150px;
    font-family: 'Poppins', sans-serif; color: #888;
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'><path d='M1 1l5 5 5-5' stroke='%23888888' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>");
    background-repeat: no-repeat;
    background-position: right 14px center;
}
.inv-toolbar select.has-value { color: var(--text-dark); }
.inv-search {
    flex: 1; min-width: 220px; display: flex; align-items: center; gap: 8px;
    min-height: 46px; padding: 8px 14px; border: 1px solid #d8d5cd; border-radius: 10px; background: #fff;
}
.inv-search i { color: #888; font-size: 16px; flex-shrink: 0; }
.inv-search input { flex: 1; min-width: 0; border: none; outline: none; background: transparent; padding: 0; }
.notify-btn { border: 0; border-radius: 8px; padding: 8px 14px; background: #1c1c1c; color: #fff; font-weight: 700; cursor: pointer; white-space: nowrap; }
</style>

<div class="page-wrap">
    <div class="page-panel">
        <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>

        <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between">
            <div class="d-flex flex-wrap gap-3">
                <span class="stat-pill green"><i class="bi bi-check-square-fill"></i> In Stock: <?= esc($inStockCount ?? 0) ?></span>
                <span class="stat-pill amber"><i class="bi bi-lightning-fill"></i> Low Stock: <?= esc($lowStockCount ?? 0) ?></span>
                <span class="stat-pill red"><i class="bi bi-exclamation-triangle-fill"></i> Reorder Now: <?= esc($reorderCount ?? 0) ?></span>
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
            <a href="<?= site_url('staff/inventory') ?>" class="<?= $filterCategory === 'All' ? 'active' : '' ?>">All Items (<?= esc($totalItems ?? 0) ?>)</a>
            <?php foreach ($categories as $itemCategory): ?>
                <a href="<?= site_url('staff/inventory') . '?category=' . urlencode($itemCategory) ?>" class="<?= $filterCategory === $itemCategory ? 'active' : '' ?>"><?= esc($itemCategory) ?></a>
            <?php endforeach; ?>
        </div>

        <div class="data-table-wrap">
            <table class="data-table" style="min-width: 980px;">
                <thead>
                    <tr>
                        <th>Item Name</th><th>Size</th><th>Type</th><th>On Hand</th><th>Rop</th>
                        <th>Stock Level</th><th>Status</th><th>Last Updated</th><th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($products)): ?>
                        <?php foreach ($products as $product): ?>
                            <?php
                            $itemStatus = $product['status']['label'] ?? 'In Stock';
                            $pillClass = match ($itemStatus) {
                                'Low Stock' => 'amber',
                                'Reorder Now' => 'red',
                                default => 'green',
                            };
                            $rop = max((int) ($product['manual_rop_warning'] ?? 0), 1);
                            $pct = min(100, round(((int) $product['current_stock'] / ($rop * 2)) * 100));
                            ?>
                            <tr>
                                <td><strong><?= esc($product['item_name'] ?? 'Unknown item') ?></strong></td>
                                <td><?= esc($product['size'] ?? '—') ?></td>
                                <td><?= esc($product['category'] ?? '') ?></td>
                                <td><?= esc($product['current_stock'] ?? 0) ?></td>
                                <td><?= esc($product['manual_rop_warning'] ?? 0) ?></td>
                                <td><div class="stock-bar <?= $pillClass ?>"><span style="width: <?= $pct ?>%"></span></div></td>
                                <td><span class="status-pill <?= $pillClass ?>"><?= esc($itemStatus) ?></span></td>
                                <td><?= esc(date('M j, Y', strtotime($product['updated_at'] ?? 'now'))) ?></td>
                                <td class="text-end">
                                    <?php if ($itemStatus === 'Reorder Now'): ?>
                                        <form method="post" action="<?= site_url('staff/inventory/notify/' . $product['item_id']) ?>">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="notify-btn">Notify Admin</button>
                                        </form>
                                    <?php else: ?>
                                        &hellip;
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="9" class="text-center py-4">No inventory items found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-3">
            <span class="text-muted small">Showing <?= count($products ?? []) ?> of <?= esc($totalItems ?? 0) ?> Items</span>
            <?php if (($totalPages ?? 1) > 1): ?>
                <div class="admin-pagination">
                    <?php
                    $prevPage = max(1, (int) $currentPage - 1);
                    $nextPage = min((int) $totalPages, (int) $currentPage + 1);
                    $params = $_GET;
                    $params['page'] = $prevPage;
                    ?>
                    <a href="<?= site_url('staff/inventory') . '?' . http_build_query($params) ?>">&larr; Prev</a>
                    <?php for ($i = 1; $i <= $totalPages; $i++): $params['page'] = $i; ?>
                        <a href="<?= site_url('staff/inventory') . '?' . http_build_query($params) ?>" class="<?= $i === (int) $currentPage ? 'active' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    <?php $params['page'] = $nextPage; ?>
                    <a href="<?= site_url('staff/inventory') . '?' . http_build_query($params) ?>">Next &rarr;</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
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
