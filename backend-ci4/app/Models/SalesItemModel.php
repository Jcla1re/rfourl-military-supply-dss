<?php

namespace App\Models;

use CodeIgniter\Model;

class SalesItemModel extends Model
{
    protected $table            = 'sales_item';
    protected $primaryKey       = 'sales_item_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'sales_id',
        'item_id',
        'quantity_sold',
        'selling_price',
    ];

    protected $useTimestamps = false;

    public function forSale(int $salesId): array
    {
        return $this->select('sales_item.*, products.item_name')
            ->join('products', 'products.item_id = sales_item.item_id', 'left')
            ->where('sales_id', $salesId)
            ->findAll();
    }

    public function topSellers(int $limit = 5): array
    {
        return $this->select('sales_item.item_id, products.item_name, SUM(sales_item.quantity_sold) as units_sold')
            ->join('products', 'products.item_id = sales_item.item_id', 'left')
            ->groupBy('sales_item.item_id')
            ->orderBy('units_sold', 'DESC')
            ->findAll($limit);
    }
}
