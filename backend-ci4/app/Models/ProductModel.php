<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductModel extends Model
{
    protected $table            = 'products';
    protected $primaryKey       = 'item_id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'item_id',
        'supplier_id',
        'item_name',
        'category',
        'size',
        'unit_cost',
        'selling_price',
        'current_stock',
        'manual_rop_warning',
        'abc_category',
        'is_active',
    ];

    public const CATEGORIES = ['Clothes', 'Shoes', 'Equipment', 'Patches', 'Metal', 'Accessories'];

    /**
     * Inventory search rule: letters (incl. ñ/Ñ), numbers, spaces and the
     * symbols that appear in item names (- : / & . ' #), max 50 characters.
     * The search box checks the same rule in the browser
     * (admin/inventory.php, staff/inventory.php); keep them in sync.
     */
    public const SEARCH_PATTERN = "/^[\\p{L}\\p{N}\\s\\-:\\/&.'#]{0,50}$/uD";
    public const SEARCH_ERROR   = "Use letters, numbers, spaces, or - : / & . ' # only.";

    /**
     * Validates a raw ?search= value.
     *
     * Returns [term to search by, text to show in the search box, error].
     * An invalid term is ignored (all items are shown) and the error is
     * passed to the view, which shows it under the search box.
     *
     * @param mixed $raw
     *
     * @return array{0: string, 1: string, 2: string|null}
     */
    public static function validateSearch($raw): array
    {
        if (! is_string($raw)) {
            return ['', '', null];
        }

        $term = trim($raw);
        if ($term === '') {
            return ['', '', null];
        }

        if (preg_match(self::SEARCH_PATTERN, $term) === 1) {
            return [$term, $term, null];
        }

        return ['', mb_substr($term, 0, 50), self::SEARCH_ERROR];
    }

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'item_name'     => 'required',
        'category'      => 'required',
        'unit_cost'     => 'required|numeric',
        'selling_price' => 'required|numeric',
        'current_stock' => 'required|integer',
    ];

    public function getLowStock()
    {
        return $this->where('current_stock <=', 'manual_rop_warning', false)
                    ->findAll();
    }

    /**
     * Plain numeric ID (e.g. "0563"), no "ITM-" prefix. Existing products
     * keep their old "ITM-####" ids — item_id is a real FK (RESTRICT) in
     * six other tables (sales_item, so_item, inventory_log, reorder_alert,
     * cluster_segments, pdss_computation), so renaming them retroactively
     * would mean dropping and rebuilding every one of those constraints.
     * This only changes the format for newly added items going forward.
     */
    public function generateNextId(): string
    {
        $count = $this->countAll();
        return str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Active products grouped by item_name+category — e.g. "Goa Pants" size
     * S, M, and L are three fully independent rows (own item_id, stock,
     * ROP, and sales/order history), but the POS, Stock Ordering, and
     * Inventory screens show them as one logical item with a size picker
     * underneath, rather than three unrelated-looking cards/rows. Grouping
     * happens here at query time — no schema change, no new table.
     */
    public function groupedActive(): array
    {
        $products = $this->where('is_active', 1)
            ->orderBy('item_name', 'ASC')
            ->orderBy('size', 'ASC')
            ->findAll();

        return $this->groupRows($products);
    }

    /**
     * Same item_name+category grouping as groupedActive(), but over an
     * already-fetched row list — lets a caller apply its own filters/status
     * annotations first (e.g. Inventory's search/category/size/status
     * filters) and then group what's left, instead of grouping everything
     * and filtering groups afterward.
     */
    /**
     * Inventory search match: the term is found in the item name (partial,
     * case-insensitive: "shirt" finds "06 Shirt") or in the item ID, where
     * leading zeros don't matter ("146", "0146" and "00146" all find 0146).
     */
    public static function matchesSearch(array $product, string $term): bool
    {
        $needle = mb_strtolower(trim($term));
        if ($needle === '') {
            return true;
        }

        $name = mb_strtolower((string) ($product['item_name'] ?? ''));
        $id   = mb_strtolower((string) ($product['item_id'] ?? ''));

        if (str_contains($name, $needle) || str_contains($id, $needle)) {
            return true;
        }

        // Numeric IDs: compare without leading zeros so extra zeros match too.
        return ctype_digit($needle) && ctype_digit($id)
            && ltrim($needle, '0') === ltrim($id, '0');
    }

    /**
     * How many inventory table rows each category has, plus 'All'.
     *
     * One row = one item (sizes of the same item are grouped, exactly as the
     * Inventory table shows them), counted over active items only. This is
     * the single source for the Inventory category tabs, the "All Items"
     * count and the dashboard's category chart, so they always agree.
     *
     * @param array|null $products Active product rows (fetched when null).
     *
     * @return array<string, int> ['All' => n, 'Clothes' => n, ...] in tab order
     */
    public function countRowsByCategory(?array $products = null): array
    {
        $products ??= $this->where('is_active', 1)->findAll();

        $counts = array_fill_keys(self::CATEGORIES, 0);
        foreach ($this->groupRows($products) as $group) {
            $category          = (string) ($group['category'] ?? '');
            $counts[$category] = ($counts[$category] ?? 0) + 1;
        }

        return ['All' => array_sum($counts)] + $counts;
    }

    public function groupRows(array $products): array
    {
        $groups = [];
        foreach ($products as $p) {
            $key = $p['item_name'] . '|' . $p['category'];

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'item_name'   => $p['item_name'],
                    'category'    => $p['category'],
                    'variants'    => [],
                    'total_stock' => 0,
                ];
            }

            $groups[$key]['variants'][]   = $p;
            $groups[$key]['total_stock'] += (int) $p['current_stock'];
        }

        return array_values($groups);
    }

    /** Stock up to this multiple of the reorder point is shown as "Low Stock" (120%). */
    public const LOW_STOCK_BAND = 1.2;

    public function getStatus(array $product): array
    {
        $stock = (int) $product['current_stock'];
        $rop   = (int) ($product['manual_rop_warning'] ?? 0);

        // Zero (or negative) on-hand stock is always a reorder condition,
        // regardless of ROP — an item that has never sold gets ROP=0 from
        // the DSS engine, but that must never be read as "safe to be at
        // zero units." Checking this first prevents a literal stockout
        // from ever being displayed as "In Stock".
        if ($stock <= 0) {
            return ['label' => 'Reorder Now', 'class' => 'status-reorder'];
        }
        if ($rop <= 0) {
            return ['label' => 'In Stock', 'class' => 'status-in-stock'];
        }
        if ($stock <= $rop) {
            return ['label' => 'Reorder Now', 'class' => 'status-reorder'];
        }
        if ($stock <= $rop * self::LOW_STOCK_BAND) {
            return ['label' => 'Low Stock', 'class' => 'status-low-stock'];
        }
        return ['label' => 'In Stock', 'class' => 'status-in-stock'];
    }
}