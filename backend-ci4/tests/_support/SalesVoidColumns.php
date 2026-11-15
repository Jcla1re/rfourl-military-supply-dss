<?php

namespace Tests\Support;

/**
 * The void columns of sales_transaction (migration 2026-11-15-100000_AddVoidToSalesTransaction),
 * added to every test table that stands in for sales_transaction: every query that adds up sales
 * filters on voided_at (SalesTransactionModel::notVoided()).
 */
final class SalesVoidColumns
{
    public const FIELDS = [
        'voided_at'             => ['type' => 'DATETIME', 'null' => true],
        'voided_by'             => ['type' => 'INTEGER', 'null' => true],
        'void_reason'           => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        'duplicate_of_sales_id' => ['type' => 'INTEGER', 'null' => true],
    ];
}
