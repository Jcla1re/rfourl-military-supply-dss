
<?= $this->extend('layouts/staff_layout') ?>

<?= $this->section('content') ?>

<?php
$filterCategory = $category ?? 'All';
$filterSize = $size ?? '';
$filterStatus = $status ?? '';
$filterSearch = $search ?? '';
$categories = $categories ?? \App\Models\ProductModel::CATEGORIES;
$statuses = $statuses ?? \App\Models\ProductModel::STATUSES;
?>

<style>

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

/* Summary cards are links: each applies its status filter. */
a.stat-pill { text-decoration: none; cursor: pointer; transition: transform 0.12s ease, box-shadow 0.15s ease; }
a.stat-pill:hover { transform: translateY(-1px); box-shadow: 0 3px 8px rgba(31, 38, 23, .08); }
a.stat-pill:focus-visible { outline: 3px solid var(--focus-ring); outline-offset: 2px; }
a.stat-pill.is-active { box-shadow: inset 0 0 0 2px currentColor; }

/* Status badges (e.g. "Reorder Now · 2XL") and dates stay on one line. */
.data-table .status-pill, .data-table td.inv-updated-cell { white-space: nowrap; }

/* Same layout as the admin Inventory table: percentage widths from each
   column's measured content, Item name takes the rest, the table always
   fits its card at 1366px and up. */
.inv-table-card .data-table-wrap { overflow-x: visible; }
.data-table.inv-table { width: 100%; table-layout: fixed; }
.inv-table col.c-id      { width: 6%; }
.inv-table col.c-size    { width: 6%; }
.inv-table col.c-type    { width: 9.5%; }
.inv-table col.c-onhand  { width: 7.5%; }
.inv-table col.c-rop     { width: 5.5%; }
.inv-table col.c-stock   { width: 10%; }
.inv-table col.c-status  { width: 14%; }   /* "Reorder Now · 2XL" */
.inv-table col.c-updated { width: 10.5%; } /* "Sep 29, 2026" */
.inv-table col.c-actions { width: 12.5%; } /* "Notify Admin" */
/* Item name: no width, so it gets the rest. */
.inv-table th, .inv-table td { padding-left: 10px; padding-right: 10px; }
.inv-table th.col-stock, .inv-table td.inv-bar-cell { padding-left: 24px; }
.inv-table .stock-bar { width: 100%; max-width: 70px; }
.inv-table .inv-size-select { width: 100%; }
@media (max-width: 1400px) {
    .inv-table th, .inv-table td { padding-left: 8px; padding-right: 8px; }
    .inv-table th { font-size: 11px; letter-spacing: .02em; white-space: nowrap; }
    .inv-table td { font-size: 13px; }
    .inv-table th.col-stock, .inv-table td.inv-bar-cell { padding-left: 14px; }
    .inv-table .stock-bar { max-width: 60px; }
    .inv-table .status-pill { padding: 4px 10px; font-size: 11px; }
    .inv-table .notify-btn { padding: 6px 10px; font-size: 13px; }
    .inv-table col.c-id      { width: 6%; }
    .inv-table col.c-size    { width: 6.2%; }
    .inv-table col.c-type    { width: 8.8%; }
    .inv-table col.c-onhand  { width: 7%; }
    .inv-table col.c-rop     { width: 5%; }
    .inv-table col.c-stock   { width: 8.8%; }
    .inv-table col.c-status  { width: 14.6%; }
    .inv-table col.c-updated { width: 10%; }
    .inv-table col.c-actions { width: 13.6%; }
}

/* Below a 1340px-wide window the columns no longer fit, so instead of letting badges and
   buttons overflow, the table keeps its 1366px layout and scrolls sideways
   inside its card. At 1340px and up (so any 1366px screen) there is no scroll container. */
@media (max-width: 1339px) {
    .inv-table-card .data-table-wrap { overflow-x: auto; }
    .data-table.inv-table { min-width: 1030px; }
}


