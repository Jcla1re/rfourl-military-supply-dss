<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Replaces reorder_alert's 2-state status (Open/Resolved) with the 5-state
 * workflow documented in the capstone's Data Dictionary — Active,
 * Acknowledged, Ordered, Fulfilled, Dismissed — and adds so_id so a stock
 * order created from an alert can be traced back to it (needed to
 * auto-transition the alert to Fulfilled when that order is delivered).
 *
 * The status change is done in three steps (widen -> remap -> narrow) so
 * no existing row's value is ever outside the column's allowed ENUM set,
 * regardless of SQL mode.
 */
class UpdateReorderAlertWorkflow extends Migration
{
    public function up()
    {
        $this->forge->addColumn('reorder_alert', [
            'so_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
                'after'      => 'computation_id',
            ],
        ]);

        // 1) Widen to the union of old + new values — safe, nothing is dropped yet.
        $this->db->query(
            "ALTER TABLE `reorder_alert` MODIFY `status` "
            . "ENUM('Open','Resolved','Active','Acknowledged','Ordered','Fulfilled','Dismissed') NOT NULL DEFAULT 'Open'"
        );

        // 2) Remap existing data into the new vocabulary.
        $this->db->query("UPDATE `reorder_alert` SET status = 'Active' WHERE status = 'Open'");
        $this->db->query("UPDATE `reorder_alert` SET status = 'Fulfilled' WHERE status = 'Resolved'");

        // 3) Narrow to the final 5-value set now that no row uses the old labels.
        $this->db->query(
            "ALTER TABLE `reorder_alert` MODIFY `status` "
            . "ENUM('Active','Acknowledged','Ordered','Fulfilled','Dismissed') NOT NULL DEFAULT 'Active'"
        );
    }

    public function down()
    {
        $this->db->query(
            "ALTER TABLE `reorder_alert` MODIFY `status` "
            . "ENUM('Open','Resolved','Active','Acknowledged','Ordered','Fulfilled','Dismissed') NOT NULL DEFAULT 'Active'"
        );

        $this->db->query("UPDATE `reorder_alert` SET status = 'Open' WHERE status IN ('Active','Acknowledged','Ordered')");
        $this->db->query("UPDATE `reorder_alert` SET status = 'Resolved' WHERE status IN ('Fulfilled','Dismissed')");

        $this->db->query(
            "ALTER TABLE `reorder_alert` MODIFY `status` ENUM('Open','Resolved') NOT NULL DEFAULT 'Open'"
        );

        $this->forge->dropColumn('reorder_alert', 'so_id');
    }
}
