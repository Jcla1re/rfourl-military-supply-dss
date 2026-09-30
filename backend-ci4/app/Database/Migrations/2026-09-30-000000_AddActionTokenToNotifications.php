<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Lets an "Access Request" notification's Approve/Decline actions be
 * triggered from a one-click link in the email itself (no admin login
 * needed), gated by this random per-notification token rather than a
 * session — the same "magic link" pattern as an email confirmation link.
 */
class AddActionTokenToNotifications extends Migration
{
    public function up()
    {
        $this->forge->addColumn('notifications', [
            'action_token' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'status',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('notifications', ['action_token']);
    }
}
