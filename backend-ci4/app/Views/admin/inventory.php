
<?= $this->extend('layouts/admin_layout') ?>

<?= $this->section('content') ?>

<?php
$filterCategory = $category ?? 'All';
$filterSize = $size ?? '';
$filterStatus = $status ?? '';
$filterSearch = $search ?? '';
$showArchived = ! empty($showArchived);
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

.page-wrap, .order-modal { font-family: 'Poppins', sans-serif; }

.inv-toolbar {
    display: flex;
    align-items: flex-start; /* the search error message mustn't stretch the filters */
    gap: 12px;
    flex-wrap: wrap;
    margin: 18px 0;
}
.inv-toolbar select {
    min-height: 46px;
    padding: 8px 34px 8px 14px;
    border: 1px solid #d8d5cd;
    border-radius: var(--radius-control);
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
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 46px;
    padding: 8px 14px;
    border: 1px solid #d8d5cd;
    border-radius: var(--radius-control);
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

/* Colors and states come from the shared primary-button rules in
   admin-theme.css. */
.inv-action-btn {
    border: 0;
    padding: 8px 12px;
    cursor: pointer;
    white-space: nowrap;
}
.inv-more-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    padding: 0;
    border: 0;
    border-radius: var(--radius-icon);
    background: transparent;
    color: #444;
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
    transition: background-color 0.15s ease, color 0.15s ease;
}
.inv-more-btn:hover {
    background: #eceae4;
    color: #222;
}
.inv-more-btn:active {
    background: #dedbd3;
}
.inv-more-btn:focus-visible {
    outline: 2px solid #5d965c;
    outline-offset: 2px;
}

/* Only the table + pagination sit in a card; the summary pills, filters
   and tabs sit directly on the page background. */
.inv-table-card {
    background: var(--card-bg);
    border: 1px solid #e4e1d9;
    border-radius: var(--radius-control); /* matches the search bar, filters and summary cards */
    overflow: hidden;
}
.inv-table-card .data-table tbody tr:last-child td {
    border-bottom: 0;
}
.inv-table-footer {
    padding: 14px 16px;
    border-top: 1px solid #e2e2e2;
}
.inv-table-footer .admin-pagination {
    margin-top: 0;
}

/* Fixed column widths; Item name has no width so it takes whatever is
   left. The table is always exactly as wide as its card (no min-width, no
   sideways scrolling): on narrower screens Item name shrinks first, and
   below 1400px a few roomy columns tighten up as well. */
.inv-table-card .data-table-wrap {
    overflow-x: visible;
}
.data-table.inv-table {
    width: 100%;
    table-layout: fixed;
}
.inv-table col.c-id      { width: 72px; }
.inv-table col.c-size    { width: 56px; }
.inv-table col.c-type    { width: 120px; }
.inv-table col.c-onhand  { width: 88px; }
.inv-table col.c-rop     { width: 64px; }
.inv-table col.c-eoq     { width: 64px; }
.inv-table col.c-stock   { width: 120px; }
.inv-table col.c-status  { width: 140px; }
.inv-table col.c-updated { width: 130px; }
.inv-table col.c-actions { width: 140px; }
.inv-table th,
.inv-table td {
    padding-left: 12px;
    padding-right: 12px;
}
.inv-table th.col-stock,
.inv-table td.inv-bar-cell {
    padding-left: 28px; /* keep the bar off the EOQ number */
}
.inv-table .inv-size-select {
    width: 100%;
    min-width: 0;
    max-width: 100%;
    box-sizing: border-box;
}

/* Column alignment: text left (default), numbers right, short codes
   centered, actions right in fixed slots. */
.data-table th.col-num,
.data-table td.col-num {
    text-align: right;
    font-variant-numeric: tabular-nums;
}
.data-table th.col-center,
.data-table td.col-center {
    text-align: center;
}
.data-table th.col-actions,
.data-table td.col-actions {
    text-align: right;
}
.data-table td.inv-item-id,
.data-table td.inv-updated-cell {
    color: #777;
}

/* Two fixed slots: Order (shown only when reordering) + the ··· button,
   so the ··· sits in the same spot on every row. */
.inv-actions {
    display: inline-grid;
    grid-template-columns: auto 38px;
    align-items: center;
    gap: 6px;
}
.inv-actions .inv-action-btn {
    grid-column: 1;
    justify-self: end;
}
.inv-actions .inv-more-btn {
    grid-column: 2;
}

