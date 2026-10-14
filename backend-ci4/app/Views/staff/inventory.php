
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

.page-wrap { font-family: 'Poppins', sans-serif; }

.inv-toolbar { display: flex; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin: 18px 0; }
.inv-toolbar select {
    min-height: 46px; padding: 8px 34px 8px 14px; border: 1px solid #d8d5cd; border-radius: var(--radius-control); background: #fff; min-width: 150px;
    font-family: 'Poppins', sans-serif; color: #888;
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'><path d='M1 1l5 5 5-5' stroke='%23888888' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>");
    background-repeat: no-repeat;
    background-position: right 14px center;
}
.inv-toolbar select.has-value { color: var(--text-dark); }
.inv-search {
    display: flex; align-items: center; gap: 8px;
    min-height: 46px; padding: 8px 14px; border: 1px solid #d8d5cd; border-radius: var(--radius-control); background: #fff;
}
.inv-search i { color: #888; font-size: 16px; flex-shrink: 0; }
.inv-search input { flex: 1; min-width: 0; border: none; outline: none; background: transparent; padding: 0; }
.notify-btn { border: 0; padding: 8px 14px; cursor: pointer; white-space: nowrap; } /* colors: shared primary-button rules */
.notify-none { display: inline-block; padding: 0 10px; color: #666; font-size: 18px; line-height: 1; vertical-align: middle; }

/* Only the table + pagination sit in a card; the summary pills, filters
   and tabs sit directly on the page background. */
.inv-table-card { background: var(--card-bg); border: 1px solid #e4e1d9; border-radius: var(--radius-control); overflow: hidden; }
.inv-table-card .data-table tbody tr:last-child td { border-bottom: 0; }
.inv-table-footer { padding: 14px 16px; border-top: 1px solid #e2e2e2; }
.inv-table-footer .admin-pagination { margin-top: 0; }

/* Column alignment: text left (default), numbers right, short codes
   centered, actions right. */
.data-table th.col-num, .data-table td.col-num { text-align: right; font-variant-numeric: tabular-nums; }
.data-table th.col-center, .data-table td.col-center { text-align: center; }
.data-table th.col-actions, .data-table td.col-actions { text-align: right; }
.data-table td.inv-item-id, .data-table td.inv-updated-cell { color: #777; }
</style>

<div class="page-wrap">
        <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>

        <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between">
            <div class="d-flex flex-wrap gap-3">
                <span class="stat-pill green"><i class="bi bi-check-square-fill"></i> In Stock: <?= esc((string) ($inStockCount ?? 0)) ?></span>
                <span class="stat-pill amber"><i class="bi bi-lightning-fill"></i> Low Stock: <?= esc((string) ($lowStockCount ?? 0)) ?></span>
                <span class="stat-pill red"><i class="bi bi-exclamation-triangle-fill"></i> Reorder Now: <?= esc((string) ($reorderCount ?? 0)) ?></span>
            </div>
        </div>

        <div class="inv-toolbar">
            <?php $searchError = $searchError ?? null; ?>
            <div class="inv-search-field">
                <div class="inv-search<?= $searchError ? ' is-invalid' : '' ?>">
                    <i class="bi bi-search"></i>
                    <input id="searchInput" type="search" value="<?= esc($searchInput ?? $filterSearch) ?>" placeholder="Search by item name or ID…" maxlength="50"
                           aria-label="Search inventory by item name or ID"<?= $searchError ? ' aria-invalid="true" aria-describedby="searchError"' : '' ?>>
                </div>
                <p id="searchError" class="search-error" role="alert"<?= $searchError ? '' : ' hidden' ?>>
                    <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                    <span><?= esc($searchError ?? \App\Models\ProductModel::SEARCH_ERROR) ?></span>
                </p>
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

        <?php $categoryCounts = $categoryCounts ?? []; ?>
        <div class="underline-tabs">
            <?php $keepPerPage = (int) ($perPage ?? 10) !== (int) (($perPageOptions ?? [10])[0]) ? ['per_page' => (int) $perPage] : []; ?>
            <a href="<?= site_url('staff/inventory') . ($keepPerPage ? '?' . http_build_query($keepPerPage) : '') ?>" class="<?= $filterCategory === 'All' ? 'active' : '' ?>">All Items (<?= number_format($categoryCounts['All'] ?? 0) ?>)</a>
                        <?php foreach ($categories as $itemCategory): ?>
                <a href="<?= site_url('staff/inventory') . '?' . http_build_query(['category' => $itemCategory] + $keepPerPage) ?>" class="<?= $filterCategory === $itemCategory ? 'active' : '' ?>"><?= esc($itemCategory) ?> (<?= number_format($categoryCounts[$itemCategory] ?? 0) ?>)</a>
            <?php endforeach; ?>
        </div>

        <?= view('staff/_inventory_table', get_defined_vars()) ?>
</div>

<script>
// Rows per page + "return to the same page": the page, filters, search and
// rows-per-page all live in the URL. Forms post the current query string as
// return_query so the controller can redirect back to exactly this view.
function syncReturnQuery() {
    document.querySelectorAll('input[name="return_query"]').forEach(input => {
        input.value = window.location.search;
    });
}
syncReturnQuery();

document.addEventListener('change', event => {
    const select = event.target.closest('.per-page-select');
    if (!select) return;
    const url = new URL(window.location.href);
    url.searchParams.set('per_page', select.value);
    url.searchParams.delete('page');
    window.location.href = url.toString();
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

// Live search: fetches the real server-side search result in the
// background (debounced) and swaps just the table + pagination in place.
// No full page reload — and because it's a real query, not a filter of
// whatever rows happen to already be on screen, it finds a match no
// matter which page it's sitting on.
(function () {
    const searchInput = document.getElementById('searchInput');
    const searchBox = searchInput.closest('.inv-search');
    const searchError = document.getElementById('searchError');
    // Same rule as ProductModel::SEARCH_PATTERN on the server: letters
    // (incl. ñ/Ñ), numbers, spaces and - : / & . ' #, max 50 characters.
    const SEARCH_PATTERN = /^[\p{L}\p{N}\s\-:\/&.'#]{0,50}$/u;
    let debounceTimer;
    let latestRequest = 0;

    function setSearchInvalid(invalid) {
        searchBox.classList.toggle('is-invalid', invalid);
        searchError.hidden = !invalid;
        if (invalid) {
            searchInput.setAttribute('aria-invalid', 'true');
            searchInput.setAttribute('aria-describedby', 'searchError');
        } else {
            searchInput.removeAttribute('aria-invalid');
            searchInput.removeAttribute('aria-describedby');
        }
    }

    function fetchResults() {
        const url = new URL(window.location.href);
        const term = searchInput.value.trim();
        if (term) {
            url.searchParams.set('search', term);
        } else {
            url.searchParams.delete('search');
        }
        url.searchParams.delete('page');

        const request = ++latestRequest;
        fetch(url.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(response => response.text())
            .then(html => {
                // Ignore a response that's been overtaken by newer typing,
                // including typing that made the search invalid.
                if (request !== latestRequest) return;
                const current = document.getElementById('inventoryResults');
                if (current) current.outerHTML = html;
                window.history.replaceState({}, '', url.toString());
                syncReturnQuery();
            });
    }

    // "Clear search" button in the empty-results state (delegated, since
    // the table is swapped in place).
    document.addEventListener('click', event => {
        if (!event.target.closest('.btn-clear-search')) return;
        searchInput.value = '';
        searchInput.dispatchEvent(new Event('input'));
        searchInput.focus();
    });


    searchInput.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        if (!SEARCH_PATTERN.test(searchInput.value.trim())) {
            // Invalid: show the message, don't search, leave table + URL alone.
            latestRequest++;
            setSearchInvalid(true);
            return;
        }
        setSearchInvalid(false);
        debounceTimer = setTimeout(fetchResults, 200);
    });
})();

// Size dropdown is delegated so it keeps working after live search swaps the table.
// Picking a different size in a row's Size dropdown swaps every other cell
// (and the Notify Admin button's target) to that size's own real product
// row — each size is independently stock-tracked, this is purely a display
// switch, no page reload.
function pillClassForStatus(label) {
    if (label === 'Low Stock') return 'amber';
    if (label === 'Reorder Now') return 'red';
    return 'green';
}

document.addEventListener('change', event => {
    const select = event.target.closest('.inv-size-select');
    if (!select) return;
    {
        const row = select.closest('tr');
        const variants = JSON.parse(row.dataset.variants || '[]');
        const v = variants[select.value];
        if (!v) return;

        row.querySelector('.inv-item-id').textContent = v.item_id;
        row.querySelector('.inv-stock-cell').textContent = v.current_stock;
        row.querySelector('.inv-rop-cell').textContent = v.manual_rop_warning;
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

        const notifyForm = row.querySelector('.notify-form');
        const notifyNone = row.querySelector('.notify-none');
        if (v.status_label === 'Reorder Now') {
            notifyForm.action = notifyForm.action.replace(/\/notify\/.*$/, '/notify/' + v.item_id);
            notifyForm.style.display = '';
            notifyNone.style.display = 'none';
        } else {
            notifyForm.style.display = 'none';
            notifyNone.style.display = '';
        }
    }
});
</script>

<?= $this->endSection() ?>
