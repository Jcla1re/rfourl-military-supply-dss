<?php

use App\Libraries\AttemptStore;
use App\Libraries\EmailNotifier;
use App\Libraries\LoginLockout;
use App\Libraries\StaffPasswordReset;
use CodeIgniter\Email\Email;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * A real CodeIgniter mailer whose delivery only records what would have gone out (who,
 * the mail type, the plain-text part and the finished message). Nothing is sent.
 */
final class RecordingEmail extends Email
{
    public array $out = [];

    protected function spoolEmail()
    {
        $this->out[] = [
            'to'        => $this->recipients,
            'mailType'  => $this->mailType,
            'alt'       => $this->altMessage,
            'subject'   => $this->headers['Subject'] ?? '',
            'body'      => $this->finalBody,
        ];

        return true;
    }
}

/**
 * The password and email security fixes: a fresh mailer for every email (no plain-text part
 * carried over), the Owner's email change needing the current password, trimming at the staff
 * login, the clearer temporary-password email, "Signed in as" on Set your password, the locked
 * message (and no sale), the email after an OTP reset, and a staff email never being an
 * Owner's. Runs on the in-memory 'tests' database; nothing is really emailed.
 *
 * @internal
 */
final class PasswordSecurityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    private const TABLES = ['users', 'notifications', 'security_log', 'products', 'sales_transaction', 'sales_item'];

    private int $ownerId;
    private int $staffId;
    private int $supplierUserId;
    private RecordingEmail $mail;

    protected function setUp(): void
    {
        parent::setUp();

        $filters = config('Filters');
        $filters->globals['before'] = array_values(array_diff($filters->globals['before'], ['csrf']));

        $forge = \Config\Database::forge();
        foreach (self::TABLES as $t) {
            $forge->dropTable($t, true);
        }
        $str  = ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true];
        $when = ['type' => 'DATETIME', 'null' => true];
        $forge->addField([
            'user_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'username' => $str, 'full_name' => $str, 'email' => $str, 'phone' => $str,
            'role' => $str, 'supplier_id' => $str, 'is_active' => ['type' => 'INTEGER', 'default' => 1], 'is_demo' => ['type' => 'INTEGER', 'default' => 0],
            'password_hash' => $str, 'temp_password_hash' => $str, 'last_login' => $when, 'created_at' => $when, 'deleted_at' => $when,
            'must_change_password' => ['type' => 'INTEGER', 'default' => 0], 'temp_password_kind' => $str, 'temp_password_expires_at' => $when,
            'reset_otp_hash' => $str, 'reset_otp_expires' => $when,
        ]);
        $forge->addKey('user_id', true);
        $forge->createTable('users');
        $forge->addField([
            'notification_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'recipient_role' => $str, 'recipient_id' => ['type' => 'INTEGER', 'null' => true],
            'type' => $str, 'category' => $str, 'title' => $str, 'message' => ['type' => 'TEXT', 'null' => true], 'link_url' => $str,
            'is_read' => ['type' => 'INTEGER', 'default' => 0], 'status' => $str, 'action_token' => $str, 'action_user_id' => ['type' => 'INTEGER', 'null' => true],
            'action_expires_at' => $when, 'created_at' => $when,
        ]);
        $forge->addKey('notification_id', true);
        $forge->createTable('notifications');
        $forge->addField([
            'log_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'user_id' => ['type' => 'INTEGER', 'null' => true], 'username' => $str,
            'role' => $str, 'event' => $str, 'detail' => $str, 'ip_address' => $str, 'created_at' => $when,
        ]);
        $forge->addKey('log_id', true);
        $forge->createTable('security_log');
        $forge->addField(['item_id' => ['type' => 'VARCHAR', 'constraint' => 15], 'item_name' => $str, 'current_stock' => ['type' => 'INTEGER', 'default' => 0], 'selling_price' => ['type' => 'REAL', 'default' => 0], 'is_active' => ['type' => 'INTEGER', 'default' => 1]]);
        $forge->addKey('item_id', true);
        $forge->createTable('products');
        foreach (['sales_transaction', 'sales_item'] as $t) {
            $forge->addField(['id' => ['type' => 'INTEGER', 'auto_increment' => true], 'note' => $str]);
            $forge->addKey('id', true);
            $forge->createTable($t);
        }

        $this->ownerId        = $this->user('owner1', 'Owner One', 'Admin', 'OwnerPass123', 'owner@example.test');
        $this->staffId        = $this->user('cashier1', 'Ana Cruz', 'Staff', 'Cashier1Pass1', 'ana@example.test');
        $this->supplierUserId = $this->user('tgi', 'Tactical Gear', 'Supplier', 'SupplierPass1', 'tgi@example.test');

        $this->mail = new RecordingEmail(['fromEmail' => 'app@example.test', 'fromName' => 'RfourL', 'protocol' => 'mail', 'mailType' => 'text']);
        Services::injectMock('email', $this->mail);
        $this->clearLimits();
    }

    protected function tearDown(): void
    {
        $this->clearLimits();
        Services::resetSingle('email');
        foreach (self::TABLES as $t) {
            \Config\Database::forge()->dropTable($t, true);
        }
        parent::tearDown();
    }

    private function clearLimits(): void
    {
        (new LoginLockout())->reset('staff');
        AttemptStore::forget('staff_forgot_' . md5('0.0.0.0'));
        foreach ([1, 2, 3, 4] as $id) {
            AttemptStore::forget('staff_forgot_acct_' . $id);
            AttemptStore::forget('owner_email_change_' . $id);
            AttemptStore::forget('staff_email_change_' . $id);
        }
    }

    private function user(string $username, string $name, string $role, string $password, ?string $email): int
    {
        $this->db->table('users')->insert(['username' => $username, 'full_name' => $name, 'role' => $role, 'is_active' => 1, 'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'supplier_id' => $role === 'Supplier' ? 'SUP-001' : null]);

        return (int) $this->db->insertID();
    }

    private function row(int $id): array
    {
        return $this->db->table('users')->where('user_id', $id)->get()->getRowArray();
    }

    private function asOwner(): self
    {
        return $this->withSession(['isLoggedIn' => true, 'role' => 'Admin', 'user_id' => $this->ownerId, 'username' => 'owner1', 'full_name' => 'Owner One', 'last_activity' => time()]);
    }

    private function asStaff(array $extra = []): self
    {
        return $this->withSession($extra + ['isLoggedIn' => true, 'role' => 'Staff', 'user_id' => $this->staffId, 'username' => 'cashier1', 'full_name' => 'Ana Cruz', 'last_activity' => time()]);
    }

    /** @return list<array> what went out to this address */
    private function sentTo(string $email): array
    {
        return array_values(array_filter($this->mail->out, static fn ($m) => in_array($email, $m['to'], true)));
    }

    // ------------------------------------------------------------ 1. the mailer leak

    public function testAPlainEmailAfterAnHtmlOneCarriesNothingFromIt(): void
    {
        $notifier = new EmailNotifier();
        $notifier->sendHtml(['email' => 'first@example.test'], 'First', '<p>Hello</p>', 'SECRET-LINK https://example.test/lock/abc');
        $notifier->toUser(['email' => 'second@example.test'], 'Second', 'Just a note.');

        $this->assertCount(2, $this->mail->out);
        $second = $this->sentTo('second@example.test')[0];
        $this->assertSame('text', $second['mailType'], 'Back to plain text.');
        $this->assertSame('', $second['alt']);
        $this->assertStringNotContainsString('SECRET-LINK', $second['body']);
        $this->assertSame(['second@example.test'], $second['to'], 'Only its own recipient.');
    }

    public function testTheOldAddressOnStaffEmailChangeDoesNotGetTheLockLink(): void
    {
        $code = '123456';
        $this->asStaff(['pending_email_change' => ['email' => 'ana.new@example.test', 'hash' => password_hash($code, PASSWORD_DEFAULT), 'expires' => time() + 600, 'tries' => 0]])
            ->post('staff/account/email/confirm', ['code' => $code]);

        $this->assertSame('ana.new@example.test', $this->row($this->staffId)['email']);
        $owner = $this->sentTo('owner@example.test');
        $this->assertCount(1, $owner);
        $this->assertStringContainsString('notification-action/', $owner[0]['body'], 'The Owner gets the Lock link.');

        $old = $this->sentTo('ana@example.test');
        $this->assertCount(1, $old);
        $this->assertStringNotContainsString('notification-action/', $old[0]['body'], 'The old address never gets it.');
        $this->assertStringNotContainsString('Lock this account', $old[0]['body']);
        $this->assertSame('', $old[0]['alt']);
    }

    // ------------------------------------------------------------ 2. Owner email change

    public function testTheOwnerNeedsTheirPasswordToChangeTheirEmail(): void
    {
        $post = ['full_name' => 'Owner One', 'email' => 'owner.new@example.test', 'phone' => ''];

        $this->asOwner()->post('admin/settings/account', $post);
        $this->assertSame('owner@example.test', $this->row($this->ownerId)['email'], 'No password: refused.');
        $this->asOwner()->post('admin/settings/account', $post + ['current_password' => 'wrong-one-1']);
        $this->assertSame('owner@example.test', $this->row($this->ownerId)['email'], 'Wrong password: refused.');
        $this->assertSame([], $this->mail->out);

        $this->asOwner()->post('admin/settings/account', $post + ['current_password' => 'OwnerPass123']);
        $this->assertSame('owner.new@example.test', $this->row($this->ownerId)['email']);
        $told = $this->sentTo('owner@example.test');
        $this->assertCount(1, $told, 'The old address is told.');
        $this->assertStringContainsString('was changed', $told[0]['body']);
        $this->assertStringNotContainsString('http', $told[0]['body'], 'No links.');

        // Name only: no password needed.
        $this->asOwner()->post('admin/settings/account', ['full_name' => 'Owner Renamed', 'email' => 'owner.new@example.test', 'phone' => '']);
        $this->assertSame('Owner Renamed', $this->row($this->ownerId)['full_name']);
    }

    // ------------------------------------------------------------ 3 + 4. trim, the clearer email, newest only

    public function testTheTemporaryPasswordEmailNamesTheAccountAndOnlyTheNewestWorks(): void
    {
        $reset  = new StaffPasswordReset();
        $first  = $reset->requestForgot($this->row($this->staffId));
        $second = $reset->requestForgot($this->row($this->staffId));

        $mails = $this->sentTo('ana@example.test');
        $this->assertCount(2, $mails, 'Only the staff member gets it.');
        $this->assertSame([], $this->sentTo('owner@example.test'));
        foreach ($mails as $m) {
            $this->assertStringContainsString('Account: Ana Cruz (cashier1)', $m['body']);
            $this->assertStringContainsString('This replaces any earlier temporary password. Only the newest one works.', $m['body']);
        }

        $this->post('login/staff', ['password' => $first])->assertRedirectTo(site_url('/'));
        $this->assertNotSame(1, (int) $this->row($this->staffId)['must_change_password'], 'The older one no longer works.');
        $this->clearLimits();

        // The newest one, copied with a space and a line break at the end, signs in.
        $this->post('login/staff', ['password' => $second . " \n"])->assertRedirectTo(site_url('/staff/set-password'));
        $this->assertSame(1, (int) $this->row($this->staffId)['must_change_password']);
    }

    public function testAPasswordThatReallyEndsInASpaceStillWorksAsTyped(): void
    {
        $this->db->table('users')->update(['password_hash' => password_hash('Spaced pass1 ', PASSWORD_DEFAULT)], ['user_id' => $this->staffId]);

        $this->post('login/staff', ['password' => 'Spaced pass1 '])->assertRedirectTo(site_url('/staff/dashboard'));
    }

    // ------------------------------------------------------------ 5. Signed in as

    public function testSetYourPasswordSaysWhichAccount(): void
    {
        $this->db->table('users')->update(['must_change_password' => 1, 'temp_password_kind' => 'reset', 'temp_password_expires_at' => date('Y-m-d H:i:s', time() + 3600)], ['user_id' => $this->staffId]);

        $html = (string) $this->asStaff()->get('staff/set-password')->getBody();
        $this->assertStringContainsString('Signed in as <strong>Ana Cruz</strong> (@cashier1)', $html);
    }

    // ------------------------------------------------------------ 6. locked

    public function testALockedStaffMemberIsToldAndTheirSaleIsNotSaved(): void
    {
        $this->db->table('products')->insert(['item_id' => 'P-1', 'item_name' => 'Patch', 'current_stock' => 5, 'selling_price' => 50]);
        $this->db->table('users')->update(['is_active' => 0], ['user_id' => $this->staffId]); // the Owner clicked Lock

        $response = $this->asStaff()->post('staff/sales/checkout', ['item_id' => ['P-1'], 'quantity' => ['2'], 'payment_method' => 'Cash', 'cash_received' => '100']);
        $response->assertRedirectTo(site_url('/login/staff'));
        $this->assertSame('Your account was locked by the Owner. Please contact the Owner. Your last sale was not saved.', $_SESSION['error'] ?? null);
        $this->assertSame(0, $this->db->table('sales_transaction')->countAllResults(), 'No sale.');
        $this->assertSame(5, (int) $this->db->table('products')->where('item_id', 'P-1')->get()->getRow()->current_stock, 'Stock unchanged.');

        $this->asStaff()->get('staff/dashboard');
        $this->assertSame('Your account was locked by the Owner. Please contact the Owner.', $_SESSION['error'] ?? null, 'Any other page: the lock message alone.');
    }

    // ------------------------------------------------------------ 7. after an OTP reset

    /** @dataProvider otpRoles */
    public function testAnOtpResetSendsOneEmailToThatAccount(string $role, string $userProp, string $email, string $contact): void
    {
        $userId = $this->{$userProp};
        $this->withSession(['pwd_reset_user_id' => $userId, 'pwd_reset_role' => $role, 'pwd_reset_verified' => true])
            ->post("login/{$role}/reset-password", ['new_password' => 'BrandNew123', 'confirm_password' => 'BrandNew123']);

        $this->assertTrue(password_verify('BrandNew123', $this->row($userId)['password_hash']));
        $this->assertCount(1, $this->mail->out, 'One email.');
        $this->assertSame([$email], $this->mail->out[0]['to'], "To that account's own address only.");
        $this->assertMatchesRegularExpression('/Your RfourL password was changed on \w{3} \d{1,2}, \d{4}, \d{1,2}:\d{2} [AP]M\. If this wasn\'t you, contact ' . $contact . ' right away\./', preg_replace('/\s+/', ' ', $this->mail->out[0]['body'])); // the mailer wraps long lines
        $this->assertStringNotContainsString('http', $this->mail->out[0]['body']);
    }

    public static function otpRoles(): array
    {
        return ['Owner' => ['admin', 'ownerId', 'owner@example.test', 'support'], 'Supplier' => ['supplier', 'supplierUserId', 'tgi@example.test', 'the owner']];
    }

    // ------------------------------------------------------------ 8. a staff email is never the Owner's

    public function testAddStaffAccountRefusesTheOwnersEmail(): void
    {
        $this->asOwner()->post('admin/settings/staff-accounts', ['full_name' => 'New Cashier', 'username' => 'cashier9', 'contact_email' => 'OWNER@example.test']);

        $this->assertSame("That is the owner's email. Use the staff member's own email.", $_SESSION['error'] ?? null);
        $this->assertSame(0, $this->db->table('users')->where('username', 'cashier9')->countAllResults());
        $this->assertSame([], $this->mail->out);
    }

    public function testStaffChangeEmailRefusesTheOwnersEmail(): void
    {
        $this->asStaff()->post('staff/account/profile', ['full_name' => 'Ana Cruz', 'phone' => '', 'email' => 'Owner@Example.test', 'current_password' => 'Cashier1Pass1']);

        $this->assertSame("That email can't be used. Use your own email.", $_SESSION['error'] ?? null);
        $this->assertSame('ana@example.test', $this->row($this->staffId)['email']);
        $this->assertSame([], $this->mail->out, 'No code sent.');
    }

    public function testForgotPasswordSendsNothingWhenTheStaffEmailIsTheOwners(): void
    {
        $this->db->table('users')->update(['email' => ' Owner@Example.test '], ['user_id' => $this->staffId]); // old data

        $this->post('login/staff/forgot', ['staff_name' => 'cashier1']);

        $this->assertSame([], $this->mail->out, 'Nothing is emailed.');
        $this->assertNull($this->row($this->staffId)['temp_password_hash']);
        $notice = $this->db->table('notifications')->where('recipient_role', 'Admin')->get()->getRow();
        $this->assertSame('Ana Cruz tried to reset their password', $notice->title);
        $this->assertStringContainsString("same as the owner's", $notice->message);
        $this->assertSame(1, $this->db->table('security_log')->where('event', 'password_forgot_owner_email')->countAllResults());
    }

    // ------------------------------------------------------------ 9. the eye on every password field

    public function testEveryLayoutLoadsTheSharedEye(): void
    {
        foreach (['admin', 'staff', 'supplier'] as $role) {
            $layout = (string) file_get_contents(APPPATH . "Views/layouts/{$role}_layout.php");
            $this->assertStringContainsString('<link href="/assets/css/pw-toggle.css" rel="stylesheet">', $layout, $role);
            $this->assertStringContainsString('<script src="/assets/js/pw-toggle.js"></script>', $layout, $role);
        }
        $this->assertStringStartsWith("/* public/assets/css/auth-theme.css */\n@import url('pw-toggle.css');", str_replace("\r\n", "\n", (string) file_get_contents(FCPATH . 'assets/css/auth-theme.css')));
    }
}
