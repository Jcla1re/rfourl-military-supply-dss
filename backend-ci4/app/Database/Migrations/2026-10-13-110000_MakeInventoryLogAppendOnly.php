<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * inventory_log is an audit trail: rows may be added, never edited or
 * deleted. The application has no screen for it; these triggers make the
 * database refuse it as well (direct SQL, a future bug, a careless script).
 * Cascading deletes done by the database itself do not fire triggers.
 */
class MakeInventoryLogAppendOnly extends Migration
{
    public function up()
    {
        $this->db->query('DROP TRIGGER IF EXISTS inventory_log_no_update');
        $this->db->query('DROP TRIGGER IF EXISTS inventory_log_no_delete');

        $this->db->query(
            "CREATE TRIGGER inventory_log_no_update BEFORE UPDATE ON inventory_log FOR EACH ROW "
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inventory_log is append-only: entries cannot be edited'"
        );
        $this->db->query(
            "CREATE TRIGGER inventory_log_no_delete BEFORE DELETE ON inventory_log FOR EACH ROW "
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inventory_log is append-only: entries cannot be deleted'"
        );
    }

    public function down()
    {
        $this->db->query('DROP TRIGGER IF EXISTS inventory_log_no_update');
        $this->db->query('DROP TRIGGER IF EXISTS inventory_log_no_delete');
    }
}
