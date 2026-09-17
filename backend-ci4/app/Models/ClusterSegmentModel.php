<?php

namespace App\Models;

use CodeIgniter\Model;

class ClusterSegmentModel extends Model
{
    protected $table            = 'cluster_segments';
    protected $primaryKey       = 'item_id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'item_id',
        'cluster_label',
        'cluster_name',
    ];

    protected $useTimestamps = false;

    public function distribution(): array
    {
        return $this->select('cluster_name, COUNT(*) as total')
            ->groupBy('cluster_name')
            ->findAll();
    }
}
