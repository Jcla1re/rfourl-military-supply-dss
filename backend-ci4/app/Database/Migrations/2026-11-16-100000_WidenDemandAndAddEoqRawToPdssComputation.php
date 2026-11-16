<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * pdss_computation:
 *
 *  - avg_daily_demand and demand_std_dev: DECIMAL(10,2) -> DECIMAL(10,4). dss:run already rounds
 *    both to 4 places, but the column cut them to 2, so a small demand (0.004 a day) was saved as
 *    0.00. Only the saved copy changes: ROP, Safety Stock, EOQ and K-Means are worked out from the
 *    unrounded numbers in memory. Existing rows keep their 2-place values (now shown as x.xx00).
 *  - eoq_raw: the EOQ before the minimum order quantity is applied (NULL for rows saved before
 *    this column). eoq_value stays the floored value used everywhere else.
 *
 * MySQL only for the type change (the SQLite test tables use REAL). up() and down() can be run
 * again safely: each step is skipped when already done.
 */
class WidenDemandAndAddEoqRawToPdssComputation extends Migration
{
    private const DEMAND_COLUMNS = ['avg_daily_demand', 'demand_std_dev'];

    public function up()
    {
        $this->db->resetDataCache(); // column lists are cached per connection
        if ($this->db->getPlatform() === 'MySQLi') {
            foreach (self::DEMAND_COLUMNS as $column) {
                $this->db->query("ALTER TABLE {$this->db->prefixTable('pdss_computation')} MODIFY {$column} DECIMAL(10,4) NULL DEFAULT NULL");
            }
        }

        if (! $this->db->fieldExists('eoq_raw', 'pdss_computation')) {
            $this->forge->addColumn('pdss_computation', [
                'eoq_raw' => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'after' => 'eoq_value'],
            ]);
        }
    }

    public function down()
    {
        $this->db->resetDataCache(); // column lists are cached per connection
        if ($this->db->fieldExists('eoq_raw', 'pdss_computation')) {
            $this->forge->dropColumn('pdss_computation', 'eoq_raw');
        }

        if ($this->db->getPlatform() === 'MySQLi') {
            foreach (self::DEMAND_COLUMNS as $column) {
                $this->db->query("ALTER TABLE {$this->db->prefixTable('pdss_computation')} MODIFY {$column} DECIMAL(10,2) NULL DEFAULT NULL");
            }
        }
    }
}
