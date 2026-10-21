<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * "Notify Admin" from staff Inventory, remembered per item so every staff
 * account sees "✓ Admin notified" (and the admin gets one notification, not
 * one per click). A notice stays open until the item is ordered or no longer
 * needs reordering; see App\Models\ReorderNoticeModel.
 */
class CreateReorderNotices extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'notice_id'       => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
            'item_id'         => ['type' => 'VARCHAR', 'constraint' => 15],
            'notified_by'     => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'notification_id' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'created_at'      => ['type' => 'DATETIME'],
            'cleared_at'      => ['type' => 'DATETIME', 'null' => true],
            'cleared_reason'  => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
        ]);
        $this->forge->addKey('notice_id', true);
        $this->forge->addKey(['item_id', 'cleared_at']);
        $this->forge->addForeignKey('item_id', 'products', 'item_id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('notified_by', 'users', 'user_id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('notification_id', 'notifications', 'notification_id', 'CASCADE', 'SET NULL');
        // Same collation as products.item_id (the connection default differs), or the FK can't form.
        $this->forge->createTable('reorder_notices', true, ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci']);
    }

    public function down()
    {
        $this->forge->dropTable('reorder_notices', true);
    }
}
