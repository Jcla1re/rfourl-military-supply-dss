<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * products.rop_source: who set the item's Reorder At (manual_rop_warning).
 *
 *  - 'dss'    written by dss:run. A later run that gives 0 (too few sales) writes the 0, so an
 *             old calculated value doesn't stay behind.
 *  - 'manual' typed by the Owner (Inventory Add / Edit item). A run that gives 0 keeps it; a run
 *             that gives more than 0 still overwrites it and marks it 'dss' (as before).
 *
 * Backfill: a Reorder At above 0 that some DSS run produced for that item is 'dss'; one no run
 * ever produced is 'manual'; 0 or empty is 'dss' (nothing to keep). up() and down() can be run
 * again safely.
 */
class AddRopSourceToProducts extends Migration
{
    public function up()
    {
        $this->db->resetDataCache(); // column lists are cached per connection
        if (! $this->db->fieldExists('rop_source', 'products')) {
            $this->forge->addColumn('products', [
                'rop_source' => ['type' => 'ENUM', 'constraint' => ['dss', 'manual'], 'null' => true, 'after' => 'manual_rop_warning'],
            ]);
        }

        $products = $this->db->prefixTable('products');
        $runs     = $this->db->prefixTable('pdss_computation');

        // Only rows not filled yet, so running it again changes nothing.
        $this->db->query("UPDATE {$products} SET rop_source = 'dss'
            WHERE rop_source IS NULL AND (manual_rop_warning IS NULL OR manual_rop_warning <= 0)");
        $this->db->query("UPDATE {$products} SET rop_source = 'dss'
            WHERE rop_source IS NULL AND EXISTS (SELECT 1 FROM {$runs} c
                WHERE c.item_id = {$products}.item_id AND c.reorder_point = {$products}.manual_rop_warning)");
        $this->db->query("UPDATE {$products} SET rop_source = 'manual' WHERE rop_source IS NULL");
    }

    public function down()
    {
        $this->db->resetDataCache(); // column lists are cached per connection
        if ($this->db->fieldExists('rop_source', 'products')) {
            $this->forge->dropColumn('products', 'rop_source');
        }
    }
}
