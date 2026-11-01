<?php
// app/Views/partials/_inventory_empty.php
// Inventory table (Owner + Staff) with no rows and no search: says why it's empty.
//   1. A status filter found nothing (with or without category / size): good news, green check.
//   2. No items at all (no filters): "No items yet" (+ "Add item" for the owner).
//   3. Anything else: "No items match these filters" + "Clear filters" (same as Clear all).
// Search empty states stay in the table partials.
//
// Expects: $colspan, $category, $size, $status, $clearUrl, $canAdd.
$categoryName = ($category ?? 'All') !== 'All' ? (string) $category : '';
$status       = (string) ($status ?? '');
$hasFilters   = $categoryName !== '' || ($size ?? '') !== '' || $status !== '';

$good = [
    'Out of Stock' => [
        $categoryName !== '' ? "No {$categoryName} items are out of stock" : 'No items are out of stock',
        'Every item has at least 1 unit on hand.',
    ],
    'Low Stock' => [
        $categoryName !== '' ? "No {$categoryName} items are low on stock" : 'No items are low on stock',
        'Stock levels look healthy.',
    ],
    'Reorder Now' => [
        $categoryName !== '' ? "Nothing in {$categoryName} needs reordering right now" : 'Nothing needs reordering right now',
        'All items are above their reorder point.',
    ],
];

if (isset($good[$status])) {
    [$kind, $icon, [$title, $line]] = ['good', 'bi-check-lg', $good[$status]];
} elseif (! $hasFilters) {
    [$kind, $icon, $title] = ['none', 'bi-box-seam', 'No items yet'];
    $line = $canAdd ? 'Add your first item to start tracking stock.' : 'Items the owner adds will show here.';
} else {
    [$kind, $icon, $title, $line] = ['filters', 'bi-funnel', 'No items match these filters', ''];
}
?>
<tr>
    <td colspan="<?= (int) $colspan ?>" class="inv-empty-state is-<?= $kind ?>">
        <div class="inv-empty-icon" aria-hidden="true"><i class="bi <?= $icon ?>"></i></div>
        <div class="inv-empty-title"><?= esc($title) ?></div>
        <?php if ($line !== ''): ?><p class="inv-empty-line"><?= esc($line) ?></p><?php endif; ?>
        <?php if ($kind === 'none' && $canAdd): ?>
            <button type="button" class="btn btn-success inv-empty-add"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add item</button>
        <?php elseif ($kind === 'filters'): ?>
            <a href="<?= esc($clearUrl, 'attr') ?>" class="inv-empty-clear">Clear filters</a>
        <?php endif; ?>
    </td>
</tr>
