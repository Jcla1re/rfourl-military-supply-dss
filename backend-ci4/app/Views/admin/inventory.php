
<?= $this->extend('layouts/admin_layout') ?>

<?= $this->section('content') ?>

<?php
$filterCategory = $category ?? 'All';
$filterSize = $size ?? '';
$filterStatus = $status ?? '';
$filterSearch = $search ?? '';
$showArchived = ! empty($showArchived);
$categories ??= \App\Models\ProductModel::CATEGORIES;
$statuses ??= \App\Models\ProductModel::STATUSES;
$suppliers ??= [];
$lowStockCount ??= 0;
$totalItems ??= 0;
$success ??= null;
$error ??= null;
$inStockCount ??= 0;
$reorderCount ??= 0;
$outOfStockCount ??= 0;
$sizes ??= [];
$products ??= [];
?>

<link href="/assets/css/inventory-table.css" rel="stylesheet">
<link href="/assets/css/item-modal.css" rel="stylesheet">
<script src="/assets/js/inventory-table.js" defer></script>
<script src="/assets/js/clamp-tip.js" defer></script>
<style>

.page-wrap { font-family: 'Poppins', sans-serif; }

/* Filter toolbar, search box and "Clear all": shared rules in admin-theme.css. */

/* ===== Search results (owner): archived items are searched too ===== */
/* Bar on top of the table while a search has active results. */
.inv-search-bar {
    display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
    padding: 12px 16px; background: #ffffff; border-bottom: 1px solid #EFECE4;
    border-radius: var(--radius-control) var(--radius-control) 0 0;
}
.inv-search-bar-text { display: flex; align-items: center; gap: 10px; min-width: 0; font-size: 14px; color: #3A4030; }
.inv-search-bar-text strong { font-weight: 600; color: #1F2617; }
.inv-search-bar-text > span:last-child { overflow-wrap: anywhere; }
.inv-search-bar-icon {
    display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
    width: 30px; height: 30px; border-radius: 8px; background: #EAF1E3; color: #3B5434; font-size: 16px;
}
.inv-table-card.has-search-bar .inv-table thead th { border-radius: 0; }
.inv-archived-btn {
    display: inline-flex; align-items: center; gap: 8px; flex-shrink: 0;
    height: 36px; padding: 0 14px; border: 1px solid #D6D2C6; border-radius: 9px; background: #fff;
    color: #1F2617; font-size: 13px; font-weight: 600; text-decoration: none; white-space: nowrap;
    transition: background-color .15s ease, border-color .15s ease, color .15s ease;
}
.inv-archived-btn .bi-archive { font-size: 15px; color: #5C6152; }
.inv-archived-btn .bi-arrow-right { font-size: 14px; }
.inv-archived-count {
    border-radius: 999px; background: #EFECE4; color: #3A4030; font-size: 11.5px; font-weight: 600;
}
/* Empty states (owner, active list). */
.inv-empty-owner .title { font-size: 16px; font-weight: 600; color: #1F2617; }
.inv-empty-owner .hint { font-size: 14px; color: #5C6152; }
.inv-archived-box {
    display: flex; align-items: center; gap: 14px;
    max-width: 620px; margin: 0 auto; padding: 14px 16px;
    background: #FAF8F4; border: 1px solid #E8E5DC; border-radius: 12px; text-align: left;
}
.inv-archived-box-icon {
    display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
    width: 34px; height: 34px; border-radius: 9px; background: #F2F0EA; color: #5C6152; font-size: 18px;
}
.inv-archived-box-text { display: flex; flex-direction: column; gap: 2px; flex: 1; min-width: 0; }
.inv-archived-box-text strong { font-size: 13.5px; font-weight: 600; color: #1F2617; }
.inv-archived-box-text span { font-size: 13px; color: #5C6152; overflow-wrap: anywhere; }
.inv-archived-view {
    display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0;
    height: 36px; padding: 0 14px; border: 1px solid #D6D2C6; border-radius: 9px; background: #fff;
    color: #1F2617; font-size: 13px; font-weight: 600; text-decoration: none; white-space: nowrap;
    transition: background-color .15s ease, border-color .15s ease, color .15s ease;
}
.btn-clear-search.inv-clear-outline {
    height: 36px; padding: 0 14px; border: 1px solid #D6D2C6; border-radius: 9px; background: #fff;
    color: #2C3A1E; font-size: 13px; font-weight: 600; transform: none;
}

.inv-more-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
    width: 40px;
    height: 40px; /* as tall as the Order button beside it */
    padding: 0;
    border: 1px solid #E3E0D7;
    border-radius: 10px;
    background: #fff;
    color: #3A4030;
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
    transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}
.inv-more-btn svg { display: block; flex: none; }
/* Edit (pencil) button: outlined square; its light-green hover is the shared outlined-button rule
   (admin-theme.css), which stands out on the hovered row. */
.inv-more-btn:focus-visible {
    outline: 3px solid var(--focus-ring);
    outline-offset: 2px;
}


/* Two fixed slots: Order (shown only when reordering) + the Edit (pencil) button,
   so the pencil sits in the same spot on every row. */
.inv-actions {
    display: inline-grid;
    grid-template-columns: auto 40px;
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

/* 1400px and below: the admin-only action slots get a little tighter
   (the rest of the table's compact rules are in inventory-table.css). */
@media (max-width: 1400px) {
    /* The compact Order button is 34.5px tall here (6px padding + 15px text); the pencil matches it. */
    .inv-actions { grid-template-columns: auto 34.5px; gap: 4px; }
    .inv-actions .inv-more-btn { width: 34.5px; height: 34.5px; font-size: 16px; }
}

/* Add / Edit item dialog: shared form-dialog rules in assets/css/item-modal.css. */
#archiveConfirm { z-index: 1010; }
.im-confirm { width: min(420px, 92vw); padding: 24px; }
.im-confirm-icon {
    display: flex; align-items: center; justify-content: center;
    width: 44px; height: 44px; margin-bottom: 16px;
    border-radius: 50%; font-size: 18px;
}
.im-confirm-icon.is-danger { background: #FDECEA; color: #A1261B; }
.im-confirm-icon.is-success { background: #EAF3E6; color: #3B5434; }
.im-confirm h2 { margin: 0 0 6px; font-size: 18px; font-weight: 600; color: #2C3A1E; word-break: break-word; }
.im-confirm p { margin: 0 0 22px; font-size: 14px; color: #5C6152; line-height: 1.5; }
/* Restore: the stock line reads as part of the same message (6px apart). */
#restoreConfirmText:has(+ #restoreConfirmNote:not([hidden])) { margin-bottom: 6px; }
.im-confirm .im-foot-actions { justify-content: flex-end; }

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
    height: 36px; padding: 0 14px; border: 1px solid #D6D2C6; border-radius: 9px; background: #fff;
    color: #2C3A1E; font-size: 13px; font-weight: 600; text-decoration: none; white-space: nowrap;
    transition: background-color .15s ease;
}
.archived-back .bi-arrow-left { font-size: 15px; }
.archived-back:focus-visible { outline: 3px solid var(--focus-ring); outline-offset: 2px; }
/* Archived rows: Reorder At, Order Qty and Stock Level don't apply. */
.data-table tr.is-archived td.inv-na { color: #9A9C8F; }
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
.btn-restore:active { transform: translateY(0); }
.btn-restore:focus-visible { outline: 3px solid var(--focus-ring); outline-offset: 2px; }
#restoreConfirm { z-index: 1010; }


/* Summary cards are links: each applies its status filter. */
a.stat-pill { text-decoration: none; cursor: pointer; transition: box-shadow 0.15s ease; }
/* Hover cue without movement: a soft shadow (same on every clickable card). */
a.stat-pill:hover { box-shadow: 0 2px 8px rgba(31, 38, 23, .08); }
a.stat-pill:focus-visible { outline: 3px solid var(--focus-ring); outline-offset: 2px; }
a.stat-pill.is-active { box-shadow: inset 0 0 0 2px currentColor; }

/* Below a 1340px-wide window the tab row is tighter still, and the Archived link shows
   just its icon + count ("Archived" stays for screen readers and in the
   tooltip), so the tabs and Archived still fit on one row at 1280px. */
@media (max-width: 1339px) {
    .underline-tabs { gap: 2px; }
    .underline-tabs a { padding-left: 5px; padding-right: 5px; }
    .underline-tabs a.archived-link { padding-left: 8px; padding-right: 8px; }
    .archived-link .archived-label {
        position: absolute; width: 1px; height: 1px; overflow: hidden;
        clip: rect(0 0 0 0); white-space: nowrap;
    }
}


/* Summary cards are toggles; "Clear filters" sits after the filters. */
a.stat-pill[aria-pressed="true"]:hover { box-shadow: inset 0 0 0 2px currentColor, 0 2px 8px rgba(31, 38, 23, .08); }

/* Size filter: disabled ("No sizes") for categories without sized items. */
.size-filter-wrap { display: inline-flex; }
.size-filter-wrap select:disabled { background-color: #F5F3EE; color: #8A8D7E; cursor: not-allowed; opacity: 1; }
</style>

<div class="page-wrap">

        <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>

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
                    return site_url('admin/inventory') . ($q ? '?' . http_build_query($q) : '');
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
                    <?php // Visible whenever the box has text, focused or not. ?>
                    <button type="button" class="inv-search-clear" id="searchClear" aria-label="Clear search"<?= ($searchInput ?? $filterSearch ?? '') === '' ? ' hidden' : '' ?>><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <p id="searchError" class="search-error" role="alert"<?= $searchError ? '' : ' hidden' ?>>
                    <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                    <span><?= esc($searchError ?? \App\Models\ProductModel::SEARCH_ERROR) ?></span>
                </p>
            </div>

            <select id="categoryFilter" class="<?= $filterCategory !== 'All' ? 'has-value' : '' ?>">
                <option value="All">All categories</option>
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
                <option value="archived" <?= $showArchived ? 'selected' : '' ?>>Archived</option>
            </select>

            <?php
            // "Clear all": only when any filter is on. Each filter counts once: the category tab
            // and dropdown are one filter, a stat card and the Status dropdown are one, and
            // Archived is a Status value too.
            $activeFilters = (int) (($search ?? '') !== '' || ($searchInput ?? '') !== '')
                + (int) (($category ?? 'All') !== 'All' && ($category ?? '') !== '')
                + (int) (($size ?? '') !== '')
                + (int) (($status ?? '') !== '');
            $keepPer = isset($_GET['per_page']) ? '?' . http_build_query(['per_page' => $_GET['per_page']]) : '';
            ?>
            <a href="<?= site_url('admin/inventory') . $keepPer ?>" class="clear-filters" id="clearFilters"
               aria-label="Clear all filters (<?= $activeFilters ?> active)"<?= $activeFilters ? '' : ' hidden' ?>>
                Clear all <span class="clear-filters-count" id="clearFiltersCount" aria-hidden="true"><?= $activeFilters ?></span>
            </a>
        </div>

        <?php $categoryCounts = $categoryCounts ?? []; ?>
        <div class="underline-tabs">
            <?php $keepPerPage = (int) ($perPage ?? 10) !== (int) (($perPageOptions ?? [10])[0]) ? ['per_page' => (int) $perPage] : []; ?>
            <a href="<?= site_url('admin/inventory') . ($keepPerPage ? '?' . http_build_query($keepPerPage) : '') ?>" class="<?= $filterCategory === 'All' && ! $showArchived ? 'active' : '' ?>">All Items (<?= number_format($categoryCounts['All'] ?? 0) ?>)</a>
                        <?php foreach ($categories as $itemCategory): ?>
                <a href="<?= site_url('admin/inventory') . '?' . http_build_query(['category' => $itemCategory] + $keepPerPage) ?>" class="<?= $filterCategory === $itemCategory && ! $showArchived ? 'active' : '' ?>"><?= esc($itemCategory) ?> (<?= number_format($categoryCounts[$itemCategory] ?? 0) ?>)</a>
            <?php endforeach; ?>
            <?php /* Always visible, even at 0, so the archive is easy to find. */ ?>
            <a href="<?= site_url('admin/inventory') . '?' . http_build_query(['status' => 'archived'] + $keepPerPage) ?>" class="archived-link<?= $showArchived ? ' active' : '' ?>" title="Archived items"<?= $showArchived ? ' aria-current="page"' : '' ?>>
                <i class="bi bi-archive" aria-hidden="true"></i> <span class="archived-label">Archived</span><?= ($archivedCount ?? 0) > 0 ? ' (' . number_format($archivedCount) . ')' : '' ?>
            </a>
        </div>

        <?php if ($showArchived): ?>
            <div class="archived-banner" role="status">
                <span><i class="bi bi-info-circle" aria-hidden="true"></i> Archived items are hidden everywhere. You can restore them.</span>
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
                            <div class="im-label-row">
                                <label for="item_name">Item name</label>
                                <small class="im-count" id="nameCount">0 / 60</small>
                            </div>
                            <input id="item_name" name="item_name" required aria-describedby="nameCount nameError">
                            <?php // Soft limit: typing past 60 is allowed, but the field turns red and Save is disabled. ?>
                            <small class="im-field-error" id="nameError" role="alert"></small>
                        </div>
                        <div class="im-field">
                            <label for="category">Category</label>
                            <select id="category" name="category" required>
                                <?php foreach ($categories as $itemCategory): ?>
                                    <option value="<?= esc($itemCategory) ?>"><?= esc($itemCategory) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php // One grid cell for every kind of size field, so switching category never moves a field. ?>
                        <div class="im-size-slot">
                            <div class="im-field" id="sizeTextField">
                                <label for="sizeText" id="sizeTextLabel">Size</label>
                                <input id="sizeText" name="size" type="text" maxlength="10" placeholder="e.g. M" autocomplete="off" list="sizeSuggestions">
                                <datalist id="sizeSuggestions"></datalist>
                            </div>
                            <div class="im-field" id="sizeNoneField" hidden>
                                <span class="im-label" id="sizeNoneLabel">Size</span>
                                <div class="im-static" aria-disabled="true" aria-labelledby="sizeNoneLabel">No size for this category</div>
                                <?php // Saving with Patches / Metal sends an empty size, so the server's keep-the-size safety net doesn't apply. ?>
                                <input type="hidden" name="size" id="sizeClear" value="" disabled>
                            </div>
                            <?php // Edit item moving to Patches / Metal. Its line is always reserved, so nothing moves when it appears. ?>
                            <small class="im-size-warn" id="sizeRemoveWarn" role="status"></small>
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
                            <div class="im-label-line">
                                <label for="manual_rop_warning" id="ropLabel"><?= esc(\App\Libraries\Glossary::label('reorder_at')) ?></label><?= \App\Libraries\Glossary::tip('reorder_at', "Reorder Point (ROP): when on hand reaches this number, it's time to order. Calculated from sales history. Low Stock shows when stock is within 50% above it (at least 2 units).") ?>
                            </div>
                            <?php // No calculated ROP yet: an editable starting value. Calculated by the DSS: read-only, not sent. ?>
                            <input id="manual_rop_warning" name="manual_rop_warning" type="number" min="0" step="1" value="0" aria-describedby="ropHelp">
                            <div class="im-static im-auto" id="ropAuto" aria-labelledby="ropLabel" aria-describedby="ropHelp" hidden>
                                <span id="ropAutoValue">—</span> <span class="im-auto-tag"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>Auto</span>
                            </div>
                            <small class="im-help" id="ropHelp">Starting value. The system replaces it once the item has sales.</small>
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
                                <input id="unit_cost" name="unit_cost" type="number" step="0.01" min="0" placeholder="0.00" required aria-describedby="costError">
                            </div>
                            <small class="im-field-error" id="costError" role="alert"></small>
                        </div>
                        <div class="im-field">
                            <label for="selling_price">Selling price</label>
                            <div class="im-prefix">
                                <span aria-hidden="true">₱</span>
                                <input id="selling_price" name="selling_price" type="number" step="0.01" min="0" placeholder="0.00" required aria-describedby="priceError">
                            </div>
                            <small class="im-field-error" id="priceError" role="alert"></small>
                        </div>
                    </div>
                    <p class="im-margin" id="marginLine" aria-live="polite"></p>

                    <div class="im-archive" id="deleteZone" hidden>
                        <div>
                            <strong>Archive this item</strong>
                            <p>Hide this item? You can restore it later.</p>
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
    <div class="inv-modal-card im-confirm" role="alertdialog" aria-modal="true" aria-labelledby="restoreConfirmTitle" aria-describedby="restoreConfirmText restoreConfirmNote">
        <div class="im-confirm-icon is-success" aria-hidden="true"><i class="bi bi-arrow-counterclockwise"></i></div>
        <h2 id="restoreConfirmTitle">Restore item?</h2>
        <p id="restoreConfirmText">It will show again in inventory, POS and alerts.</p>
        <?php // Nothing on hand: say which status it comes back with (same rule as ProductModel::getStatus). ?>
        <p id="restoreConfirmNote" hidden></p>
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
        <p id="archiveConfirmText">Hide this item? You can restore it later.</p>
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

<?php // "Order" opens the shared purchase order dialog (also used by Reorder Alerts). ?>
<?= view('partials/_purchase_order_dialog', ['suppliers' => $suppliers ?? []]) ?>

<script>
// Delegated (not bound per-button): live search replaces the table's rows
// in place, so a listener attached directly to a button at page load would
// stop working on anything fetched in afterward. Delegating from a parent
// that's never replaced keeps this working for every row, old or new.
// The button carries the line of the size it orders (data-line, kept up to date
// when the Size dropdown changes); the order comes back to this page and view.
document.addEventListener('click', event => {
    const button = event.target.closest('.open-order-modal');
    if (!button || !window.PurchaseOrderDialog) return;
    window.PurchaseOrderDialog.open([JSON.parse(button.dataset.line)], {
        opener: button, returnTo: '/admin/inventory', returnQuery: location.search,
    });
});
</script>

<script>
const itemModal = document.getElementById('itemModal');
const itemForm = document.getElementById('itemForm');
const storeUrl = "<?= site_url('admin/inventory/store') ?>";

// One Size field (text + suggestions of every size already used in the
// category) for Clothes, Shoes, Equipment and Accessories; Patches and Metal
// show the disabled "No size for this category" box in the same place, so
// the layout never moves. A disabled field isn't submitted, and the server
// then keeps the item's current size.
const SIZE_SUGGESTIONS = <?= json_encode($sizeSuggestions ?? new stdClass()) ?>;
const SIZE_FIELD_BY_CATEGORY = {
    Clothes:     { label: 'Size', hint: '(optional)',     placeholder: 'e.g. M' },
    Shoes:       { label: 'Size', hint: '(US, optional)', placeholder: 'e.g. 9' },
    Equipment:   { label: 'Size', hint: '(optional)',     placeholder: 'e.g. 32' },
    Accessories: { label: 'Size', hint: '(optional)',     placeholder: 'e.g. 32' },
};

function updateSizeFieldVisibility() {
    const category = document.getElementById('category').value;
    const field = SIZE_FIELD_BY_CATEGORY[category];
    document.getElementById('sizeTextField').hidden = !field;
    document.getElementById('sizeText').disabled = !field;
    document.getElementById('sizeNoneField').hidden = !!field;
    // Patches / Metal: the size is cleared on save. The typed size stays in the
    // (hidden) Size field, so switching back to a sized category restores it.
    document.getElementById('sizeClear').disabled = !!field;
    const warn = document.getElementById('sizeRemoveWarn');
    const current = document.getElementById('sizeText').value.trim().toUpperCase();
    warn.textContent = field || originalStock === null || current === '' ? '' : `Saving removes size ${current}.`;
    if (!field) return;
    // Size is never required (server: permit_empty). "(optional)" / "(US, optional)"
    // is helper text next to the label, not part of it.
    const sizeLabel = document.getElementById('sizeTextLabel');
    sizeLabel.textContent = field.label;
    if (field.hint) {
        const hint = document.createElement('span');
        hint.className = 'im-optional';
        hint.textContent = ' ' + field.hint;
        sizeLabel.append(hint);
    }
    document.getElementById('sizeText').placeholder = field.placeholder;
    const list = document.getElementById('sizeSuggestions');
    list.replaceChildren(...(SIZE_SUGGESTIONS[category] || []).map(s => new Option(s)));
}

document.getElementById('category').addEventListener('change', updateSizeFieldVisibility);

// Size: uppercase as you type, trimmed when you leave the field (max 10 via maxlength).
const sizeText = document.getElementById('sizeText');
sizeText.addEventListener('input', () => {
    const pos = sizeText.selectionStart;
    sizeText.value = sizeText.value.toUpperCase();
    sizeText.setSelectionRange(pos, pos);
});
sizeText.addEventListener('blur', () => { sizeText.value = sizeText.value.trim(); });

// ----- Add / Edit item modal --------------------------------------------
const stockInput = document.getElementById('current_stock');
const ropInput = document.getElementById('manual_rop_warning');

// Reorder At: editable starting value until the DSS calculates a ROP from
// sales; then read-only with "Auto" (and not sent: the server ignores it anyway).
function setRopMode(value, calculatedAt) {
    ropInput.value = value;
    ropInput.hidden = ropInput.disabled = !!calculatedAt;
    document.getElementById('ropAuto').hidden = !calculatedAt;
    document.getElementById('ropAutoValue').textContent = calculatedAt ? value : '—';
    document.getElementById('ropHelp').textContent = calculatedAt
        ? `Calculated from sales every week. Last updated ${calculatedAt}.`
        : 'Starting value. The system replaces it once the item has sales.';
}
const costInput = document.getElementById('unit_cost');
const priceInput = document.getElementById('selling_price');
const submitItemBtn = document.getElementById('submitItemBtn');
const stockReason = document.getElementById('stockReason');
let modalSnapshot = {};
let originalStock = null;   // null in Add mode
let modalOpener = null;

const peso = n => '₱' + Number(n).toLocaleString('en-PH', { maximumFractionDigits: 2 });

// The values that count as "the form" (Size only while its field is enabled).
function formValues() {
    const sizeInput = document.getElementById('sizeText');
    const size = sizeInput.disabled ? '' : sizeInput.value.trim().toUpperCase();
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

function refreshItemModal() {
    // Item name counter (maxlength 60; the server checks the same limit).
    const nameInput = document.getElementById('item_name');
    const nameLen = [...nameInput.value].length;
    const nameCount = document.getElementById('nameCount');
    const nameOver = nameLen > 60;
    nameCount.textContent = `${nameLen} / 60`;
    nameCount.classList.toggle('is-near', nameLen >= 50 && !nameOver);
    nameCount.classList.toggle('is-over', nameOver);

    // Required fields. Empty / too-short messages show only after the user has
    // left the field (touchedFields) and go away as soon as the value is valid;
    // "over 60 characters" shows right away. Nothing is red when the modal opens.
    const trimmedName = [...nameInput.value.trim()].length;
    const costVal = costInput.value.trim(), priceVal = priceInput.value.trim();
    const checks = [
        { input: nameInput, error: 'nameError', missing: trimmedName === 0,
          msg: nameOver ? 'Item name can be up to 60 characters.' : trimmedName < 2 ? 'Enter an item name (at least 2 characters).'
             : serverNameError && serverNameError.key === duplicateKey() ? serverNameError.msg : '' },
        { input: costInput, error: 'costError', missing: costVal === '',
          msg: costVal === '' || !(Number(costVal) > 0) ? 'Enter a unit cost.' : '' },
        { input: priceInput, error: 'priceError', missing: priceVal === '',
          msg: priceVal === '' || !(Number(priceVal) > 0) ? 'Enter a selling price.' : '' },
        { input: document.getElementById('category'), error: null, missing: document.getElementById('category').value === '', msg: '' },
    ];
    for (const c of checks) {
        if (!c.error) continue;
        // Valid again: forget the blur, so a new problem waits for the next one.
        if (c.msg === '') touchedFields.delete(c.input.id);
        const show = c.msg !== '' && (touchedFields.has(c.input.id) || (c.input === nameInput && (nameOver || c.msg === serverNameError?.msg)));
        c.shown = show;
        c.input.classList.toggle('is-invalid', show);
        c.input.setAttribute('aria-invalid', show ? 'true' : 'false');
        const el = document.getElementById(c.error);
        const text = show ? c.msg : '';
        if (el.textContent !== text) el.textContent = text;
    }
    // Footer follows the fields: a problem counts as an error only while its red
    // message shows; until then it's a required field not filled in yet.
    const problemCount = checks.filter(c => c.missing || c.msg).length;
    const errorCount = checks.filter(c => c.shown).length;
    const missingCount = problemCount - errorCount;

    const stock = Number(stockInput.value || 0);
    const rop = Number(ropInput.value || 0);

    const [label, cls] = stockStatus(stock, rop);
    const pill = document.getElementById('imStatus');
    pill.textContent = label;
    pill.className = 'status-pill ' + cls;
    pill.title = stockStatusTitle(label); // Out of Stock explains itself

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
    if (costInput.value.trim() === '' || priceInput.value.trim() === '') {
        // Both prices needed first: no margin and no "lower than unit cost" warning yet.
        marginLine.className = 'im-margin is-pending';
        marginLine.innerHTML = '<i class="bi bi-graph-up-arrow" aria-hidden="true"></i> Margin: —';
    } else if (price < cost) {
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
    // Save only with a change, every required field filled in and no invalid value.
    submitItemBtn.disabled = savingItem || changed === 0 || missingCount > 0 || errorCount > 0;
    // Footer: invalid values (red) first, then empty required fields (gray), else the change count.
    const summary = document.getElementById('changeSummary');
    summary.classList.toggle('is-error', errorCount > 0);
    summary.textContent = errorCount > 0
        ? `Fix ${errorCount} ${errorCount === 1 ? 'error' : 'errors'} to ${originalStock === null ? 'add' : 'save'}`
        : missingCount > 0
            ? `Fill in ${missingCount} required ${missingCount === 1 ? 'field' : 'fields'}`
            : changed === 0 ? 'No changes yet.' : `${changed} ${changed === 1 ? 'change' : 'changes'}`;
}

// A required field is "touched" when the user leaves it. Not when the modal
// leaves it: the automatic focus on Item name only counts once the user has
// typed there, and closing the modal (×, Cancel, Esc) never counts.
// Everything resets each time the modal opens.
const touchedFields = new Set();
const editedFields = new Set();
const REQUIRED_FIELDS = ['item_name', 'unit_cost', 'selling_price'];
let autoFocusedField = null;
itemForm.addEventListener('input', event => {
    if (REQUIRED_FIELDS.includes(event.target.id)) editedFields.add(event.target.id);
}, true);
itemForm.addEventListener('focusout', event => {
    const id = event.target.id;
    if (!REQUIRED_FIELDS.includes(id) || !itemModal.classList.contains('show')) return;
    if (event.relatedTarget && event.relatedTarget.closest('#closeItemModal, #cancelItemModal')) return;
    if (id === autoFocusedField) {
        autoFocusedField = null;
        if (!editedFields.has(id)) return;
    }
    touchedFields.add(id);
    refreshItemModal();
});
itemForm.addEventListener('input', refreshItemModal);
itemForm.addEventListener('change', refreshItemModal);

// Duplicate name + size (checked by the server when saving): the form is sent in
// the background so the modal stays open with the user's input and the message
// shows under Item name. It stays until the name or size changes. Anything else
// goes on as a normal save (the server says which page to open, toast included).
let serverNameError = null;
const duplicateKey = () => {
    const sizeInput = document.getElementById('sizeText');
    return document.getElementById('item_name').value.trim().replace(/\s+/g, ' ').toLowerCase()
        + '|' + (sizeInput.disabled ? '' : sizeInput.value.trim().toUpperCase());
};
// While a save is on its way, Save stays disabled and says "Adding…" / "Saving…",
// so a double click can't send the same item twice.
let savingItem = false;
itemForm.addEventListener('submit', event => {
    if (!window.fetch) return; // plain form post
    event.preventDefault();
    if (savingItem || submitItemBtn.disabled) return;
    savingItem = true;
    const idleLabel = submitItemBtn.textContent;
    submitItemBtn.disabled = true;
    submitItemBtn.textContent = originalStock === null ? 'Adding…' : 'Saving…';
    const settle = () => {
        savingItem = false;
        submitItemBtn.textContent = idleLabel;
        refreshItemModal();
    };
    const failed = () => {
        showToast({ type: 'error', title: "Couldn't save", message: 'Something went wrong. Please try again.' });
        settle();
    };
    fetch(itemForm.action, {
        method: 'POST',
        body: new FormData(itemForm),
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        credentials: 'same-origin',
        redirect: 'manual', // a redirect here means the sign-in page: the session has ended
    })
        .then(response => {
            if (response.type === 'opaqueredirect') {
                // Signed out (session timed out): say so, keep the modal and the input.
                showToast({ type: 'error', title: 'Your session has expired. Please sign in again.' });
                settle();
                return;
            }
            return response.json().then(
                data => ({ status: response.status, data }),
                () => ({ status: response.status, data: null })
            ).then(({ status, data }) => {
                if (status === 422 && data && data.errors && data.errors.item_name) {
                    settle();
                    serverNameError = { msg: data.errors.item_name, key: duplicateKey() };
                    refreshItemModal();
                    document.getElementById('item_name').focus();
                    return;
                }
                if (data && data.redirect) {
                    window.location.href = data.redirect; // Save stays "Adding…" until the page changes
                    return;
                }
                failed();
            });
        })
        .catch(failed);
});

function openItemModal(opener) {
    modalOpener = opener || null;
    touchedFields.clear();
    editedFields.clear();
    serverNameError = null;
    autoFocusedField = 'item_name';
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

// "Add item" in the empty "No items yet" state (delegated: the table is swapped in place).
document.addEventListener('click', event => {
    if (event.target.closest('.inv-empty-add')) document.getElementById('openAddModal').click();
});

document.getElementById('openAddModal').addEventListener('click', event => {
    itemForm.reset();
    itemForm.action = storeUrl;
    originalStock = null;
    document.getElementById('modalTitle').textContent = 'Add item';
    document.getElementById('modalSubtitle').textContent = 'Fill in the details for the new item.';
    submitItemBtn.textContent = 'Add item';
    document.getElementById('deleteZone').hidden = true;
    setRopMode(0, null); // a new item has no calculated ROP yet
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
    // The item's current size, whether or not it's among the suggestions.
    document.getElementById('sizeText').value = item.size || '';
    document.getElementById('supplier_id').value = item.supplier_id || '';
    costInput.value = item.unit_cost || 0;
    priceInput.value = item.selling_price || 0;
    stockInput.value = item.current_stock || 0;
    setRopMode(item.manual_rop_warning || 0, item.rop_calculated_at || null);
    originalStock = Number(item.current_stock || 0);
    updateSizeFieldVisibility(); // again, now that the size and Edit mode are set (size warning)

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

// Sticky table header state: assets/js/inventory-table.js (shared with staff).

// ----- Restore (archived view) -------------------------------------------
let restoreOpener = null;
document.addEventListener('click', event => {
    const button = event.target.closest('.btn-restore');
    if (!button) return;
    restoreOpener = button;
    document.getElementById('restoreConfirmTitle').textContent = `Restore ${button.dataset.itemName || 'this item'}?`;
    const note = document.getElementById('restoreConfirmNote');
    const emptyStock = Number(button.dataset.stock || 0) <= 0;
    note.hidden = !emptyStock;
    note.textContent = !emptyStock ? ''
        : Number(button.dataset.rop || 0) > 0
            ? 'It has 0 on hand, so it will show as Reorder Now in your Inventory.'
            : 'It has 0 on hand, so it will show as Out of Stock.';
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

// Status cell: the badge keeps the item's worst status; when it refers to a size other
// than the selected one, that size goes on a small line under the badge ("Size 2XL"),
// so the narrow Status column never has to fit "Reorder Now · 2XL" in one badge.
function setStatusBadge(row, info) {
    const cell = row.querySelector('.inv-status-cell');
    const pill = cell && cell.querySelector('.status-pill');
    if (!pill || info.status === 'Archived') return;
    pill.textContent = info.status;
    let size = cell.querySelector('.status-size');
    if (info.differs) {
        if (!size) { size = document.createElement('span'); size.className = 'status-size'; pill.after(size); }
        size.textContent = 'Size ' + info.target.size;
    } else if (size) {
        size.remove();
    }
}

function pillClassForStatus(label) {
    return stockStatusColor(label); // assets/js/stock-status.js
}

function applyVariantToRow(row, v) {
    row.querySelector('.inv-item-id').textContent = v.item_id;
    row.querySelector('.inv-stock-cell').textContent = v.current_stock;
    row.querySelector('.inv-updated-cell').textContent = v.updated_at_display || '—';

    // Archived rows keep "—" for Reorder At, Order Qty and Stock Level.
    if (!row.classList.contains('is-archived')) {
        row.querySelector('.inv-rop-cell').textContent = v.manual_rop_warning;
        row.querySelector('.inv-eoq-cell').textContent = (v.eoq_value !== null && v.eoq_value !== undefined) ? v.eoq_value : '—';
        const fill = stockFill(v);
        const bar = row.querySelector('.inv-bar-cell .stock-bar');
        bar.className = 'stock-bar ' + fill.cls + (fill.empty ? ' is-empty' : '');
        bar.title = fill.pct + '% of reorder point + order quantity';
        bar.querySelector('span').style.width = fill.pct + '%';
    }

    // The status badge stays on the item's worst status (set by the server);
    // on hand, ROP and the stock bar follow the selected size.
    // Order shows while any size needs reordering. It orders the selected
    // size if that one needs it, otherwise the size that does.
    const variants = JSON.parse(row.dataset.variants || '[]');
    const selectedIndex = variants.findIndex(x => x.item_id === v.item_id);
    const info = rowStatusText(row, selectedIndex, variants);
    setStatusBadge(row, info);

    const orderBtn = row.querySelector('.open-order-modal');
    if (orderBtn) {
        const target = v.status_label === 'Reorder Now' && !v.open_so ? v : (info.target || v);
        orderBtn.style.display = row.dataset.rowStatus === 'Reorder Now' ? '' : 'none';
        // Always just "Order"; the size is in the badge, the modal and this tooltip.
        if (target.size) orderBtn.title = 'Orders size ' + target.size; else orderBtn.removeAttribute('title');
        orderBtn.dataset.itemId = target.item_id;
        orderBtn.dataset.line = JSON.stringify(target.po); // its line in the purchase order dialog
    }

    const editBtn = row.querySelector('.edit-row');
    if (editBtn) editBtn.dataset.item = JSON.stringify(v);

    const restoreBtn = row.querySelector('.btn-restore');
    if (restoreBtn) {
        restoreBtn.dataset.itemId = v.item_id;
        restoreBtn.dataset.itemName = v.item_name;
        restoreBtn.dataset.stock = v.current_stock;
        restoreBtn.dataset.rop = v.manual_rop_warning;
    }
}

// Delegated so it keeps working after live search swaps the table rows.
document.addEventListener('change', event => {
    const select = event.target.closest('.inv-size-select');
    if (!select) return;
    const row = select.closest('tr');
    const variants = JSON.parse(row.dataset.variants || '[]');
    const v = variants[select.value];
    syncSizeLabel(select);
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

// "Clear all [n]": shown only while a filter is on, with how many (each counted
// once: search, category, size, status / archived). Live search changes the URL
// without a reload, so re-check after each search.
function syncClearFilters() {
    const link = document.getElementById('clearFilters');
    if (!link) return;
    const q = new URL(window.location.href).searchParams;
    const count = ['status', 'size', 'search'].filter(k => (q.get(k) || '') !== '').length
        + ((q.get('category') || 'All') !== 'All' ? 1 : 0);
    link.hidden = count === 0;
    document.getElementById('clearFiltersCount').textContent = count;
    link.setAttribute('aria-label', `Clear all filters (${count} active)`);
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
                updateStickyHeader();
                window.history.replaceState({}, '', url.toString());
                syncClearFilters();
                syncReturnQuery();
            });
    }

    // "Clear search" (empty-results state, delegated since the table is
    // swapped in place) and the clear button in the search box.
    const searchClear = document.getElementById('searchClear');
    const syncSearchClear = () => { searchClear.hidden = searchInput.value === ''; };
    document.addEventListener('click', event => {
        if (!event.target.closest('.btn-clear-search, #searchClear')) return;
        searchInput.value = '';
        searchInput.dispatchEvent(new Event('input'));
        searchInput.focus();
    });

    searchInput.addEventListener('input', () => {
        syncSearchClear();
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
