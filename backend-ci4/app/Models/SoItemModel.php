<?php

namespace App\Models;

use CodeIgniter\Model;

class SoItemModel extends Model
{
    protected $table            = 'so_item';
    protected $primaryKey       = 'so_item_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'so_id',
        'item_id',
        'order_quantity',
        'unit_price',
    ];

    protected $useTimestamps = false;

    protected $validationRules = [
        'so_id'          => 'required',
        'item_id'        => 'required',
        'order_quantity' => 'required|integer|greater_than[0]',
        'unit_price'     => 'required|numeric',
    ];

    public function forOrder(string $soId): array
    {
        return $this->select('so_item.*, products.item_name, products.category')
            ->join('products', 'products.item_id = so_item.item_id', 'left')
            ->where('so_id', $soId)
            ->findAll();
    }
}
