<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The last few passwords each staff member chose, as hashes only, so they can't go straight back
 * to one (see App\Libraries\PasswordHistory). Owner-issued temporary passwords are never stored here.
 */
class CreatePasswordHistory extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('password_history')) {
            return;
        }

        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'user_id'       => ['type' => 'INT', 'constraint' => 11],
            'password_hash' => ['type' => 'VARCHAR', 'constraint' => 255],
            'created_at'    => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('user_id');
        $this->forge->addForeignKey('user_id', 'users', 'user_id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('password_history', true, ['ENGINE' => 'InnoDB', 'DEFAULT CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci']);
    }

    public function down()
    {
        $this->forge->dropTable('password_history', true);
    }
}
