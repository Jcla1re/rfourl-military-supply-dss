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

    public function generateNextId(): string
    {
        $count = $this->countAll();
        return 'ITM-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
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