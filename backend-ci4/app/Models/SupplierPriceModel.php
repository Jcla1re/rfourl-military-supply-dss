<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Offers from suppliers other than an item's main supplier (table supplier_prices), for comparison only.
 * Nothing here feeds Lead time, Safety Stock, ROP, EOQ or K-Means: those use the main supplier
 * (products.supplier_id) alone.
 */
class SupplierPriceModel extends Model
{
    protected $table            = 'supplier_prices';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = ['supplier_id', 'item_id', 'unit_price', 'lead_time_days', 'min_order_qty', 'is_sample'];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'supplier_id' => 'required',
        'item_id'     => 'required',
        'unit_price'  => 'required|numeric|greater_than_equal_to[0]',
    ];

    /**
     * Every offer for one item: the main supplier's first (its cost, its lead time and the item's supplier
     * minimum, from products and suppliers), then the price-list offers, cheapest first.
     *
     * Each offer: supplier_id, company_name, is_main, is_sample, unit_price (float), lead_time_days (the
     * offer's own, else the supplier's), lead_time_from ('supplier' | 'offer'), min_order_qty (null = no
     * minimum), payment_terms. An item without a main supplier has no main offer.
     *
     * @return list<array<string, mixed>>
     */
    public function offersForItem(string $itemId): array
    {
        $product = (new ProductModel())->find($itemId);
        if (! $product) {
            return [];
        }

        $offers = [];
        $mainId = (string) ($product['supplier_id'] ?? '');
        if ($mainId !== '') {
            $main = (new SupplierModel())->find($mainId);
            if ($main) {
                $offers[] = [
                    'supplier_id'    => $mainId,
                    'company_name'   => (string) $main['company_name'],
                    'is_main'        => true,
                    'is_sample'      => (bool) ($main['is_sample'] ?? 0),
                    'unit_price'     => (float) $product['unit_cost'],
                    'lead_time_days' => (int) $main['lead_time_days'],
                    'lead_time_from' => 'supplier',
                    'min_order_qty'  => ! empty($product['supplier_min_qty']) ? (int) $product['supplier_min_qty'] : null,
                    'payment_terms'  => $main['payment_terms'] ?? null,
                ];
            }
        }

        $rows = $this->select('supplier_prices.*, suppliers.company_name, suppliers.lead_time_days AS supplier_lead_time, suppliers.payment_terms, suppliers.is_sample AS supplier_is_sample')
            ->join('suppliers', 'suppliers.supplier_id = supplier_prices.supplier_id')
            ->where('supplier_prices.item_id', $itemId)
            ->where('supplier_prices.supplier_id !=', $mainId) // the main supplier's offer is the product's own
            ->orderBy('supplier_prices.unit_price', 'ASC')
            ->orderBy('suppliers.company_name', 'ASC')
            ->findAll();

        foreach ($rows as $r) {
            $own      = $r['lead_time_days'] !== null;
            $offers[] = [
                'supplier_id'    => (string) $r['supplier_id'],
                'company_name'   => (string) $r['company_name'],
                'is_main'        => false,
                'is_sample'      => (bool) $r['supplier_is_sample'] || (bool) $r['is_sample'],
                'unit_price'     => (float) $r['unit_price'],
                'lead_time_days' => $own ? (int) $r['lead_time_days'] : (int) $r['supplier_lead_time'],
                'lead_time_from' => $own ? 'offer' : 'supplier',
                'min_order_qty'  => $r['min_order_qty'] !== null ? (int) $r['min_order_qty'] : null,
                'payment_terms'  => $r['payment_terms'] ?? null,
            ];
        }

        return $offers;
    }
}
