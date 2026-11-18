<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * How a supplier is paid (Cash / Check / Other). Optional; shown on the supplier card and as "Terms" on
 * the Purchase Order PDF.
 */
class AddPaymentTermsToSuppliers extends Migration
{
    public function up()
    {
        $this->forge->addColumn('suppliers', [
            'payment_terms' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'default' => null],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('suppliers', 'payment_terms');
    }
}