/* Smaller screens only (your 1536px+ view is unaffected): slightly more
   compact type and controls so every column still fits on one line. */
@media (max-width: 1400px) {
    .inv-table col.c-type    { width: 100px; }
    .inv-table col.c-onhand  { width: 80px; }
    .inv-table col.c-stock   { width: 100px; }
    .inv-table col.c-status  { width: 132px; }
    .inv-table col.c-updated { width: 112px; }
    .inv-table col.c-actions { width: 128px; }
    .inv-table th { font-size: 11px; letter-spacing: .02em; white-space: nowrap; }
    .inv-table td { font-size: 13px; }
    .inv-table th.col-stock,
    .inv-table td.inv-bar-cell { padding-left: 18px; }
    .inv-table .stock-bar { width: 100%; max-width: 70px; }
    .inv-table .status-pill { padding: 4px 10px; font-size: 11px; }
    .inv-actions { grid-template-columns: auto 32px; gap: 4px; }
    .inv-actions .inv-action-btn { padding: 6px 10px; font-size: 15px; }
    .inv-actions .inv-more-btn { width: 32px; height: 32px; font-size: 16px; }
}

/* ===== Add / Edit item modal =====
   Scoped to .item-modal so the shared .inv-modal shell used by Orders and
   Sales is untouched. Header and footer stay put; only the body scrolls. */
