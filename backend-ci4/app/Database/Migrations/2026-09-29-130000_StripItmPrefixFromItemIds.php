<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Strips the legacy "ITM-" prefix from every product's item_id (e.g.
 * "ITM-0146" -> "0146"), matching the plain-numeric format now used for
 * new items (see ProductModel::generateNextId()).
 *
 * item_id is a real FK (ON UPDATE RESTRICT) in six other tables, so MySQL
 * blocks a plain UPDATE on products.item_id while any of those rows exist.
 * This drops those six constraints, rewrites item_id everywhere it's
 * referenced (products + all six children, same transformation, so
 * referential integrity is preserved), then rebuilds the constraints.
 */
class StripItmPrefixFromItemIds extends Migration
{
    private const CHILD_TABLES = [
        'sales_item'       => 'sales_item_ibfk_2',
        'so_item'          => 'so_item_ibfk_2',
        'inventory_log'    => 'inventory_log_ibfk_1',
        'reorder_alert'    => 'reorder_alert_ibfk_1',
        'cluster_segments' => 'cluster_segments_ibfk_1',
        'pdss_computation' => 'pdss_computation_ibfk_1',
    ];

    public function up()
    {
        foreach (self::CHILD_TABLES as $table => $constraint) {
            $this->db->query("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
        }

        $this->db->query("UPDATE `products` SET item_id = SUBSTRING(item_id, 5) WHERE item_id LIKE 'ITM-%'");

        foreach (self::CHILD_TABLES as $table => $constraint) {
            $this->db->query("UPDATE `{$table}` SET item_id = SUBSTRING(item_id, 5) WHERE item_id LIKE 'ITM-%'");
        }

        foreach (self::CHILD_TABLES as $table => $constraint) {
            $this->db->query(
                "ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` FOREIGN KEY (`item_id`) "
                . "REFERENCES `products` (`item_id`) ON DELETE RESTRICT ON UPDATE RESTRICT"
            );
        }
    }

    public function down()
    {
        foreach (self::CHILD_TABLES as $table => $constraint) {
            $this->db->query("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
        }

        $this->db->query("UPDATE `products` SET item_id = CONCAT('ITM-', item_id) WHERE item_id NOT LIKE 'ITM-%'");

        foreach (self::CHILD_TABLES as $table => $constraint) {
            $this->db->query("UPDATE `{$table}` SET item_id = CONCAT('ITM-', item_id) WHERE item_id NOT LIKE 'ITM-%'");
        }

        foreach (self::CHILD_TABLES as $table => $constraint) {
            $this->db->query(
                "ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` FOREIGN KEY (`item_id`) "
                . "REFERENCES `products` (`item_id`) ON DELETE RESTRICT ON UPDATE RESTRICT"
            );
        }
    }
}