/* Summary cards are toggles; "Clear filters" sits after the filters. */
a.stat-pill[aria-pressed="true"]:hover { box-shadow: inset 0 0 0 2px currentColor, 0 3px 8px rgba(31, 38, 23, .08); }
.clear-filters {
    align-self: center; display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 2px; font-size: 14px; color: #3B5434; font-weight: 500;
    text-decoration: none; white-space: nowrap;
}
.clear-filters[hidden] { display: none; }
.clear-filters:hover { text-decoration: underline; color: #2C3A1E; }
.clear-filters:focus-visible { outline: 3px solid var(--focus-ring); outline-offset: 2px; border-radius: 4px; }

/* Size filter: disabled ("No sizes") for categories without sized items. */
.size-filter-wrap { display: inline-flex; }
.size-filter-wrap select:disabled { background-color: #F5F3EE; color: #8A8D7E; cursor: not-allowed; opacity: 1; }
</style>

<div class="page-wrap">
        <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>

        <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between">
            <div class="d-flex flex-wrap gap-3">
                <?php
                // Summary cards are filters (the same ?status= the Status dropdown sets,
                // so the two always agree). Clicking the active card again removes the
                // status filter; clicking another card switches to it.
                $curStatus = (string) ($status ?? '');
                $statusUrl = static function (string $st) use ($curStatus) {
                    $q = array_diff_key($_GET, ['page' => 1, 'status' => 1]);
                    if ($st !== $curStatus) {
                        $q['status'] = $st;
                    }
                    return site_url('staff/inventory') . ($q ? '?' . http_build_query($q) : '');
                };
                $cardAttrs = static function (string $st) use ($curStatus) {
                    $on = $st === $curStatus;
                    return ($on ? ' is-active' : '') . '" aria-pressed="' . ($on ? 'true' : 'false') . '" title="'
                        . esc($on ? 'Click to show all items.' : 'Show only ' . $st . ' items', 'attr');
                };
                ?>
                <a href="<?= $statusUrl('In Stock') ?>" class="stat-pill green<?= $cardAttrs('In Stock') ?>"><i class="bi bi-check-square-fill"></i> In Stock: <?= esc((string) ($inStockCount ?? 0)) ?></a>
                <a href="<?= $statusUrl('Low Stock') ?>" class="stat-pill amber<?= $cardAttrs('Low Stock') ?>"><i class="bi bi-lightning-fill"></i> Low Stock: <?= esc((string) ($lowStockCount ?? 0)) ?></a>
                <a href="<?= $statusUrl('Reorder Now') ?>" class="stat-pill red<?= $cardAttrs('Reorder Now') ?>"><i class="bi bi-exclamation-triangle-fill"></i> Reorder Now: <?= esc((string) ($reorderCount ?? 0)) ?></a>
                <?php // Only when there is at least one (the other three still add up to All Items); the Status filter always offers it. ?>
                <?php if (($outOfStockCount ?? 0) > 0): ?>
                <a href="<?= $statusUrl('Out of Stock') ?>" class="stat-pill gray<?= $cardAttrs('Out of Stock') ?>"><i class="bi bi-dash-circle-fill"></i> Out of Stock: <?= esc((string) $outOfStockCount) ?></a>
                <?php endif; ?>
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

            <?php
            $sizeOptions = $sizeOptions ?? ['disabled' => false, 'groups' => ['' => $sizes ?? []], 'all' => $sizes ?? []];
            $noSizesTip  = $sizeOptions['disabled'] ? ($filterCategory !== 'All' ? $filterCategory : 'These') . " items don't have sizes" : '';
            ?>
            <span class="size-filter-wrap"<?= $noSizesTip !== '' ? ' title="' . esc($noSizesTip, 'attr') . '"' : '' ?>>
                <select id="sizeFilter" class="<?= $filterSize !== '' ? 'has-value' : '' ?>"<?= $sizeOptions['disabled'] ? ' disabled aria-disabled="true"' : '' ?> aria-label="Size">
                    <?php if ($sizeOptions['disabled']): ?>
                        <option value="">No sizes</option>
                    <?php else: ?>
                        <option value="">All sizes</option>
                        <?php foreach ($sizeOptions['groups'] as $groupLabel => $groupSizes): ?>
                            <?php if ($groupLabel !== ''): ?><optgroup label="<?= esc($groupLabel, 'attr') ?>"><?php endif; ?>
                            <?php foreach ($groupSizes as $itemSize): ?>
                                <option value="<?= esc($itemSize) ?>" <?= $filterSize === $itemSize ? 'selected' : '' ?>><?= esc($itemSize) ?></option>
                            <?php endforeach; ?>
                            <?php if ($groupLabel !== ''): ?></optgroup><?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </span>

            <select id="statusFilter" class="<?= $filterStatus !== '' ? 'has-value' : '' ?>">
                <option value="">All statuses</option>
                <?php foreach ($statuses as $itemStatus): ?>
                    <option value="<?= esc($itemStatus) ?>" <?= $filterStatus === $itemStatus ? 'selected' : '' ?>><?= esc($itemStatus) ?></option>
                <?php endforeach; ?>
            </select>

            <?php
            // "Clear filters": only when any filter is on (status/card, category, size, search).
            $hasFilters = ($status ?? '') !== '' || (($category ?? 'All') !== 'All' && ($category ?? '') !== '')
                || ($size ?? '') !== '' || ($search ?? '') !== '' || ($searchInput ?? '') !== '';
            $keepPer    = isset($_GET['per_page']) ? '?' . http_build_query(['per_page' => $_GET['per_page']]) : '';
            ?>
            <a href="<?= site_url('staff/inventory') . $keepPer ?>" class="clear-filters" id="clearFilters"<?= $hasFilters ? '' : ' hidden' ?>>
                <i class="bi bi-x-circle" aria-hidden="true"></i> Clear filters
            </a>
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

// Sizes that exist in each category (for resetting Size on category change).
const SIZES_BY_CATEGORY = <?= json_encode($sizesByCategory ?? new stdClass()) ?>;

['categoryFilter', 'sizeFilter', 'statusFilter'].forEach(id => {
    document.getElementById(id).addEventListener('change', event => {
        const url = new URL(window.location.href);
        const parameter = id.replace('Filter', '').toLowerCase();
        if (event.target.value && event.target.value !== 'All') {
            url.searchParams.set(parameter, event.target.value);
        } else {
            url.searchParams.delete(parameter);
        }
        // New category without the selected size → Size back to "All sizes".
        if (id === 'categoryFilter') {
            const size = url.searchParams.get('size');
            const cat = event.target.value;
            if (size && cat && cat !== 'All' && !(SIZES_BY_CATEGORY[cat] || []).includes(size)) {
                url.searchParams.delete('size');
            }
        }
        url.searchParams.delete('page');
        window.location.href = url.toString();
    });
});

// "Clear filters" link: shown only while a filter is on. Live search
// changes the URL without a reload, so re-check after each search.
function syncClearFilters() {
    const link = document.getElementById('clearFilters');
    if (!link) return;
    const q = new URL(window.location.href).searchParams;
    const on = ['status', 'size', 'search'].some(k => (q.get(k) || '') !== '')
        || ((q.get('category') || 'All') !== 'All');
    link.hidden = !on;
}

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
                syncClearFilters();
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

// Stock-level bar for one size (same rule as ProductModel::stockFill):
// fill = on hand ÷ (ROP + EOQ), capped at 100%; color = the size's own status.
function stockFill(v) {
    const stock = Number(v.current_stock) || 0;
    const target = (Number(v.manual_rop_warning) || 0) + (Number(v.eoq_value) || 0);
    const pct = stock <= 0 ? 0 : (target > 0 ? Math.min(100, Math.round(stock / target * 100)) : 100);
    const cls = stockStatusColor(v.status_label); // assets/js/stock-status.js
    return { pct, cls, empty: stock <= 0 };
}

// Size picker: the visible label shows only the selected size.
function syncSizeLabel(select) {
    const label = select.closest('.size-picker')?.querySelector('.size-picker-label');
    if (label) label.textContent = select.selectedOptions[0]?.dataset.size || '';
}
// "Reorder Now · 2XL" / "Order 2XL" when the selected size isn't the one
// the warning and the button refer to.
function rowStatusText(row, selectedIndex, variants) {
    const status = row.dataset.rowStatus;
    const target = variants[+row.dataset.worstIndex];
    const differs = selectedIndex !== +row.dataset.worstIndex && target && target.size
        && (status === 'Reorder Now' || status === 'Low Stock' || status === 'Out of Stock')
        && !(status === 'Reorder Now' && variants[selectedIndex]?.status_label === 'Reorder Now' && !variants[selectedIndex]?.open_so);
    return { status, target, differs, badge: differs ? status + ' · ' + target.size : status };
}

// Size dropdown is delegated so it keeps working after live search swaps the table.
// Picking a different size in a row's Size dropdown swaps every other cell
// (and the Notify Admin button's target) to that size's own real product
// row — each size is independently stock-tracked, this is purely a display
// switch, no page reload.
function pillClassForStatus(label) {
    return stockStatusColor(label); // assets/js/stock-status.js
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

        const fill = stockFill(v);
        const bar = row.querySelector('.inv-bar-cell .stock-bar');
        bar.className = 'stock-bar ' + fill.cls + (fill.empty ? ' is-empty' : '');
        bar.title = fill.pct + '% of reorder point + order quantity';
        bar.querySelector('span').style.width = fill.pct + '%';

        // The status badge stays on the item's worst status (set by the
        // server); on hand, ROP and the stock bar follow the selected size.
        // Notify shows while any size needs reordering, for the selected size
        // if it needs it, otherwise for the size that does.
        syncSizeLabel(select);
        const info = rowStatusText(row, +select.value, variants);
        row.querySelector('.inv-status-cell .status-pill').textContent = info.badge;

        const notifyForm = row.querySelector('.notify-form');
        const notifyNone = row.querySelector('.notify-none');
        const target = v.status_label === 'Reorder Now' && !v.open_so ? v : (info.target || v);
        notifyForm.action = notifyForm.action.replace(/\/notify\/.*$/, '/notify/' + target.item_id);
        const notifyBtn = notifyForm.querySelector('.notify-btn');
        // Always just "Notify Admin"; the size is in the badge and this tooltip.
        if (notifyBtn) { if (target.size) notifyBtn.title = 'Notify about size ' + target.size; else notifyBtn.removeAttribute('title'); }
        const needsReorder = row.dataset.rowStatus === 'Reorder Now' && !row.querySelector('.ordered-tag');
        notifyForm.style.display = needsReorder ? '' : 'none';
        notifyNone.style.display = needsReorder ? 'none' : '';
    }
});
</script>

<?= $this->endSection() ?>
