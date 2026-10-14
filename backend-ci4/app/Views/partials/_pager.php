<?php
// app/Views/partials/_pager.php
// Shared table footer: "Showing 1–10 of 562 items", a rows-per-page picker
// and a shortened pager (‹ Prev 1 … 24 25 26 … 57 Next ›) that always keeps
// the first page, the last page and the pages around the current one, so it
// stays ~7 buttons wide no matter how many pages there are.
//
// Expects: $baseUrl, $currentPage, $totalPages, $totalItems, $perPage,
// $perPageOptions, $shownCount. Current query-string filters are preserved
// on every link.

$currentPage = max(1, (int) $currentPage);
$totalPages  = max(1, (int) $totalPages);
$totalItems  = (int) $totalItems;
$perPage     = (int) $perPage;
$firstShown  = $totalItems > 0 ? ($currentPage - 1) * $perPage + 1 : 0;
$lastShown   = $totalItems > 0 ? $firstShown + (int) $shownCount - 1 : 0;

$pageUrl = static function (int $page) use ($baseUrl): string {
    $params = $_GET;
    $params['page'] = $page;
    return $baseUrl . '?' . http_build_query($params);
};

// Build the list of page numbers to show; null marks an ellipsis gap.
if ($totalPages <= 7) {
    $pages = range(1, $totalPages);
} elseif ($currentPage <= 4) {
    $pages = [1, 2, 3, 4, 5, null, $totalPages];
} elseif ($currentPage >= $totalPages - 3) {
    $pages = [1, null, $totalPages - 4, $totalPages - 3, $totalPages - 2, $totalPages - 1, $totalPages];
} else {
    $pages = [1, null, $currentPage - 1, $currentPage, $currentPage + 1, null, $totalPages];
}
?>
<div class="inv-table-footer pager-footer">
    <div class="pager-info">
        <?php if ($totalItems === 0): ?>
            <span class="text-muted small">No results</span>
        <?php else: ?>
            <span class="text-muted small">
                Showing <strong><?= number_format($firstShown) ?>&ndash;<?= number_format($lastShown) ?></strong>
                of <strong><?= number_format($totalItems) ?></strong> items
            </span>
            <label class="pager-per-page">
                <span>Rows per page</span>
                <select class="per-page-select">
                    <?php foreach ($perPageOptions as $option): ?>
                        <option value="<?= (int) $option ?>" <?= (int) $option === $perPage ? 'selected' : '' ?>><?= (int) $option ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="admin-pagination" aria-label="Pagination">
            <?php if ($currentPage > 1): ?>
                <a href="<?= $pageUrl($currentPage - 1) ?>"><i class="bi bi-chevron-left"></i> Prev</a>
            <?php else: ?>
                <span class="disabled" aria-disabled="true"><i class="bi bi-chevron-left"></i> Prev</span>
            <?php endif; ?>

            <?php foreach ($pages as $page): ?>
                <?php if ($page === null): ?>
                    <span class="pager-gap">&hellip;</span>
                <?php else: ?>
                    <a href="<?= $pageUrl($page) ?>" class="<?= $page === $currentPage ? 'active' : '' ?>" <?= $page === $currentPage ? 'aria-current="page"' : '' ?>><?= $page ?></a>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($currentPage < $totalPages): ?>
                <a href="<?= $pageUrl($currentPage + 1) ?>">Next <i class="bi bi-chevron-right"></i></a>
            <?php else: ?>
                <span class="disabled" aria-disabled="true">Next <i class="bi bi-chevron-right"></i></span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</div>
