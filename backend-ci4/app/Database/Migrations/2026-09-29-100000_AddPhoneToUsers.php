<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The "My Profile" Contact number field was a static, always-disabled
 * placeholder ("—") because `users` had nowhere to actually store it.
 */
class AddPhoneToUsers extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            'phone' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
                'after'      => 'email',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('users', 'phone');
    }
}
