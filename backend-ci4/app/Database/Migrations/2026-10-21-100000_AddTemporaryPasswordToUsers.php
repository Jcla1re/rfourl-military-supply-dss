<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Staff temporary passwords. A reset or a new account gets a random temporary
 * password (emailed to the staff member, never shown to the owner by default).
 * While must_change_password = 1 the staff member can only reach the
 * "set your own password" page. temp_password_kind is 'reset' or 'invite' (a
 * new account); temp_password_expires_at is when the temporary password stops working.
 */
class AddTemporaryPasswordToUsers extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            'must_change_password' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'null'       => false,
            ],
            'temp_password_kind' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'null'       => true,
            ],
            'temp_password_expires_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('users', ['must_change_password', 'temp_password_kind', 'temp_password_expires_at']);
    }
}
