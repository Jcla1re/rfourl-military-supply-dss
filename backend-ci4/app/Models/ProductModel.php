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
        if ($stock <= $rop * 1.5) {
            return ['label' => 'Low Stock', 'class' => 'status-low-stock'];
        }
        return ['label' => 'In Stock', 'class' => 'status-in-stock'];
    }
}