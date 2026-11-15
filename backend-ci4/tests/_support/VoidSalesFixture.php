<?php

namespace Tests\Support;

/**
 * Sales tables (with the void columns) on top of ReorderFixture, for the void-duplicate tests.
 * Use with ReorderFixture, DatabaseTestTrait and FeatureTestTrait; call setUpVoidSales() /
 * tearDownVoidSales(). The table prefix is off here because the dashboards, Trend Analysis and Old
 * Sales Records also run raw SQL.
 */
trait VoidSalesFixture
{
    private string $voidPrefix = '';

    private static array $voidSalesTables = ['sales_transaction', 'sales_item', 'inventory_log', 'app_settings', 'form_submissions'];

    protected function setUpVoidSales(): void
    {
        $this->voidPrefix = $this->db->DBPrefix;
        $this->db->setPrefix('');
        $this->setUpReorderFixture();

        $forge = \Config\Database::forge();
        foreach (self::$voidSalesTables as $t) {
            $forge->dropTable($t, true);
        }
        $id   = ['type' => 'INTEGER', 'auto_increment' => true];
        $int  = ['type' => 'INTEGER', 'null' => true];
        $str  = ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true];
        $real = ['type' => 'REAL', 'default' => 0];
        $when = ['type' => 'DATETIME', 'null' => true];
        $make = static function (string $name, array $fields, string $key) use ($forge) {
            $forge->addField($fields);
            $forge->addKey($key, true);
            $forge->createTable($name);
        };
        $forge->addUniqueKey('receipt_no');
        $make('sales_transaction', ['sales_id' => $id, 'receipt_no' => $str, 'or_number' => $str, 'user_id' => $int, 'sale_date' => $when, 'payment_method' => $str, 'cash_amount' => $real, 'gcash_amount' => $real, 'gcash_reference_no' => $str, 'subtotal' => $real, 'discount' => $real, 'total_amount' => $real] + SalesVoidColumns::FIELDS, 'sales_id');
        $make('sales_item', ['sales_item_id' => $id, 'sales_id' => $int, 'item_id' => $str, 'quantity_sold' => $int, 'selling_price' => $real, 'is_bulk' => ['type' => 'INTEGER', 'default' => 0]], 'sales_item_id');
        $make('inventory_log', ['log_id' => $id, 'item_id' => $str, 'user_id' => $int, 'log_type' => $str, 'quantity_changed' => $int, 'stock_after_change' => $int, 'reference_so_id' => $str, 'notes' => $str, 'timestamp' => $when], 'log_id');
        $make('app_settings', ['key' => $str, 'value' => $str], 'key');
        $forge->addUniqueKey('submission_key');
        $make('form_submissions', ['id' => $id, 'submission_key' => ['type' => 'CHAR', 'constraint' => 32], 'action' => $str, 'user_id' => $int, 'result_type' => $str, 'result_id' => $str, 'created_at' => $when], 'id');

        // MySQL functions the readers use, for SQLite.
        $this->db->connID->createFunction('DATE_FORMAT', static fn ($date, $format) => $format === '%Y-%m' ? substr((string) $date, 0, 7) : (string) $date);
        $this->db->connID->createFunction('YEAR', static fn ($d) => $d === null ? null : (int) substr((string) $d, 0, 4), 1);
        $this->db->connID->createFunction('MONTH', static fn ($d) => $d === null ? null : (int) substr((string) $d, 5, 2), 1);

        // Patrol Shirt M (reorder point 3) and a Brass Medal.
        $this->addSize('P-1', 'Patrol Shirt', 'M', 5, 3, ['selling_price' => 250]);
        $this->addSize('P-2', 'Brass Medal', null, 9, 0, ['selling_price' => 120]);
    }

    protected function tearDownVoidSales(): void
    {
        $forge = \Config\Database::forge();
        foreach (self::$voidSalesTables as $t) {
            $forge->dropTable($t, true);
        }
        $this->tearDownReorderFixture();
        $this->db->setPrefix($this->voidPrefix);
    }

    /**
     * A sale, as PosCheckout saves it (receipt, lines, one SALE log per line, stock taken out).
     *
     * @param list<array{0: string, 1: int, 2: float, 3?: int}> $lines [item_id, qty, price, is_bulk]
     */
    protected function sale(string $receiptNo, string $when, array $lines, array $header = []): int
    {
        $total = 0.0;
        foreach ($lines as $l) {
            $total += $l[1] * $l[2];
        }
        $this->db->table('sales_transaction')->insert($header + [
            'receipt_no' => $receiptNo, 'user_id' => $this->ownerId, 'sale_date' => $when, 'payment_method' => 'Cash',
            'cash_amount' => $total, 'gcash_amount' => 0, 'subtotal' => $total, 'discount' => 0, 'total_amount' => $total,
        ]);
        $salesId = (int) $this->db->insertID();
        foreach ($lines as $l) {
            $this->db->table('sales_item')->insert(['sales_id' => $salesId, 'item_id' => $l[0], 'quantity_sold' => $l[1], 'selling_price' => $l[2], 'is_bulk' => $l[3] ?? 0]);
            $this->db->query('UPDATE products SET current_stock = current_stock - ? WHERE item_id = ?', [$l[1], $l[0]]);
            $after = (int) $this->db->table('products')->where('item_id', $l[0])->get()->getRow()->current_stock;
            $this->db->table('inventory_log')->insert(['item_id' => $l[0], 'user_id' => $this->ownerId, 'log_type' => 'SALE', 'quantity_changed' => -$l[1], 'stock_after_change' => $after, 'notes' => 'Ref: sales_id=' . $salesId, 'timestamp' => $when]);
        }

        return $salesId;
    }

    /** The double click: the same sale saved again one second later. @return array{0: int, 1: int} [original, duplicate] */
    protected function doubleClick(string $first, string $second, string $when, array $lines = [['P-1', 1, 250.0]], array $header = []): array
    {
        $original  = $this->sale($first, $when, $lines, $header);
        $duplicate = $this->sale($second, date('Y-m-d H:i:s', strtotime($when) + 1), $lines, $header);

        return [$original, $duplicate];
    }

    protected function stock(string $itemId): int
    {
        return (int) $this->db->table('products')->where('item_id', $itemId)->get()->getRow()->current_stock;
    }

    /** Every row of the tables a void could touch, to prove that nothing changed. */
    protected function snapshot(array $tables = ['sales_transaction', 'sales_item', 'products', 'inventory_log', 'security_log', 'reorder_alert', 'reorder_notices']): array
    {
        $out = [];
        foreach ($tables as $t) {
            $out[$t] = $this->db->table($t)->get()->getResultArray();
        }

        return $out;
    }
}