#itemModal, #archiveConfirm { font-family: 'Poppins', sans-serif; }
.item-modal {
    display: flex;
    flex-direction: column;
    width: min(680px, 94vw);
    max-height: 90vh;
    padding: 0;
    overflow: hidden;
}
.item-modal input,
.item-modal select,
.item-modal button,
.im-confirm button { font-family: inherit; }
.im-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 24px 28px 18px;
    border-bottom: 1px solid #E8E5DC;
    flex-shrink: 0;
}
.im-head h2 { margin: 0; font-size: 22px; font-weight: 600; color: #2C3A1E; line-height: 1.3; }
.im-subtitle { margin-top: 4px; font-size: 14px; color: #5C6152; }
.im-close {
    display: inline-flex; align-items: center; justify-content: center;
    width: 36px; height: 36px; flex-shrink: 0;
    border: 0; border-radius: var(--radius-icon); background: transparent;
    color: #2C3A1E; font-size: 16px; cursor: pointer;
    transition: background-color 0.15s ease;
}
.im-close:hover { background: #F5F3EE; }
.im-close:focus-visible { outline: 3px solid #71AA60; outline-offset: 2px; }

.im-form { display: flex; flex-direction: column; min-height: 0; flex: 1; margin: 0; }
.im-body { flex: 1; min-height: 0; overflow-y: auto; padding: 4px 28px 24px; }
.im-section { padding: 16px 0; }
.im-section + .im-section { border-top: 1px solid #E8E5DC; }
.im-section-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.im-section-head .im-section-title { margin: 0; }
.im-section-title {
    display: flex; align-items: center; gap: 8px;
    margin: 0 0 12px; font-size: 13px; font-weight: 600; color: #2C3A1E;
}
.im-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 16px; }
.im-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.im-field label { font-size: 13px; font-weight: 500; color: #2C3A1E; }
.im-field input,
.im-field select {
    width: 100%;
    height: 44px;
    padding: 0 14px;
    border: 1px solid #E8E5DC;
    border-radius: var(--radius-control);
    background: #fff;
    color: #2C3A1E;
    font-size: 15px;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.im-field select {
    padding-right: 38px;
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background: #fff url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'><path d='M1 1l5 5 5-5' stroke='%235C6152' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>") no-repeat right 14px center;
    text-overflow: ellipsis;
}
.im-field input:focus,
.im-field select:focus {
    outline: none;
    border-color: #71AA60;
    box-shadow: 0 0 0 3px rgba(113, 170, 96, .25);
}
.im-help { font-size: 12px; color: #5C6152; line-height: 1.45; }
.im-prefix { position: relative; }
.im-prefix span {
    position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
    color: #5C6152; font-size: 15px; pointer-events: none;
}
.im-field .im-prefix input { padding-left: 32px; }

.im-reason {
    margin: 14px 0 0; padding: 14px 16px; border: 0;
    border-radius: var(--radius-control); background: #F5F3EE;
}
.im-reason[hidden] { display: none; }
.im-reason legend { float: left; width: 100%; margin: 0 0 10px; font-size: 13px; font-weight: 500; color: #2C3A1E; }
.im-chips { clear: both; display: flex; flex-wrap: wrap; gap: 8px; }
.im-chip { position: relative; margin: 0; cursor: pointer; }
.im-chip input { position: absolute; opacity: 0; width: 1px; height: 1px; }
.im-chip span {
    display: inline-block; padding: 7px 14px;
    border: 1px solid #E8E5DC; border-radius: var(--radius-pill); background: #fff;
    font-size: 13px; color: #2C3A1E;
    transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}
.im-chip:hover span { border-color: #71AA60; }
.im-chip input:checked + span { background: #71AA60; border-color: #71AA60; color: #fff; }
.im-chip input:focus-visible + span { outline: 3px solid #71AA60; outline-offset: 2px; }

.im-margin {
    display: flex; align-items: center; gap: 8px;
    margin: 14px 0 0; font-size: 13px; color: #2C3A1E;
}
.im-margin.is-error { color: #B42318; }

.im-archive {
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
    margin-top: 20px; padding: 16px 18px;
    background: #FDF2F0; border: 1px solid #F1C4BD; border-radius: 12px;
}
.im-archive[hidden] { display: none; }
.im-archive strong { display: block; font-size: 14px; font-weight: 600; color: #7a1f17; }
.im-archive p { margin: 4px 0 0; font-size: 13px; color: #6a3a35; line-height: 1.45; }
.im-btn-archive {
    flex-shrink: 0; height: 44px; padding: 0 20px;
    border: 1px solid #B42318; border-radius: var(--radius-control);
    background: #fff; color: #B42318; font-weight: 500; cursor: pointer;
    transition: background-color 0.15s ease, transform 0.12s ease;
}
.im-btn-archive:hover { background: #FDECEA; transform: translateY(-1px); }
.im-btn-archive:active { transform: translateY(0); }
.im-btn-archive:focus-visible { outline: 3px solid #B42318; outline-offset: 2px; }

.im-foot {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 16px 28px; border-top: 1px solid #E8E5DC; background: #fff; flex-shrink: 0;
}
.im-changes { font-size: 13px; color: #5C6152; }
.im-foot-actions { display: flex; gap: 10px; }
.im-btn-secondary,
.im-btn-danger,
.im-btn-save.btn {
    height: 44px; padding: 0 20px; border-radius: var(--radius-control);
    font-size: 15px; font-weight: 500; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
}
.im-btn-secondary {
    border: 1px solid #E8E5DC; background: #fff; color: #2C3A1E;
    transition: background-color 0.15s ease, transform 0.12s ease;
}
.im-btn-secondary:hover { background: #F5F3EE; transform: translateY(-1px); }
.im-btn-secondary:active { transform: translateY(0); }
.im-btn-secondary:focus-visible { outline: 3px solid #2C3A1E; outline-offset: 2px; }
.im-btn-danger {
    border: 1px solid #B42318; background: #B42318; color: #fff;
    transition: background-color 0.15s ease, transform 0.12s ease;
}
.im-btn-danger:hover { background: #912018; border-color: #912018; transform: translateY(-1px); }
.im-btn-danger:active { transform: translateY(0); }
.im-btn-danger:focus-visible { outline: 3px solid #2C3A1E; outline-offset: 2px; }

#archiveConfirm { z-index: 1010; }
.im-confirm { width: min(420px, 92vw); padding: 24px; }
.im-confirm-icon {
    display: flex; align-items: center; justify-content: center;
    width: 44px; height: 44px; margin-bottom: 16px;
    border-radius: 50%; font-size: 18px;
}
.im-confirm-icon.is-danger { background: #FDECEA; color: #B42318; }
.im-confirm-icon.is-success { background: #EAF3E6; color: #3B5434; }
.im-confirm h2 { margin: 0 0 6px; font-size: 18px; font-weight: 600; color: #2C3A1E; word-break: break-word; }
.im-confirm p { margin: 0 0 22px; font-size: 14px; color: #5C6152; line-height: 1.5; }
.im-confirm .im-foot-actions { justify-content: flex-end; }

@media (max-width: 600px) {
    .im-grid { grid-template-columns: 1fr; }
    .im-head, .im-foot { padding-left: 18px; padding-right: 18px; }
    .im-body { padding-left: 18px; padding-right: 18px; }
    .im-archive { flex-direction: column; align-items: stretch; }
    .im-foot { flex-wrap: wrap; }
}

/* ===== Archived items (?status=archived) ===== */
.underline-tabs a.archived-link {
    display: inline-flex; align-items: center; gap: 6px;
    margin-left: auto; align-self: center;
    padding: 6px 12px; border: 0; border-radius: var(--radius-icon);
    font-size: 14px; color: #5C6152; text-decoration: none;
}
.underline-tabs a.archived-link:hover { color: #2C3A1E; text-decoration: underline; }
.underline-tabs a.archived-link.active { background: #EEECE5; color: #2C3A1E; font-weight: 500; }
.underline-tabs a.archived-link.active:hover { text-decoration: none; }
.archived-banner {
    display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
    margin-bottom: 14px; padding: 12px 16px;
    background: #FFFFFF; border: 1px solid #E8E5DC; border-radius: var(--radius-control);
    font-size: 14px; color: #2C3A1E;
}
.archived-banner span { display: inline-flex; align-items: center; gap: 8px; }
.archived-back {
    display: inline-flex; align-items: center; gap: 6px;
    color: #3B5434; font-weight: 600; text-decoration: none; white-space: nowrap;
}
.archived-back:hover { color: #3B5434; text-decoration: underline; }
.archived-back:focus-visible { outline: 3px solid #71AA60; outline-offset: 2px; border-radius: 4px; }
.archived-empty { padding: 40px 16px; text-align: center; }
.archived-empty-icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 48px; height: 48px; margin-bottom: 12px;
    border-radius: 50%; background: #F5F3EE; color: #5C6152; font-size: 20px;
}
.archived-empty-title { font-size: 15px; font-weight: 600; color: #1F2617; }
.archived-empty-text { margin: 4px 0 0; font-size: 14px; color: #5C6152; }
.data-table tr.is-archived td { color: #5C6152; }
.status-pill.archived { background: #EEECE5; color: #4A5040; }
.stock-bar.archived > span { background: #B9B6AC; }
.btn-restore {
    display: inline-flex; align-items: center; gap: 6px;
    height: 38px; padding: 0 14px;
    border: 1px solid #E8E5DC; border-radius: var(--radius-control);
    background: #FFFFFF; color: #2C3A1E; font-size: 14px; font-weight: 500;
    cursor: pointer; white-space: nowrap;
    transition: transform 0.12s ease, background-color 0.15s ease, border-color 0.15s ease;
}
.btn-restore:hover { background: #F5F3EE; border-color: #71AA60; transform: translateY(-1px); }
.btn-restore:active { transform: translateY(0); }
.btn-restore:focus-visible { outline: 3px solid #71AA60; outline-offset: 2px; }
#restoreConfirm { z-index: 1010; }

</style>

<div class="page-wrap">

        <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>

        <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between">
            <div class="d-flex flex-wrap gap-3">
                <span class="stat-pill green"><i class="bi bi-check-square-fill"></i> In Stock: <?= esc((string) ($inStockCount ?? 0)) ?></span>
                <span class="stat-pill amber"><i class="bi bi-lightning-fill"></i> Low Stock: <?= esc((string) (is_array($lowStockCount ?? null) ? count((array) ($lowStockCount ?? [])) : ($lowStockCount ?? 0))) ?></span>
                <span class="stat-pill red"><i class="bi bi-exclamation-triangle-fill"></i> Reorder Now: <?= esc((string) (isset($reorderCount) ? (is_array($reorderCount) ? count($reorderCount) : $reorderCount) : 0)) ?></span>
            </div>
            <button type="button" class="btn btn-success" id="openAddModal">
                <i class="bi bi-plus-lg"></i> Add Item
            </button>
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
                <option value="archived" <?= $showArchived ? 'selected' : '' ?>>Archived</option>
            </select>
        </div>

        <?php $categoryCounts = $categoryCounts ?? []; ?>
        <div class="underline-tabs">
            <?php $keepPerPage = (int) ($perPage ?? 10) !== (int) (($perPageOptions ?? [10])[0]) ? ['per_page' => (int) $perPage] : []; ?>
            <a href="<?= site_url('admin/inventory') . ($keepPerPage ? '?' . http_build_query($keepPerPage) : '') ?>" class="<?= $filterCategory === 'All' && ! $showArchived ? 'active' : '' ?>">All Items (<?= number_format($categoryCounts['All'] ?? 0) ?>)</a>
                        <?php foreach ($categories as $itemCategory): ?>
                <a href="<?= site_url('admin/inventory') . '?' . http_build_query(['category' => $itemCategory] + $keepPerPage) ?>" class="<?= $filterCategory === $itemCategory && ! $showArchived ? 'active' : '' ?>"><?= esc($itemCategory) ?> (<?= number_format($categoryCounts[$itemCategory] ?? 0) ?>)</a>
            <?php endforeach; ?>
            <?php /* Always visible, even at 0, so the archive is easy to find. */ ?>
            <a href="<?= site_url('admin/inventory') . '?' . http_build_query(['status' => 'archived'] + $keepPerPage) ?>" class="archived-link<?= $showArchived ? ' active' : '' ?>"<?= $showArchived ? ' aria-current="page"' : '' ?>>
                <i class="bi bi-archive" aria-hidden="true"></i> Archived<?= ($archivedCount ?? 0) > 0 ? ' (' . number_format($archivedCount) . ')' : '' ?>
            </a>
        </div>

        <?php if ($showArchived): ?>
            <div class="archived-banner" role="status">
                <span><i class="bi bi-info-circle" aria-hidden="true"></i> Showing archived items. These are hidden from POS, reorder alerts, and stock counts.</span>
                <a class="archived-back" href="<?= site_url('admin/inventory') . ($keepPerPage ? '?' . http_build_query($keepPerPage) : '') ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to inventory</a>
            </div>
        <?php endif; ?>

        <?= view('admin/_inventory_table', get_defined_vars()) ?>
</div>

<div class="inv-modal" id="itemModal">
    <div class="inv-modal-card item-modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="im-head">
            <div class="im-head-text">
                <h2 id="modalTitle">Add item</h2>
                <div class="im-subtitle" id="modalSubtitle"></div>
            </div>
            <button type="button" class="im-close" id="closeItemModal" aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>

        <form id="itemForm" class="im-form" method="post" action="<?= site_url('admin/inventory/store') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="return_query" value="">

            <div class="im-body">
                <section class="im-section">
                    <h3 class="im-section-title"><i class="bi bi-box" aria-hidden="true"></i> Item details</h3>
                    <div class="im-grid">
                        <div class="im-field">
                            <label for="item_name">Item name</label>
                            <input id="item_name" name="item_name" required maxlength="100">
                        </div>
                        <div class="im-field" id="sizeLetterField">
                            <label for="sizeLetter">Size</label>
                            <select id="sizeLetter" name="size">
                                <option value="">No size</option>
                                <option value="XS">XS</option>
                                <option value="S">S</option>
                                <option value="M">M</option>
                                <option value="L">L</option>
                                <option value="XL">XL</option>
                                <option value="XXL">XXL</option>
                            </select>
                        </div>
                        <div class="im-field" id="sizeNumericField" style="display:none;">
                            <label for="sizeNumeric">Size (US)</label>
                            <input id="sizeNumeric" name="size" type="number" step="0.5" min="0" placeholder="e.g. 9">
                        </div>
                        <div class="im-field">
                            <label for="category">Type</label>
                            <select id="category" name="category" required>
                                <?php foreach ($categories as $itemCategory): ?>
                                    <option value="<?= esc($itemCategory) ?>"><?= esc($itemCategory) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="im-field">
                            <label for="supplier_id">Supplier</label>
                            <select id="supplier_id" name="supplier_id">
                                <option value="">Unassigned</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?= esc($s['supplier_id']) ?>"><?= esc($s['company_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </section>

                <section class="im-section">
                    <div class="im-section-head">
                        <h3 class="im-section-title"><i class="bi bi-bar-chart" aria-hidden="true"></i> Stock</h3>
                        <span class="status-pill green" id="imStatus">In Stock</span>
                    </div>
                    <div class="im-grid">
                        <div class="im-field">
                            <label for="current_stock">On hand</label>
                            <input id="current_stock" name="current_stock" type="number" min="0" step="1" value="0">
                            <small class="im-help" id="stockHelp">Current units in the store.</small>
                        </div>
                        <div class="im-field">
                            <label for="manual_rop_warning">Reorder point (ROP)</label>
                            <input id="manual_rop_warning" name="manual_rop_warning" type="number" min="0" step="1" value="0">
                            <small class="im-help">You'll get an alert when stock reaches this number.</small>
                        </div>
                    </div>
                    <fieldset class="im-reason" id="stockReason" hidden>
                        <legend>Reason for stock change</legend>
                        <div class="im-chips">
                            <?php foreach (['Restock', 'Damaged', 'Returned', 'Count correction'] as $reason): ?>
                                <label class="im-chip">
                                    <input type="radio" name="stock_reason" value="<?= esc($reason) ?>">
                                    <span><?= esc($reason) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                </section>

                <section class="im-section">
                    <h3 class="im-section-title"><i class="bi bi-tag" aria-hidden="true"></i> Pricing</h3>
                    <div class="im-grid">
                        <div class="im-field">
                            <label for="unit_cost">Unit cost</label>
                            <div class="im-prefix">
                                <span aria-hidden="true">₱</span>
                                <input id="unit_cost" name="unit_cost" type="number" step="0.01" min="0" value="0" required>
                            </div>
                        </div>
                        <div class="im-field">
                            <label for="selling_price">Selling price</label>
                            <div class="im-prefix">
                                <span aria-hidden="true">₱</span>
                                <input id="selling_price" name="selling_price" type="number" step="0.01" min="0" value="0" required>
                            </div>
                        </div>
                    </div>
                    <p class="im-margin" id="marginLine" aria-live="polite"></p>

                    <div class="im-archive" id="deleteZone" hidden>
                        <div>
                            <strong>Archive this item</strong>
                            <p>It will be hidden from active inventory. You can restore it anytime from Archived items.</p>
                        </div>
                        <button type="button" class="im-btn-archive" id="archiveBtn">Archive</button>
                    </div>
                </section>
            </div>

            <div class="im-foot">
                <span class="im-changes" id="changeSummary" aria-live="polite">No changes yet.</span>
                <div class="im-foot-actions">
                    <button type="button" class="im-btn-secondary" id="cancelItemModal">Cancel</button>
                    <button type="submit" class="btn btn-success im-btn-save" id="submitItemBtn" disabled>Add item</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="inv-modal" id="restoreConfirm">
    <div class="inv-modal-card im-confirm" role="alertdialog" aria-modal="true" aria-labelledby="restoreConfirmTitle" aria-describedby="restoreConfirmText">
        <div class="im-confirm-icon is-success" aria-hidden="true"><i class="bi bi-arrow-counterclockwise"></i></div>
        <h2 id="restoreConfirmTitle">Restore item?</h2>
        <p id="restoreConfirmText">It will appear in inventory, POS, and reorder alerts again.</p>
        <form id="restoreForm" method="post" class="im-foot-actions">
            <?= csrf_field() ?>
            <input type="hidden" name="return_query" value="">
            <button type="button" class="im-btn-secondary" id="restoreCancelBtn">Cancel</button>
            <button type="submit" class="btn btn-success im-btn-save">Restore</button>
        </form>
    </div>
</div>

<div class="inv-modal" id="archiveConfirm">
    <div class="inv-modal-card im-confirm" role="alertdialog" aria-modal="true" aria-labelledby="archiveConfirmTitle" aria-describedby="archiveConfirmText">
        <div class="im-confirm-icon is-danger" aria-hidden="true"><i class="bi bi-archive"></i></div>
        <h2 id="archiveConfirmTitle">Archive item?</h2>
        <p id="archiveConfirmText">It won't appear in inventory, POS, or reorder alerts. You can restore it from Archived items.</p>
        <div class="im-foot-actions">
            <button type="button" class="im-btn-secondary" id="archiveCancelBtn">Cancel</button>
            <button type="button" class="im-btn-danger" id="archiveConfirmBtn">Archive item</button>
        </div>
    </div>
</div>

<form id="deleteForm" method="post" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="return_query" value="">
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
.order-modal-card { width: min(760px, 92vw); max-height: 90vh; overflow-y: auto; padding: 26px; border-radius: var(--radius-card); background: #fff; }
.order-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.order-field { display: flex; flex-direction: column; gap: 6px; }
.order-field input, .order-field select { padding: 12px; border: 1px solid #ccc; border-radius: var(--radius-control); }
@media (max-width: 700px) { .order-form-grid { grid-template-columns: 1fr; } }
</style>

<script>
const orderModal = document.getElementById('orderModal');

// Delegated (not bound per-button): live search replaces the table's rows
// in place, so a listener attached directly to a button at page load would
// stop working on anything fetched in afterward. Delegating from a parent
// that's never replaced keeps this working for every row, old or new.
document.addEventListener('click', event => {
    const button = event.target.closest('.open-order-modal');
    if (!button) return;
    document.getElementById('orderItemId').value = button.dataset.itemId || '';
    document.getElementById('orderItemName').value = button.dataset.itemName || '';
    document.getElementById('orderSupplierId').value = button.dataset.supplierId || '';
    document.getElementById('orderQuantity').value = button.dataset.recommendedEoq || 1;
    document.getElementById('orderUnitPrice').value = button.dataset.unitCost || 0;
    orderModal.classList.add('show');
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

// ----- Add / Edit item modal --------------------------------------------
const stockInput = document.getElementById('current_stock');
const ropInput = document.getElementById('manual_rop_warning');
const costInput = document.getElementById('unit_cost');
const priceInput = document.getElementById('selling_price');
const submitItemBtn = document.getElementById('submitItemBtn');
const stockReason = document.getElementById('stockReason');
let modalSnapshot = {};
let originalStock = null;   // null in Add mode
let modalOpener = null;

const peso = n => '₱' + Number(n).toLocaleString('en-PH', { maximumFractionDigits: 2 });

// The values that count as "the form": the active size input only (the
// other one is disabled and isn't submitted).
function formValues() {
    const size = document.getElementById('sizeLetter').disabled
        ? (document.getElementById('sizeNumeric').disabled ? '' : document.getElementById('sizeNumeric').value)
        : document.getElementById('sizeLetter').value;
    return {
        item_name: document.getElementById('item_name').value.trim(),
        size: size,
        category: document.getElementById('category').value,
        supplier_id: document.getElementById('supplier_id').value,
        current_stock: String(Number(stockInput.value || 0)),
        manual_rop_warning: String(Number(ropInput.value || 0)),
        unit_cost: String(Number(costInput.value || 0)),
        selling_price: String(Number(priceInput.value || 0)),
    };
}

// Same rule as ProductModel::getStatus() on the server.
function stockStatus(stock, rop) {
    if (stock <= 0) return ['Reorder Now', 'red'];
    if (rop <= 0) return ['In Stock', 'green'];
    if (stock <= rop) return ['Reorder Now', 'red'];
    if (stock <= rop * 1.5) return ['Low Stock', 'amber'];
    return ['In Stock', 'green'];
}

function refreshItemModal() {
    const stock = Number(stockInput.value || 0);
    const rop = Number(ropInput.value || 0);

    const [label, cls] = stockStatus(stock, rop);
    const pill = document.getElementById('imStatus');
    pill.textContent = label;
    pill.className = 'status-pill ' + cls;

    // "Was 5 (+1)" + reason chips when On hand changes (Edit mode only).
    const stockHelp = document.getElementById('stockHelp');
    const delta = originalStock === null ? 0 : stock - originalStock;
    if (delta !== 0) {
        stockHelp.textContent = `Was ${originalStock} (${delta > 0 ? '+' : '−'}${Math.abs(delta)})`;
        stockReason.hidden = false;
    } else {
        stockHelp.textContent = 'Current units in the store.';
        stockReason.hidden = true;
        stockReason.querySelectorAll('input').forEach(r => { r.checked = false; });
    }

    // Live margin: selling price − unit cost, % of the selling price.
    const cost = Number(costInput.value || 0);
    const price = Number(priceInput.value || 0);
    const marginLine = document.getElementById('marginLine');
    if (price < cost) {
        marginLine.className = 'im-margin is-error';
        marginLine.innerHTML = '<i class="bi bi-exclamation-circle" aria-hidden="true"></i> Selling price is lower than unit cost.';
    } else {
        const margin = price - cost;
        const pct = price > 0 ? Math.round((margin / price) * 100) : 0;
        marginLine.className = 'im-margin';
        marginLine.innerHTML = '<i class="bi bi-graph-up-arrow" aria-hidden="true"></i> ';
        marginLine.append(`Margin: ${peso(margin)} per unit (${pct}%)`);
    }

    // Save stays disabled until a field actually changes.
    const now = formValues();
    const changed = Object.keys(now).filter(k => now[k] !== modalSnapshot[k]).length;
    submitItemBtn.disabled = changed === 0;
    document.getElementById('changeSummary').textContent =
        changed === 0 ? 'No changes yet.' : `${changed} ${changed === 1 ? 'change' : 'changes'}`;
}

itemForm.addEventListener('input', refreshItemModal);
itemForm.addEventListener('change', refreshItemModal);

function openItemModal(opener) {
    modalOpener = opener || null;
    modalSnapshot = formValues();
    refreshItemModal();
    itemModal.classList.add('show');
    document.querySelector('#itemModal .im-body').scrollTop = 0;
    setTimeout(() => document.getElementById('item_name').focus(), 50);
}

function closeItemModal() {
    itemModal.classList.remove('show');
    if (modalOpener) modalOpener.focus();
}

document.getElementById('openAddModal').addEventListener('click', event => {
    itemForm.reset();
    itemForm.action = storeUrl;
    originalStock = null;
    document.getElementById('modalTitle').textContent = 'Add item';
    document.getElementById('modalSubtitle').textContent = 'Fill in the details for the new item.';
    submitItemBtn.textContent = 'Add item';
    document.getElementById('deleteZone').hidden = true;
    updateSizeFieldVisibility();
    openItemModal(event.currentTarget);
});

// Delegated for the same reason as .open-order-modal above.
document.addEventListener('click', event => {
    const button = event.target.closest('.edit-row');
    if (!button) return;
    const item = JSON.parse(button.dataset.item || '{}');

    itemForm.reset();
    document.getElementById('modalTitle').textContent = 'Edit item';
    document.getElementById('modalSubtitle').textContent =
        `${item.item_name || ''} · ${item.size ? 'Size ' + item.size : 'No size'}`;
    submitItemBtn.textContent = 'Save changes';
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
    costInput.value = item.unit_cost || 0;
    priceInput.value = item.selling_price || 0;
    stockInput.value = item.current_stock || 0;
    ropInput.value = item.manual_rop_warning || 0;
    originalStock = Number(item.current_stock || 0);

    itemForm.action = "<?= site_url('admin/inventory/update') ?>/" + item.item_id;

    document.getElementById('deleteZone').hidden = false;
    document.getElementById('archiveBtn').onclick = () => {
        document.getElementById('archiveConfirmTitle').textContent = `Archive ${item.item_name || 'this item'}?`;
        document.getElementById('archiveConfirmBtn').onclick = () => {
            const form = document.getElementById('deleteForm');
            form.action = "<?= site_url('admin/inventory/delete') ?>/" + item.item_id;
            form.submit();
        };
        document.getElementById('archiveConfirm').classList.add('show');
        setTimeout(() => document.getElementById('archiveCancelBtn').focus(), 50);
    };

    openItemModal(button);
});

function closeArchiveConfirm() {
    document.getElementById('archiveConfirm').classList.remove('show');
    document.getElementById('archiveBtn').focus();
}
document.getElementById('archiveCancelBtn').addEventListener('click', closeArchiveConfirm);

// ----- Restore (archived view) -------------------------------------------
let restoreOpener = null;
document.addEventListener('click', event => {
    const button = event.target.closest('.btn-restore');
    if (!button) return;
    restoreOpener = button;
    document.getElementById('restoreConfirmTitle').textContent = `Restore ${button.dataset.itemName || 'this item'}?`;
    document.getElementById('restoreForm').action =
        "<?= site_url('admin/inventory/restore') ?>/" + encodeURIComponent(button.dataset.itemId);
    syncReturnQuery();
    document.getElementById('restoreConfirm').classList.add('show');
    setTimeout(() => document.getElementById('restoreCancelBtn').focus(), 50);
});
function closeRestoreConfirm() {
    document.getElementById('restoreConfirm').classList.remove('show');
    if (restoreOpener) restoreOpener.focus();
}
document.getElementById('restoreCancelBtn').addEventListener('click', closeRestoreConfirm);



document.getElementById('closeItemModal').addEventListener('click', closeItemModal);
document.getElementById('cancelItemModal').addEventListener('click', closeItemModal);
document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    if (document.getElementById('restoreConfirm').classList.contains('show')) closeRestoreConfirm();
    else if (document.getElementById('archiveConfirm').classList.contains('show')) closeArchiveConfirm();
    else if (itemModal.classList.contains('show')) closeItemModal();
});

// Picking a different size in a row's Size dropdown swaps every other cell
// (and the Order/Edit buttons' underlying data) to that size's own real
// product row — each size is independently stock-tracked, this is purely
// a display switch, no page reload.
function pillClassForStatus(label) {
    if (label === 'Low Stock') return 'amber';
    if (label === 'Reorder Now') return 'red';
    if (label === 'Archived') return 'archived';
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

    const restoreBtn = row.querySelector('.btn-restore');
    if (restoreBtn) {
        restoreBtn.dataset.itemId = v.item_id;
        restoreBtn.dataset.itemName = v.item_name;
    }
}

// Delegated so it keeps working after live search swaps the table rows.
document.addEventListener('change', event => {
    const select = event.target.closest('.inv-size-select');
    if (!select) return;
    const row = select.closest('tr');
    const variants = JSON.parse(row.dataset.variants || '[]');
    const v = variants[select.value];
    if (v) applyVariantToRow(row, v);
});

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
</script>

<?= $this->endSection() ?>
