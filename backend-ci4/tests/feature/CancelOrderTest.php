<?php

use App\Models\StockOrderModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * The owner's "Cancel order": allowed only for Order Confirmed and Preparing, needs a reason,
 * tells the supplier, puts reorder alerts back to Active and never touches stock on hand.
 * Email goes to a fake mailer. Runs on the in-memory 'tests' database.
 *
 * @internal
 */
final class CancelOrderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    private int $adminId;
    private int $supplierUserId;
    private object $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        $filters = config('Filters');
        $filters->globals['before'] = array_values(array_diff($filters->globals['before'], ['csrf']));

        $forge = \Config\Database::forge();
        foreach (['users', 'suppliers', 'products', 'stock_order', 'so_item', 'reorder_alert', 'notifications', 'security_log'] as $t) {
            $forge->dropTable($t, true);
        }
        $table = function (string $name, array $fields, string $key) use ($forge) {
            $forge->addField($fields);
            $forge->addKey($key, true);
            $forge->createTable($name);
        };
        $table('users', [
            'user_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'username' => ['type' => 'VARCHAR', 'constraint' => 50],
            'full_name' => ['type' => 'VARCHAR', 'constraint' => 100], 'email' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'role' => ['type' => 'VARCHAR', 'constraint' => 20], 'supplier_id' => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'is_active' => ['type' => 'INTEGER', 'default' => 1], 'password_hash' => ['type' => 'VARCHAR', 'constraint' => 255],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ], 'user_id');
        $table('suppliers', [
            'supplier_id' => ['type' => 'VARCHAR', 'constraint' => 10], 'company_name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'contact_person' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true], 'contact_email' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'contact_number' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true], 'lead_time_days' => ['type' => 'INTEGER', 'default' => 7],
            'is_active' => ['type' => 'INTEGER', 'default' => 1],
        ], 'supplier_id');
        $table('products', [
            'item_id' => ['type' => 'VARCHAR', 'constraint' => 15], 'item_name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'category' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true], 'size' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'unit_cost' => ['type' => 'REAL', 'default' => 0], 'current_stock' => ['type' => 'INTEGER', 'default' => 0],
            'manual_rop_warning' => ['type' => 'INTEGER', 'default' => 0], 'is_active' => ['type' => 'INTEGER', 'default' => 1],
        ], 'item_id');
        $table('stock_order', [
            'so_id' => ['type' => 'VARCHAR', 'constraint' => 15], 'supplier_id' => ['type' => 'VARCHAR', 'constraint' => 10], 'user_id' => ['type' => 'INTEGER', 'null' => true],
            'priority' => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'Order'], 'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'Order Confirmed'],
            'order_date' => ['type' => 'DATE'], 'expected_delivery_date' => ['type' => 'DATE', 'null' => true], 'actual_delivery_date' => ['type' => 'DATE', 'null' => true],
            'tracking_no' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true], 'decline_reason' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'cancel_reason' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true], 'cancelled_by' => ['type' => 'INTEGER', 'null' => true],
            'cancelled_at' => ['type' => 'DATETIME', 'null' => true],
        ], 'so_id');
        $table('so_item', [
            'so_item_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'so_id' => ['type' => 'VARCHAR', 'constraint' => 15], 'item_id' => ['type' => 'VARCHAR', 'constraint' => 15],
            'order_quantity' => ['type' => 'INTEGER'], 'shipped_quantity' => ['type' => 'INTEGER', 'null' => true], 'unit_price' => ['type' => 'REAL'],
        ], 'so_item_id');
        $table('reorder_alert', [
            'alert_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'item_id' => ['type' => 'VARCHAR', 'constraint' => 15], 'so_id' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'Active'],
        ], 'alert_id');
        $table('notifications', [
            'notification_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'recipient_role' => ['type' => 'VARCHAR', 'constraint' => 20], 'recipient_id' => ['type' => 'INTEGER', 'null' => true],
            'type' => ['type' => 'VARCHAR', 'constraint' => 50], 'category' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true], 'title' => ['type' => 'VARCHAR', 'constraint' => 255],
            'message' => ['type' => 'TEXT', 'null' => true], 'link_url' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true], 'is_read' => ['type' => 'INTEGER', 'default' => 0],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true], 'action_token' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'action_user_id' => ['type' => 'INTEGER', 'null' => true], 'action_expires_at' => ['type' => 'DATETIME', 'null' => true], 'created_at' => ['type' => 'DATETIME', 'null' => true],
        ], 'notification_id');
        $table('security_log', [
            'log_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'user_id' => ['type' => 'INTEGER', 'null' => true], 'username' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'role' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true], 'event' => ['type' => 'VARCHAR', 'constraint' => 50], 'detail' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true], 'created_at' => ['type' => 'DATETIME', 'null' => true],
        ], 'log_id');

        $this->db->table('suppliers')->insert(['supplier_id' => 'SUP-001', 'company_name' => 'Tactical Gear Inc', 'contact_person' => 'Ana']);
        $this->adminId        = $this->makeUser('owner1', 'Owner One', 'Admin', null, 'owner@example.test');
        $this->supplierUserId = $this->makeUser('tgi', 'Tactical Gear', 'Supplier', 'SUP-001', 'supplier@example.test');
        $this->db->table('products')->insert(['item_id' => 'P-1', 'item_name' => 'Tactical Pants', 'size' => 'M', 'unit_cost' => 100, 'current_stock' => 12]);

        $this->mailer = new class () {
            public array $sent = [];
            private array $current = [];

            public function __call(string $name, array $args)
            {
                if ($name === 'send') {
                    $this->sent[]  = $this->current;
                    $this->current = [];

                    return true;
                }
                $this->current[$name] = $args[0] ?? null;

                return $this;
            }
        };
        Services::injectMock('email', $this->mailer);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        foreach (['users', 'suppliers', 'products', 'stock_order', 'so_item', 'reorder_alert', 'notifications', 'security_log'] as $t) {
            \Config\Database::forge()->dropTable($t, true);
        }
        parent::tearDown();
    }

    private function makeUser(string $username, string $name, string $role, ?string $supplierId, string $email): int
    {
        $this->db->table('users')->insert(['username' => $username, 'full_name' => $name, 'role' => $role, 'supplier_id' => $supplierId, 'email' => $email, 'is_active' => 1, 'password_hash' => 'x']);

        return (int) $this->db->insertID();
    }

    private function order(string $status, string $id = 'SO-0001'): string
    {
        $this->db->table('stock_order')->insert(['so_id' => $id, 'supplier_id' => 'SUP-001', 'status' => $status, 'priority' => 'Urgent', 'order_date' => '2026-10-01']);
        $this->db->table('so_item')->insert(['so_id' => $id, 'item_id' => 'P-1', 'order_quantity' => 10, 'unit_price' => 100]);
        $this->db->table('reorder_alert')->insert(['item_id' => 'P-1', 'so_id' => $id, 'status' => 'Ordered']);

        return $id;
    }

    private function row(string $id = 'SO-0001'): object
    {
        return $this->db->table('stock_order')->where('so_id', $id)->get()->getRow();
    }

    private function stock(): int
    {
        return (int) $this->db->table('products')->where('item_id', 'P-1')->get()->getRow()->current_stock;
    }

    private function asOwner(): self
    {
        return $this->withSession(['isLoggedIn' => true, 'role' => 'Admin', 'user_id' => $this->adminId, 'username' => 'owner1', 'full_name' => 'Owner One', 'last_activity' => time()]);
    }

    private function cancel(string $id, array $post = ['reason' => 'Ordered by mistake'])
    {
        return $this->asOwner()->post("admin/orders/cancel/{$id}", $post);
    }

    // ------------------------------------------------------------ allowed

    public function testCancelsAnOrderConfirmedOrder(): void
    {
        $id = $this->order('Order Confirmed');

        $result = $this->cancel($id);

        $result->assertRedirectTo(site_url('/admin/orders'));
        $row = $this->row($id);
        $this->assertSame('Cancelled', $row->status);
        $this->assertSame('Ordered by mistake', $row->cancel_reason);
        $this->assertSame($this->adminId, (int) $row->cancelled_by);
        $this->assertEqualsWithDelta(time(), strtotime($row->cancelled_at), 10);

        $toast = $_SESSION['toast'] ?? [];
        $this->assertSame('Order SO-0001 cancelled', $toast['title'] ?? null);
        $this->assertSame('Its reorder alerts are active again.', $toast['message'] ?? null);
        $this->assertStringContainsString('reorder=SO-0001', $toast['action']['url'] ?? '');
        $this->assertSame(1, $this->db->table('security_log')->where('event', 'order_cancelled')->countAllResults());
    }

    public function testCancelsAPreparingOrderAndApologises(): void
    {
        $id = $this->order('Preparing');

        $this->cancel($id, ['reason' => 'Wrong item or quantity']);

        $this->assertSame('Cancelled', $this->row($id)->status);
        $mail = $this->mailer->sent[0];
        $this->assertStringContainsString("We're sorry for any work already started.", $mail['setMessage']);
    }

    public function testSupplierIsToldAndNotToPrepareOrShip(): void
    {
        $id = $this->order('Order Confirmed');

        $this->cancel($id, ['reason' => 'No longer needed']);

        $n = $this->db->table('notifications')->get()->getRow();
        $this->assertSame('Supplier', $n->recipient_role);
        $this->assertSame($this->supplierUserId, (int) $n->recipient_id);
        $this->assertSame("Order SO-0001 was cancelled by RfourL. Reason: No longer needed. Please don't prepare or ship it.", $n->message);

        $mail = $this->mailer->sent[0];
        $this->assertSame('supplier@example.test', $mail['setTo']);
        $this->assertStringNotContainsString('sorry', $mail['setMessage'], 'only a Preparing order gets the apology');
    }

    public function testReorderAlertsGoBackToActiveAndStockIsUntouched(): void
    {
        $id = $this->order('Preparing');

        $this->cancel($id);

        $alert = $this->db->table('reorder_alert')->get()->getRow();
        $this->assertSame('Active', $alert->status);
        $this->assertNull($alert->so_id);
        $this->assertSame(12, $this->stock());
    }

    // ------------------------------------------------------------ refused

    public static function notCancellable(): array
    {
        return ['shipped' => ['Shipped'], 'delivered' => ['Delivered'], 'already cancelled' => ['Cancelled']];
    }

    /**
     * @dataProvider notCancellable
     */
    public function testRefusedAfterShipping(string $status): void
    {
        $id = $this->order($status);

        $this->cancel($id);

        $this->assertSame($status, $this->row($id)->status);
        $this->assertNull($this->row($id)->cancel_reason);
        $this->assertSame('Ordered', $this->db->table('reorder_alert')->get()->getRow()->status, 'alerts stay linked');
        $this->assertSame(0, $this->db->table('notifications')->countAllResults());
        $this->assertSame([], $this->mailer->sent);
        $this->assertSame(12, $this->stock());
    }

    public function testShippedGetsTheContactTheSupplierMessage(): void
    {
        $id = $this->order('Shipped');

        $this->cancel($id);

        $this->assertStringContainsString('Contact the supplier', json_encode($_SESSION['toast'] ?? []));
    }

    public function testUnknownOrderIsRefused(): void
    {
        $this->cancel('SO-9999')->assertRedirectTo(site_url('/admin/orders'));
        $this->assertSame(0, $this->db->table('notifications')->countAllResults());
    }

    public function testUpdateStatusCanNotCancelOrReviveAnOrder(): void
    {
        foreach (['Order Confirmed', 'Preparing', 'Shipped', 'Delivered'] as $i => $status) {
            $id = $this->order($status, 'SO-10' . $i);
            $this->asOwner()->post("admin/orders/update-status/{$id}", ['status' => 'Cancelled']);
            $this->assertSame($status, $this->row($id)->status, "{$status} must stay as it is");
        }

        // A cancelled order cannot be pushed to Delivered (that would add stock).
        $id = $this->order('Order Confirmed', 'SO-2000');
        $this->cancel($id);
        $this->asOwner()->post("admin/orders/update-status/{$id}", ['status' => 'Delivered']);
        $this->assertSame('Cancelled', $this->row($id)->status);
        $this->assertSame(12, $this->stock());
    }

    // ------------------------------------------------------------ reason rules

    public function testReasonIsRequiredAndMustBeOneOfTheList(): void
    {
        $id = $this->order('Order Confirmed');

        $this->cancel($id, []);
        $this->cancel($id, ['reason' => '']);
        $this->cancel($id, ['reason' => 'Because I said so']);

        $this->assertSame('Order Confirmed', $this->row($id)->status);
        $this->assertSame(0, $this->db->table('notifications')->countAllResults());
    }

    public function testOtherNeedsANoteOfFiveToTwoHundredCharacters(): void
    {
        $id = $this->order('Order Confirmed');

        $this->cancel($id, ['reason' => 'Other', 'note' => 'abcd']);
        $this->cancel($id, ['reason' => 'Other', 'note' => str_repeat('x', 201)]);
        $this->cancel($id, ['reason' => 'Other']);
        $this->assertSame('Order Confirmed', $this->row($id)->status);

        $this->cancel($id, ['reason' => 'Other', 'note' => 'Customer changed plan']);
        $this->assertSame('Cancelled', $this->row($id)->status);
        $this->assertSame('Other: Customer changed plan', $this->row($id)->cancel_reason);
    }

    public function testOnlyTheOwnerCanCancel(): void
    {
        $id = $this->order('Order Confirmed');

        $this->withSession(['isLoggedIn' => true, 'role' => 'Staff', 'user_id' => 99, 'last_activity' => time()])
            ->post("admin/orders/cancel/{$id}", ['reason' => 'Ordered by mistake'])->assertRedirectTo(site_url('login/admin'));
        $this->withSession(['isLoggedIn' => true, 'role' => 'Supplier', 'user_id' => $this->supplierUserId, 'supplier_id' => 'SUP-001', 'last_activity' => time()])
            ->post("admin/orders/cancel/{$id}", ['reason' => 'Ordered by mistake'])->assertRedirectTo(site_url('login/admin'));

        $this->assertSame('Order Confirmed', $this->row($id)->status);
    }

    // ------------------------------------------------------------ history and supplier side

    public function testHistoryTellsOwnerCancelFromSupplierDecline(): void
    {
        $owner = $this->order('Order Confirmed', 'SO-0001');
        $this->cancel($owner, ['reason' => 'Found another supplier']);

        $declined = $this->order('Order Confirmed', 'SO-0002');
        $this->withSession(['isLoggedIn' => true, 'role' => 'Supplier', 'user_id' => $this->supplierUserId, 'supplier_id' => 'SUP-001', 'full_name' => 'Tactical Gear', 'last_activity' => time()])
            ->post("supplier/new-orders/decline/{$declined}", ['reason' => 'Out of stock']);

        $declinedRow = $this->row('SO-0002');
        $this->assertSame('Cancelled', $declinedRow->status);
        $this->assertNull($declinedRow->cancelled_by, 'a supplier decline leaves cancelled_by empty');
        $this->assertNotNull($declinedRow->cancelled_at);

        $list = array_column((new StockOrderModel())->listWithSupplier(), null, 'so_id');
        $this->assertMatchesRegularExpression('/^Cancelled by Owner One · [A-Z][a-z]{2} \d{1,2}, \d{4} · Found another supplier$/', StockOrderModel::cancelNote($list['SO-0001']));
        $this->assertMatchesRegularExpression('/^Declined by supplier · [A-Z][a-z]{2} \d{1,2}, \d{4} · Out of stock$/', StockOrderModel::cancelNote($list['SO-0002']));
        $this->assertSame('Cancelled by RfourL · Found another supplier', StockOrderModel::supplierCancelNote($list['SO-0001']));
        $this->assertSame('Declined by you · Out of stock', StockOrderModel::supplierCancelNote($list['SO-0002']));
    }

    public function testOrderDetailShowsTheRightControls(): void
    {
        $confirmed = $this->order('Order Confirmed', 'SO-0001');
        $shipped   = $this->order('Shipped', 'SO-0002');
        $cancelled = $this->order('Order Confirmed', 'SO-0003');
        $this->cancel($cancelled, ['reason' => 'Ordered by mistake']);

        $page = $this->asOwner()->get("admin/orders/{$confirmed}");
        $page->assertOK();
        $page->assertSee('Cancel order');
        $page->assertSee('The supplier has already accepted this order and may have started preparing it.');
        $page->assertSee('Keep order');

        $page = $this->asOwner()->get("admin/orders/{$shipped}");
        $page->assertSee('Already shipped. Contact the supplier to refuse or return it on delivery.');
        $this->assertStringNotContainsString('data-so="SO-0002"', (string) $page->getBody(), 'no cancel button for a shipped order');

        $page = $this->asOwner()->get("admin/orders/{$cancelled}");
        $page->assertSee('Cancelled by Owner One');
        $page->assertSee('Ordered by mistake');
        $page->assertSee('orders?reorder=SO-0003');
    }

    public function testReorderOpensTheNewOrderFormPreFilledAndCreatesNothing(): void
    {
        $id = $this->order('Order Confirmed');
        $this->cancel($id);
        $before = $this->db->table('stock_order')->countAllResults();

        $page = $this->asOwner()->get('admin/orders?reorder=' . $id);

        $page->assertOK();
        $body = (string) $page->getBody();
        $this->assertStringContainsString('"supplier_id":"SUP-001"', $body);
        $this->assertStringContainsString('"item_id":"P-1"', $body);
        $this->assertStringContainsString('"qty":10', $body);
        $this->assertSame($before, $this->db->table('stock_order')->countAllResults());

        // Only a cancelled order can be reordered from.
        $other = $this->order('Preparing', 'SO-0009');
        $this->assertStringContainsString('const reorderPrefill = null', (string) $this->asOwner()->get('admin/orders?reorder=' . $other)->getBody());
    }

    public function testSupplierSeesCancelledOrdersInCompletedNotInNewOrdersOrDeliveries(): void
    {
        $id = $this->order('Preparing');
        $this->cancel($id, ['reason' => 'No longer needed']);
        $supplier = fn () => $this->withSession(['isLoggedIn' => true, 'role' => 'Supplier', 'user_id' => $this->supplierUserId, 'supplier_id' => 'SUP-001', 'full_name' => 'Tactical Gear', 'last_activity' => time()]);

        $this->assertStringNotContainsString('SO-0001', (string) $supplier()->get('supplier/deliveries')->getBody());
        $this->assertStringNotContainsString('SO-0001', (string) $supplier()->get('supplier/new-orders')->getBody());

        $completed = $supplier()->get('supplier/completed?range=all');
        $completed->assertSee('Cancelled orders');
        $completed->assertSee('Cancelled by RfourL · No longer needed');
    }
}
