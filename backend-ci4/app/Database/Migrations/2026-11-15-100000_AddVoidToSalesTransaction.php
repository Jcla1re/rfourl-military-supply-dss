<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A sale can be voided (only by `php spark sales:void-duplicate`): it stays in the database and on
 * the receipt lists, marked "voided duplicate of #XXXX", but is left out of every total, chart and
 * demand figure (SalesTransactionModel::notVoided()). The receipt number stays taken.
 *
 *  - voided_at              when it was voided (NULL = a normal sale)
 *  - voided_by              the Owner account that ran the command
 *  - void_reason            why
 *  - duplicate_of_sales_id  the original sale it duplicated
 *
 * inventory_log.log_type gets SALE_VOID: the stock given back when a sale is voided.
 *
 * Safe with inventory_log's add-only triggers: they fire on row UPDATE / DELETE only, and adding a
 * value at the END of an ENUM changes no stored row (MySQL 8 / MariaDB 10.3+ do it in place, without
 * copying the table). The new columns are added at the end of sales_transaction, all NULL, no default
 * to fill, so existing rows are not rewritten either (in-place / instant ADD COLUMN).
 *
 * The ENUM step reads the column's real value list first: it refuses (changing nothing) if the list
 * is not exactly the five expected values, so it never overwrites a list it doesn't know. up() and
 * down() can both be run again safely; each step is skipped when it is already done.
 *
 * The platform is checked with getPlatform(): this app's MySQLi connection class is
 * App\Database\MySQLi (see Config\Database), so DBDriver is not 'MySQLi'.
 */
class AddVoidToSalesTransaction extends Migration
{
    private const LOG_TYPES_BEFORE = ['MANUAL_ADJUSTMENT', 'RESTOCK', 'SALE', 'RETURN', 'DAMAGED_LOST'];
    private const LOG_TYPES_AFTER  = [...self::LOG_TYPES_BEFORE, 'SALE_VOID'];

    private const COLUMNS = [
        'voided_at'             => ['type' => 'DATETIME', 'null' => true],
        'voided_by'             => ['type' => 'INT', 'constraint' => 11, 'null' => true],
        'void_reason'           => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        'duplicate_of_sales_id' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
    ];

    public function up()
    {
        // 1. Check the ENUM before changing anything (ENUM is MySQL / MariaDB only; the SQLite test
        //    tables use a plain text column).
        $mysql  = $this->db->getPlatform() === 'MySQLi';
        $values = $mysql ? $this->logTypeValues() : null;
        if ($mysql && $values !== self::LOG_TYPES_AFTER && $values !== self::LOG_TYPES_BEFORE) {
            throw new \RuntimeException('inventory_log.log_type is not the expected ENUM(' . implode(',', self::LOG_TYPES_BEFORE)
                . '); it is ENUM(' . implode(',', $values ?? []) . '). Nothing was changed.');
        }

        // 2. The ENUM value.
        if ($mysql && $values === self::LOG_TYPES_BEFORE) {
            $this->db->query($this->logTypeSql(self::LOG_TYPES_AFTER));
            if ($this->logTypeValues() !== self::LOG_TYPES_AFTER) {
                throw new \RuntimeException('Adding SALE_VOID to inventory_log.log_type did not take effect.');
            }
        }

        // 3. The columns.
        $fields = array_filter(self::COLUMNS, fn (string $name) => ! $this->db->fieldExists($name, 'sales_transaction'), ARRAY_FILTER_USE_KEY);
        if ($fields !== []) {
            $this->forge->addColumn('sales_transaction', $fields);
        }
    }

    public function down()
    {
        $mysql = $this->db->getPlatform() === 'MySQLi';

        // Refuse first, before changing anything. Rolling back would count the voided duplicates again
        // although their stock was given back, and the SALE_VOID log rows cannot be removed
        // (inventory_log is add-only).
        if ($this->db->fieldExists('voided_at', 'sales_transaction')
            && $this->db->table('sales_transaction')->where('voided_at IS NOT NULL', null, false)->countAllResults() > 0) {
            throw new \RuntimeException('Some sales are voided; this migration cannot be rolled back without un-voiding them. Nothing was changed.');
        }
        $values = $mysql ? $this->logTypeValues() : null;
        $hasSaleVoid = $values !== null && in_array('SALE_VOID', $values, true);
        if ($hasSaleVoid && $this->db->table('inventory_log')->where('log_type', 'SALE_VOID')->countAllResults() > 0) {
            throw new \RuntimeException('inventory_log has SALE_VOID entries (add-only); this migration cannot be rolled back. Nothing was changed.');
        }

        // The ENUM value: only when it is exactly the list up() made (never added, or changed by
        // someone else: left alone).
        if ($hasSaleVoid && $values === self::LOG_TYPES_AFTER) {
            $this->db->query($this->logTypeSql(self::LOG_TYPES_BEFORE));
        }

        foreach (array_reverse(array_keys(self::COLUMNS)) as $name) {
            if ($this->db->fieldExists($name, 'sales_transaction')) {
                $this->forge->dropColumn('sales_transaction', $name);
            }
        }
    }

    /** The values of inventory_log.log_type as they are in the database right now, in order. */
    private function logTypeValues(): ?array
    {
        $row = $this->db->query(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable('inventory_log'), 'log_type']
        )->getRowArray();

        return $row ? \App\Libraries\DuplicateSaleVoid::enumValues((string) $row['COLUMN_TYPE']) : null;
    }

    private function logTypeSql(array $values): string
    {
        $list = implode(',', array_map(static fn (string $v): string => "'" . $v . "'", $values));

        return 'ALTER TABLE ' . $this->db->escapeIdentifiers($this->db->prefixTable('inventory_log')) . " MODIFY `log_type` ENUM({$list}) NOT NULL";
    }
}
