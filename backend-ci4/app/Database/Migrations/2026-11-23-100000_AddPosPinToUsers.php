<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The personal 4-digit checkout PIN asked for at every POS checkout (App\Libraries\PosPin).
 * Only a hash is stored. Existing users start without one and set it themselves, confirmed
 * with their account password. Adds columns only; safe to run twice.
 *
 *   pos_pin_hash      password_hash() of the PIN, null = no PIN yet (or reset by the owner)
 *   pos_pin_set_at    when the user last set it
 *   pos_pin_reset_at  when the owner last reset it (cleared when the user sets a new one)
 */
class AddPosPinToUsers extends Migration
{
    private const COLUMNS = [
        'pos_pin_hash'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        'pos_pin_set_at'   => ['type' => 'DATETIME', 'null' => true],
        'pos_pin_reset_at' => ['type' => 'DATETIME', 'null' => true],
    ];

    public function up()
    {
        foreach (self::COLUMNS as $name => $definition) {
            if (! $this->db->fieldExists($name, 'users')) {
                $this->forge->addColumn('users', [$name => $definition]);
            }
        }
    }

    public function down()
    {
        foreach (array_keys(self::COLUMNS) as $name) {
            if ($this->db->fieldExists($name, 'users')) {
                $this->forge->dropColumn('users', $name);
            }
        }
    }
}
