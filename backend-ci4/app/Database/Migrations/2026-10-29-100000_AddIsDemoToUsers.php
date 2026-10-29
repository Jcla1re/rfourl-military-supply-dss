<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Marks the demo accounts (see `php spark demo:setup`). Harmless on the live
 * database: the column defaults to 0 and nothing reads it unless DEMO_MODE is on.
 * Safe to run on a database that already has the column (e.g. a copy of live
 * that was migrated first).
 */
class AddIsDemoToUsers extends Migration
{
    public function up()
    {
        if ($this->db->fieldExists('is_demo', 'users')) {
            return;
        }

        $this->forge->addColumn('users', [
            'is_demo' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],
        ]);
    }

    public function down()
    {
        if ($this->db->fieldExists('is_demo', 'users')) {
            $this->forge->dropColumn('users', 'is_demo');
        }
    }
}
