
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
            <?php $categoryStockTotals = $categoryStockTotals ?? []; ?>
            <?php foreach ($categories as $itemCategory): ?>
                <a href="<?= site_url('staff/inventory') . '?category=' . urlencode($itemCategory) ?>" class="<?= $filterCategory === $itemCategory ? 'active' : '' ?>"><?= esc($itemCategory) ?> (<?= number_format($categoryStockTotals[$itemCategory] ?? 0) ?>)</a>
            <?php endforeach; ?>
        </div>

        <?= view('staff/_inventory_table', get_defined_vars()) ?>
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

// Live search: fetches the real server-side search result in the
// background (debounced) and swaps just the table + pagination in place.
// No full page reload — and because it's a real query, not a filter of
// whatever rows happen to already be on screen, it finds a match no
// matter which page it's sitting on.
(function () {
    const searchInput = document.getElementById('searchInput');
    let debounceTimer;

    function fetchResults() {
        const url = new URL(window.location.href);
        const term = searchInput.value.trim();
        if (term) {
            url.searchParams.set('search', term);
        } else {
            url.searchParams.delete('search');
        }
        url.searchParams.delete('page');

        fetch(url.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(response => response.text())
            .then(html => {
                const current = document.getElementById('inventoryResults');
                if (current) current.outerHTML = html;
                window.history.replaceState({}, '', url.toString());
            });
    }

    searchInput.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(fetchResults, 200);
    });
})();
</script>

<?= $this->endSection() ?>
