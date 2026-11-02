<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Marks a Dismissed alert the Owner hid on purpose: it stays hidden, and sync
 * doesn't raise a new one for that size, until the size is no longer Reorder
 * Now. Status alone can't tell this apart: sync also writes Dismissed when
 * stock recovers on its own, and those rows must not block a later alert.
 */
class AddHiddenUntilRecoveredToReorderAlert extends Migration
{
    public function up()
    {
        $this->forge->addColumn('reorder_alert', [
            'hidden_until_recovered' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
                'after'      => 'status',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('reorder_alert', 'hidden_until_recovered');
    }
}
