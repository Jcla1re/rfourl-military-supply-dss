<?php

use App\Libraries\OnceToken;
use App\Models\ProductModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ReorderFixture;

/**
 * Saved once, whatever happens in the browser: the one-time submission key (OnceToken,
 * form_submissions) on POS checkout, stock orders, deliveries received and staff stock logs;
 * stock checked and changed inside one transaction (relative updates, never below 0); and
 * order status moves that only go forward (StockOrderModel::transition), so a repeat or a stale
 * tab changes nothing and sends no second email.
 */
final class DuplicateSubmitTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ReorderFixture;

    protected $migrate = false;
    protected $refresh = false;

    private const TABLES = ['sales_transaction', 'sales_item', 'inventory_log', 'form_submissions'];

    private object $mail;
    private int $supplierUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReorderFixture();
        Services::resetSingle('renderer');

        $forge = \Config\Database::forge();
        foreach (self::TABLES as $t) {
            $forge->dropTable($t, true);
        }
        $id   = ['type' => 'INTEGER', 'auto_increment' => true];
        $int  = ['type' => 'INTEGER', 'null' => true];
        $str  = ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true];
        $real = ['type' => 'REAL', 'default' => 0];
        $make = static function (string $name, array $fields, string $key) use ($forge) {
            $forge->addField($fields);
            $forge->addKey($key, true);
            $forge->createTable($name);
        };
        $forge->addUniqueKey('receipt_no');
        $make('sales_transaction', ['sales_id' => $id, 'receipt_no' => $str, 'user_id' => $int, 'sale_date' => ['type' => 'DATETIME', 'null' => true], 'payment_method' => $str, 'cash_amount' => $real, 'gcash_amount' => $real, 'gcash_reference_no' => $str, 'subtotal' => $real, 'discount' => $real, 'total_amount' => $real], 'sales_id');
        $make('sales_item', ['sales_item_id' => $id, 'sales_id' => $int, 'item_id' => $str, 'quantity_sold' => $int, 'selling_price' => $real], 'sales_item_id');
        $make('inventory_log', ['log_id' => $id, 'item_id' => $str, 'user_id' => $int, 'log_type' => $str, 'quantity_changed' => $int, 'stock_after_change' => $int, 'reference_so_id' => $str, 'notes' => $str, 'timestamp' => ['type' => 'DATETIME', 'null' => true]], 'log_id');
        // The migration's table (2026-11-14-100000_CreateFormSubmissions): the UNIQUE key is the real guard.
        $forge->addUniqueKey('submission_key');
        $make('form_submissions', ['id' => $id, 'submission_key' => ['type' => 'CHAR', 'constraint' => 32], 'action' => $str, 'user_id' => $int, 'result_type' => $str, 'result_id' => $str, 'created_at' => ['type' => 'DATETIME']], 'id');

        $this->supplierUserId = (int) $this->db->table('users')->where('username', 'tgi')->get()->getRow()->user_id;

        $this->addSize('P-1', 'Patrol Shirt', 'M', 5, 2, ['selling_price' => 250]);
        $this->addSize('P-LAST', 'Brass Medal', null, 1, 0, ['selling_price' => 120]);

        // Counts every email actually sent.
        $this->mail = new class () {
            public int $sent = 0;

            public function __call(string $name, array $args)
            {
                if ($name === 'send') {
                    $this->sent++;

                    return true;
                }

                return $this;
            }
        };
        Services::injectMock('email', $this->mail);
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $t) {
            \Config\Database::forge()->dropTable($t, true);
        }
        Services::resetSingle('email');
        $this->tearDownReorderFixture();
        parent::tearDown();
    }

    // ------------------------------------------------------------ helpers

    private function key(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function rows(string $table, array $where = []): int
    {
        return $this->db->table($table)->where($where)->countAllResults();
    }

    private function stock(string $itemId): int
    {
        return (int) $this->db->table('products')->where('item_id', $itemId)->get()->getRow()->current_stock;
    }

    private function as(string $role): self
    {
        return $role === 'admin' ? $this->asOwner() : $this->asStaff();
    }

    private function asSupplier(): self
    {
        return $this->withSession(['isLoggedIn' => true, 'role' => 'Supplier', 'user_id' => $this->supplierUserId, 'supplier_id' => 'SUP-001', 'full_name' => 'Tactical Gear', 'last_activity' => time()]);
    }

    private function checkout(string $role, string $itemId, int $qty, ?string $key, array $extra = [])
    {
        return $this->as($role)->post($role . '/sales/checkout', $extra + [
            'item_id' => [$itemId], 'quantity' => [(string) $qty], 'payment_method' => 'Cash', 'cash_received' => '5000',
        ] + ($key ? [OnceToken::FIELD => $key] : []));
    }

    private function orderStatus(string $soId): string
    {
        return $this->db->table('stock_order')->where('so_id', $soId)->get()->getRow()->status;
    }

    public static function roles(): array
    {
        return ['Owner' => ['admin'], 'Staff' => ['staff']];
    }

    // ------------------------------------------------------------ POS checkout

    /** @dataProvider roles */
    public function testTheSameCheckoutTwiceSavesOneSaleAndShowsTheFirstReceipt(string $role): void
    {
        $key = $this->key();

        $this->checkout($role, 'P-1', 2, $key)->assertRedirectTo(site_url($role . '/sales'));
        $first = $_SESSION['receipt']['receipt_no'] ?? null;
        $this->assertSame('1001', $first);

        $this->checkout($role, 'P-1', 2, $key)->assertRedirectTo(site_url($role . '/sales'));
        $this->assertSame('This sale was already saved (receipt 1001).', $_SESSION['toast']['title'] ?? null);
        $this->assertSame('info', $_SESSION['toast']['type'] ?? null);
        $this->assertSame('1001', $_SESSION['receipt']['receipt_no'] ?? null, 'The first sale\'s receipt again.');
        $this->assertCount(1, $_SESSION['receipt']['lines']);
        $this->assertSame(500.0, (float) $_SESSION['receipt']['total']);

        $this->assertSame(1, $this->rows('sales_transaction'));
        $this->assertSame(1, $this->rows('sales_item'));
        $this->assertSame(1, $this->rows('inventory_log', ['log_type' => 'SALE']));
        $this->assertSame(3, $this->stock('P-1'), 'Stock down once (5 - 2).');
        $this->assertSame(3, (int) $this->db->table('inventory_log')->get()->getRow()->stock_after_change);
        $this->assertSame(1, $this->rows('form_submissions', ['result_type' => 'sale']));
    }

    /** @dataProvider roles */
    public function testTwoCheckoutsForTheLastUnitSellItOnce(string $role): void
    {
        $this->checkout($role, 'P-LAST', 1, $this->key());
        $this->checkout($role, 'P-LAST', 1, $this->key());

        $this->assertSame('Not enough stock for Brass Medal (only 0 left). Nothing was saved.', $_SESSION['error'] ?? null);
        $this->assertSame(0, $this->stock('P-LAST'), 'Never negative.');
        $this->assertSame(1, $this->rows('sales_transaction'));
        $this->assertSame(1, $this->rows('sales_item'));
        $this->assertSame(1, $this->rows('inventory_log'));
    }

    public function testStockIsCheckedUnderLockAndARefusedSaleWritesNothing(): void
    {
        // The same size twice in one cart: each line alone fits, together they don't. The check
        // inside the transaction (locked rows, quantities added up) refuses the whole sale.
        $key = $this->key();
        $this->asOwner()->post('admin/sales/checkout', [
            'item_id' => ['P-LAST', 'P-LAST'], 'quantity' => ['1', '1'], 'payment_method' => 'Cash', 'cash_received' => '5000', OnceToken::FIELD => $key,
        ]);

        $this->assertSame('Not enough stock for Brass Medal (only 1 left). Nothing was saved.', $_SESSION['error'] ?? null);
        $this->assertSame(1, $this->stock('P-LAST'));
        foreach (self::TABLES as $t) {
            $this->assertSame(0, $this->rows($t), "Nothing written to {$t}.");
        }

        // The key was released with the rollback, so the corrected cart can be sent with it.
        $this->checkout('admin', 'P-LAST', 1, $key);
        $this->assertSame(1, $this->rows('sales_transaction'));
        $this->assertSame(0, $this->stock('P-LAST'));
    }

    public function testStockOnlyEverChangesRelativelyAndNeverBelowZero(): void
    {
        $products = new ProductModel();

        $this->assertNull($products->changeStock('P-LAST', -2), 'Not enough: nothing changes.');
        $this->assertSame(1, $this->stock('P-LAST'));
        $this->assertSame(0, $products->changeStock('P-LAST', -1));
        $this->assertSame(4, $products->changeStock('P-LAST', 4));
        $this->assertNull($products->changeStock('NOPE', 3));
    }

    public function testAKeyAlreadyTakenRollsTheCallersTransactionBack(): void
    {
        // What a simultaneous second request meets: the key is in form_submissions already.
        $key = $this->key();
        $this->db->table('form_submissions')->insert(['submission_key' => $key, 'action' => 'pos_checkout', 'result_type' => 'sale', 'result_id' => '7', 'created_at' => date('Y-m-d H:i:s')]);

        $this->db->transStart();
        $this->db->table('inventory_log')->insert(['item_id' => 'P-1', 'log_type' => 'SALE', 'quantity_changed' => -1, 'stock_after_change' => 4]);
        $earlier = OnceToken::claim($key, 'pos_checkout', $this->ownerId);

        $this->assertSame('7', $earlier['result_id'] ?? null);
        $this->assertSame(0, $this->db->transDepth, 'The caller\'s transaction is over.');
        $this->assertSame(0, $this->rows('inventory_log'), 'And rolled back.');
        $this->assertTrue($this->db->transStatus(), 'The rest of the request can still save.');

        // The database itself refuses a second row with the same key.
        $this->db->transStart();
        $this->db->table('form_submissions')->insert(['submission_key' => $key, 'action' => 'x', 'created_at' => date('Y-m-d H:i:s')]);
        $this->db->transComplete();
        $this->db->resetTransStatus();
        $this->assertSame(1, $this->rows('form_submissions'));
    }

    public function testOldKeysCanBePurged(): void
    {
        $this->db->table('form_submissions')->insertBatch([
            ['submission_key' => $this->key(), 'action' => 'a', 'created_at' => date('Y-m-d H:i:s', strtotime('-8 days'))],
            ['submission_key' => $this->key(), 'action' => 'b', 'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))],
        ]);

        $this->assertSame(1, OnceToken::purgeOlderThan(7));
        $this->assertSame(1, $this->rows('form_submissions'));
    }

    // ------------------------------------------------------------ deliveries received

    public function testReceivingAnOrderTwiceAddsStockOnce(): void
    {
        $this->openOrder('SO-0001', ['P-1'], null, 'Shipped');
        $key = $this->key();

        $this->asOwner()->post('admin/orders/update-status/SO-0001', ['status' => 'Delivered', OnceToken::FIELD => $key]);
        $this->assertSame('Delivered', $this->orderStatus('SO-0001'));
        $this->assertSame(17, $this->stock('P-1'), '5 + 12 received.');
        $emails = $this->mail->sent;

        // Same form again: "Already saved."
        $this->asOwner()->post('admin/orders/update-status/SO-0001', ['status' => 'Delivered', OnceToken::FIELD => $key]);
        $this->assertSame('Already saved.', $_SESSION['toast']['title'] ?? null);

        // Another form (Staff Confirm Delivery Received, or a second tab): already received.
        $this->asStaff()->post('staff/log-transaction/restock-confirm/SO-0001', [OnceToken::FIELD => $this->key()]);
        $this->assertSame('This order was already received.', $_SESSION['toast']['title'] ?? null);
        $this->asOwner()->post('admin/orders/update-status/SO-0001', ['status' => 'Delivered']);
        $this->assertSame('This order was already received.', $_SESSION['toast']['title'] ?? null);

        $this->assertSame(17, $this->stock('P-1'), 'Stock added once.');
        $this->assertSame(1, $this->rows('inventory_log', ['log_type' => 'RESTOCK']));
        $this->assertSame($emails, $this->mail->sent, 'No second email.');
    }

    public function testStaffConfirmUsesTheSameOnceOnlyReceive(): void
    {
        $this->openOrder('SO-0002', ['P-1'], null, 'Shipped');
        $key = $this->key();

        $this->asStaff()->post('staff/log-transaction/restock-confirm/SO-0002', [OnceToken::FIELD => $key]);
        $this->asStaff()->post('staff/log-transaction/restock-confirm/SO-0002', [OnceToken::FIELD => $key]);

        $this->assertSame('Already saved.', $_SESSION['toast']['title'] ?? null);
        $this->assertSame(17, $this->stock('P-1'));
        $this->assertSame(1, $this->rows('notifications', ['recipient_role' => 'Admin']));
    }

    public function testTheOwnerOnlyMovesAnOrderForward(): void
    {
        $this->openOrder('SO-0003', ['P-1'], null, 'Shipped');

        $this->asOwner()->post('admin/orders/update-status/SO-0003', ['status' => 'Preparing']);
        $this->assertSame('Shipped', $this->orderStatus('SO-0003'), 'Never backwards.');
        $this->assertSame('This order is already Shipped.', $_SESSION['toast']['title'] ?? null);

        $this->openOrder('SO-0004', ['P-1'], null, 'Delivered');
        $this->asOwner()->post('admin/orders/update-status/SO-0004', ['status' => 'Shipped']);
        $this->assertSame('Delivered', $this->orderStatus('SO-0004'), 'Never out of Delivered.');
        $this->assertSame(0, $this->mail->sent);
        $this->assertSame(0, $this->rows('notifications'));

        $this->openOrder('SO-0005', ['P-1'], null, 'Order Confirmed');
        $this->asOwner()->post('admin/orders/update-status/SO-0005', ['status' => 'Preparing']);
        $this->assertSame('Preparing', $this->orderStatus('SO-0005'), 'Forward is fine.');
    }

    // ------------------------------------------------------------ supplier moves

    public function testSupplierMovesOnlyFromTheRightStatus(): void
    {
        $this->openOrder('SO-0010', ['P-1'], null, 'Delivered');
        $this->openOrder('SO-0011', ['P-1'], null, 'Cancelled');

        foreach (['SO-0010' => 'Delivered', 'SO-0011' => 'Cancelled'] as $soId => $status) {
            $this->asSupplier()->post("supplier/new-orders/accept/{$soId}");
            $this->assertSame("This order is already {$status}.", $_SESSION['toast']['title'] ?? null);
            $this->asSupplier()->post("supplier/new-orders/decline/{$soId}", ['reason' => 'Out of stock']);
            $this->asSupplier()->post("supplier/deliveries/ship/{$soId}", ['tracking_no' => 'TRK-1']);
            $this->assertSame($status, $this->orderStatus($soId), "{$status} stays {$status}.");
        }

        $this->assertSame(0, $this->mail->sent, 'No email for a refused move.');
        $this->assertSame(0, $this->rows('notifications'));
        $this->assertNull($this->db->table('stock_order')->where('so_id', 'SO-0010')->get()->getRow()->tracking_no);
    }

    public function testSupplierDoubleClicksSendOneEmail(): void
    {
        $this->openOrder('SO-0012', ['P-1'], null, 'Order Confirmed');

        $this->asSupplier()->post('supplier/new-orders/accept/SO-0012');
        $this->asSupplier()->post('supplier/new-orders/accept/SO-0012');
        $this->assertSame('Preparing', $this->orderStatus('SO-0012'));

        $this->asSupplier()->post('supplier/deliveries/ship/SO-0012', ['tracking_no' => 'TRK-9']);
        $this->asSupplier()->post('supplier/deliveries/ship/SO-0012', ['tracking_no' => 'TRK-9']);
        $this->assertSame('Shipped', $this->orderStatus('SO-0012'));
        $this->assertSame('This order is already Shipped.', $_SESSION['toast']['title'] ?? null);

        $this->assertSame(2, $this->rows('notifications', ['recipient_role' => 'Admin']), 'One for accept, one for ship.');
    }

    // ------------------------------------------------------------ staff stock logs

    public function testDamagedAndReturnPostedTwiceChangeStockOnce(): void
    {
        $damaged = ['type' => 'damaged', 'item_id' => 'P-1', 'qty_affected' => '2', 'item_condition' => 'Torn', OnceToken::FIELD => $this->key()];
        $this->asStaff()->post('staff/log-transaction/store', $damaged);
        $this->asStaff()->post('staff/log-transaction/store', $damaged);
        $this->assertSame('Already saved.', $_SESSION['toast']['title'] ?? null);
        $this->assertSame(3, $this->stock('P-1'));

        $this->db->table('sales_transaction')->insert(['receipt_no' => '1001', 'sale_date' => date('Y-m-d H:i:s'), 'payment_method' => 'Cash', 'total_amount' => 250]);
        $return = ['type' => 'return', 'item_id' => 'P-1', 'qty_returned' => '1', 'original_sale_ref' => '1001', OnceToken::FIELD => $this->key()];
        $this->asStaff()->post('staff/log-transaction/store', $return);
        $this->asStaff()->post('staff/log-transaction/store', $return);
        $this->assertSame(4, $this->stock('P-1'));

        $this->assertSame(2, $this->rows('inventory_log'), 'One log row each.');
        $this->assertSame(2, $this->rows('notifications', ['recipient_role' => 'Admin']), 'One Owner notification each.');
    }

    public function testDamagedCanNotGoBelowZero(): void
    {
        $this->asStaff()->post('staff/log-transaction/store', ['type' => 'damaged', 'item_id' => 'P-LAST', 'qty_affected' => '3', OnceToken::FIELD => $this->key()]);

        $this->assertSame(1, $this->stock('P-LAST'));
        $this->assertSame(0, $this->rows('inventory_log'));
    }

    // ------------------------------------------------------------ stock orders

    public function testAnOrderingReportOrderPostedTwiceMakesOneOrderAndOneEmail(): void
    {
        $post = ['supplier_id' => 'SUP-001', 'item_id' => ['P-1'], 'quantity' => ['12'], 'unit_price' => ['50'], OnceToken::FIELD => $this->key()];

        $this->asOwner()->post('admin/orders/store', $post)->assertRedirectTo(site_url('/admin/orders'));
        $this->asOwner()->post('admin/orders/store', $post)->assertRedirectTo(site_url('/admin/orders'));

        $this->assertSame('Already saved.', $_SESSION['toast']['title'] ?? null);
        $this->assertSame('Order SO-0001 was already created.', $_SESSION['toast']['message'] ?? null);
        $this->assertSame(1, $this->rows('stock_order'));
        $this->assertSame(1, $this->rows('so_item'));
        $this->assertSame(1, $this->mail->sent);
    }

    public function testTheSharedDialogOrderIsProtectedTheSameWay(): void
    {
        $post = ['supplier_id' => 'SUP-001', 'item_id' => ['P-1'], 'quantity' => ['12'], 'unit_price' => ['50'], 'return_to' => '/admin/reorder-alerts', OnceToken::FIELD => $this->key()];

        $this->asOwner()->post('admin/orders/store', $post);
        $this->asOwner()->post('admin/orders/store', $post)->assertRedirectTo(site_url('/admin/reorder-alerts?tab=ordered'));

        $this->assertSame(1, $this->rows('stock_order'));
        $this->assertSame(1, $this->mail->sent);
    }

    // ------------------------------------------------------------ the pages carry the keys

    public function testTheFormsCarryAKeyAndSubmitOnce(): void
    {
        foreach (['admin', 'staff'] as $role) {
            $html = (string) $this->as($role)->get($role . '/sales')->getBody();
            $this->assertMatchesRegularExpression('/id="cartForm" data-submit-once data-busy-label="Processing sale(…|&hellip;)"/', $html);
            $this->assertMatchesRegularExpression('/name="submission_key" value="[0-9a-f]{32}" data-once-action="pos_checkout"/', $html);
        }

        $this->openOrder('SO-0020', ['P-1'], null, 'Shipped');
        $orders = (string) $this->asOwner()->get('admin/orders')->getBody();
        $this->assertMatchesRegularExpression('/update-status\/SO-0020" data-submit-once data-busy-label="Saving(…|&hellip;)">\s*<input type="hidden" name="csrf_test_name"[^>]*>\s*<input type="hidden" name="submission_key"/', $orders);
    }
}
