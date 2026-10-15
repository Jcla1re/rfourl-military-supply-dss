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

    /**
     * Stock-level bar for one size:
     *   fill  = on hand ÷ (ROP + EOQ), capped at 100%
     *           (ROP + EOQ is roughly the stock right after a full reorder);
     *           when ROP + EOQ is 0 there's no target, so any stock fills it;
     *   color = this size's own status (red Reorder Now, amber Low Stock,
     *           green In Stock);
     *   empty = on hand is 0 (drawn with a red outline, not as "no data").
     * The Inventory pages repeat this in JS (stockFill) for size switching.
     *
     * @return array{pct: int, class: string, empty: bool}
     */
    public function stockFill(array $p): array
    {
        $stock  = (int) ($p['current_stock'] ?? 0);
        $target = (int) ($p['manual_rop_warning'] ?? 0) + (int) ($p['eoq_value'] ?? 0);
        $pct    = $stock <= 0 ? 0 : ($target > 0 ? (int) min(100, round($stock / $target * 100)) : 100);
        $label  = ($p['status'] ?? $this->getStatus($p))['label'];
        $class  = match ($label) {
            'Reorder Now' => 'red',
            'Low Stock'   => 'amber',
            'Archived'    => 'archived',
            default       => 'green',
        };

        return ['pct' => $pct, 'class' => $class, 'empty' => $stock <= 0];
    }

    /** Letter sizes, smallest first (2XL = XXL, 3XL = XXXL). */
    private const LETTER_RANK = ['XS' => 0, 'S' => 1, 'M' => 2, 'L' => 3, 'XL' => 4, '2XL' => 5, 'XXL' => 5, '3XL' => 6, 'XXXL' => 6, '4XL' => 7];

    /**
     * Natural size order: letter sizes XS → S → M → L → XL → 2XL → 3XL, then
     * letter+waist sets ("L+33") by letter then waist, then numbers ascending
     * (4, 5 … 10, 10.5, 11), then anything else alphabetically.
     */
    public static function sortSizes(array $sizes): array
    {
        $key = static function (string $s): array {
            $u = strtoupper(trim($s));
            if (isset(self::LETTER_RANK[$u])) {
                return [0, self::LETTER_RANK[$u], 0, ''];
            }
            if (preg_match('/^([A-Z0-9]+)\+(\d+(?:\.\d+)?)$/', $u, $m) && isset(self::LETTER_RANK[$m[1]])) {
                return [1, self::LETTER_RANK[$m[1]], (float) $m[2], ''];
            }
            if (is_numeric($u)) {
                return [2, (float) $u, 0, ''];
            }

            return [3, 0, 0, $u];
        };
        usort($sizes, static fn ($a, $b) => $key((string) $a) <=> $key((string) $b));

        return $sizes;
    }

    /** Sorted distinct sizes per category, from the given product rows. */
    public function sizesByCategory(array $products): array
    {
        $by = [];
        foreach ($products as $p) {
            if (($p['size'] ?? '') !== '' && $p['size'] !== null) {
                $by[$p['category']][$p['size']] = true;
            }
        }

        // array keys turn numeric sizes ("10") into ints; cast back to strings.
        return array_map(fn ($set) => self::sortSizes(array_map('strval', array_keys($set))), $by);
    }

    /**
     * Size filter options for the selected category (only sizes that exist
     * there, naturally sorted). With All categories they're grouped:
     * Clothing (letters), Clothing + waist ("L+33"), Waist (28–44), Shoes,
     * Other. 'disabled' is true when the category has no sized items.
     *
     * @return array{disabled: bool, groups: array<string, string[]>, all: string[]}
     */
    public function sizeOptions(array $products, ?string $category): array
    {
        $byCategory = $this->sizesByCategory($products);

        if ($category !== null && $category !== '' && $category !== 'All') {
            $list = $byCategory[$category] ?? [];

            return ['disabled' => $list === [], 'groups' => $list ? ['' => $list] : [], 'all' => $list];
        }

        $shoeSizes = array_flip($byCategory['Shoes'] ?? []);
        $all       = self::sortSizes(array_values(array_unique(array_merge([], ...array_values($byCategory ?: [[]])))));
        $groups    = ['Clothing' => [], 'Clothing + waist' => [], 'Waist' => [], 'Shoes' => [], 'Other' => []];
        foreach ($all as $s) {
            $u = strtoupper($s);
            if (isset(self::LETTER_RANK[$u])) {
                $groups['Clothing'][] = $s;
            } elseif (str_contains($s, '+')) {
                $groups['Clothing + waist'][] = $s;
            } elseif (isset($shoeSizes[$s])) {
                $groups['Shoes'][] = $s;
            } elseif (is_numeric($s) && (float) $s >= 28 && (float) $s <= 44) {
                $groups['Waist'][] = $s;
            } else {
                $groups['Other'][] = $s;
            }
        }

        return ['disabled' => $all === [], 'groups' => array_filter($groups), 'all' => $all];
    }

    /** Status priority for an item with several sizes: the worst size wins. */
    public const STATUS_RANK = ['In Stock' => 0, 'Low Stock' => 1, 'Reorder Now' => 2];

    /**
     * Groups rows into items (item_name + category; each size stays its own
     * product row in 'variants'). Each group also gets:
     *   'status'      — the WORST status across its sizes
     *                   (Reorder Now > Low Stock > In Stock), so a size that
     *                   needs reordering is never hidden behind another size;
     *   'worst_index' — which variant has that status (the first such size).
     * Rows without a 'status' are given one via getStatus().
     */
    /** item_id => so_id of its newest open stock order (cached per instance). */
    private ?array $openOrderCache = null;

    /**
     * Items that are on order: in a stock order that hasn't been Delivered or
     * Cancelled yet, wherever it was placed (Inventory, Orders, Reorder
     * Alerts). Once the delivery is received the item drops off on its own.
     *
     * @return array<string, string> item_id => so_id
     */
    public function openOrders(): array
    {
        if ($this->openOrderCache === null) {
            $this->openOrderCache = [];
            $rows = $this->db->table('so_item si')
                ->select('si.item_id, so.so_id')
                ->join('stock_order so', 'so.so_id = si.so_id')
                ->whereNotIn('so.status', ['Delivered', 'Cancelled'])
                ->orderBy('so.so_id', 'DESC')
                ->get()->getResultArray();
            foreach ($rows as $r) {
                $this->openOrderCache[$r['item_id']] ??= $r['so_id'];
            }
        }

        return $this->openOrderCache;
    }

    public function groupRows(array $products): array
    {
        $openOrders = $this->openOrders();
        $groups = [];
        foreach ($products as $p) {
            $p['status'] ??= $this->getStatus($p);
            $p['open_so'] ??= $openOrders[$p['item_id']] ?? null;
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

        // Worst size first; among sizes that need reordering, one that is NOT
        // already on order wins (it's the one that still needs action).
        $score = static fn (array $v): int => (self::STATUS_RANK[$v['status']['label']] ?? 0) * 2
            + (($v['status']['label'] === 'Reorder Now' && empty($v['open_so'])) ? 1 : 0);
        foreach ($groups as &$group) {
            $worst = 0;
            foreach ($group['variants'] as $i => $v) {
                if ($score($v) > $score($group['variants'][$worst])) {
                    $worst = $i;
                }
            }
            $target = $group['variants'][$worst];
            $group['worst_index'] = $worst;
            $group['status']      = $target['status'];
            // Every size that needs reordering is already on order.
            $group['on_order'] = $target['status']['label'] === 'Reorder Now' && ! empty($target['open_so']);
            $group['open_so']  = $group['on_order'] ? $target['open_so'] : null;
        }
        unset($group);

        return array_values($groups);
    }

    /**
     * Items whose worst size needs reordering, split into those that still
     * need action and those already on order (pending delivery).
     *
     * @return array{needs: int, on_order: int}
     */
    public function reorderSummary(?array $products = null): array
    {
        $products ??= $this->where('is_active', 1)->findAll();

        $summary = ['needs' => 0, 'on_order' => 0];
        foreach ($this->groupRows($products) as $group) {
            if ($group['status']['label'] === 'Reorder Now') {
                $summary[$group['on_order'] ? 'on_order' : 'needs']++;
            }
        }

        return $summary;
    }

    /**
     * Items per status (by each item's worst status). Same unit as the
     * category tabs, so In Stock + Low Stock + Reorder Now = All Items.
     *
     * @param array|null $products Active product rows (fetched when null).
     *
     * @return array{'In Stock': int, 'Low Stock': int, 'Reorder Now': int}
     */
    public function statusCounts(?array $products = null): array
    {
        $products ??= $this->where('is_active', 1)->findAll();

        $counts = ['In Stock' => 0, 'Low Stock' => 0, 'Reorder Now' => 0];
        foreach ($this->groupRows($products) as $group) {
            $label = $group['status']['label'];
            if (isset($counts[$label])) {
                $counts[$label]++;
            }
        }

        return $counts;
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