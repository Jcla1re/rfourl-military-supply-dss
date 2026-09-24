<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds a configurable Minimum Order Quantity floor for EOQ. Without it,
 * an item with zero recorded demand (e.g. it's been out of stock the
 * whole lookback window, or is newly added) computes EOQ = 0 even while
 * flagged CRITICAL — the classical EOQ formula has no demand signal to
 * work from, but "order 0 units" is never a useful recommendation for an
 * item that needs restocking. See Alnahhal et al. (2024), cited in the
 * capstone's literature review: "suppliers may enforce a minimum order
 * quantity that is much larger than the EOQ."
 */
class AddMinimumOrderQtyToDssParameters extends Migration
{
    public function up()
    {
        $this->forge->addColumn('dss_parameters', [
            'minimum_order_qty' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => false,
                'default'    => 5,
                'after'      => 'demand_lookback_days',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('dss_parameters', 'minimum_order_qty');
    }
}
