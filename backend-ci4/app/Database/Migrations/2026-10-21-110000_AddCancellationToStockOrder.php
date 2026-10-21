<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Who cancelled a stock order, when and why. cancelled_by is the owner's user
 * id; it stays NULL when the supplier declined the order (their reason keeps
 * living in decline_reason), which is how history tells "Cancelled by owner"
 * from "Declined by supplier".
 */
class AddCancellationToStockOrder extends Migration
{
    public function up()
    {
        $this->forge->addColumn('stock_order', [
            'cancel_reason' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'decline_reason',
            ],
            'cancelled_by' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
                'after'      => 'cancel_reason',
            ],
            'cancelled_at' => [
                'type'  => 'DATETIME',
                'null'  => true,
                'after' => 'cancelled_by',
            ],
        ]);

        $this->db->query('ALTER TABLE `stock_order` ADD CONSTRAINT `stock_order_cancelled_by_fk` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE `stock_order` DROP FOREIGN KEY `stock_order_cancelled_by_fk`');
        $this->forge->dropColumn('stock_order', ['cancel_reason', 'cancelled_by', 'cancelled_at']);
    }
}
