<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPreferredCourierToSuppliers extends Migration
{
    public function up()
    {
        $this->forge->addColumn('suppliers', [
            'preferred_courier' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'after'      => 'lead_time_days',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('suppliers', 'preferred_courier');
    }
}
