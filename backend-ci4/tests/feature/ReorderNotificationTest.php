<?php

use App\Models\ProductModel;
use App\Models\ReorderAlertModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Reorder notifications to the Owner: the automatic one (live sync and DSS run
 * share raiseAlerts(), titles name the size, the "Reorder At Alerts" switch turns
 * it off) and the three staff "Notify Owner" buttons (one shared notice per size,
 * item name + size + staff name, honest message when the switch is off). Also
 * checks that Dismiss All Pending and Acknowledge are gone. Email goes to a fake
 * mailer. Runs on the in-memory 'tests' database.
 *
 * @internal
 */
final class ReorderNotificationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const TABLES = ['users', 'suppliers', 'products', 'stock_order', 'so_item', 'reorder_alert', 'reorder_notices', 'notifications', 'notification_preferences', 'security_log', 'pdss_computation', 'dss_parameters', 'cluster_segments', 'sales_transaction', 'sales_item'];

    protected $migrate = false;
    protected $refresh = false;

    private int $ownerId;
    private int $staffId;

    protected function setUp(): void
    {
        parent::setUp();

        $filters = config('Filters');
        $filters->globals['before'] = array_values(array_diff($filters->globals['before'], ['csrf']));

        $forge = \Config\Database::forge();
        foreach (self::TABLES as $t) {
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

        $table('users', ['user_id' => $id, 'username' => $str, 'full_name' => $str, 'email' => $str, 'role' => $str, 'supplier_id' => $str, 'is_active' => ['type' => 'INTEGER', 'default' => 1], 'password_hash' => $str, 'deleted_at' => $when, 'must_change_password' => ['type' => 'INTEGER', 'default' => 0], 'is_demo' => ['type' => 'INTEGER', 'default' => 0]], 'user_id');
        $table('suppliers', ['supplier_id' => ['type' => 'VARCHAR', 'constraint' => 10], 'company_name' => $str, 'contact_person' => $str, 'contact_email' => $str, 'contact_number' => $str, 'lead_time_days' => ['type' => 'INTEGER', 'default' => 7], 'is_active' => ['type' => 'INTEGER', 'default' => 1]], 'supplier_id');
        $table('products', ['item_id' => ['type' => 'VARCHAR', 'constraint' => 15], 'product_code' => $str, 'supplier_id' => $str, 'item_name' => $str, 'category' => $str, 'size' => $str, 'unit_cost' => $real, 'selling_price' => $real, 'current_stock' => ['type' => 'INTEGER', 'default' => 0], 'manual_rop_warning' => $int, 'abc_category' => $str, 'is_active' => ['type' => 'INTEGER', 'default' => 1], 'updated_at' => $when], 'item_id');
        $table('stock_order', ['so_id' => ['type' => 'VARCHAR', 'constraint' => 15], 'supplier_id' => $str, 'user_id' => $int, 'priority' => $str, 'status' => $str, 'order_date' => $when, 'expected_delivery_date' => $when, 'actual_delivery_date' => $when, 'tracking_no' => $str, 'decline_reason' => $str, 'cancel_reason' => $str, 'cancelled_by' => $int, 'cancelled_at' => $when, 'po_number' => $str, 'so_number' => $str, 'dr_number' => $str, 'accepted_at' => $when, 'shipped_at' => $when, 'received_by' => $int, 'received_at' => $when], 'so_id');
        $table('so_item', ['so_item_id' => $id, 'so_id' => $str, 'item_id' => $str, 'order_quantity' => $int, 'shipped_quantity' => $int, 'unit_price' => $real], 'so_item_id');
        $table('reorder_alert', ['alert_id' => $id, 'item_id' => $str, 'computation_id' => $int, 'so_id' => $str, 'alert_timestamp' => $when, 'trigger_reason' => $str, 'recommended_eoq' => $int, 'stock_at_trigger' => $int, 'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'Active'], 'hidden_until_recovered' => ['type' => 'INTEGER', 'default' => 0]], 'alert_id');
        $table('reorder_notices', ['notice_id' => $id, 'item_id' => $str, 'notified_by' => $int, 'notification_id' => $int, 'created_at' => $when, 'cleared_at' => $when, 'cleared_reason' => $str], 'notice_id');
        $table('notifications', ['notification_id' => $id, 'recipient_role' => $str, 'recipient_id' => $int, 'type' => $str, 'category' => $str, 'title' => $str, 'message' => ['type' => 'TEXT', 'null' => true], 'link_url' => $str, 'is_read' => ['type' => 'INTEGER', 'default' => 0], 'status' => $str, 'action_token' => $str, 'action_user_id' => $int, 'action_expires_at' => $when, 'created_at' => $when], 'notification_id');
        $table('notification_preferences', ['id' => $id, 'rop_alerts' => ['type' => 'INTEGER', 'default' => 1], 'updated_by' => $int], 'id');
        $table('security_log', ['log_id' => $id, 'user_id' => $int, 'username' => $str, 'role' => $str, 'event' => $str, 'detail' => $str, 'ip_address' => $str, 'created_at' => $when], 'log_id');
        $table('pdss_computation', ['computation_id' => $id, 'item_id' => $str, 'computed_at' => $when, 'annual_demand' => $int, 'avg_daily_demand' => ['type' => 'REAL', 'null' => true], 'demand_std_dev' => ['type' => 'REAL', 'null' => true], 'lead_time_days' => $int, 'service_level_z' => ['type' => 'REAL', 'null' => true], 'safety_stock' => $int, 'reorder_point' => $int, 'eoq_value' => $int, 'triggered_by' => $str], 'computation_id');
        $table('dss_parameters', ['id' => $id, 'ordering_cost' => ['type' => 'REAL', 'default' => 500], 'holding_cost_per_unit' => ['type' => 'REAL', 'default' => 60], 'service_level_target' => ['type' => 'REAL', 'default' => 95], 'z_score' => ['type' => 'REAL', 'default' => 1.645], 'demand_lookback_days' => ['type' => 'INTEGER', 'default' => 730], 'minimum_order_qty' => ['type' => 'INTEGER', 'default' => 10], 'updated_by' => $int, 'updated_at' => $when], 'id');
        $table('cluster_segments', ['item_id' => ['type' => 'VARCHAR', 'constraint' => 15], 'cluster_label' => $int, 'cluster_name' => $str, 'abc_class' => $str, 'service_level' => ['type' => 'REAL', 'null' => true], 'z_score' => ['type' => 'REAL', 'null' => true], 'computed_at' => $when], 'item_id');
        $table('sales_transaction', ['sales_id' => $id, 'receipt_no' => $str, 'user_id' => $int, 'sale_date' => $when, 'payment_method' => $str, 'cash_amount' => $real, 'gcash_amount' => $real, 'gcash_reference_no' => $str, 'subtotal' => $real, 'discount' => $real, 'total_amount' => $real], 'sales_id');
        $table('sales_item', ['sales_item_id' => $id, 'sales_id' => $int, 'item_id' => $str, 'quantity_sold' => $int, 'selling_price' => $real, 'is_bulk' => ['type' => 'INTEGER', 'default' => 0]], 'sales_item_id');

        $this->db->table('dss_parameters')->insert(['ordering_cost' => 500]);
        $this->db->table('notification_preferences')->insert(['rop_alerts' => 1]);
        $this->db->table('suppliers')->insert(['supplier_id' => 'SUP-001', 'company_name' => 'Tactical Gear Inc']);

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

    protected function tearDown(): void
    {
        $forge = \Config\Database::forge();
        foreach (self::TABLES as $t) {
            $forge->dropTable($t, true);
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------ helpers

    private function product(string $id, string $name, ?string $size, int $stock, ?int $rop): void
    {
        $this->db->table('products')->insert(['item_id' => $id, 'item_name' => $name, 'category' => 'Clothes', 'size' => $size, 'supplier_id' => 'SUP-001', 'unit_cost' => 50, 'current_stock' => $stock, 'manual_rop_warning' => $rop, 'is_active' => 1, 'updated_at' => '2026-11-01 10:00:00']);
        $this->db->table('pdss_computation')->insert(['item_id' => $id, 'computed_at' => '2026-11-01 10:00:00', 'eoq_value' => 12, 'safety_stock' => 1, 'reorder_point' => $rop, 'lead_time_days' => 5]);
    }

    private function alert(string $itemId, string $status = 'Active'): int
    {
        $this->db->table('reorder_alert')->insert(['item_id' => $itemId, 'status' => $status, 'alert_timestamp' => '2026-11-01 10:00:00', 'recommended_eoq' => 12, 'trigger_reason' => 'Stock (1) at/below reorder point (3)']);

        return (int) $this->db->insertID();
    }

    /** A row as dss:run builds it. */
    private function dssRow(string $itemId): array
    {
        return ['item_id' => $itemId, 'trigger_reason' => 'Stock (1) at/below reorder point (3)', 'recommended_eoq' => 12, 'stock_at_trigger' => 1, 'status' => 'Active'];
    }

    private function switchOff(): void
    {
        $this->db->table('notification_preferences')->update(['rop_alerts' => 0]);
    }

    /** @return list<array> */
    private function notifications(): array
    {
        return $this->db->table('notifications')->orderBy('notification_id')->get()->getResultArray();
    }

    /** @return list<array> */
    private function openNotices(): array
    {
        return $this->db->table('reorder_notices')->where('cleared_at', null)->get()->getResultArray();
    }

    private function asOwner(): self
    {
        return $this->withSession(['isLoggedIn' => true, 'role' => 'Admin', 'user_id' => $this->ownerId, 'username' => 'owner1', 'full_name' => 'Owner One', 'last_activity' => time()]);
    }

    private function asStaff(): self
    {
        return $this->withSession(['isLoggedIn' => true, 'role' => 'Staff', 'user_id' => $this->staffId, 'username' => 'staff1', 'full_name' => 'Ana Cruz', 'last_activity' => time()]);
    }

    /** Presses one staff "Notify Owner" button for this size. */
    private function pressNotify(string $page, string $itemId, int $alertId)
    {
        return match ($page) {
            'inventory'      => $this->asStaff()->post('staff/inventory/notify/' . $itemId),
            'dashboard'      => $this->asStaff()->post('staff/dashboard/notify', ['item_id' => $itemId]),
            'reorder-alerts' => $this->asStaff()->post('staff/reorder-alerts/notify/' . $alertId),
        };
    }

    // ------------------------------------------------------------ automatic notification

    public function testDssRunAlertNotifiesTheOwnerOnceWithTheSize(): void
    {
        $this->product('P-L', 'Tactical Pants', 'L', 1, 3);

        $saved = (new ReorderAlertModel())->raiseAlerts([$this->dssRow('P-L')]); // what dss:run calls

        $this->assertCount(1, $saved);
        $sent = $this->notifications();
        $this->assertCount(1, $sent);
        $this->assertSame('Admin', $sent[0]['recipient_role']);
        $this->assertSame('Reorder alert: Tactical Pants · Size L', $sent[0]['title']);
        $this->assertSame('reorder_alert', $sent[0]['category']);
        $this->assertSame('/admin/reorder-alerts', $sent[0]['link_url']);

        // The next live sync sees the DSS alert: no second alert, no second notification.
        (new ReorderAlertModel())->syncFromLiveInventory();
        $this->assertSame(1, $this->db->table('reorder_alert')->countAllResults());
        $this->assertCount(1, $this->notifications());
    }

    public function testDssRunSkipsSizesThatAlreadyHaveAnAlert(): void
    {
        $this->product('P-1', 'Whistle', null, 1, 3);
        $this->alert('P-1', 'Ordered');

        $this->assertSame([], (new ReorderAlertModel())->raiseAlerts([$this->dssRow('P-1')]));
        $this->assertSame(1, $this->db->table('reorder_alert')->countAllResults());
        $this->assertSame([], $this->notifications());
    }

    public function testDssRunCommandUsesTheSharedPath(): void
    {
        // dss:run needs the K-Means service, so it can't run here; this guards the wiring.
        $source = file_get_contents(APPPATH . 'Commands/DssRun.php');
        $this->assertStringContainsString('->raiseAlerts(', $source);
        $this->assertStringNotContainsString("table('reorder_alert')->insertBatch", $source);
    }

    public function testSyncTitleHasTheSizeOnlyWhenTheItemHasOne(): void
    {
        $this->product('P-M', 'Tactical Pants', 'M', 1, 3);
        $this->product('P-N', 'Whistle', null, 0, 2);

        (new ReorderAlertModel())->syncFromLiveInventory();

        $titles = array_column($this->notifications(), 'title');
        sort($titles);
        $this->assertSame(['Reorder alert: Tactical Pants · Size M', 'Reorder alert: Whistle'], $titles);
    }

    public function testManyNewAlertsAtOnceSendOneSummary(): void
    {
        foreach (range(1, 6) as $i) {
            $this->product("P-{$i}", "Item {$i}", null, 1, 3);
        }

        (new ReorderAlertModel())->syncFromLiveInventory();

        $sent = $this->notifications();
        $this->assertCount(1, $sent);
        $this->assertSame('6 items reached their reorder point', $sent[0]['title']);
        $this->assertSame(6, $this->db->table('reorder_alert')->countAllResults());
    }

    public function testSwitchOffStopsTheAutomaticNotificationButStillRaisesAlerts(): void
    {
        $this->switchOff();
        $this->product('P-1', 'Tactical Pants', 'L', 1, 3);
        $this->product('P-2', 'Whistle', null, 1, 3);

        (new ReorderAlertModel())->syncFromLiveInventory();
        (new ReorderAlertModel())->raiseAlerts([$this->dssRow('P-2')]);

        $this->assertSame(2, $this->db->table('reorder_alert')->countAllResults());
        $this->assertSame([], $this->notifications());
    }

    // ------------------------------------------------------------ staff "Notify Owner"

    public function testAllThreeButtonsShareOneNoticePerSize(): void
    {
        foreach (['inventory', 'dashboard', 'reorder-alerts'] as $first) {
            $this->db->table('notifications')->emptyTable();
            $this->db->table('reorder_notices')->emptyTable();
            $this->db->table('reorder_alert')->emptyTable();
            $this->db->table('products')->emptyTable();
            $this->product('P-M', 'Tactical Pants', 'M', 1, 3);
            $alertId = $this->alert('P-M');

            $this->pressNotify($first, 'P-M', $alertId)->assertSessionHas('success', 'Owner notified about Tactical Pants · Size M.');

            $sent = $this->notifications();
            $this->assertCount(1, $sent, "First press on {$first}");
            $this->assertSame('Reorder Point Notice', $sent[0]['type']);
            $this->assertSame('Reorder notice: Tactical Pants · Size M', $sent[0]['title']);
            $this->assertStringContainsString('Ana Cruz flagged this for reorder.', $sent[0]['message']);
            $this->assertSame('/admin/reorder-alerts', $sent[0]['link_url']);
            $this->assertCount(1, $this->openNotices());
            $this->assertSame($this->staffId, (int) $this->openNotices()[0]['notified_by']);

            foreach (['inventory', 'dashboard', 'reorder-alerts'] as $again) {
                $result = $this->pressNotify($again, 'P-M', $alertId);
                $this->assertStringStartsWith('The Owner was already notified about Tactical Pants · Size M. Notified by Ana Cruz · ', $_SESSION['success'] ?? '', "{$again} after {$first}");
                $result->assertRedirect();
            }
            $this->assertCount(1, $this->notifications(), "No second notification after {$first}");
            $this->assertCount(1, $this->openNotices());
        }
    }

    public function testReorderAlertsButtonNamesTheItemNotTheAlertNumber(): void
    {
        $this->product('P-N', 'Whistle', null, 1, 3);
        $alertId = $this->alert('P-N');

        $this->pressNotify('reorder-alerts', 'P-N', $alertId);

        $title = $this->notifications()[0]['title'];
        $this->assertSame('Reorder notice: Whistle', $title);
        $this->assertStringNotContainsString('alert #', $title);
    }

    public function testSwitchOffStaffButtonsSendNothingAndSayTheOwnerWasNotNotified(): void
    {
        $this->switchOff();
        $this->product('P-M', 'Tactical Pants', 'M', 1, 3);
        $alertId = $this->alert('P-M');

        foreach (['inventory', 'dashboard', 'reorder-alerts'] as $page) {
            $result = $this->pressNotify($page, 'P-M', $alertId);

            $result->assertSessionMissing('success');
            $toast = $_SESSION['toast'] ?? [];
            $this->assertSame('info', $toast['type'] ?? null, $page);
            $this->assertSame('Owner not notified', $toast['title']);
            $this->assertStringContainsString('turned off in Settings', $toast['message']);
            unset($_SESSION['toast']);
        }
        $this->assertSame([], $this->notifications());
        $this->assertSame([], $this->openNotices());
    }

    public function testNoticeClosesWhenStockRecoversAndTheSizeCanBeFlaggedAgain(): void
    {
        $this->product('P-M', 'Tactical Pants', 'M', 1, 3);
        $alertId = $this->alert('P-M');
        $this->pressNotify('dashboard', 'P-M', $alertId);

        (new ProductModel())->update('P-M', ['current_stock' => 20]);
        $this->assertSame([], $this->openNotices());
        $this->assertSame('stock_ok', $this->db->table('reorder_notices')->get()->getRow()->cleared_reason);

        (new ProductModel())->update('P-M', ['current_stock' => 1]);
        $this->pressNotify('reorder-alerts', 'P-M', $alertId)->assertSessionHas('success', 'Owner notified about Tactical Pants · Size M.');
        $this->assertSame(2, count(array_filter($this->notifications(), static fn ($n) => $n['type'] === 'Reorder Point Notice')));
    }

    public function testNoticeClosesWhenTheSizeIsOrdered(): void
    {
        $this->product('P-M', 'Tactical Pants', 'M', 1, 3);
        $alertId = $this->alert('P-M');
        $this->pressNotify('inventory', 'P-M', $alertId);

        $this->asOwner()->post('admin/orders/store', ['alert_id' => $alertId, 'supplier_id' => 'SUP-001', 'item_id' => ['P-M'], 'quantity' => [12], 'unit_price' => [50], 'priority' => 'Order']);

        $this->assertSame([], $this->openNotices());
        $this->assertSame('ordered', $this->db->table('reorder_notices')->get()->getRow()->cleared_reason);
    }

    // The staff Dashboard can't load on this test database (its sales query uses raw
    // SUM(sales_item.…), which the test table prefix doesn't rewrite): checked in the browser.
    public function testReorderAlertsPageShowsOwnerNotifiedOnceFlagged(): void
    {
        $this->product('P-M', 'Tactical Pants', 'M', 1, 3);
        $alertId = $this->alert('P-M');

        $this->assertStringContainsString('>Notify Owner</button>', $this->asStaff()->get('staff/reorder-alerts')->getBody());

        $this->pressNotify('dashboard', 'P-M', $alertId);

        $html = $this->asStaff()->get('staff/reorder-alerts')->getBody();
        $this->assertMatchesRegularExpression('/<button type="button" class="btn btn-success btn-sm notify-btn" disabled>Owner notified<\/button>/', $html);
        $this->assertStringContainsString('Notified by Ana Cruz &middot; ', $html); // esc() writes the dot as &middot;
        $this->assertStringNotContainsString('>Notify Owner</button>', $html);
    }

    // ------------------------------------------------------------ removed from the Owner page

    public function testDismissAllAndAcknowledgeAreGone(): void
    {
        $this->product('P-1', 'Whistle', null, 1, 3);
        $alertId = $this->alert('P-1');

        $html = $this->asOwner()->get('admin/reorder-alerts')->getBody();
        $this->assertStringNotContainsString('Dismiss All Pending', $html);
        $this->assertStringNotContainsString('resolve-all', $html);
        $this->assertStringNotContainsString('>Acknowledge<', $html);
        $this->assertStringContainsString('reorder-alerts/dismiss/' . $alertId, $html, 'Single Dismiss stays.');

        foreach (['admin/reorder-alerts/resolve-all', 'admin/reorder-alerts/acknowledge/' . $alertId] as $url) {
            try {
                $status = $this->asOwner()->post($url)->response()->getStatusCode();
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $status = 404;
            }
            $this->assertSame(404, $status, $url);
        }
        $this->assertSame('Active', $this->db->table('reorder_alert')->get()->getRow()->status);
    }
}
