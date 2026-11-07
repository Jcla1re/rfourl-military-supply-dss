<?php

namespace Tests\Support;

use Config\Services;

/**
 * The tables the Reorder Alerts pages touch, created fresh on the in-memory 'tests'
 * database for each test and dropped afterwards, plus helpers to add sizes, alerts
 * and orders and to act as the Owner or a staff member. CSRF is off and email goes
 * to a fake mailer. Use inside a test class that has DatabaseTestTrait and
 * FeatureTestTrait; call setUpReorderFixture() / tearDownReorderFixture().
 */
trait ReorderFixture
{
    protected int $ownerId;
    protected int $staffId;

    private static array $reorderTables = ['users', 'suppliers', 'products', 'stock_order', 'so_item', 'reorder_alert', 'reorder_notices', 'notifications', 'notification_preferences', 'security_log', 'pdss_computation', 'dss_parameters', 'cluster_segments'];

    protected function setUpReorderFixture(): void
    {
        $filters = config('Filters');
        $filters->globals['before'] = array_values(array_diff($filters->globals['before'], ['csrf']));

        $forge = \Config\Database::forge();
        foreach (self::$reorderTables as $t) {
            $forge->dropTable($t, true);
        }
        $table = static function (string $name, array $fields, string $key) use ($forge) {
            $forge->addField($fields);
            $forge->addKey($key, true);
            $forge->createTable($name);
        };
        $id   = ['type' => 'INTEGER', 'auto_increment' => true];
        $int  = ['type' => 'INTEGER', 'null' => true];
        $str  = ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true];
        $when = ['type' => 'DATETIME', 'null' => true];
        $real = ['type' => 'REAL', 'default' => 0];

