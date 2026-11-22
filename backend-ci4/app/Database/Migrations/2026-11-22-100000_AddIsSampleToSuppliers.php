<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Marks sample suppliers (php spark sample-suppliers load): made-up suppliers whose offers are only for
 * comparison. A sample supplier is never an item's main supplier, never gets an order or a portal account,
 * and can never become a real one (no form changes this column).
 */
class AddIsSampleToSuppliers extends Migration
{
    public function up()
    {
        $this->forge->addColumn('suppliers', [
            'is_sample' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('suppliers', 'is_sample');
    }
}
