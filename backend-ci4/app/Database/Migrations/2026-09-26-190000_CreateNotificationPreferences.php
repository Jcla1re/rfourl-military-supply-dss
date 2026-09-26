<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The Settings > Notifications tab previously rendered five toggle
 * switches with `disabled` and hardcoded `checked` state — nothing was
 * actually saved. This gives them a real, single-row settings table (same
 * "one authoritative row" pattern as dss_parameters).
 */
class CreateNotificationPreferences extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'                     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'rop_alerts'             => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'low_stock_warnings'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'daily_sales_summary'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'procurement_reminders'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'weekly_trend_report'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'updated_by'             => ['type' => 'INT', 'constraint' => 11, 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('notification_preferences');

        $this->db->table('notification_preferences')->insert([
            'rop_alerts'            => 1,
            'low_stock_warnings'    => 1,
            'daily_sales_summary'   => 1,
            'procurement_reminders' => 1,
            'weekly_trend_report'   => 0,
        ]);
    }

    public function down()
    {
        $this->forge->dropTable('notification_preferences');
    }
}
