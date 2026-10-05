<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Reverts 2026-10-03-120000_AddGroupNameToProducts — manual family tagging
 * (e.g. "Goa" bundling Goa Pants/Goa B/Goa C into one POS tile) turned out
 * to need more clicks than it saved and complicated the picker flow. POS,
 * Ordering, and Inventory now group by exact item_name+category only — the
 * simpler "click Goa Pants, pick a size" flow the Owner actually asked for.
 */
class DropGroupNameFromProducts extends Migration
{
    public function up()
    {
        $this->forge->dropColumn('products', ['group_name']);
    }

    public function down()
    {
        $this->forge->addColumn('products', [
            'group_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
                'after'      => 'item_name',
            ],
        ]);
    }
}
