<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Marks sale lines that came from a bulk order (the cleaned 2021-2026 history
 * flags them in its Bulk_Order column). It is only a label: no DSS calculation
 * reads it. Set by `php spark dss:seed-dataset`; the POS leaves it at 0.
 */
class AddIsBulkToSalesItem extends Migration
{
    public function up()
    {
        $this->forge->addColumn('sales_item', [
            'is_bulk' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
                'after'      => 'selling_price',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('sales_item', 'is_bulk');
    }
}
