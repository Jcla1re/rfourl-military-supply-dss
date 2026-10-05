<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Lets the Supplier declare exactly how many units of each line item they
 * actually shipped (which may differ from the originally ordered quantity
 * on a partial shipment), separately from `order_quantity`. Admin/Staff
 * then confirm delivery against this declared figure instead of blindly
 * trusting the original order — see StockOrderModel::markDelivered().
 */
class AddShippedQuantityToSoItem extends Migration
{
    public function up()
    {
        $this->forge->addColumn('so_item', [
            'shipped_quantity' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
                'after'      => 'order_quantity',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('so_item', ['shipped_quantity']);
    }
}
