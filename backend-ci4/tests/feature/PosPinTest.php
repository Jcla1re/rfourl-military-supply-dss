<?php

use App\Exceptions\DemoLockedException;
use App\Libraries\AttemptStore;
use App\Libraries\OnceToken;
use App\Libraries\PosLock;
use App\Libraries\PosPin;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\PosPinFixture;
use Tests\Support\ReorderFixture;

/**
 * B1: the personal 4-digit checkout PIN (App\Libraries\PosPin) and Lock POS (App\Libraries\PosLock),
 * Owner and Staff. Checked by the server before anything is saved; 3 wrong PINs lock checkout for
 * that user for 60 seconds and tell the owner; the owner can reset a staff PIN; the PIN is never kept
 * as old input, in the session, a log or a notification. Plus the "Point of Sale" rename.
 *
 * @internal
 */
final class PosPinTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ReorderFixture;

    protected $migrate = false;
    protected $refresh = false;

    private const TABLES = ['sales_transaction', 'sales_item', 'inventory_log', 'form_submissions'];

    private const OWNER_PASSWORD = 'OwnerPass123';
    private const STAFF_PASSWORD = 'StaffPass123';

    /** A wrong PIN that appears nowhere else, to prove it is never stored. */
    private const WRONG = '9371';

    private array $savedDemo = [];

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
        $make('sales_transaction', ['sales_id' => $id, 'receipt_no' => $str, 'or_number' => $str, 'user_id' => $int, 'sale_date' => ['type' => 'DATETIME', 'null' => true], 'payment_method' => $str, 'cash_amount' => $real, 'gcash_amount' => $real, 'gcash_reference_no' => $str, 'subtotal' => $real, 'discount' => $real, 'total_amount' => $real] + \Tests\Support\SalesVoidColumns::FIELDS, 'sales_id');
        $make('sales_item', ['sales_item_id' => $id, 'sales_id' => $int, 'item_id' => $str, 'quantity_sold' => $int, 'selling_price' => $real], 'sales_item_id');
        $make('inventory_log', ['log_id' => $id, 'item_id' => $str, 'user_id' => $int, 'log_type' => $str, 'quantity_changed' => $int, 'stock_after_change' => $int, 'reference_so_id' => $str, 'notes' => $str, 'timestamp' => ['type' => 'DATETIME', 'null' => true]], 'log_id');
        $forge->addUniqueKey('submission_key');
        $make('form_submissions', ['id' => $id, 'submission_key' => ['type' => 'CHAR', 'constraint' => 32], 'action' => $str, 'user_id' => $int, 'result_type' => $str, 'result_id' => $str, 'created_at' => ['type' => 'DATETIME']], 'id');

        // Real account passwords (setting a PIN asks for them).
        $this->db->table('users')->update(['password_hash' => password_hash(self::OWNER_PASSWORD, PASSWORD_BCRYPT, ['cost' => 4])], ['user_id' => $this->ownerId]);
        $this->db->table('users')->update(['password_hash' => password_hash(self::STAFF_PASSWORD, PASSWORD_BCRYPT, ['cost' => 4])], ['user_id' => $this->staffId]);

        $this->addSize('P-1', 'Patrol Shirt', 'M', 5, 2, ['selling_price' => 250]);

        foreach (['owner_signin_change_' . $this->ownerId, 'staff_pw_change_' . $this->staffId] as $key) {
            AttemptStore::forget($key);
        }
        $this->savedDemo = [$_SERVER['DEMO_MODE'] ?? null, $_ENV['DEMO_MODE'] ?? null];
    }

    protected function tearDown(): void
    {
        [$server, $env] = $this->savedDemo;
        unset($_SERVER['DEMO_MODE'], $_ENV['DEMO_MODE']);
        if ($server !== null) {
            $_SERVER['DEMO_MODE'] = $server;
        }
        if ($env !== null) {
            $_ENV['DEMO_MODE'] = $env;
        }
        foreach (self::TABLES as $t) {
            \Config\Database::forge()->dropTable($t, true);
        }
        PosPinFixture::clearLocks();
        $this->tearDownReorderFixture();
        parent::tearDown();
    }

    public static function roles(): array
    {
        return ['Owner' => ['admin'], 'Staff' => ['staff']];
    }

    // ------------------------------------------------------------ helpers

    private function as(string $role): self
    {
        return $role === 'admin' ? $this->asOwner() : $this->asStaff();
    }

    private function userId(string $role): int
    {
        return $role === 'admin' ? $this->ownerId : $this->staffId;
    }

    private function key(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function checkout(string $role, ?string $pin, ?string $key = null, ?self $session = null)
    {
        return ($session ?? $this->as($role))->post($role . '/sales/checkout', [
            'item_id' => ['P-1'], 'quantity' => ['2'], 'payment_method' => 'Cash', 'cash_received' => '5000',
            OnceToken::FIELD => $key ?? $this->key(),
        ] + ($pin === null ? [] : [PosPin::FIELD => $pin]));
    }

    private function rows(string $table, array $where = []): int
    {
        return $this->db->table($table)->where($where)->countAllResults();
    }

    private function stock(): int
    {
        return (int) $this->db->table('products')->where('item_id', 'P-1')->get()->getRow()->current_stock;
    }

    private function user(int $id): array
    {
        return $this->db->table('users')->where('user_id', $id)->get()->getRowArray();
    }

    /** [message, locked] of a refused checkout PIN, shown in the reopened PIN dialog. */
    private function pinError(): array
    {
        $this->assertArrayNotHasKey('toast', $_SESSION, 'Not a toast.');

        return [$_SESSION['checkout_pin_error'] ?? null, (bool) ($_SESSION['checkout_pin_locked'] ?? false)];
    }

    private function toast(): array
    {
        return [$_SESSION['toast']['title'] ?? null, $_SESSION['toast']['message'] ?? null];
    }

    /** Nothing anywhere holds this PIN: no session value (old input, flashdata), no log line, no notification. */
    private function assertPinStoredNowhere(string $pin): void
    {
        array_walk_recursive($_SESSION, function ($value, $key) use ($pin) {
            $this->assertNotSame($pin, is_scalar($value) ? (string) $value : null, "Not in the session ({$key}).");
        });
        foreach ($this->db->table('security_log')->get()->getResultArray() as $row) {
            $this->assertStringNotContainsString($pin, (string) $row['detail'], 'Not in the security log.');
        }
        foreach ($this->db->table('notifications')->get()->getResultArray() as $row) {
            $this->assertStringNotContainsString($pin, $row['title'] . ' ' . $row['message'], 'Not in a notification.');
        }
    }

    private function page(string $role, ?self $session = null): string
    {
        return (string) ($session ?? $this->as($role))->get($role . '/sales')->response()->getBody();
    }

    // ------------------------------------------------------------ checkout

    /** @dataProvider roles */
    public function testTheRightPinChecksOutAsBefore(string $role): void
    {
        $this->checkout($role, PosPinFixture::PIN)->assertRedirectTo(site_url($role . '/sales'));

        $this->assertSame('Sale completed and receipt printed.', $_SESSION['success'] ?? null);
        $this->assertSame([1, 1, 3], [$this->rows('sales_transaction'), $this->rows('form_submissions'), $this->stock()]);
        $this->assertSame($this->userId($role), (int) $this->db->table('sales_transaction')->get()->getRow()->user_id, 'The cashier is the signed-in user.');
    }

    /** @dataProvider roles */
    public function testNoPinTypedSavesNothingUsesNoKeyAndIsNotCounted(string $role): void
    {
        $key = $this->key();
        $this->checkout($role, null, $key)->assertRedirectTo(site_url($role . '/sales'));

        $this->assertSame(['Enter your checkout PIN. Type your 4-digit checkout PIN, then try again. Nothing was saved.', false], $this->pinError(), 'In the reopened PIN dialog, not a toast.');
        $this->assertSame([0, 0, 5], [$this->rows('sales_transaction'), $this->rows('form_submissions'), $this->stock()], 'Nothing sold, the one-time key not used.');
        $this->assertSame([['P-1'], ['2']], [$_SESSION['_ci_old_input']['post']['item_id'], $_SESSION['_ci_old_input']['post']['quantity']], 'The cart comes back.');
        $this->assertSame(0, $this->rows('security_log', ['event' => 'pos_pin_failed']), 'Nothing typed is not a wrong try.');

        // The same form, now with the PIN: one sale.
        $this->checkout($role, PosPinFixture::PIN, $key);
        $this->assertSame(1, $this->rows('sales_transaction'));
    }

    /** @dataProvider roles */
    public function testAWrongPinSavesNothingKeepsTheKeyAndTheCartButNeverThePin(string $role): void
    {
        $key = $this->key();
        $this->checkout($role, self::WRONG, $key);

        $this->assertSame(['Wrong PIN. 2 tries left before checkout locks for 60 seconds. Nothing was saved.', false], $this->pinError());
        $this->assertSame([0, 0, 0, 5], [$this->rows('sales_transaction'), $this->rows('sales_item'), $this->rows('form_submissions'), $this->stock()]);
        $old = $_SESSION['_ci_old_input']['post'];
        $this->assertSame([['P-1'], ['2']], [$old['item_id'], $old['quantity']]);
        foreach ([PosPin::FIELD, OnceToken::FIELD] as $never) {
            $this->assertArrayNotHasKey($never, $old);
        }
        $this->assertSame(1, $this->rows('security_log', ['event' => 'pos_pin_failed', 'user_id' => $this->userId($role)]));
        $this->assertPinStoredNowhere(self::WRONG);

        // The page drawn after it: every PIN box empty.
        $html = $this->page($role, $this->withSession());
        $this->assertMatchesRegularExpression('#<input type="hidden" name="pos_pin" id="posPin" value="">#', $html);
        $this->assertStringNotContainsString(self::WRONG, $html);

        $this->checkout($role, PosPinFixture::PIN, $key);
        $this->assertSame([1, 3], [$this->rows('sales_transaction'), $this->stock()], 'The same key still works with the right PIN.');
    }

    public function testThreeWrongPinsLockStaffCheckoutFor60SecondsAndAlertTheOwnerWithLock(): void
    {
        $this->checkout('staff', self::WRONG);
        $this->checkout('staff', self::WRONG);
        $this->assertSame(['Wrong PIN. 1 try left before checkout locks for 60 seconds. Nothing was saved.', false], $this->pinError());
        $this->checkout('staff', self::WRONG);
        $this->assertSame(['Checkout locked. 3 wrong PINs. Checkout is locked for 60 seconds and the owner was notified. Nothing was saved.', true], $this->pinError(), 'Locked: the dialog offers only Cancel.');

        $alert = $this->db->table('notifications')->where('recipient_role', 'Admin')->get()->getRowArray();
        $this->assertNotNull($alert, 'The owner is told.');
        $this->assertSame(['security', 'Security', $this->staffId], [$alert['category'], $alert['type'], (int) $alert['action_user_id']], 'With "Lock this account" (the staff alert style).');
        $this->assertStringStartsWith('Checkout locked for Ana Cruz on ', $alert['title']);
        $this->assertNull($alert['action_token'], 'In-app only: no email link.');
        $this->assertSame(1, $this->rows('security_log', ['event' => 'pos_pin_locked']));

        // Even the right PIN, from a brand-new session, is refused while the lock lasts.
        $this->checkout('staff', PosPinFixture::PIN);
        [$message, $locked] = $this->pinError();
        $this->assertTrue($locked);
        $this->assertMatchesRegularExpression('/^Checkout locked\. Too many wrong PINs\. Try again in ([1-9]|[1-5]\d|60) seconds\. Nothing was saved\.$/', $message);
        $this->assertSame(0, $this->rows('sales_transaction'));
        $this->assertSame(1, $this->rows('notifications'), 'One alert per lock.');
        $this->assertPinStoredNowhere(self::WRONG);
        $this->assertPinStoredNowhere(PosPinFixture::PIN);

        // The owner's own checkout is not affected: the lock is per user.
        $this->checkout('admin', PosPinFixture::PIN);
        $this->assertSame(1, $this->rows('sales_transaction'));
    }

    public function testTheOwnersOwnLockAlsoNotifiesButHasNoLockButton(): void
    {
        foreach (range(1, 3) as $i) {
            $this->checkout('admin', self::WRONG);
        }
        $this->assertSame(['Checkout locked. 3 wrong PINs. Checkout is locked for 60 seconds. Nothing was saved.', true], $this->pinError());
        $alert = $this->db->table('notifications')->get()->getRowArray();
        $this->assertStringStartsWith('Checkout locked on your account on ', $alert['title']);
        $this->assertNull($alert['action_user_id']);
    }

    /** @dataProvider roles */
    public function testWithoutAPinThereIsNoCheckoutAndThePageSaysHowToSetOne(string $role): void
    {
        $this->db->table('users')->update(['pos_pin_hash' => null], ['user_id' => $this->userId($role)]);

        $this->checkout($role, PosPinFixture::PIN);
        $this->assertSame(['Set your checkout PIN first', 'You need a 4-digit checkout PIN before you can check out. Nothing was saved.'], $this->toast());
        $this->assertSame(0, $this->rows('sales_transaction'));

        $html = $this->page($role);
        $this->assertStringContainsString('data-no-pin="1"', $html, 'pos.js keeps Checkout disabled.');
        $this->assertStringNotContainsString('id="posLockForm"', $html, 'No Lock POS without a PIN to unlock it.');
        if ($role === 'admin') {
            // The owner's first PIN: the same card as staff, saved through My Profile's own route.
            $this->assertStringContainsString('action="' . site_url('admin/settings/sign-in') . '"', $html);
            $this->assertStringContainsString('<input type="hidden" name="return" value="pos">', $html);
            $this->assertStringContainsString('name="new_pin"', $html);
            $this->assertStringContainsString('name="current_password"', $html);
            $this->assertStringNotContainsString('edit=signin', $html, 'No link to My Profile any more.');
        } else {
            $this->assertStringContainsString('action="' . site_url('staff/sales/pin') . '"', $html);
            $this->assertStringContainsString('name="new_pin"', $html);
            $this->assertStringContainsString('name="current_password"', $html);
        }
    }

    // ------------------------------------------------------------ staff sets a PIN

    public function testStaffSetTheirFirstPinOnThePosPageConfirmedWithTheirPassword(): void
    {
        $this->db->table('users')->update(['pos_pin_hash' => null], ['user_id' => $this->staffId]);
        $post = fn (array $f) => $this->asStaff()->post('staff/sales/pin', $f);

        $post(['new_pin' => '1234', 'new_pin_confirm' => '1234', 'current_password' => self::STAFF_PASSWORD])->assertRedirectTo(site_url('staff/sales'));
        $this->assertStringStartsWith('That PIN is too easy to guess.', $_SESSION['pin_error']);
        $this->assertSame('new_pin', $_SESSION['pin_error_field'], 'Shown under the New PIN box.');
        $post(['new_pin' => '5739', 'new_pin_confirm' => '5738', 'current_password' => self::STAFF_PASSWORD]);
        $this->assertSame("The two PINs don't match.", $_SESSION['pin_error']);
        $this->assertSame('new_pin_confirm', $_SESSION['pin_error_field']);
        $post(['new_pin' => '5739', 'new_pin_confirm' => '5739', 'current_password' => 'wrong-password']);
        $this->assertSame('Current password is incorrect.', $_SESSION['pin_error']);
        $this->assertSame('current_password', $_SESSION['pin_error_field']);
        $this->assertArrayNotHasKey('_ci_old_input', $_SESSION, 'Nothing typed is kept.');
        $this->assertEmpty($this->user($this->staffId)['pos_pin_hash']);

        $post(['new_pin' => '5739', 'new_pin_confirm' => '5739', 'current_password' => self::STAFF_PASSWORD])->assertRedirectTo(site_url('staff/sales'));
        $this->assertSame(['success', 'Checkout PIN saved'], [$_SESSION['toast']['type'], $_SESSION['toast']['title']], 'The usual toast; it hides itself.');
        $row = $this->user($this->staffId);
        $this->assertTrue(password_verify('5739', $row['pos_pin_hash']), 'Saved as a hash.');
        $this->assertNotSame('5739', $row['pos_pin_hash']);
        $this->assertNotEmpty($row['pos_pin_set_at']);
        $this->assertSame(1, $this->rows('security_log', ['event' => 'pos_pin_set']));
        $this->assertPinStoredNowhere('5739');

        $this->checkout('staff', '5739');
        $this->assertSame(1, $this->rows('sales_transaction'));
    }

    public function testStaffChangeTheirPinInMyAccountAndShareThePasswordAttemptLimit(): void
    {
        $this->asStaff()->post('staff/account/pin', ['new_pin' => '6150', 'new_pin_confirm' => '6150', 'current_password' => self::STAFF_PASSWORD])
            ->assertRedirectTo(site_url('staff/account'));
        $this->assertSame('Checkout PIN saved', $_SESSION['toast']['title']);
        $this->assertTrue(password_verify('6150', $this->user($this->staffId)['pos_pin_hash']));

        $html = (string) $this->asStaff()->get('staff/account')->response()->getBody();
        $this->assertStringContainsString('action="' . site_url('staff/account/pin') . '"', $html);
        $this->assertStringNotContainsString('6150', $html);

        // Wrong passwords here count on the same counter as Change Password (5 per 10 minutes).
        foreach (range(1, 5) as $i) {
            $this->asStaff()->post('staff/account/pin', ['new_pin' => '6150', 'new_pin_confirm' => '6150', 'current_password' => 'nope']);
        }
        $this->asStaff()->post('staff/account/password', ['current_password' => self::STAFF_PASSWORD, 'new_password' => 'NewPass1234', 'confirm_password' => 'NewPass1234']);
        $this->assertSame('Too many attempts. Please wait a few minutes and try again.', $_SESSION['error'] ?? null);
    }

    // ------------------------------------------------------------ owner: My Profile and reset

    public function testTheOwnerSetsTheirPinInSignInAndSecurityWithTheCurrentPassword(): void
    {
        $this->db->table('users')->update(['email' => 'owner@example.test', 'pos_pin_hash' => null], ['user_id' => $this->ownerId]);
        $fields = ['username' => 'owner1', 'email' => 'owner@example.test', 'email_confirm' => 'owner@example.test'];

        // A weak PIN: refused under the PIN field, nothing saved, no attempt counted, nothing kept.
        $this->asOwner()->post('admin/settings/sign-in', $fields + ['new_pin' => '2026', 'new_pin_confirm' => '2026', 'current_password' => self::OWNER_PASSWORD]);
        $this->assertSame('signin', $_SESSION['field_errors']['form']);
        $this->assertStringStartsWith('That PIN is too easy to guess.', $_SESSION['field_errors']['fields']['new_pin']);
        foreach (['new_pin', 'new_pin_confirm', 'current_password'] as $never) {
            $this->assertArrayNotHasKey($never, $_SESSION['_ci_old_input']['post']);
        }
        $this->assertSame(0, AttemptStore::count('owner_signin_change_' . $this->ownerId), 'A format error is not an attempt.');

        // The form drawn after it: reopened, the message under the PIN box, the boxes empty.
        $html = (string) $this->withSession()->get('admin/settings?tab=profile')->response()->getBody();
        $this->assertMatchesRegularExpression('#<form [^>]*id="siForm"(?![^>]*hidden)#', $html, 'Reopened.');
        $this->assertMatchesRegularExpression('#<input id="siNewPin" name="new_pin" type="password"[^>]*class="is-invalid"[^>]*>#', $html);
        $this->assertStringContainsString('<div class="pf-error" id="siNewPinErr">That PIN is too easy to guess.', $html);
        $this->assertDoesNotMatchRegularExpression('#id="siNewPin(Confirm)?"[^>]*value=#', $html, 'Never filled in again.');

        // Repeat that doesn't match.
        $this->asOwner()->post('admin/settings/sign-in', $fields + ['new_pin' => '5739', 'new_pin_confirm' => '5793', 'current_password' => self::OWNER_PASSWORD]);
        $this->assertSame("The two PINs don't match.", $_SESSION['field_errors']['fields']['new_pin_confirm']);

        // Wrong current password: counted, nothing saved.
        $this->asOwner()->post('admin/settings/sign-in', $fields + ['new_pin' => '5739', 'new_pin_confirm' => '5739', 'current_password' => 'wrong']);
        $this->assertSame('Current password is incorrect.', $_SESSION['field_errors']['fields']['current_password']);
        $this->assertEmpty($this->user($this->ownerId)['pos_pin_hash']);

        // Only the PIN changed: that is a change, saved as a hash.
        $this->asOwner()->post('admin/settings/sign-in', $fields + ['new_pin' => '5739', 'new_pin_confirm' => '5739', 'current_password' => self::OWNER_PASSWORD])
            ->assertRedirectTo(site_url('admin/settings?tab=profile'));
        $this->assertSame('Checkout PIN changed.', $_SESSION['success']);
        $this->assertTrue(password_verify('5739', $this->user($this->ownerId)['pos_pin_hash']));
        $this->assertSame(1, $this->rows('security_log', ['event' => 'pos_pin_set']));
        $this->assertPinStoredNowhere('5739');
    }

    public function testThePosLinkOpensTheSignInFormAndThePinFieldsCountAsAChange(): void
    {
        $html = (string) $this->asOwner()->get('admin/settings?tab=profile&edit=signin')->response()->getBody();
        $this->assertMatchesRegularExpression('#<form [^>]*id="siForm"(?![^>]*hidden)#', $html, 'Opened by ?edit=signin.');
        $this->assertStringContainsString('<dt>Checkout PIN</dt>', $html);
        // Phase B: the PIN counts as a change once both boxes are filled; one box alone keeps Save disabled.
        $this->assertStringContainsString("const pinChanged = () => newPin.value !== '' && newPin2.value !== '';", $html);
        $this->assertStringContainsString("const pinHalfTyped = () => (newPin.value === '') !== (newPin2.value === '');", $html);
        $this->assertStringContainsString('const signInChanged = () => !pinHalfTyped() && (pinChanged() || otherSignInChanged());', $html);

        $closed = (string) $this->asOwner()->get('admin/settings?tab=profile')->response()->getBody();
        $this->assertMatchesRegularExpression('#<form [^>]*id="siForm"[^>]* hidden>#', $closed, 'Closed without it.');
    }

    public function testTheOwnerResetsAStaffPinButNeverAnOwnersOrSeesIt(): void
    {
        $this->checkout('staff', self::WRONG); // one wrong try on the counter

        $this->asOwner()->post('admin/settings/staff-accounts/reset-pin/' . $this->staffId)->assertRedirectTo(site_url('admin/settings?tab=accounts'));
        $this->assertSame('Checkout PIN reset', $_SESSION['toast']['title']);
        $row = $this->user($this->staffId);
        $this->assertNull($row['pos_pin_hash']);
        $this->assertNotEmpty($row['pos_pin_reset_at']);
        $this->assertSame(1, $this->rows('security_log', ['event' => 'pos_pin_reset']));
        $notice = $this->db->table('notifications')->where('recipient_role', 'Staff')->get()->getRowArray();
        $this->assertSame([$this->staffId, 'Your checkout PIN was reset'], [(int) $notice['recipient_id'], $notice['title']]);

        // Staff only.
        $this->asOwner()->post('admin/settings/staff-accounts/reset-pin/' . $this->ownerId);
        $this->assertSame('Staff account not found.', $_SESSION['error'] ?? null);
        $this->assertNotNull($this->user($this->ownerId)['pos_pin_hash']);

        // The staff member's POS page says so and asks for a new one.
        $this->assertStringContainsString('The owner reset your PIN. Set a new one before your next sale.', $this->page('staff'));
    }

    // ------------------------------------------------------------ Lock POS

    /** @dataProvider roles */
    public function testLockPosKeepsTheCartRefusesCheckoutAndUnlocksOnlyWithTheOwnPin(string $role): void
    {
        $this->as($role)->post($role . '/sales/lock', ['item_id' => ['P-1'], 'quantity' => ['2']])->assertRedirectTo(site_url($role . '/sales'));
        $this->assertSame([['P-1', 2]], $_SESSION[PosLock::SESSION_KEY]['cart']);
        $session = $_SESSION;

        $html = $this->page($role, $this->withSession($session));
        $this->assertStringContainsString('Point of Sale is locked', $html);
        $this->assertStringContainsString('action="' . site_url($role . '/sales/unlock') . '"', $html);
        $this->assertStringNotContainsString('id="cartForm"', $html, 'No cart, no checkout.');
        $this->assertStringNotContainsString('?tab=receipts', $html, 'Neither tab.');

        // Checkout, even with the right PIN, the exports and the OR check: refused while locked.
        $this->checkout($role, PosPinFixture::PIN, null, $this->withSession($session));
        $this->assertSame('Point of Sale is locked', $this->toast()[0]);
        $this->assertSame(0, $this->rows('sales_transaction'));
        $this->assertSame(site_url($role . '/sales'), $this->withSession($session)->get($role . '/sales/export-csv')->getRedirectUrl());
        $this->assertSame(423, $this->withSession($session)->get($role . '/sales/check-or?or_number=1')->response()->getStatusCode());

        // Other pages stay open.
        $this->withSession($session)->get($role === 'admin' ? 'admin/settings' : 'staff/account')->assertOK();

        // A wrong PIN: still locked, and it counts.
        $this->withSession($session)->post($role . '/sales/unlock', [PosPin::FIELD => self::WRONG]);
        $this->assertSame('Wrong PIN. 2 tries left before checkout locks for 60 seconds.', $_SESSION['unlock_error'] ?? null, 'Shown under the PIN box.');
        $this->assertArrayHasKey(PosLock::SESSION_KEY, $_SESSION);

        // The right one: unlocked, the cart comes back.
        $this->withSession($session)->post($role . '/sales/unlock', [PosPin::FIELD => PosPinFixture::PIN]);
        $this->assertSame(['success', 'Unlocked. Your order is still here.', 'pos-unlocked'], [$_SESSION['toast']['type'] ?? null, $_SESSION['toast']['title'] ?? null, $_SESSION['toast']['key'] ?? null], 'The usual toast.');
        $this->assertArrayNotHasKey(PosLock::SESSION_KEY, $_SESSION);
        $html = $this->page($role, $this->withSession());
        $this->assertStringNotContainsString('posUnlockedNote', $html, 'No note in the cart column any more.');
        preg_match('/data-restore="([^"]*)"/', $html, $restore);
        $this->assertSame([['id' => 'P-1', 'qty' => 2]], json_decode(html_entity_decode($restore[1]), true));
        $this->assertPinStoredNowhere(self::WRONG);
        $this->assertPinStoredNowhere(PosPinFixture::PIN);
    }

    public function testThreeWrongUnlocksStartTheSameLock(): void
    {
        $this->asStaff()->post('staff/sales/lock');
        $session = $_SESSION;
        foreach (range(1, 3) as $i) {
            $this->withSession($session)->post('staff/sales/unlock', [PosPin::FIELD => self::WRONG]);
        }
        $this->assertStringStartsWith('Checkout locked. 3 wrong PINs.', $_SESSION['unlock_error'] ?? '');
        $this->withSession($session)->post('staff/sales/unlock', [PosPin::FIELD => PosPinFixture::PIN]);
        $this->assertStringStartsWith('Checkout locked. Too many wrong PINs.', $_SESSION['unlock_error'] ?? '');
        $this->assertArrayHasKey(PosLock::SESSION_KEY, $_SESSION, 'Still locked.');
        $this->assertSame(1, $this->rows('notifications', ['recipient_role' => 'Admin']));
    }

    public function testLockPosNeedsAPin(): void
    {
        $this->db->table('users')->update(['pos_pin_hash' => null], ['user_id' => $this->staffId]);
        $this->asStaff()->post('staff/sales/lock');
        $this->assertSame("Couldn't lock the Point of Sale", $this->toast()[0]);
        $this->assertArrayNotHasKey(PosLock::SESSION_KEY, $_SESSION);
    }

    /** @dataProvider roles */
    public function testThePosPageHasThePinPromptAndLockButtonAndNoPinValue(string $role): void
    {
        $html = $this->page($role);
        $this->assertStringContainsString('<input type="hidden" name="pos_pin" id="posPin" value="">', $html);
        $this->assertStringContainsString('id="pinModal"', $html);
        $this->assertMatchesRegularExpression('#<input type="password" id="pinInput"[^>]*autocomplete="one-time-code"#', $html, 'The prompt box has no name: never posted itself; browsers neither fill nor save it.');
        $this->assertDoesNotMatchRegularExpression('#id="pinInput"[^>]*name=#', $html);
        $this->assertStringContainsString('action="' . site_url($role . '/sales/lock') . '" id="posLockForm"', $html);
        $this->assertStringContainsString('<script src="/assets/js/pos-pin.js" defer></script>', $html);
        $this->assertStringContainsString('data-no-pin="0"', $html);
    }

    // ------------------------------------------------------------ the rename

    /** @dataProvider roles */
    public function testItIsCalledPointOfSaleWithCheckoutAndSalesAndReceiptsTabs(string $role): void
    {
        $html = $this->page($role);
        $this->assertStringContainsString('<title>Point of Sale | RfourL</title>', $html);
        $this->assertStringContainsString('<h1>Point of Sale</h1>', $html);
        $this->assertStringContainsString('data-tooltip="Point of Sale"', $html);
        $this->assertStringContainsString('<span class="nav-label">Point of Sale</span>', $html);
        $this->assertStringContainsString('>Checkout</a>', $html);
        $this->assertStringContainsString('>Sales &amp; Receipts</a>', $html);
        $this->assertStringNotContainsString('POS &amp; Sales', $html);
        $this->assertStringNotContainsString('POS & Sales', $html);
    }

    // ------------------------------------------------------------ demo mode

    public function testDemoAccountsKeepTheirPin(): void
    {
        $_SERVER['DEMO_MODE'] = 'true';
        $this->db->table('users')->update(['is_demo' => 1], ['user_id' => $this->staffId]);
        $before = $this->user($this->staffId)['pos_pin_hash'];

        $this->asStaff()->post('staff/account/pin', ['new_pin' => '5739', 'new_pin_confirm' => '5739', 'current_password' => self::STAFF_PASSWORD]);
        $this->assertSame('Disabled in the demo version.', $_SESSION['pin_error'] ?? null);
        $this->asOwner()->post('admin/settings/staff-accounts/reset-pin/' . $this->staffId);
        $this->assertSame($before, $this->user($this->staffId)['pos_pin_hash'], 'The owner cannot reset it either.');

        // The model backstop.
        $this->expectException(DemoLockedException::class);
        (new UserModel())->skipValidation(true)->update($this->staffId, ['pos_pin_hash' => null]);
    }

    public function testADemoAccountCanStillCheckOutWithItsPin(): void
    {
        $_SERVER['DEMO_MODE'] = 'true';
        $this->db->table('users')->update(['is_demo' => 1], ['user_id' => $this->staffId]);

        $this->checkout('staff', PosPinFixture::PIN);
        $this->assertSame(1, $this->rows('sales_transaction'));
    }
}
