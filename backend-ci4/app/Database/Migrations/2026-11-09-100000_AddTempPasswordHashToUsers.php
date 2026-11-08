<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Staff "Forgot password" now emails a temporary password directly. Until that password is
 * actually used, the person's current password keeps working, so the temporary one is stored
 * beside it (as a hash) instead of replacing it. Safe to run twice.
 */
class AddTempPasswordHashToUsers extends Migration
{
    public function up()
    {
        if ($this->db->fieldExists('temp_password_hash', 'users')) {
            return;
        }

        $this->forge->addColumn('users', [
            'temp_password_hash' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'password_hash'],
        ]);
    }

    public function down()
    {
        if ($this->db->fieldExists('temp_password_hash', 'users')) {
            $this->forge->dropColumn('users', 'temp_password_hash');
        }
    }
}
