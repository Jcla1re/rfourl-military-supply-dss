<?php

namespace App\Models;

use CodeIgniter\Model;

class DssParameterModel extends Model
{
    protected $table            = 'dss_parameters';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'ordering_cost',
        'holding_cost_per_unit',
        'service_level_target',
        'z_score',
        'demand_lookback_days',
        'minimum_order_qty',
        'owner_usual_order_qty', // what the owner usually orders (20): the Cost comparison's "before"
        'updated_by',
    ];

    protected $useTimestamps = false;

    protected $validationRules = [
        'ordering_cost'          => 'required|numeric',
        'holding_cost_per_unit'  => 'required|numeric',
        'service_level_target'   => 'required|numeric',
        'z_score'                => 'required|numeric',
        'demand_lookback_days'   => 'required|integer',
        'minimum_order_qty'      => 'required|integer|greater_than[0]',
        'owner_usual_order_qty'  => 'permit_empty|integer|greater_than[0]|less_than[100000]',
    ];

    /** The owner's usual order quantity when none was saved (from the owner interview). */
    public const DEFAULT_OWNER_USUAL_ORDER_QTY = 20;

    /**
     * There is only ever one active parameter row (id=1). Returns it,
     * or sensible defaults if the table is somehow empty.
     */
    public function current(): array
    {
        return $this->first() ?? [
            'id'                     => null,
            'ordering_cost'          => 0,
            'holding_cost_per_unit'  => 0,
            'service_level_target'   => 95,
            'z_score'                => 1.645,
            'demand_lookback_days'   => 90,
            'minimum_order_qty'      => 5,
            'owner_usual_order_qty'  => self::DEFAULT_OWNER_USUAL_ORDER_QTY,
        ];
    }
}
