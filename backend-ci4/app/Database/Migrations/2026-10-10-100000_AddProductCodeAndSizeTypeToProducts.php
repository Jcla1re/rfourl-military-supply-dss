<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Links a product row to the cleaned product catalog (P-0001...) and records
 * what kind of size it carries. One row per catalog item + size, so the
 * existing `size` column stays the single place a size lives.
 *
 * product_code is only set on rows created by `php spark dss:import-history`;
 * products that already existed are matched by name and left untouched.
 */
class AddProductCodeAndSizeTypeToProducts extends Migration
{
    public function up()
    {
        $this->forge->addColumn('products', [
            'product_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'null'       => true,
                'after'      => 'item_id',
            ],
            'size_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'null'       => true,
                'after'      => 'size',
            ],
        ]);

        $this->db->query('CREATE INDEX idx_products_code_size ON products (product_code, size)');
    }

    public function down()
    {
        $this->db->query('DROP INDEX idx_products_code_size ON products');
        $this->forge->dropColumn('products', ['product_code', 'size_type']);
    }
}
