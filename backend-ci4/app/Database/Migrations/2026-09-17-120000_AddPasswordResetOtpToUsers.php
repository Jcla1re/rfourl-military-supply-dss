<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPasswordResetOtpToUsers extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            'reset_otp_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'last_login',
            ],
            'reset_otp_expires' => [
                'type'       => 'DATETIME',
                'null'       => true,
                'after'      => 'reset_otp_hash',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('users', ['reset_otp_hash', 'reset_otp_expires']);
    }
}
