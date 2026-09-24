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

    public function topSellers(int $limit = 5, ?int $year = null): array
    {
        $builder = $this->select('sales_item.item_id, products.item_name, SUM(sales_item.quantity_sold) as units_sold')
            ->join('products', 'products.item_id = sales_item.item_id', 'left');

        if ($year !== null) {
            $builder->join('sales_transaction', 'sales_transaction.sales_id = sales_item.sales_id')
                ->where('YEAR(sales_transaction.sale_date)', $year);
        }

        return $builder->groupBy('sales_item.item_id')
            ->orderBy('units_sold', 'DESC')
            ->findAll($limit);
    }

    /**
     * Revenue per item (quantity_sold * selling_price), ranked highest
     * first. Feeds the ABC (Pareto) classification panel — optionally
     * scoped to a single year to match the Trend Analysis page filter.
     */
    public function revenueByItem(?int $year = null): array
    {
        $builder = $this->select('sales_item.item_id, products.item_name, SUM(sales_item.quantity_sold * sales_item.selling_price) as revenue')
            ->join('products', 'products.item_id = sales_item.item_id', 'left');

        if ($year !== null) {
            $builder->join('sales_transaction', 'sales_transaction.sales_id = sales_item.sales_id')
                ->where('YEAR(sales_transaction.sale_date)', $year);
        }

        return $builder->groupBy('sales_item.item_id')
            ->orderBy('revenue', 'DESC')
            ->findAll();
    }

    /**
     * Units sold per calendar day between two dates (inclusive), optionally
     * scoped to one item. Feeds the Trend Analysis weekly chart's daily
     * series (and its "last week" comparison).
     */
    public function dailyQuantities(string $startDate, string $endDate, ?string $itemId = null): array
    {
        $builder = $this->select("DATE(sales_transaction.sale_date) as d, SUM(sales_item.quantity_sold) as qty")
            ->join('sales_transaction', 'sales_transaction.sales_id = sales_item.sales_id')
            ->where('DATE(sales_transaction.sale_date) >=', $startDate)
            ->where('DATE(sales_transaction.sale_date) <=', $endDate)
            ->groupBy('d');

        if ($itemId) {
            $builder->where('sales_item.item_id', $itemId);
        }

        return $builder->get()->getResultArray();
    }

    /**
     * Latest sale date within a given year (optionally for one item), used
     * as the "as of" anchor for the weekly chart instead of the server's
     * real-world date — this data is historical/seeded, so "today" often
     * has no relationship to whichever year is selected.
     */
    public function maxSaleDateInYear(int $year, ?string $itemId = null): ?string
    {
        $builder = $this->select('MAX(sales_transaction.sale_date) as mx')
            ->join('sales_transaction', 'sales_transaction.sales_id = sales_item.sales_id')
            ->where('YEAR(sales_transaction.sale_date)', $year);

        if ($itemId) {
            $builder->where('sales_item.item_id', $itemId);
        }

        $row = $builder->get()->getRowArray();
        return $row['mx'] ?? null;
    }
}
