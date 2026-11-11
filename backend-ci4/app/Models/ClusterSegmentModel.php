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
        'abc_class',
        'service_level',
        'z_score',
    ];

    protected $useTimestamps = false;

    /** Service level (%) each K-Means class is held to (capstone Objective 4): A 95, B 90, C 85. */
    public const SERVICE_LEVELS = ['A' => 95, 'B' => 90, 'C' => 85];

    /** What each K-Means group is, as shown to the user (with its availability target: description()). */
    public const GROUPS = [
        'A' => 'Group with the highest average demand',
        'B' => 'Group with medium average demand',
        'C' => 'Group with the lowest average demand',
    ];

    /** How the classes are made; shown with the ABC ⓘ (Glossary 'abc'), Help and the Trend Analysis PDF. */
    public const GROUP_NOTE = 'Items are grouped by unit cost, daily demand, demand variability and lead time together. '
        . 'The class is a group, not a sales rank, so a best seller can be in any class.';

    /** "Group with the highest average demand · 95% availability target" */
    public static function description(string $class): string
    {
        return self::GROUPS[$class] . ' · ' . self::SERVICE_LEVELS[$class] . '% availability target';
    }

    /** Shown wherever an item has no K-Means class yet. */
    public const UNCLASSIFIED_NOTE = 'Run the DSS engine to classify this item.';

    /**
     * THE class of every item: the K-Means class that dss:run saved in cluster_segments (and
     * used for that item's Z-score, safety stock and reorder point). Every screen and export
     * reads the class from here; none works out its own. Items the engine has not classified
     * yet are simply missing from the result.
     *
     * @return array<string, 'A'|'B'|'C'>  item_id => class
     */
    public function classByItem(): array
    {
        $map = [];
        foreach ($this->select('item_id, abc_class')->findAll() as $row) {
            if (isset(self::SERVICE_LEVELS[$row['abc_class'] ?? ''])) {
                $map[$row['item_id']] = $row['abc_class'];
            }
        }

        return $map;
    }

    /** Whole saved rows (class, service level, Z-score) keyed by item_id, for screens that show them. */
    public function rowsByItem(): array
    {
        $rows = [];
        foreach ($this->findAll() as $row) {
            $rows[$row['item_id']] = $row;
        }

        return $rows;
    }

    public function distribution(): array
    {
        return $this->select('cluster_name, COUNT(*) as total')
            ->groupBy('cluster_name')
            ->findAll();
    }
}
