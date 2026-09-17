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

    protected $allowedFields = [
        'supplier_id',
        'company_name',
        'category',
        'lead_time_days',
        'preferred_courier',
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

    public function generateNextId(): string
    {
        $count = $this->countAll();
        return 'SUP-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);
    }
}