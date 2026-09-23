<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Widens inventory_log.log_type and sales_transaction.payment_method to match
 * the taxonomy actually used by the historical POS/inventory data, and
 * migrates any existing rows written under the old vocabulary.
 */
class UpdateTaxonomyEnums extends Migration
{
    public function up()
    {
        // --- inventory_log.log_type ---
        // Old: Restock, Return, Damaged, Adjustment
        // New: RESTOCK, RETURN, DAMAGED_LOST, MANUAL_ADJUSTMENT, SALE
        $this->db->query(
            "ALTER TABLE `inventory_log` MODIFY `log_type` "
            . "ENUM('MANUAL_ADJUSTMENT','RESTOCK','SALE','RETURN','DAMAGED_LOST') NOT NULL"
        );
        $this->db->query("UPDATE `inventory_log` SET log_type = 'RESTOCK' WHERE log_type = 'Restock'");
        $this->db->query("UPDATE `inventory_log` SET log_type = 'RETURN' WHERE log_type = 'Return'");
        $this->db->query("UPDATE `inventory_log` SET log_type = 'DAMAGED_LOST' WHERE log_type = 'Damaged'");
        $this->db->query("UPDATE `inventory_log` SET log_type = 'MANUAL_ADJUSTMENT' WHERE log_type = 'Adjustment'");

        // --- sales_transaction.payment_method ---
        // Old: Cash, GCash
        // New: Cash, GCash, Bank Transfer, Credit Card
        $this->db->query(
            "ALTER TABLE `sales_transaction` MODIFY `payment_method` "
            . "ENUM('Cash','GCash','Bank Transfer','Credit Card') NOT NULL"
        );
    }

    public function down()
    {
        $this->db->query("UPDATE `inventory_log` SET log_type = 'Restock' WHERE log_type = 'RESTOCK'");
        $this->db->query("UPDATE `inventory_log` SET log_type = 'Return' WHERE log_type = 'RETURN'");
        $this->db->query("UPDATE `inventory_log` SET log_type = 'Damaged' WHERE log_type = 'DAMAGED_LOST'");
        $this->db->query("UPDATE `inventory_log` SET log_type = 'Adjustment' WHERE log_type = 'MANUAL_ADJUSTMENT'");
        $this->db->query("DELETE FROM `inventory_log` WHERE log_type = 'SALE'");
        $this->db->query(
            "ALTER TABLE `inventory_log` MODIFY `log_type` "
            . "ENUM('Restock','Return','Damaged','Adjustment') NOT NULL"
        );

        $this->db->query("DELETE FROM `sales_transaction` WHERE payment_method IN ('Bank Transfer','Credit Card')");
        $this->db->query(
            "ALTER TABLE `sales_transaction` MODIFY `payment_method` "
            . "ENUM('Cash','GCash') NOT NULL"
        );
    }
}
