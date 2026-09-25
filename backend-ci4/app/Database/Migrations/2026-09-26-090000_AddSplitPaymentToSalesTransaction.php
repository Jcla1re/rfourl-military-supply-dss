<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds real split-payment tracking (cash_amount / gcash_amount) to
 * sales_transaction. Previously a transaction only recorded one
 * payment_method for the whole total, so "paid partly cash, partly
 * GCash" couldn't be represented at all. Backfills existing rows from
 * their payment_method so historical receipts still add up correctly.
 */
class AddSplitPaymentToSalesTransaction extends Migration
{
    public function up()
    {
        $this->forge->addColumn('sales_transaction', [
            'cash_amount' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,2',
                'null'       => false,
                'default'    => 0,
                'after'      => 'payment_method',
            ],
            'gcash_amount' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,2',
                'null'       => false,
                'default'    => 0,
                'after'      => 'cash_amount',
            ],
        ]);

        // Backfill: Cash -> cash_amount, everything else -> gcash_amount
        // (GCash/Bank Transfer/Credit Card were all non-cash before this).
        $this->db->query("UPDATE `sales_transaction` SET cash_amount = total_amount WHERE payment_method = 'Cash'");
        $this->db->query("UPDATE `sales_transaction` SET gcash_amount = total_amount WHERE payment_method != 'Cash'");

        // 'Split' is a new payment_method value for transactions paid
        // partly in cash and partly via GCash.
        $this->db->query(
            "ALTER TABLE `sales_transaction` MODIFY `payment_method` "
            . "ENUM('Cash','GCash','Bank Transfer','Credit Card','Split') NOT NULL DEFAULT 'Cash'"
        );
    }

    public function down()
    {
        $this->db->query("UPDATE `sales_transaction` SET payment_method = 'Cash' WHERE payment_method = 'Split'");
        $this->db->query(
            "ALTER TABLE `sales_transaction` MODIFY `payment_method` "
            . "ENUM('Cash','GCash','Bank Transfer','Credit Card') NOT NULL DEFAULT 'Cash'"
        );
        $this->forge->dropColumn('sales_transaction', ['cash_amount', 'gcash_amount']);
    }
}
