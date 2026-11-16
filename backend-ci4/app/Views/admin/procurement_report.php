
<?= $this->extend('layouts/admin_layout') ?>

<?= $this->section('content') ?>

<?php
// Procurement Report (Owner): read-only DSS results per size, laid out like Inventory (filter
// toolbar, table card, pager). Ordering stays on Reorder Alerts and Inventory.
$searchError   = $searchError ?? null;
$searchInput   = $searchInput ?? ($search ?? '');
$class         = $class ?? '';
$status        = $status ?? '';
$activeFilters = (int) ($activeFilters ?? 0);
$exportQuery   = $exportQuery ?? [];
$exportSuffix  = $exportQuery ? '?' . http_build_query($exportQuery) : '';
$keepPer       = isset($_GET['per_page']) ? '?' . http_build_query(['per_page' => $_GET['per_page']]) : '';
?>

<link href="/assets/css/inventory-table.css" rel="stylesheet">
<script src="/assets/js/inventory-table.js" defer></script>
<script src="/assets/js/clamp-tip.js" defer></script>
<style>
.page-wrap { font-family: 'Poppins', sans-serif; }

/* Top row: DSS results date (left), exports (right). */
.pr-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.pr-top-left { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.pr-top .lt-back { margin-bottom: 0; } /* sits on the row, not above the page */
.pr-date-chip {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 6px 12px; border-radius: 999px; background: #EFECE4;
    color: #5C6152; font-size: 13px; font-weight: 500; cursor: default; white-space: nowrap;
}
.pr-date-chip i { font-size: 14px; }
.pr-date-chip:focus-visible { outline: 3px solid var(--focus-ring); outline-offset: 2px; }
.pr-exports { display: flex; gap: 10px; flex-wrap: wrap; }
.btn.pr-export-btn {
    display: inline-flex; align-items: center; gap: 7px;
    height: 40px; padding: 0 16px; border: 1px solid #D6D2C6; border-radius: 10px;
    background: #fff; color: #1F2617; font-size: 14px; font-weight: 500;
}

/* Columns: Item takes what is left. Fixed widths that fit each header on one line from 1280px
   up (headers never wrap here, even "Reorder At", which Inventory's shared rule lets wrap).
   Rows, text and item names come from inventory-table.css, as on Inventory. */
.pr-table col.c-size   { width: 72px; }
.pr-table col.c-class  { width: 92px; }
.pr-table col.c-onhand { width: 96px; }
.pr-table col.c-rop    { width: 124px; }
.pr-table col.c-status { width: 150px; }
.pr-table col.c-ss     { width: 124px; }
.pr-table col.c-eoq    { width: 116px; }
.pr-table th,
.pr-table th.col-term { white-space: nowrap; }

/* Default values (no reorder point yet / only the minimum order): muted gray. */
.pr-table td.pr-default { color: #8A8D7E; }

/* K-Means class badge (same colours as Trend Analysis: A darkest, B mid, C lightest). */
.abc-badge {
    display: inline-flex; width: 26px; height: 26px; align-items: center; justify-content: center;
    border-radius: 50%; font-weight: 700; font-size: 13px;
}
.abc-badge.cls-a { background: #2C3A1E; color: #fff; }
.abc-badge.cls-b { background: #71AA60; color: #fff; }
.abc-badge.cls-c { background: #E4EFDF; color: #2F4A22; }
</style>

<div class="page-wrap">
    <div class="pr-top">
        <div class="pr-top-left">
        <?php // Same "← Back" button as the Log Transaction forms (.lt-back, admin-theme.css); back to Reorder Alerts, which opens this report. ?>
        <a href="<?= site_url('admin/reorder-alerts') ?>" class="lt-back"><i class="bi bi-arrow-left"></i> Back</a>
        <?php // The date chip opens the same tooltip card as the (i) buttons (help-tip.js). ?>
        <span class="help-tip pr-date-chip" tabindex="0" role="note" aria-expanded="false"
              data-help-title="DSS results"
              data-help-body="<?= esc('<p>' . esc('Updates weekly when the owner opens the Dashboard, or through Recalculate now.') . '</p>', 'attr') ?>">
            <i class="bi bi-calendar3" aria-hidden="true"></i>
            <?= ! empty($lastRunAt) ? 'DSS results from ' . esc(date('M j, Y', strtotime($lastRunAt))) : 'No DSS results yet' ?>
        </span>
        </div>
        <div class="pr-exports">
            <a class="btn btn-outline-secondary pr-export-btn" id="exportPdf" data-base="<?= site_url('admin/procurement-report/pdf') ?>" href="<?= site_url('admin/procurement-report/pdf') . $exportSuffix ?>"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Export PDF</a>
            <a class="btn btn-outline-secondary pr-export-btn" id="exportCsv" data-base="<?= site_url('admin/procurement-report/csv') ?>" href="<?= site_url('admin/procurement-report/csv') . $exportSuffix ?>"><i class="bi bi-filetype-csv" aria-hidden="true"></i> Export CSV</a>
        </div>
    </div>

    <div class="inv-toolbar">
        <div class="inv-search-field">
            <div class="inv-search<?= $searchError ? ' is-invalid' : '' ?>">
                <i class="bi bi-search"></i>
                <input id="searchInput" type="search" value="<?= esc($searchInput) ?>" placeholder="Search by item name or ID…" maxlength="50"
                       aria-label="Search by item name or ID"<?= $searchError ? ' aria-invalid="true" aria-describedby="searchError"' : '' ?>>
                <button type="button" class="inv-search-clear" id="searchClear" aria-label="Clear search"<?= $searchInput === '' ? ' hidden' : '' ?>><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <p id="searchError" class="search-error" role="alert"<?= $searchError ? '' : ' hidden' ?>>
                <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                <span><?= esc($searchError ?? \App\Models\ProductModel::SEARCH_ERROR) ?></span>
            </p>
        </div>

        <select id="classFilter" class="<?= $class !== '' ? 'has-value' : '' ?>" aria-label="Class">
            <option value="">All classes</option>
            <?php foreach (\App\Libraries\ProcurementReport::CLASSES as $c): ?>
                <option value="<?= esc($c) ?>" <?= $class === $c ? 'selected' : '' ?>>Class <?= esc($c) ?></option>
            <?php endforeach; ?>
        </select>

        <select id="statusFilter" class="<?= $status !== '' ? 'has-value' : '' ?>" aria-label="Status">
            <option value="">All statuses</option>
            <?php foreach (\App\Libraries\ProcurementReport::STATUSES as $s): ?>
                <option value="<?= esc($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= esc($s) ?></option>
            <?php endforeach; ?>
        </select>

        <a href="<?= site_url('admin/procurement-report') . $keepPer ?>" class="clear-filters" id="clearFilters"
           aria-label="Clear all filters (<?= $activeFilters ?> active)"<?= $activeFilters ? '' : ' hidden' ?>>
            Clear all <span class="clear-filters-count" id="clearFiltersCount" aria-hidden="true"><?= $activeFilters ?></span>
        </a>
    </div>

    <?= view('admin/_procurement_table', get_defined_vars()) ?>
</div>

<script>
// Filters, search and paging are GET parameters handled on the server over every item.
// Dropdowns and rows per page reload the page; search fetches just the table (like Inventory).
(function () {
    const FILTER_KEYS = ['search', 'class', 'status'];

    // Export links and "Clear all [n]" follow the current URL (live search changes it without a reload).
    function syncFromUrl() {
        const q = new URL(window.location.href).searchParams;
        const count = FILTER_KEYS.filter(k => (q.get(k) || '') !== '').length;
        const link = document.getElementById('clearFilters');
        link.hidden = count === 0;
        document.getElementById('clearFiltersCount').textContent = count;
        link.setAttribute('aria-label', `Clear all filters (${count} active)`);

        const params = new URLSearchParams();
        FILTER_KEYS.forEach(k => { if (q.get(k)) params.set(k, q.get(k)); });
        const suffix = params.toString() ? '?' + params.toString() : '';
        ['exportPdf', 'exportCsv'].forEach(id => {
            const a = document.getElementById(id);
            a.href = a.dataset.base + suffix;
        });
    }

    ['classFilter', 'statusFilter'].forEach(id => {
        document.getElementById(id).addEventListener('change', event => {
            const url = new URL(window.location.href);
            const key = id.replace('Filter', '');
            if (event.target.value) url.searchParams.set(key, event.target.value);
            else url.searchParams.delete(key);
            url.searchParams.delete('page');
            window.location.href = url.toString();
        });
    });

    document.addEventListener('change', event => {
        const select = event.target.closest('.per-page-select');
        if (!select) return;
        const url = new URL(window.location.href);
        url.searchParams.set('per_page', select.value);
        url.searchParams.delete('page');
        window.location.href = url.toString();
    });

    const searchInput = document.getElementById('searchInput');
    const searchBox = searchInput.closest('.inv-search');
    const searchError = document.getElementById('searchError');
    const searchClear = document.getElementById('searchClear');
    // Same rule as ProductModel::SEARCH_PATTERN on the server.
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
        if (term) url.searchParams.set('search', term);
        else url.searchParams.delete('search');
        url.searchParams.delete('page');

        const request = ++latestRequest;
        // App.fetch: signed out, the page goes to the login page.
        App.fetch(url.toString())
            .then(response => response.ok ? response.text() : null)
            .then(html => {
                if (html === null || request !== latestRequest) return;
                const current = document.getElementById('procurementResults');
                if (current) current.outerHTML = html;
                updateStickyHeader();
                window.history.replaceState({}, '', url.toString());
                syncFromUrl();
            });
    }

    document.addEventListener('click', event => {
        if (!event.target.closest('.btn-clear-search, #searchClear')) return;
        searchInput.value = '';
        searchInput.dispatchEvent(new Event('input'));
        searchInput.focus();
    });

    searchInput.addEventListener('input', () => {
        searchClear.hidden = searchInput.value === '';
        clearTimeout(debounceTimer);
        if (!SEARCH_PATTERN.test(searchInput.value.trim())) {
            latestRequest++;
            setSearchInvalid(true);
            return;
        }
        setSearchInvalid(false);
        debounceTimer = setTimeout(fetchResults, 200);
    });
})();
</script>

<?= $this->endSection() ?>
