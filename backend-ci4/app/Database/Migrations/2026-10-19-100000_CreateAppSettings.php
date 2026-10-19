<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Small key/value store for system-wide settings that aren't DSS inputs
 * (those stay in dss_parameters), e.g. the sales record retention period
 * set in Settings > Data & Records. Read through App\Models\AppSettingModel,
 * which falls back to each setting's default when a key isn't stored yet.
 */
class CreateAppSettings extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'setting_key'   => ['type' => 'VARCHAR', 'constraint' => 50],
            'setting_value' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'updated_by'    => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('setting_key', true);
        $this->forge->createTable('app_settings', true);
    }

    public function down()
    {
        $this->forge->dropTable('app_settings', true);
    }
}
