<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The quantity the owner usually orders (20 pieces, from the owner interview). The Procurement Report's
 * Cost comparison works out the yearly cost of ordering this much against the DSS Order Qty.
 */
class AddOwnerUsualOrderQtyToDssParameters extends Migration
{
    public function up()
    {
        $this->forge->addColumn('dss_parameters', [
            'owner_usual_order_qty' => ['type' => 'INT', 'constraint' => 11, 'null' => false, 'default' => 20],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('dss_parameters', 'owner_usual_order_qty');
    }
}
