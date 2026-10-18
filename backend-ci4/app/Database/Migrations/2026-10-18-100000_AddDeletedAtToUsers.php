<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Lets the Owner "remove" a staff account that already has sales/order/log
 * history. The row stays (those tables FK to users.user_id, and reports join
 * it for the cashier's name); deleted_at just hides it from the account
 * list, login and staff pickers. UserModel deliberately does NOT enable CI4
 * soft deletes, which would hide the name from every joined report.
 */
class AddDeletedAtToUsers extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('users', 'deleted_at');
    }
}
