<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The official receipt / invoice number from the store's BIR booklet, typed by the cashier at
 * checkout. Optional: the system's own receipt_no is not an official receipt.
 */
class AddOrNumberToSalesTransaction extends Migration
{
    public function up()
    {
        $this->forge->addColumn('sales_transaction', [
            'or_number' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'after' => 'receipt_no'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('sales_transaction', 'or_number');
    }
}
