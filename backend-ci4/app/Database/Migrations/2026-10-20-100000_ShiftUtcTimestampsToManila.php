<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The app used to run on UTC (Config\App::$appTimezone) while MySQL ran on
 * the computer's local time (+08:00). Columns that PHP filled with date()
 * therefore hold UTC, 8 hours behind Philippine time. Now that the app and
 * every DB session use Asia/Manila, this moves those existing values to
 * Philippine time so old and new rows line up:
 *
 *   security_log.created_at, users.last_login,
 *   analytics_snapshot.generated_at, app_settings.updated_at
 *
 * Deliberately NOT shifted: products.updated_at (mixes PHP-written and
 * MySQL-default values that can't be told apart) and the stock_order dates
 * (date-only; the 6 app-created orders were checked by hand).
 * sales_transaction holds no POS sales yet (imported history is stamped
 * 12:00, date-only), so nothing there moves.
 *
 * Reversible: up() records the switch moment (Philippine time); every
 * shifted value is at or before it, every row written afterwards is after
 * it, so down() takes the 8 hours back off exactly the rows up() moved.
 * Run `php spark migrate` right after pulling the timezone change.
 */
class ShiftUtcTimestampsToManila extends Migration
{
    private const COLUMNS = [
        ['security_log', 'created_at'],
        ['users', 'last_login'],
        ['analytics_snapshot', 'generated_at'],
        ['app_settings', 'updated_at'],
    ];

    private const BOUNDARY_KEY = 'timezone_shift_boundary';

    public function up()
    {
        // Philippine time now, from MySQL itself (the app's connection runs at +08:00).
        $boundary = $this->db->query("SELECT CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '+08:00') AS b")->getRow()->b;

        $this->db->transStart();
        foreach (self::COLUMNS as [$table, $column]) {
            $this->db->query("UPDATE `{$table}` SET `{$column}` = `{$column}` + INTERVAL 8 HOUR WHERE `{$column}` IS NOT NULL");
        }
        $this->db->table('app_settings')->replace([
            'setting_key'   => self::BOUNDARY_KEY,
            'setting_value' => $boundary,
            'updated_by'    => null,
            'updated_at'    => $boundary,
        ]);
        $this->db->transComplete();
    }

    public function down()
    {
        $row = $this->db->table('app_settings')->where('setting_key', self::BOUNDARY_KEY)->get()->getRow();
        if (! $row) {
            return; // up() never ran here
        }

        $this->db->transStart();
        $this->db->table('app_settings')->where('setting_key', self::BOUNDARY_KEY)->delete();
        foreach (self::COLUMNS as [$table, $column]) {
            $this->db->query(
                "UPDATE `{$table}` SET `{$column}` = `{$column}` - INTERVAL 8 HOUR WHERE `{$column}` IS NOT NULL AND `{$column}` <= ?",
                [$row->setting_value]
            );
        }
        $this->db->transComplete();
    }
}
