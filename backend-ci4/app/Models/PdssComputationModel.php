<?php

namespace App\Models;

use CodeIgniter\Model;

class PdssComputationModel extends Model
{
    protected $table            = 'pdss_computation';
    protected $primaryKey       = 'computation_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'item_id',
        'annual_demand',
        'avg_daily_demand',
        'demand_std_dev',
        'lead_time_days',
        'service_level_z',
        'safety_stock',
        'reorder_point',
        'eoq_value',
        'triggered_by',
    ];

    protected $useTimestamps = false;

    /**
     * Latest computation per item.
     */
    public function latestFor(string $itemId): ?array
    {
        return $this->where('item_id', $itemId)
            ->orderBy('computed_at', 'DESC')
            ->first();
    }

    /**
     * Latest computation for every item that has one (used by the
     * procurement report table).
     */
    public function latestPerItem(): array
    {
        $db = $this->db;

        $sub = $db->table($this->table)
            ->select('item_id, MAX(computation_id) AS latest_id')
            ->groupBy('item_id');

        return $this->select('pdss_computation.*')
            ->join("({$sub->getCompiledSelect()}) latest", 'latest.latest_id = pdss_computation.computation_id')
            ->findAll();
    }
}
