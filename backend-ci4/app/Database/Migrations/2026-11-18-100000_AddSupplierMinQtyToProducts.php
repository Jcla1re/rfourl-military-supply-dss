<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The smallest order a supplier accepts for an item (optional: NULL = no minimum).
 * dss:run takes eoq_value = max(eoq_raw, system minimum, supplier_min_qty).
 */
class AddSupplierMinQtyToProducts extends Migration
{
    public function up()
    {
        $this->forge->addColumn('products', [
            'supplier_min_qty' => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'default' => null, 'after' => 'supplier_id'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('products', 'supplier_min_qty');
    }
}
