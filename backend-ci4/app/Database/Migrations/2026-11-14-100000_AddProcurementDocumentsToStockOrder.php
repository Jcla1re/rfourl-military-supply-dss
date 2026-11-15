<?php

namespace App\Database\Migrations;

use App\Libraries\ProcurementDocs;
use CodeIgniter\Database\Migration;

/**
 * The paper trail of a stock order: Purchase Order (po_number, when the owner places it),
 * Sales Order (so_number, when the supplier accepts), Delivery Receipt (dr_number, when the
 * supplier ships) and who received it and when. accepted_at / shipped_at hold the times of
 * the two supplier steps for the order timeline. Status rules are untouched.
 *
 * Existing orders are numbered here, oldest first, from the status they are in now.
 */
class AddProcurementDocumentsToStockOrder extends Migration
{
    public function up()
    {
        $this->forge->addColumn('stock_order', [
            'po_number'   => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'after' => 'so_id'],
            'so_number'   => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'after' => 'po_number'],
            'dr_number'   => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'after' => 'so_number'],
            'accepted_at' => ['type' => 'DATETIME', 'null' => true],
            'shipped_at'  => ['type' => 'DATETIME', 'null' => true],
            'received_by' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'received_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->db->query('ALTER TABLE `stock_order` ADD UNIQUE KEY `stock_order_po_number_uq` (`po_number`)');
        $this->db->query('ALTER TABLE `stock_order` ADD UNIQUE KEY `stock_order_so_number_uq` (`so_number`)');
        $this->db->query('ALTER TABLE `stock_order` ADD UNIQUE KEY `stock_order_dr_number_uq` (`dr_number`)');
        $this->db->query('ALTER TABLE `stock_order` ADD CONSTRAINT `stock_order_received_by_fk` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL');

        ProcurementDocs::backfill($this->db);
    }

    public function down()
    {
        $this->db->query('ALTER TABLE `stock_order` DROP FOREIGN KEY `stock_order_received_by_fk`');
        $this->db->query('ALTER TABLE `stock_order` DROP INDEX `stock_order_po_number_uq`');
        $this->db->query('ALTER TABLE `stock_order` DROP INDEX `stock_order_so_number_uq`');
        $this->db->query('ALTER TABLE `stock_order` DROP INDEX `stock_order_dr_number_uq`');
        $this->forge->dropColumn('stock_order', ['po_number', 'so_number', 'dr_number', 'accepted_at', 'shipped_at', 'received_by', 'received_at']);
    }
}