        $table('users', ['user_id' => $id, 'username' => $str, 'full_name' => $str, 'email' => $str, 'role' => $str, 'supplier_id' => $str, 'is_active' => ['type' => 'INTEGER', 'default' => 1], 'password_hash' => $str, 'last_login' => $when, 'deleted_at' => $when, 'must_change_password' => ['type' => 'INTEGER', 'default' => 0], 'is_demo' => ['type' => 'INTEGER', 'default' => 0], 'temporary_password' => $str], 'user_id');
        $table('suppliers', ['supplier_id' => ['type' => 'VARCHAR', 'constraint' => 10], 'company_name' => $str, 'contact_person' => $str, 'contact_email' => $str, 'contact_number' => $str, 'lead_time_days' => ['type' => 'INTEGER', 'default' => 7], 'is_active' => ['type' => 'INTEGER', 'default' => 1]], 'supplier_id');
        $table('products', ['item_id' => ['type' => 'VARCHAR', 'constraint' => 15], 'product_code' => $str, 'supplier_id' => $str, 'item_name' => $str, 'category' => $str, 'size' => $str, 'unit_cost' => $real, 'selling_price' => $real, 'current_stock' => ['type' => 'INTEGER', 'default' => 0], 'manual_rop_warning' => $int, 'abc_category' => $str, 'is_active' => ['type' => 'INTEGER', 'default' => 1], 'updated_at' => $when], 'item_id');
        $table('stock_order', ['so_id' => ['type' => 'VARCHAR', 'constraint' => 15], 'supplier_id' => $str, 'user_id' => $int, 'priority' => $str, 'status' => $str, 'order_date' => $when, 'expected_delivery_date' => $when, 'actual_delivery_date' => $when, 'tracking_no' => $str, 'decline_reason' => $str, 'cancel_reason' => $str, 'cancelled_by' => $int, 'cancelled_at' => $when], 'so_id');
        $table('so_item', ['so_item_id' => $id, 'so_id' => $str, 'item_id' => $str, 'order_quantity' => $int, 'shipped_quantity' => $int, 'unit_price' => $real], 'so_item_id');
        $table('reorder_alert', ['alert_id' => $id, 'item_id' => $str, 'computation_id' => $int, 'so_id' => $str, 'alert_timestamp' => $when, 'trigger_reason' => $str, 'recommended_eoq' => $int, 'stock_at_trigger' => $int, 'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'Active'], 'hidden_until_recovered' => ['type' => 'INTEGER', 'default' => 0]], 'alert_id');
        $table('reorder_notices', ['notice_id' => $id, 'item_id' => $str, 'notified_by' => $int, 'notification_id' => $int, 'created_at' => $when, 'cleared_at' => $when, 'cleared_reason' => $str], 'notice_id');
        $table('notifications', ['notification_id' => $id, 'recipient_role' => $str, 'recipient_id' => $int, 'type' => $str, 'category' => $str, 'title' => $str, 'message' => ['type' => 'TEXT', 'null' => true], 'link_url' => $str, 'is_read' => ['type' => 'INTEGER', 'default' => 0], 'status' => $str, 'action_token' => $str, 'action_user_id' => $int, 'action_expires_at' => $when, 'created_at' => $when], 'notification_id');
        $table('notification_preferences', ['id' => $id, 'rop_alerts' => ['type' => 'INTEGER', 'default' => 1], 'updated_by' => $int], 'id');
        $table('security_log', ['log_id' => $id, 'user_id' => $int, 'username' => $str, 'role' => $str, 'event' => $str, 'detail' => $str, 'ip_address' => $str, 'created_at' => $when], 'log_id');
        $table('pdss_computation', ['computation_id' => $id, 'item_id' => $str, 'computed_at' => $when, 'annual_demand' => $int, 'avg_daily_demand' => ['type' => 'REAL', 'null' => true], 'demand_std_dev' => ['type' => 'REAL', 'null' => true], 'lead_time_days' => $int, 'service_level_z' => ['type' => 'REAL', 'null' => true], 'safety_stock' => $int, 'reorder_point' => $int, 'eoq_value' => $int, 'triggered_by' => $str], 'computation_id');
        $table('dss_parameters', ['id' => $id, 'ordering_cost' => ['type' => 'REAL', 'default' => 500], 'holding_cost_per_unit' => ['type' => 'REAL', 'default' => 60], 'service_level_target' => ['type' => 'REAL', 'default' => 95], 'z_score' => ['type' => 'REAL', 'default' => 1.645], 'demand_lookback_days' => ['type' => 'INTEGER', 'default' => 730], 'minimum_order_qty' => ['type' => 'INTEGER', 'default' => 10], 'updated_by' => $int, 'updated_at' => $when], 'id');
        $table('cluster_segments', ['item_id' => ['type' => 'VARCHAR', 'constraint' => 15], 'cluster_label' => $int, 'cluster_name' => $str, 'abc_class' => $str, 'service_level' => ['type' => 'REAL', 'null' => true], 'z_score' => ['type' => 'REAL', 'null' => true], 'computed_at' => $when], 'item_id');

        $this->db->table('dss_parameters')->insert(['ordering_cost' => 500]);
        $this->db->table('notification_preferences')->insert(['rop_alerts' => 1]);
        $this->db->table('suppliers')->insertBatch([
            ['supplier_id' => 'SUP-001', 'company_name' => 'Tactical Gear Inc'],
            ['supplier_id' => 'SUP-002', 'company_name' => 'Quiapo Patches Co'],
        ]);

        $this->db->table('users')->insert(['username' => 'owner1', 'full_name' => 'Owner One', 'role' => 'Admin', 'password_hash' => 'x']);
        $this->ownerId = (int) $this->db->insertID();
        $this->db->table('users')->insert(['username' => 'staff1', 'full_name' => 'Ana Cruz', 'role' => 'Staff', 'password_hash' => 'x']);
        $this->staffId = (int) $this->db->insertID();
        $this->db->table('users')->insert(['username' => 'tgi', 'full_name' => 'Tactical Gear', 'role' => 'Supplier', 'supplier_id' => 'SUP-001', 'email' => 'supplier@example.test', 'password_hash' => 'x']);

        Services::injectMock('email', new class () {
            public function __call(string $name, array $args)
            {
                return $name === 'send' ? true : $this;
            }
        });
    }

    protected function tearDownReorderFixture(): void
    {
        $forge = \Config\Database::forge();
        foreach (self::$reorderTables as $t) {
            $forge->dropTable($t, true);
        }
    }

    /** One size (a products row) and its latest DSS numbers. */
    protected function addSize(string $id, string $name, ?string $size, int $stock, ?int $rop, array $extra = []): void
    {
        $this->db->table('products')->insert($extra + ['item_id' => $id, 'item_name' => $name, 'category' => 'Clothes', 'size' => $size, 'supplier_id' => 'SUP-001', 'unit_cost' => 50, 'current_stock' => $stock, 'manual_rop_warning' => $rop, 'is_active' => 1, 'updated_at' => '2026-11-01 10:00:00']);
        $this->db->table('pdss_computation')->insert(['item_id' => $id, 'computed_at' => '2026-11-01 10:00:00', 'eoq_value' => 12, 'safety_stock' => 1, 'reorder_point' => $rop, 'lead_time_days' => 5]);
    }

    protected function alertFor(string $itemId, string $status = 'Active', array $extra = []): int
    {
        $this->db->table('reorder_alert')->insert($extra + ['item_id' => $itemId, 'status' => $status, 'alert_timestamp' => '2026-11-01 10:00:00', 'recommended_eoq' => 12, 'trigger_reason' => 'Stock at/below reorder point', 'hidden_until_recovered' => 0]);

        return (int) $this->db->insertID();
    }

    /** An open stock order with one line per item. */
    protected function openOrder(string $soId, array $itemIds, ?string $expected, string $status = 'Order Confirmed'): void
    {
        $this->db->table('stock_order')->insert(['so_id' => $soId, 'supplier_id' => 'SUP-001', 'status' => $status, 'order_date' => '2026-11-01', 'expected_delivery_date' => $expected]);
        foreach ($itemIds as $itemId) {
            $this->db->table('so_item')->insert(['so_id' => $soId, 'item_id' => $itemId, 'order_quantity' => 12, 'unit_price' => 50]);
        }
    }

    protected function asOwner(array $extra = []): self
    {
        return $this->withSession($extra + ['isLoggedIn' => true, 'role' => 'Admin', 'user_id' => $this->ownerId, 'username' => 'owner1', 'full_name' => 'Owner One', 'last_activity' => time()]);
    }

    protected function asStaff(): self
    {
        return $this->withSession(['isLoggedIn' => true, 'role' => 'Staff', 'user_id' => $this->staffId, 'username' => 'staff1', 'full_name' => 'Ana Cruz', 'last_activity' => time()]);
    }
}
