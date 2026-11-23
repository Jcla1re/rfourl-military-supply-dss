
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
$tab           = in_array($tab ?? 'table', ['suppliers', 'comparison'], true) ? $tab : 'table'; // 'table' = the Items tab
$tabLink       = static fn (string $t): string => site_url('admin/procurement-report') . '?tab=' . $t;
// "Clear all" and the live search stay on this tab (the bare URL now opens Suppliers).
$keepPer       = $tab === 'comparison' ? '?tab=comparison' : '?' . http_build_query(['tab' => 'items'] + (isset($_GET['per_page']) ? ['per_page' => $_GET['per_page']] : []));
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

/* The supplier's minimum order is above the EOQ: a line under the item, red when it is very large. */
.pr-min-note { display: block; margin-top: 2px; font-size: 12px; font-weight: 400; color: #6B6E60; white-space: normal; }
.pr-min-note.is-warning { color: #8A1C1C; font-weight: 600; }
.pr-lead-badge { display: inline-block; margin-left: 8px; padding: 1px 8px; border-radius: 999px; background: #F3E9CF; color: #6B4E0E; font-size: 11px; font-weight: 600; white-space: nowrap; vertical-align: middle; }
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

/* Tabs: plain links, so the tab is in the URL. */
.pr-tabs { display: flex; gap: 4px; margin-bottom: 14px; border-bottom: 1px solid #dcdad2; }
.pr-tab { padding: 10px 16px; font-size: 14px; font-weight: 600; color: #5C6152; text-decoration: none; border-bottom: 3px solid transparent; margin-bottom: -1px; border-radius: 8px 8px 0 0; }
.pr-tab:hover { color: #1F2617; background: #F2F0EA; }
.pr-tab:focus-visible { outline: 2px solid #4b6a3f; outline-offset: 2px; }
.pr-tab.active { color: #2F5A27; border-bottom-color: #4b6a3f; }

/* Suppliers tab */
.sp-badge { display: inline-block; margin-left: 8px; padding: 2px 9px; border-radius: 999px; background: #FFF1CC; color: #7A5A00; font-size: 11px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; vertical-align: middle; }
/* Columns size to their content (inventory-table.css fixes every column to the same width, which made the text overlap),
   and the table scrolls sideways inside its card when it still does not fit. */
.inv-table-card .sp-scroll { overflow-x: auto; }
.data-table.inv-table.sp-table { table-layout: auto; width: 100%; }
.sp-table td, .sp-table th { white-space: nowrap; padding-left: 8px; padding-right: 8px; }
.sp-table td.sp-name { white-space: normal; min-width: 200px; max-width: 300px; line-height: 1.3; }
.sp-table th.sp-c-supplier { min-width: 220px; }
.sp-table th.sp-c-order, .sp-table td.sp-c-order { min-width: 128px; } /* a PO number (PO-2026-0001) never wraps or runs into the date */
.sp-table th.sp-c-date, .sp-table td.sp-c-date { min-width: 108px; }
.sp-table th.sp-c-item, .sp-table td.sp-c-item { min-width: 170px; white-space: normal; }
.sp-stack { display: block; font-weight: 600; }
.sp-stack + .sp-sub, .sp-sub.sp-line2 { display: block; margin-top: 1px; color: #8A8D7E; font-size: 12px; font-weight: 400; }
.sp-table .sp-link { font-weight: 600; color: #1F2617; text-decoration: none; }
.sp-table .sp-link:hover { text-decoration: underline; color: #2F5A27; }
.sp-table .sp-link:focus-visible { outline: 2px solid #4b6a3f; outline-offset: 2px; border-radius: 3px; }
.sp-needs { color: #8A1C1C; font-weight: 700; }
.sp-sample-note { color: #7A5A00; font-weight: 600; }
.sp-muted { color: #8A8D7E; }
.sp-sub { color: #5C6152; font-size: 12px; font-weight: 400; }
.sp-section { background: #fff; border-radius: var(--radius-card); box-shadow: 0 1px 3px rgba(0,0,0,.06); padding: 16px 18px; margin-top: 18px; }
.sp-section h3 { font-size: 16px; font-weight: 800; margin: 0; }
.sp-period { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin: 10px 0 14px; }
.sp-seg { display: inline-flex; border: 1px solid #D6D2C6; border-radius: 10px; overflow: hidden; }
.sp-seg a { padding: 7px 14px; font-size: 13px; font-weight: 600; color: #5C6152; text-decoration: none; background: #fff; }
.sp-seg a.active { background: #3d5230; color: #fff; }
.sp-seg a:focus-visible { outline: 2px solid #4b6a3f; outline-offset: -2px; }
.sp-nav { display: inline-flex; align-items: center; gap: 6px; }
.sp-nav a { padding: 6px 10px; border: 1px solid #D6D2C6; border-radius: 8px; background: #fff; color: #1F2617; text-decoration: none; font-size: 13px; }
.sp-nav a:hover { background: #F2F0EA; }
.sp-nav strong { min-width: 150px; text-align: center; }
.sp-supplier { margin-top: 16px; }
.sp-supplier h4 { font-size: 14px; font-weight: 700; margin: 0 0 6px; }
.sp-scroll { overflow-x: auto; }
tr.sp-total td { background: #E4EFDF; font-weight: 800; }
tr.sp-subtotal td { background: #F7F6F2; font-weight: 700; }
.sp-detail-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin: 4px 0 12px; }
.sp-detail-head h2 { font-size: 20px; font-weight: 800; margin: 0; }
.sp-facts { display: flex; flex-wrap: wrap; gap: 8px 22px; color: #3d4333; font-size: 14px; margin-bottom: 10px; }
.sp-facts span b { color: #1F2617; }
.sp-toggle { display: inline-flex; align-items: center; gap: 8px; padding: 7px 14px; border: 1px solid #D6D2C6; border-radius: 10px; background: #fff; color: #1F2617; font-size: 13px; font-weight: 600; text-decoration: none; }
.sp-toggle.active { background: #3d5230; border-color: #3d5230; color: #fff; }
.sp-toggle:focus-visible { outline: 2px solid #4b6a3f; outline-offset: 2px; }

/* Cost comparison */
.pc-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin: 4px 0 16px; }
@media (max-width: 1100px) { .pc-cards { grid-template-columns: repeat(2, 1fr); } }
.pc-card { background: #fff; border-radius: var(--radius-card); box-shadow: 0 1px 3px rgba(0,0,0,.06); padding: 16px 18px; display: flex; flex-direction: column; gap: 4px; }
.pc-card-label { color: #5C6152; font-size: 13px; }
.pc-card-value { font-size: 24px; font-weight: 800; font-variant-numeric: tabular-nums; }
.pc-card-value small { font-size: 13px; font-weight: 600; }
.pc-card.is-saving .pc-card-value { color: #2F5A27; }
.pc-card.is-negative .pc-card-value { color: #8A1C1C; }
.pc-scroll { max-height: 520px; overflow: auto; }
.pc-table { table-layout: auto; }
.pc-table thead th { position: sticky; top: 0; z-index: 1; background: #3d5230; color: #fff; white-space: nowrap; }
.pc-sort { all: unset; cursor: pointer; font-weight: 600; }
.pc-sort:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
th[aria-sort="ascending"] .pc-sort::after { content: ' ▲'; font-size: 10px; }
th[aria-sort="descending"] .pc-sort::after { content: ' ▼'; font-size: 10px; }
.pc-row { cursor: pointer; }
.pc-row.is-selected td { background: #E4EFDF; }
.pc-row:focus-visible { outline: 2px solid #4b6a3f; outline-offset: -2px; }
.pc-savings { color: #2F5A27; font-weight: 600; }
.pc-savings.is-negative { color: #8A1C1C; }
.pc-savings small { font-weight: 400; color: inherit; }
.pc-chart-card { background: #fff; border-radius: var(--radius-card); box-shadow: 0 1px 3px rgba(0,0,0,.06); padding: 16px 18px; margin-top: 16px; }
.pc-chart-head { display: flex; flex-direction: column; gap: 2px; margin-bottom: 8px; }
.pc-chart-sub { color: #5C6152; font-size: 13px; }
.pc-chart-box { position: relative; height: 300px; }
.pc-chart-orders { margin: 8px 0 0; color: #3d4333; font-size: 13px; }
</style>

<div class="page-wrap">
    <nav class="pr-tabs" aria-label="Procurement Report">
        <a class="pr-tab<?= $tab === 'suppliers' ? ' active' : '' ?>" href="<?= $tabLink('suppliers') ?>"<?= $tab === 'suppliers' ? ' aria-current="page"' : '' ?>>Suppliers</a>
        <a class="pr-tab<?= $tab === 'table' ? ' active' : '' ?>" href="<?= $tabLink('items') ?>"<?= $tab === 'table' ? ' aria-current="page"' : '' ?>>Items</a>
        <a class="pr-tab<?= $tab === 'comparison' ? ' active' : '' ?>" href="<?= $tabLink('comparison') ?>"<?= $tab === 'comparison' ? ' aria-current="page"' : '' ?>>Cost comparison</a>
    </nav>

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

    <?php if ($tab !== 'suppliers'): ?>
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
    <?php endif; ?>

    <?php if ($tab === 'suppliers'): ?>
        <?= view('admin/_procurement_suppliers', get_defined_vars()) ?>
    <?php elseif ($tab === 'comparison'): ?>
        <?= view('admin/_procurement_comparison', get_defined_vars()) ?>
    <?php else: ?>
        <?= view('admin/_procurement_table', get_defined_vars()) ?>
    <?php endif; ?>
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
        if (q.get('tab')) params.set('tab', q.get('tab'));
        FILTER_KEYS.forEach(k => { if (q.get(k)) params.set(k, q.get(k)); });
        const suffix = params.toString() ? '?' + params.toString() : '';
        ['exportPdf', 'exportCsv'].forEach(id => {
            const a = document.getElementById(id);
            a.href = a.dataset.base + suffix;
        });
    }

    ['classFilter', 'statusFilter'].forEach(id => {
        const filter = document.getElementById(id);
        if (!filter) return; // the Suppliers tab has no filters
        filter.addEventListener('change', event => {
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

    // (On the Suppliers tab there is no search box: these stand-ins keep the shared script below harmless.)
    const searchInput = document.getElementById('searchInput') || document.createElement('input');
    const searchBox = searchInput.closest('.inv-search') || document.createElement('div');
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

    // ---- Cost comparison: sortable table, and the cost curve of the row clicked ----------------
    let tcChart = null;
    const peso = n => '₱' + Math.round(n).toLocaleString('en-PH');

    document.addEventListener('click', event => {
        const button = event.target.closest('.pc-sort');
        if (!button) return;
        const table = document.getElementById('pcTable');
        const body = table.tBodies[0];
        const col = Number(button.dataset.col), numeric = button.dataset.sort === 'num';
        const dir = button.closest('th').getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
        table.querySelectorAll('th').forEach(th => th.removeAttribute('aria-sort'));
        button.closest('th').setAttribute('aria-sort', dir);
        const value = row => { const v = row.children[col].dataset.v; return numeric ? parseFloat(v) : v; };
        [...body.querySelectorAll('tr.pc-row')]
            .sort((a, b) => {
                const x = value(a), y = value(b);
                const c = numeric ? x - y : String(x).localeCompare(String(y));
                return dir === 'ascending' ? c : -c;
            })
            .forEach(row => body.appendChild(row));
    });

    function drawCurve(row) {
        const wrap = document.getElementById('procurementResults');
        const S = parseFloat(wrap.dataset.orderingCost), H = parseFloat(wrap.dataset.holdingCost);
        const D = Number(row.dataset.d), qOwner = Number(row.dataset.qOwner), qSys = Number(row.dataset.qSys);
        const tc = q => D / q * S + q / 2 * H;
        const eoq = H > 0 ? Math.sqrt(2 * D * S / H) : qSys;
        const maxQ = 3 * Math.max(qOwner, qSys), step = Math.max(1, Math.ceil(maxQ / 90));
        const curve = [];
        for (let q = 1; q <= maxQ; q += step) curve.push({ x: q, y: tc(q) });
        const datasets = [
            { label: 'Yearly cost', data: curve, borderColor: '#3d5230', borderWidth: 2.5, pointRadius: 0, tension: 0.15 },
            { label: `Owner's usual order (${qOwner})`, data: [{ x: qOwner, y: tc(qOwner) }], showLine: false, pointRadius: 8, pointHoverRadius: 9, backgroundColor: '#b9791f', borderColor: '#fff', borderWidth: 2 },
            { label: 'EOQ (lowest cost)', data: [{ x: eoq, y: tc(eoq) }], showLine: false, pointRadius: 8, pointHoverRadius: 9, backgroundColor: '#2F5A27', borderColor: '#fff', borderWidth: 2 },
        ];
        if (Math.abs(qSys - eoq) >= 1) {
            datasets.push({ label: `PDSS order qty (${qSys}, minimum applied)`, data: [{ x: qSys, y: tc(qSys) }], showLine: false, pointRadius: 7, pointStyle: 'rectRot', backgroundColor: '#5b8def', borderColor: '#fff', borderWidth: 2 });
        }
        if (tcChart) tcChart.destroy();
        tcChart = new Chart(document.getElementById('pcChart'), {
            type: 'line',
            data: { datasets },
            options: {
                maintainAspectRatio: false,
                animation: false,
                parsing: false,
                interaction: { mode: 'nearest', intersect: false },
                plugins: {
                    legend: { position: 'top', align: 'start', labels: { boxWidth: 14 } },
                    tooltip: { callbacks: { title: items => `Order ${Math.round(items[0].parsed.x)} at a time`, label: item => `${item.dataset.label}: ${peso(item.parsed.y)} a year` } },
                },
                scales: {
                    x: { type: 'linear', min: 0, title: { display: true, text: 'Order quantity (Q)' }, grid: { display: false } },
                    y: { beginAtZero: true, title: { display: true, text: 'Yearly cost' }, grid: { color: '#eee' }, ticks: { callback: v => peso(v) } },
                },
            },
        });
        document.getElementById('pcChartTitle').textContent = row.dataset.item;
        document.getElementById('pcChartSub').textContent = `Yearly cost of ordering Q at a time (ordering cost + storage), from ${D.toLocaleString('en-PH')} items sold a year.`;
        document.getElementById('pcChartOrders').textContent = `Orders a year: ${qOwner} at a time = ${row.dataset.ordersOwner}; ${qSys} at a time (PDSS) = ${row.dataset.ordersSys}.`;
        document.querySelectorAll('.pc-row.is-selected').forEach(r => r.classList.remove('is-selected'));
        row.classList.add('is-selected');
    }
    document.addEventListener('click', event => {
        const row = event.target.closest('tr.pc-row');
        if (row) drawCurve(row);
    });
    document.addEventListener('keydown', event => {
        const row = event.target.closest && event.target.closest('tr.pc-row');
        if (row && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); drawCurve(row); }
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
