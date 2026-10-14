<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Stores the reason a Supplier gives when declining a stock order, so the
 * Admin can see it on the order detail page (and in the notification).
 */
class AddDeclineReasonToStockOrder extends Migration
{
    public function up()
    {
        $this->forge->addColumn('stock_order', [
            'decline_reason' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'tracking_no',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('stock_order', ['decline_reason']);
    }
}
