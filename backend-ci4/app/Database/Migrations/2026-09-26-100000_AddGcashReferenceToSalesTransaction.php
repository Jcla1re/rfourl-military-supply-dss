<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds the GCash confirmation reference number the cashier keys in during
 * checkout, so it's traceable in the Transaction History / PDF report
 * (not just left on the customer's phone screen).
 */
class AddGcashReferenceToSalesTransaction extends Migration
{
    public function up()
    {
        $this->forge->addColumn('sales_transaction', [
            'gcash_reference_no' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
                'after'      => 'gcash_amount',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('sales_transaction', 'gcash_reference_no');
    }
}
