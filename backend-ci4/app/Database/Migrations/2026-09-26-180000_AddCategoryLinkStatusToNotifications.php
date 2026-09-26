<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Notifications previously only distinguished "Staff Activity" from "Order
 * Status" by substring-matching the free-text `type` column (which false-
 * positived "Reorder Point Notice" as an order type via "reorder"). This adds
 * a real `category` column, a `link_url` for cards that should navigate
 * straight to the relevant page (e.g. a specific stock order), and a
 * `status` column so an actionable notification (like a staff password
 * reset request) can be approved/declined instead of just marked read.
 */
class AddCategoryLinkStatusToNotifications extends Migration
{
    public function up()
    {
        $this->forge->addColumn('notifications', [
            'category' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
                'after'      => 'type',
            ],
            'link_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'message',
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
                'after'      => 'is_read',
            ],
        ]);

        // Backfill existing rows by their exact (not substring) type, since
        // "Reorder Point Notice" contains "order" and was previously
        // miscategorized as an order-status notification.
        $this->db->query("UPDATE `notifications` SET category = 'order_status' WHERE type = 'Order Status'");
        $this->db->query("UPDATE `notifications` SET category = 'access_request' WHERE type = 'Access Request'");
        $this->db->query("UPDATE `notifications` SET category = 'staff_activity' WHERE category IS NULL");
    }

    public function down()
    {
        $this->forge->dropColumn('notifications', ['category', 'link_url', 'status']);
    }
}
