<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds the analytics_snapshot table documented in the capstone's Data
 * Dictionary (Table 24) but never actually built — it's the BI reporting
 * ledger for historical trend/analytics data. DssRun now writes to it
 * (see App\Commands\DssRun::writeAnalyticsSnapshots()).
 */
class CreateAnalyticsSnapshot extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'snapshot_id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => false,
            ],
            'generated_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'metric_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => false,
            ],
            'period' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => false,
            ],
            'metric_value' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,4',
                'null'       => false,
            ],
            'raw_data' => [
                'type' => 'JSON',
                'null' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('snapshot_id');
        // No DB-level FK to users — consistent with the rest of this schema
        // (stock_order.supplier_id, reorder_alert.item_id, etc. are all
        // resolved via application-level joins, not enforced constraints).
        $this->forge->addKey('user_id');
        $this->forge->addKey(['metric_type', 'period']);
        $this->forge->createTable('analytics_snapshot');
    }

    public function down()
    {
        $this->forge->dropTable('analytics_snapshot');
    }
}
