<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Append-only audit trail of security-relevant events (logins, lockouts,
 * password/email changes, account creation and deactivation).
 */
class CreateSecurityLog extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'log_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'username'   => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'role'       => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'event'      => ['type' => 'VARCHAR', 'constraint' => 50],
            'detail'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('log_id');
        $this->forge->addKey('event');
        $this->forge->addKey('created_at');
        $this->forge->createTable('security_log');
    }

    public function down()
    {
        $this->forge->dropTable('security_log');
    }
}
