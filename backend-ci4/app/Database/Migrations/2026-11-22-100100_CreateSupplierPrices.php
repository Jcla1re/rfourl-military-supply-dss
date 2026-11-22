<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Other suppliers' offers for an item, for comparison only. The item's main supplier is NOT stored here:
 * its offer is products.unit_cost + suppliers.lead_time_days + products.supplier_min_qty.
 * Lead time, Safety Stock, ROP, EOQ and K-Means keep using the main supplier (products.supplier_id) only.
 */
class CreateSupplierPrices extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'supplier_id'    => ['type' => 'VARCHAR', 'constraint' => 10],
            'item_id'        => ['type' => 'VARCHAR', 'constraint' => 15],
            'unit_price'     => ['type' => 'DECIMAL', 'constraint' => '10,2'],
            'lead_time_days' => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'default' => null], // NULL = the supplier's own lead time
            'min_order_qty'  => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'default' => null], // NULL = no minimum
            'is_sample'      => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['supplier_id', 'item_id'], 'supplier_prices_supplier_item_uq');
        $this->forge->addKey('item_id');
        // Deleting a supplier or an item takes its offers with it (they are comparison data only).
        $this->forge->addForeignKey('supplier_id', 'suppliers', 'supplier_id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('item_id', 'products', 'item_id', 'CASCADE', 'CASCADE');
        // InnoDB and the same charset as suppliers / products, so the foreign keys line up (MariaDB only; SQLite has no such options).
        $attributes = $this->db->getPlatform() === 'MySQLi' ? ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci'] : [];
        $this->forge->createTable('supplier_prices', true, $attributes);
    }

    public function down()
    {
        $this->forge->dropTable('supplier_prices', true);
    }
}
