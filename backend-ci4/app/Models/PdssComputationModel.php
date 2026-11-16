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
        'eoq_raw', // EOQ before the minimum order quantity (NULL on rows saved before it existed)
        'triggered_by',
    ];

    protected $useTimestamps = false;

    /**
     * Latest computation per item.
     */
    public function latestFor(string $itemId): ?array
    {
        // Secondary sort on computation_id: a batched dss:run insert can
        // give many rows the exact same computed_at (one CURRENT_TIMESTAMP
        // per INSERT statement, not per row), so computed_at alone doesn't
        // reliably break ties to the row that was actually written last.
        return $this->where('item_id', $itemId)
            ->orderBy('computed_at', 'DESC')
            ->orderBy('computation_id', 'DESC')
            ->first();
    }

    /**
     * Latest computation for every item that has one (used by the
     * procurement report table).
     */
    /**
     * When the item's Reorder At (ROP) was last calculated from sales, or null
     * when it has no calculated ROP (new item, or no sales in the demand window:
     * the latest run gave ROP 0). Then the item's current value is either 0 or one
     * the Owner typed (products.rop_source 'manual', which a ROP-0 run keeps).
     */
    public function ropCalculatedAt(string $itemId): ?string
    {
        $latest = $this->where('item_id', $itemId)->orderBy('computation_id', 'DESC')->first();

        return $latest && (int) $latest['reorder_point'] > 0 ? $latest['computed_at'] : null;
    }

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
