<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Security alerts ("<staff> changed their password") carry a "Lock account"
 * action. The emailed link's token is stored only as a hash in the existing
 * action_token column; these two columns bind it to one staff account and a
 * 24-hour lifetime. A used link is marked by notifications.status = 'locked'.
 */
class AddLockActionToNotifications extends Migration
{
    public function up()
    {
        $this->forge->addColumn('notifications', [
            'action_user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
                'after'      => 'action_token',
            ],
            'action_expires_at' => [
                'type'  => 'DATETIME',
                'null'  => true,
                'after' => 'action_user_id',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('notifications', ['action_user_id', 'action_expires_at']);
    }
}
