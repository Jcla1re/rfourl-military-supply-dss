<?php

use App\Models\ReorderAlertModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Reorder Alerts: the Order dialog names the size and the order is saved for that size row;
 * archived items keep their alerts through the sync; alerts are listed most urgent first; no fixed
 * "Last updated" text. Email goes to a fake mailer. Runs on the in-memory 'tests' database.
 *
 * @internal
 */
final class ReorderAlertFixesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const TABLES = ['users', 'suppliers', 'products', 'stock_order', 'so_item', 'reorder_alert', 'reorder_notices', 'notifications', 'notification_preferences', 'security_log', 'pdss_computation', 'dss_parameters', 'cluster_segments'];

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

        $table('users', ['user_id' => $id, 'username' => $str, 'full_name' => $str, 'email' => $str, 'role' => $str, 'supplier_id' => $str, 'is_active' => ['type' => 'INTEGER', 'default' => 1], 'password_hash' => $str, 'deleted_at' => $when, 'must_change_password' => ['type' => 'INTEGER', 'default' => 0], 'is_demo' => ['type' => 'INTEGER', 'default' => 0]], 'user_id');
        $table('suppliers', ['supplier_id' => ['type' => 'VARCHAR', 'constraint' => 10], 'company_name' => $str, 'contact_person' => $str, 'contact_email' => $str, 'contact_number' => $str, 'lead_time_days' => ['type' => 'INTEGER', 'default' => 7], 'is_active' => ['type' => 'INTEGER', 'default' => 1]], 'supplier_id');
        $table('products', ['item_id' => ['type' => 'VARCHAR', 'constraint' => 15], 'product_code' => $str, 'supplier_id' => $str, 'item_name' => $str, 'category' => $str, 'size' => $str, 'unit_cost' => ['type' => 'REAL', 'default' => 0], 'selling_price' => ['type' => 'REAL', 'default' => 0], 'current_stock' => ['type' => 'INTEGER', 'default' => 0], 'manual_rop_warning' => $int, 'abc_category' => $str, 'is_active' => ['type' => 'INTEGER', 'default' => 1], 'updated_at' => $when], 'item_id');
        $table('stock_order', ['so_id' => ['type' => 'VARCHAR', 'constraint' => 15], 'supplier_id' => $str, 'user_id' => $int, 'priority' => $str, 'status' => $str, 'order_date' => $when, 'expected_delivery_date' => $when, 'actual_delivery_date' => $when, 'tracking_no' => $str, 'decline_reason' => $str, 'cancel_reason' => $str, 'cancelled_by' => $int, 'cancelled_at' => $when, 'po_number' => $str, 'so_number' => $str, 'dr_number' => $str, 'accepted_at' => $when, 'shipped_at' => $when, 'received_by' => $int, 'received_at' => $when], 'so_id');
        $table('so_item', ['so_item_id' => $id, 'so_id' => $str, 'item_id' => $str, 'order_quantity' => $int, 'shipped_quantity' => $int, 'unit_price' => ['type' => 'REAL', 'default' => 0]], 'so_item_id');
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
        $this->db->table('suppliers')->insert(['supplier_id' => 'SUP-001', 'company_name' => 'Tactical Gear Inc']);

        $this->db->table('users')->insert(['username' => 'owner1', 'full_name' => 'Owner One', 'role' => 'Admin', 'password_hash' => 'x']);
        $this->ownerId = (int) $this->db->insertID();
        $this->db->table('users')->insert(['username' => 'staff1', 'full_name' => 'Staff One', 'role' => 'Staff', 'password_hash' => 'x']);
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

    private function product(string $id, string $name, ?string $size, int $stock, ?int $rop, int $active = 1): void
    {
        $this->db->table('products')->insert(['item_id' => $id, 'item_name' => $name, 'category' => 'Clothes', 'size' => $size, 'supplier_id' => 'SUP-001', 'unit_cost' => 50, 'current_stock' => $stock, 'manual_rop_warning' => $rop, 'is_active' => $active, 'updated_at' => '2026-11-01 10:00:00']);
        $this->db->table('pdss_computation')->insert(['item_id' => $id, 'computed_at' => '2026-11-01 10:00:00', 'eoq_value' => 12, 'safety_stock' => 1, 'reorder_point' => $rop, 'lead_time_days' => 5]);
    }

    private function alert(string $itemId, string $status = 'Active', string $at = '2026-11-01 10:00:00', int $hidden = 0): int
    {
        $this->db->table('reorder_alert')->insert(['item_id' => $itemId, 'status' => $status, 'alert_timestamp' => $at, 'recommended_eoq' => 12, 'hidden_until_recovered' => $hidden]);

        return (int) $this->db->insertID();
    }

    private function alertRow(int $id): object
    {
        return $this->db->table('reorder_alert')->where('alert_id', $id)->get()->getRow();
    }

    private function asOwner(): self
    {
        return $this->withSession(['isLoggedIn' => true, 'role' => 'Admin', 'user_id' => $this->ownerId, 'username' => 'owner1', 'full_name' => 'Owner One', 'last_activity' => time()]);
    }

    private function asStaff(): self
    {
        return $this->withSession(['isLoggedIn' => true, 'role' => 'Staff', 'user_id' => $this->staffId, 'username' => 'staff1', 'full_name' => 'Staff One', 'last_activity' => time()]);
    }

    /** Alert IDs in the order the Owner page lists them. */
    private function listedOnOwnerPage(): array
    {
        $html = $this->asOwner()->get('admin/reorder-alerts')->getBody();
        preg_match_all('/data-alert-id="(\d+)"/', $html, $m);

        return array_map('intval', $m[1]);
    }

    // ------------------------------------------------------------ 1. size in the Order dialog

    public function testAlertOrderButtonNamesTheSize(): void
    {
        $this->product('P-S', 'Olive Green (OG) Cotton', 'S', 1, 3);
        $this->product('P-N', 'Whistle', null, 1, 2);
        $this->alert('P-S');
        $this->alert('P-N');

        $html = $this->asOwner()->get('admin/reorder-alerts')->getBody();

        // Each Order button carries its size's dialog data as JSON (data-line; the page output
        // filter writes that attribute in single quotes).
        preg_match_all('/data-line=(["\'])(.*?)\1/', $html, $m);
        $names = array_column(array_map(static fn ($a) => json_decode(html_entity_decode($a, ENT_QUOTES), true), $m[2]), 'name', 'item_id');
        $this->assertSame('Olive Green (OG) Cotton · Size S', $names['P-S']);
        $this->assertSame('Whistle', $names['P-N']);
    }

    public function testReportOrderButtonNamesTheSize(): void
    {
        $this->product('P-S', 'Olive Green (OG) Cotton', 'S', 1, 3);
        $this->product('P-M', 'Olive Green (OG) Cotton', 'M', 2, 3);
        $this->product('P-N', 'Whistle', null, 1, 2);

        $html = $this->asOwner()->get('admin/procurement-report')->getBody();

        $this->assertMatchesRegularExpression('/data-item-id="P-S"\s+data-item-name="Olive Green \(OG\) Cotton &middot; Size S"/', $html);
        $this->assertMatchesRegularExpression('/data-item-id="P-M"\s+data-item-name="Olive Green \(OG\) Cotton &middot; Size M"/', $html);
        $this->assertMatchesRegularExpression('/data-item-id="P-N"\s+data-item-name="Whistle"/', $html);
    }

    public function testOrderIsSavedForThatExactSizeRow(): void
    {
        $this->product('P-S', 'Olive Green (OG) Cotton', 'S', 1, 3);
        $this->product('P-M', 'Olive Green (OG) Cotton', 'M', 1, 3);
        $small  = $this->alert('P-S');
        $medium = $this->alert('P-M');

        $this->asOwner()->post('admin/orders/store', ['alert_id' => $small, 'supplier_id' => 'SUP-001', 'item_id' => ['P-S'], 'quantity' => [12], 'unit_price' => [50], 'priority' => 'Order']);

        $lines = $this->db->table('so_item')->get()->getResultArray();
        $this->assertCount(1, $lines);
        $this->assertSame('P-S', $lines[0]['item_id']);
        $this->assertSame('Ordered', $this->alertRow($small)->status);
        $this->assertSame($lines[0]['so_id'], $this->alertRow($small)->so_id);
        $this->assertSame('Active', $this->alertRow($medium)->status, 'The other size is not covered by this order.');
    }

    // ------------------------------------------------------------ 2. archived items

    public function testSyncKeepsAnArchivedItemsAlertAndRestoreBringsItBack(): void
    {
        $this->product('P-4', 'Archived Item', null, 1, 3, 0);
        $alert = $this->alert('P-4', 'Acknowledged');

        (new ReorderAlertModel())->syncFromLiveInventory();

        $this->assertSame('Acknowledged', $this->alertRow($alert)->status);
        $this->assertSame([], $this->listedOnOwnerPage(), 'Hidden while archived.');

        $this->db->table('products')->where('item_id', 'P-4')->update(['is_active' => 1]);

        $this->assertSame([$alert], $this->listedOnOwnerPage(), 'The same alert returns, with no duplicate.');
        $this->assertSame(1, $this->db->table('reorder_alert')->countAllResults());
    }

    public function testSyncStillClosesAnActiveItemsAlertOnceStockRecovers(): void
    {
        $this->product('P-1', 'Recovered Item', null, 50, 3);
        $alert = $this->alert('P-1');

        (new ReorderAlertModel())->syncFromLiveInventory();

        $this->assertSame('Dismissed', $this->alertRow($alert)->status);
        $this->assertSame(0, (int) $this->alertRow($alert)->hidden_until_recovered);
    }

    // ------------------------------------------------------------ 3. most urgent first

    public function testAlertsAreListedMostUrgentFirst(): void
    {
        // Timestamps run the opposite way, so the old "newest first" order would fail.
        $this->product('P-1', 'Mike Half', null, 3, 6);       // 0.50
        $this->product('P-2', 'Alpha Half', null, 2, 4);      // 0.50, same as above: name decides
        $this->product('P-3', 'Zulu Low', null, 1, 10);       // 0.10, must not be lost to whole-number division
        $this->product('P-4', 'Zulu Zero', null, 0, 2);       // stock 0
        $this->product('P-5', 'Bravo Zero', null, 0, 9);      // stock 0: name decides
        $this->product('P-6', 'Pants', 'M', 3, 3);            // 1.00
        $this->product('P-7', 'Pants', 'S', 3, 3);            // 1.00, same name: size decides

        $half2 = $this->alert('P-1', 'Active', '2026-11-07 10:00:00');
        $half1 = $this->alert('P-2', 'Acknowledged', '2026-11-06 10:00:00');
        $low   = $this->alert('P-3', 'Active', '2026-11-05 10:00:00');
        $zero2 = $this->alert('P-4', 'Active', '2026-11-04 10:00:00');
        $zero1 = $this->alert('P-5', 'Active', '2026-11-03 10:00:00');
        $full2 = $this->alert('P-7', 'Active', '2026-11-02 10:00:00');
        $full1 = $this->alert('P-6', 'Active', '2026-11-01 10:00:00');

        $expected = [$zero1, $zero2, $low, $half1, $half2, $full1, $full2];

        $this->assertSame($expected, array_map('intval', array_column((new ReorderAlertModel())->openAlerts(), 'alert_id')));
        $this->assertSame($expected, $this->listedOnOwnerPage());

        $staff = $this->asStaff()->get('staff/reorder-alerts')->getBody();
        $this->assertLessThan(strpos($staff, 'Mike Half'), strpos($staff, 'Bravo Zero'));
        $this->assertLessThan(strpos($staff, 'Zulu Low'), strpos($staff, 'Zulu Zero'));
    }

    public function testRevealedDismissedAlertsUseTheSameOrder(): void
    {
        $this->product('P-1', 'Zulu Low', null, 1, 10);
        $this->product('P-2', 'Alpha Zero', null, 0, 5);
        $this->product('P-3', 'Bravo High', null, 3, 4);
        $high = $this->alert('P-3', 'Dismissed', '2026-11-07 10:00:00', 1);
        $low  = $this->alert('P-1', 'Dismissed', '2026-11-06 10:00:00', 1);
        $zero = $this->alert('P-2', 'Dismissed', '2026-11-05 10:00:00', 1);

        $this->assertSame([$zero, $low, $high], array_map('intval', array_column((new ReorderAlertModel())->dismissedAlerts(), 'alert_id')));
    }

    public function testASizeWithNoReorderPointSortsLast(): void
    {
        $this->product('P-1', 'Alpha No Rop', null, 1, null);
        $this->product('P-2', 'Zulu Full', null, 5, 5);
        $none = $this->alert('P-1');
        $full = $this->alert('P-2');

        $this->assertSame([$full, $none], array_map('intval', array_column((new ReorderAlertModel())->openAlerts(), 'alert_id')));
    }

    // ------------------------------------------------------------ 4. no fixed "Last updated"

    public function testNoLastUpdatedTextOnEitherPage(): void
    {
        $this->product('P-1', 'Item', null, 1, 3);
        $this->alert('P-1');

        $owner = $this->asOwner()->get('admin/reorder-alerts')->getBody();
        $this->assertStringNotContainsString('Last updated', $owner);
        $this->assertStringContainsString('Ordering Report', $owner);

        $staff = $this->asStaff()->get('staff/reorder-alerts');
        $staff->assertOK();
        $this->assertStringNotContainsString('Last updated', $staff->getBody());
    }
}
