<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Lets the Admin manually tag related products under one shared "family"
 * name (e.g. "Goa" for Goa Pants / Goa B / Goa C / Goa Attach, which don't
 * share a common name or prefix and can't be grouped by text-matching).
 * POS and Stock Ordering bucket products by group_name (falling back to
 * item_name when blank) so a single tile/tap drills into the family's
 * distinct items, then that item's sizes — exactly the 3-level structure
 * the Admin asked for after seeing flat per-item-name grouping wasn't
 * enough for a family like Goa.
 */
class AddGroupNameToProducts extends Migration
{
    public function up()
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

    public function down()
    {
        $this->forge->dropColumn('products', ['group_name']);
    }
}
