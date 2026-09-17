<?php

namespace App\Models;

use CodeIgniter\Model;

class ReorderAlertModel extends Model
{
    protected $table            = 'reorder_alert';
    protected $primaryKey       = 'alert_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'item_id',
        'computation_id',
        'trigger_reason',
        'recommended_eoq',
        'stock_at_trigger',
        'status',
    ];

    protected $useTimestamps = false;

    public function openAlerts(): array
    {
        return $this->select('reorder_alert.*, products.item_name, products.category, products.current_stock, products.supplier_id')
            ->join('products', 'products.item_id = reorder_alert.item_id', 'left')
            ->where('reorder_alert.status', 'Open')
            ->orderBy('reorder_alert.alert_timestamp', 'DESC')
            ->findAll();
    }

    public function resolvedThisWeek(): int
    {
        return $this->where('status', 'Resolved')
            ->where('alert_timestamp >=', date('Y-m-d H:i:s', strtotime('-7 days')))
            ->countAllResults();
    }
}
