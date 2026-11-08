<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The owner no longer approves staff password resets (staff reset their own with a temporary
 * password). Any request still waiting for approval is closed so it can't be acted on, and its
 * old email links stop working. Already-handled requests are left as they are.
 */
class ExpirePendingPasswordApprovals extends Migration
{
    public function up()
    {
        $this->db->table('notifications')
            ->where('category', 'access_request')
            ->groupStart()->where('status', null)->orWhere('status', '')->groupEnd()
            ->update(['status' => 'expired', 'is_read' => 1, 'action_token' => null]);
    }

    public function down()
    {
        // A closed request stays closed.
    }
}
