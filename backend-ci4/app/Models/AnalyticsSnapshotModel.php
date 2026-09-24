<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * BI reporting ledger (Data Dictionary Table 24). Written by the DSS
 * engine after each run so historical trend/analytics data survives
 * beyond the "latest state" tables (products, cluster_segments, etc.).
 */
class AnalyticsSnapshotModel extends Model
{
    protected $table            = 'analytics_snapshot';
    protected $primaryKey       = 'snapshot_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'user_id',
        'generated_at',
        'metric_type',
        'period',
        'metric_value',
        'raw_data',
    ];

    protected $useTimestamps = false;

    /**
     * Most recent snapshot rows for a given metric_type, newest first.
     */
    public function latestFor(string $metricType, int $limit = 12): array
    {
        return $this->where('metric_type', $metricType)
            ->orderBy('generated_at', 'DESC')
            ->findAll($limit);
    }
}
