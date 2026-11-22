<?php
// app/Models/SupplierModel.php

namespace App\Models;

use CodeIgniter\Model;

class SupplierModel extends Model
{
    protected $table            = 'suppliers';
    protected $primaryKey       = 'supplier_id'; // string PK, not auto-increment
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    /** The payment terms a supplier can be given (optional). 'Cash or Check' is for suppliers that accept both. */
    public const PAYMENT_TERMS = ['Cash', 'Check', 'Cash or Check', 'Other'];

    protected $allowedFields = [
        'supplier_id',
        'company_name',
        'category',
        'lead_time_days',
        'preferred_courier',
        'payment_terms',
        'contact_person',
        'contact_email',
        'contact_number',
        'is_active',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'supplier_id'    => 'required|is_unique[suppliers.supplier_id]',
        'company_name'   => 'required',
        'lead_time_days' => 'required|integer',
    ];

    /**
     * The next real supplier ID: one more than the highest SUP-### number there is. Not a row count, so
     * sample suppliers (SMP-###) and gaps in the numbers never make it hand out an ID that is already
     * taken; existing IDs are never changed.
     */
    public function generateNextId(): string
    {
        $highest = 0;
        foreach ($this->select('supplier_id')->like('supplier_id', 'SUP-', 'after')->findAll() as $row) {
            if (preg_match('/^SUP-(\d+)$/', (string) $row['supplier_id'], $m)) {
                $highest = max($highest, (int) $m[1]);
            }
        }

        return 'SUP-' . str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Real suppliers only (is_sample = 0): the ones that can be an item's main supplier or receive an order.
     * Sample suppliers (php spark sample-suppliers load) are only there for price comparison.
     */
    public function real(): static
    {
        return $this->where('suppliers.is_sample', 0);
    }
}