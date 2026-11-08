<?php

use App\Libraries\AttemptStore;
use App\Libraries\LoginLockout;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Staff password flows.
 *
 *   Flow 1  Forgot password: no owner approval. A temporary password is emailed straight to the staff
 *           member (2 hours); their current password keeps working until it is used; the first sign-in
 *           forces "Create new password"; then the owner gets ONE email and ONE in-app alert, both with
 *           Accept and Lock this account (only one of the two can be used).
 *   Flow 2  The owner types a new password in Manage Accounts (also unlocks); the staff member gets an
 *           email without the password and must create their own at the next sign-in.
 *
 * Also new accounts (invite), resend and expiry. Email is captured by a fake mailer.
 *
 * @internal
 */
final class StaffPasswordFlowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    private const NEUTRAL = 'sent';

    private int $adminId;
    private int $staffId;
    private int $otherId;
    private object $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        $filters = config('Filters');
        $filters->globals['before'] = array_values(array_diff($filters->globals['before'], ['csrf']));

        $forge = \Config\Database::forge();
        foreach (['users', 'notifications', 'security_log'] as $table) {
            $forge->dropTable($table, true);
        }
        $forge->addField([
            'user_id'                  => ['type' => 'INTEGER', 'auto_increment' => true],
            'username'                 => ['type' => 'VARCHAR', 'constraint' => 50],
            'full_name'                => ['type' => 'VARCHAR', 'constraint' => 100],
            'email'                    => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'role'                     => ['type' => 'VARCHAR', 'constraint' => 20],
            'is_active'                => ['type' => 'INTEGER', 'default' => 1],
            'password_hash'            => ['type' => 'VARCHAR', 'constraint' => 255],
            'temp_password_hash'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'last_login'               => ['type' => 'DATETIME', 'null' => true],
            'created_at'               => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'               => ['type' => 'DATETIME', 'null' => true],
            'must_change_password'     => ['type' => 'INTEGER', 'default' => 0],
            'temp_password_kind'       => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'temp_password_expires_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('user_id', true);
        $forge->createTable('users');
        $forge->addField([
            'notification_id'   => ['type' => 'INTEGER', 'auto_increment' => true],
            'recipient_role'    => ['type' => 'VARCHAR', 'constraint' => 20],
            'recipient_id'      => ['type' => 'INTEGER', 'null' => true],
            'type'              => ['type' => 'VARCHAR', 'constraint' => 50],
            'category'          => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'title'             => ['type' => 'VARCHAR', 'constraint' => 255],
            'message'           => ['type' => 'TEXT', 'null' => true],
            'link_url'          => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'is_read'           => ['type' => 'INTEGER', 'default' => 0],
            'status'            => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'action_token'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'action_user_id'    => ['type' => 'INTEGER', 'null' => true],
            'action_expires_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('notification_id', true);
        $forge->createTable('notifications');
        $forge->addField([
            'log_id'     => ['type' => 'INTEGER', 'auto_increment' => true],
            'user_id'    => ['type' => 'INTEGER', 'null' => true],
            'username'   => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'role'       => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'event'      => ['type' => 'VARCHAR', 'constraint' => 50],
            'detail'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('log_id', true);
        $forge->createTable('security_log');

        $this->adminId = $this->makeUser('owner1', 'Owner One', 'Admin', 'OwnerPass123', 'owner@example.test');
        $this->staffId = $this->makeUser('cashier1', 'Cashier1', 'Staff', 'Cashier1Pass1', 'cashier1@example.test');
        $this->otherId = $this->makeUser('cashier2', 'Cashier2', 'Staff', 'Cashier2Pass2', 'cashier2@example.test');

        $this->mailer = new class () {
            public array $sent = [];
            public bool $ok = true;
            private array $current = [];

            public function __call(string $name, array $args)
            {
                if ($name === 'send') {
                    $this->sent[]  = $this->current;
                    $this->current = [];

                    return $this->ok;
                }
                $this->current[$name] = $args[0] ?? null;

                return $this;
            }
        };
        Services::injectMock('email', $this->mailer);

        $this->clearLimits();
    }

    protected function tearDown(): void
    {
        $this->clearLimits();
        Services::resetSingle('email');
        foreach (['users', 'notifications', 'security_log'] as $table) {
            \Config\Database::forge()->dropTable($table, true);
        }
        parent::tearDown();
    }

    private function clearLimits(): void
    {
        (new LoginLockout())->reset('staff');
        AttemptStore::forget('staff_forgot_' . md5('0.0.0.0'));
        foreach ([1, 2, 3, 4] as $id) {
            AttemptStore::forget('staff_forgot_acct_' . $id);
            AttemptStore::forget('staff_set_pw_' . $id);
            AttemptStore::forget('owner_reset_' . $id);
        }
    }

    // ---------------------------------------------------------------- helpers

    private function makeUser(string $username, string $name, string $role, string $password, ?string $email): int
    {
        $this->db->table('users')->insert([
            'username' => $username, 'full_name' => $name, 'role' => $role, 'is_active' => 1,
            'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        return (int) $this->db->insertID();
    }

    private function user(int $id): object
    {
        return $this->db->table('users')->where('user_id', $id)->get()->getRow();
    }

    private function asOwner(): self
    {
        return $this->withSession(['isLoggedIn' => true, 'role' => 'Admin', 'user_id' => $this->adminId, 'username' => 'owner1', 'full_name' => 'Owner One', 'last_activity' => time()]);
    }

    private function asStaff(?int $id = null): self
    {
        return $this->withSession(['isLoggedIn' => true, 'role' => 'Staff', 'user_id' => $id ?? $this->staffId, 'username' => 'cashier1', 'full_name' => 'Cashier1', 'last_activity' => time()]);
    }

    /** The temporary password found in the last email sent to this address. */
    private function emailedPassword(string $to): ?string
    {
        foreach (array_reverse($this->mailer->sent) as $mail) {
            if (($mail['setTo'] ?? '') === $to && preg_match('#letter-spacing:2px;">([A-Za-z0-9]{12})</p>#', (string) ($mail['setMessage'] ?? ''), $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /** @return list<array> the emails sent to this address */
    private function mailsTo(string $to): array
    {
        return array_values(array_filter($this->mailer->sent, static fn ($m) => ($m['setTo'] ?? '') === $to));
    }

    /** Staff asks for a reset with their username; returns the emailed temporary password. */
    private function forgot(string $name = 'cashier1'): ?string
    {
        AttemptStore::forget('staff_forgot_' . md5('0.0.0.0'));
        $this->post('login/staff/forgot', ['staff_name' => $name]);

        return $this->emailedPassword('cashier1@example.test');
    }

    /** Forgot password, then the first sign-in with the temporary password. Returns it. */
    private function forgotAndSignIn(): string
    {
        $temp = $this->forgot();
        $this->assertNotNull($temp);
        $this->post('login/staff', ['password' => $temp])->assertRedirectTo(site_url('/staff/set-password'));
        (new LoginLockout())->reset('staff');

        return $temp;
    }

    /** The whole of Flow 1: forgot -> temporary password -> new password. Mail from before is dropped. */
    private function completeForgot(string $new = 'BrandNewPass9'): void
    {
        $this->forgotAndSignIn();
        $this->mailer->sent = [];
        $this->db->table('notifications')->truncate();

        $this->asStaff()->post('staff/set-password', ['new_password' => $new, 'confirm_password' => $new])->assertRedirectTo(site_url('/staff/dashboard'));
    }

    /** The one alert row Flow 1 leaves for the owner. */
    private function alert(): object
    {
        $row = $this->db->table('notifications')->where('type', 'Password Reset')->get()->getRow();
        $this->assertNotNull($row, 'the owner should have the Accept / Lock alert');

        return $row;
    }

    /** [notification id, raw token] from the owner's email. */
    private function emailLinks(): array
    {
        $mail = $this->mailsTo('owner@example.test')[0];
        $this->assertSame(1, preg_match('#notification-action/(\d+)/([0-9a-f]{64})/accept#', (string) $mail['setMessage'], $a));
        $this->assertSame(1, preg_match('#notification-action/(\d+)/([0-9a-f]{64})/lock#', (string) $mail['setMessage'], $l));
        $this->assertSame($a[2], $l[2], 'Accept and Lock share one alert');

        return [(int) $a[1], $a[2]];
    }

    // ================================================================ FLOW 1: Forgot password

    public function testForgotEmailsATemporaryPasswordDirectlyAndTheOldPasswordKeepsWorking(): void
    {
        $result = $this->post('login/staff/forgot', ['staff_name' => 'cashier1']);

        $result->assertSessionHas(self::NEUTRAL);
        $temp = $this->emailedPassword('cashier1@example.test');
        $this->assertNotNull($temp);
        $this->assertSame(12, strlen($temp));
        $this->assertSame('Your temporary password', $this->mailsTo('cashier1@example.test')[0]['setSubject']);
        $this->assertStringContainsString('2 hours', $this->mailsTo('cashier1@example.test')[0]['setMessage']);

        // No owner approval: nothing for the owner to do, no email or note to them yet.
        $this->assertSame([], $this->mailsTo('owner@example.test'));
        $this->assertSame(0, $this->db->table('notifications')->countAllResults());

        // The temporary password waits beside the current one; nothing about the account changed.
        $row = $this->user($this->staffId);
        $this->assertTrue(password_verify('Cashier1Pass1', $row->password_hash));
        $this->assertTrue(password_verify($temp, $row->temp_password_hash));
        $this->assertSame('forgot', $row->temp_password_kind);
        $this->assertEqualsWithDelta(time() + 7200, strtotime($row->temp_password_expires_at), 10);
        $this->assertSame('0', (string) $row->must_change_password);

        // The old password still signs in, straight to the dashboard: nobody can lock a staff member out by asking.
        $this->post('login/staff', ['password' => 'Cashier1Pass1'])->assertRedirectTo(site_url('/staff/dashboard'));
        $this->assertSame(1, $this->db->table('security_log')->where('event', 'password_forgot_requested')->countAllResults());
        $this->assertStringNotContainsString($temp, json_encode($this->db->table('security_log')->get()->getResultArray()));
        $this->assertStringNotContainsString($temp, json_encode($this->db->table('notifications')->get()->getResultArray()));
    }

    public function testTheFullNameWorksToo(): void
    {
        $this->post('login/staff/forgot', ['staff_name' => ' CASHIER1 '])->assertSessionHas(self::NEUTRAL);

        $this->assertNotNull($this->emailedPassword('cashier1@example.test'));
    }

    public function testTheSameNeutralMessageForUnknownLockedAndAmbiguousNames(): void
    {
        $this->db->table('users')->where('user_id', $this->otherId)->update(['full_name' => 'Cashier1']); // two people named Cashier1
        $locked = $this->makeUser('locked1', 'Locked Person', 'Staff', 'LockedPass123', 'locked@example.test');
        $this->db->table('users')->where('user_id', $locked)->update(['is_active' => 0]);

        foreach (['Nobody Here', 'Cashier1', 'locked1'] as $typed) {
            AttemptStore::forget('staff_forgot_' . md5('0.0.0.0'));
            $this->post('login/staff/forgot', ['staff_name' => $typed])->assertSessionHas(self::NEUTRAL);
        }

        $this->assertSame([], $this->mailer->sent, 'no email for names that do not match exactly one active account');
        $this->assertSame(0, $this->db->table('notifications')->countAllResults());
        $this->assertSame(0, $this->db->table('users')->where('temp_password_hash IS NOT NULL')->countAllResults());
        $this->assertGreaterThanOrEqual(2, $this->db->table('security_log')->where('event', 'password_forgot_no_match')->countAllResults(), 'misses are logged');

        // And the page says the same thing whatever happened.
        $page = $this->withSession(['sent' => true, '__ci_vars' => ['sent' => 'new']])->get('login/staff/forgot')->getBody();
        $this->assertStringContainsString('If the account exists and has an email on file, a temporary password has been sent.', $page);
    }

    public function testAnEmptyNameAsksForOne(): void
    {
        $this->post('login/staff/forgot', ['staff_name' => '  '])->assertSessionHas('error');

        $this->assertSame([], $this->mailer->sent);
    }

    public function testNoEmailOnFileSendsNothingAndTellsTheOwnerInTheApp(): void
    {
        foreach ([null, 'cashier1@rfourl.local'] as $i => $email) {
            $this->db->table('notifications')->truncate();
            $this->db->table('users')->where('user_id', $this->staffId)->update(['email' => $email]);

            $this->post('login/staff/forgot', ['staff_name' => 'cashier1'])->assertSessionHas(self::NEUTRAL);

            $this->assertSame([], $this->mailer->sent, 'nothing is sent when there is no usable email');
            $this->assertNull($this->user($this->staffId)->temp_password_hash);
            $note = $this->db->table('notifications')->get()->getRow();
            $this->assertNotNull($note);
            $this->assertSame('Admin', $note->recipient_role);
            $this->assertSame(0, (int) $note->is_read);
            $this->assertSame('Cashier1 tried to reset their password but has no email on file. Reset it from Manage Accounts.', $note->message);
            AttemptStore::forget('staff_forgot_acct_' . $this->staffId);
        }
        $this->assertGreaterThanOrEqual(2, $this->db->table('security_log')->where('event', 'password_forgot_no_email')->countAllResults());
    }

    public function testAtMostThreeRequestsPerAccountPerHour(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            AttemptStore::forget('staff_forgot_' . md5('0.0.0.0')); // the per-client limit is a separate rule
            $this->post('login/staff/forgot', ['staff_name' => 'cashier1'])->assertSessionHas(self::NEUTRAL);
        }

        $this->assertCount(3, $this->mailsTo('cashier1@example.test'));
        $this->assertSame(2, $this->db->table('security_log')->where('event', 'password_forgot_rate_limited')->countAllResults());
        $this->assertSame(0, $this->db->table('notifications')->countAllResults());

        // Another account is not affected.
        AttemptStore::forget('staff_forgot_' . md5('0.0.0.0'));
        $this->post('login/staff/forgot', ['staff_name' => 'cashier2'])->assertSessionHas(self::NEUTRAL);
        $this->assertCount(1, $this->mailsTo('cashier2@example.test'));
    }

    public function testAFailedEmailChangesNothing(): void
    {
        $this->mailer->ok = false;

        $this->post('login/staff/forgot', ['staff_name' => 'cashier1'])->assertSessionHas(self::NEUTRAL);

        $this->assertNull($this->user($this->staffId)->temp_password_hash);
        $this->assertSame(1, $this->db->table('security_log')->where('event', 'password_forgot_failed')->countAllResults());
    }

    public function testTheFirstSignInWithTheTemporaryPasswordForcesCreateNewPassword(): void
    {
        $temp = $this->forgotAndSignIn();

        $row = $this->user($this->staffId);
        $this->assertTrue(password_verify($temp, $row->password_hash), 'the temporary password is now the account password');
        $this->assertNull($row->temp_password_hash);
        $this->assertSame('1', (string) $row->must_change_password);
        $this->assertSame('forgot', $row->temp_password_kind);

        // Nothing else opens until they create their own.
        $this->asStaff()->get('staff/dashboard')->assertRedirectTo(site_url('/staff/set-password'));
        $this->asStaff()->get('staff/account')->assertRedirectTo(site_url('/staff/set-password'));
        $this->asStaff()->get('staff/set-password')->assertOK();

        // The old password no longer works now that the temporary one was used.
        $this->post('login/staff', ['password' => 'Cashier1Pass1'])->assertSessionHas('error', 'Incorrect password. Please try again');
    }

    public function testAnExpiredTemporaryPasswordIsRefusedAndTheOldOneStillWorks(): void
    {
        $temp = $this->forgot();
        $this->db->table('users')->where('user_id', $this->staffId)->update(['temp_password_expires_at' => date('Y-m-d H:i:s', time() - 60)]);

        $result = $this->post('login/staff', ['password' => $temp]);
        $result->assertSessionHas('error', 'This temporary password has expired. Use "Forgot password?" to get a new one.');
        $result->assertSessionMissing('isLoggedIn');

        (new LoginLockout())->reset('staff');
        $this->post('login/staff', ['password' => 'Cashier1Pass1'])->assertRedirectTo(site_url('/staff/dashboard'));
    }

    public function testNewPasswordRulesAfterForgot(): void
    {
        $temp = $this->forgotAndSignIn();

        $this->asStaff()->post('staff/set-password', ['new_password' => 'short', 'confirm_password' => 'short'])->assertSessionHas('error');
        $this->asStaff()->post('staff/set-password', ['new_password' => 'BrandNewPass9', 'confirm_password' => 'Different9Pass'])->assertSessionHas('error', 'Passwords do not match.');
        $this->asStaff()->post('staff/set-password', ['new_password' => $temp, 'confirm_password' => $temp])->assertSessionHas('error', 'Choose a different password from the temporary one.');
        $this->asStaff()->post('staff/set-password', ['new_password' => 'Cashier2Pass2', 'confirm_password' => 'Cashier2Pass2'])
            ->assertSessionHas('error', "This password can't be used. Please choose a different one.");

        $this->assertSame('1', (string) $this->user($this->staffId)->must_change_password);
    }

    public function testAWaitingTemporaryPasswordIsNotAvailableToAnotherAccount(): void
    {
        $temp = $this->forgot();

        // Staff sign in by password alone, so another account can't pick the same one.
        $this->assertTrue((new \App\Models\UserModel())->staffPasswordInUse($temp, $this->otherId));
    }

    public function testAfterTheNewPasswordTheOwnerGetsOneEmailAndOneInAppAlert(): void
    {
        $this->completeForgot('BrandNewPass9');

        $staff = $this->user($this->staffId);
        $this->assertSame('0', (string) $staff->must_change_password);
        $this->assertTrue(password_verify('BrandNewPass9', $staff->password_hash));

        // One email to the owner, nothing else sent.
        $this->assertCount(1, $this->mailer->sent);
        $mail = $this->mailsTo('owner@example.test')[0];
        $this->assertSame('Cashier1 reset their password using Forgot Password', $mail['setSubject']);
        $body = html_entity_decode((string) $mail['setMessage']);
        $this->assertStringContainsString('Cashier1 requested a temporary password and set a new password on ' . date('M j, Y, g:i A') . '.', $body);
        $this->assertStringContainsString('Did you know about this? If it was really Cashier1, click Accept. If not, click Lock this account.', $body);
        $this->assertStringContainsString('>Accept<', $mail['setMessage']);
        $this->assertStringContainsString('>Lock this account<', $mail['setMessage']);
        $this->assertStringNotContainsString('Nothing else to do', json_encode($this->mailer->sent));
        $this->assertStringNotContainsString('BrandNewPass9', json_encode($this->mailer->sent));

        // One in-app alert, unread, with the same two actions.
        $this->assertSame(1, $this->db->table('notifications')->countAllResults());
        $alert = $this->alert();
        $this->assertSame('Admin', $alert->recipient_role);
        $this->assertSame(0, (int) $alert->is_read);
        $this->assertSame('security', $alert->category);
        $this->assertSame('Cashier1 set a new password on ' . date('M j, Y, g:i A'), $alert->title);
        $this->assertSame($this->staffId, (int) $alert->action_user_id);
        $this->assertNull($alert->status);
        $this->assertNotEmpty($alert->action_token);
        $this->assertStringNotContainsString('BrandNewPass9', json_encode($alert));

        // The owner's Notifications page shows both buttons.
        $page = $this->asOwner()->get('admin/notifications')->getBody();
        $this->assertStringContainsString("admin/notifications/accept/{$alert->notification_id}", $page);
        $this->assertStringContainsString("admin/notifications/lock/{$alert->notification_id}", $page);
    }

    // ------------------------------------------------ Accept / Lock: only one of them

    public function testAcceptByEmailLinkClosesTheAlertAndLockStopsWorking(): void
    {
        $this->completeForgot();
        [$id, $token] = $this->emailLinks();

        // The link only shows a confirm page; nothing changes on GET.
        $this->get("notification-action/{$id}/{$token}/accept")->assertSee('Accept');
        $this->assertNull($this->alert()->status);

        $this->post("notification-action/{$id}/{$token}/accept")->assertSee('Confirmed');
        $alert = $this->alert();
        $this->assertSame('confirmed', $alert->status);
        $this->assertSame(1, (int) $alert->is_read);
        $this->assertSame(1, (int) $this->user($this->staffId)->is_active);

        $note = $this->db->table('notifications')->where('title', 'Password reset confirmed')->get()->getRow();
        $this->assertNotNull($note);
        $this->assertSame("You confirmed Cashier1's password reset on " . date('M j, Y, g:i A') . '.', $note->message);
        $this->assertNull($note->action_user_id, 'the note has no buttons');

        // Both links now say it is already confirmed, and Lock does nothing.
        $this->get("notification-action/{$id}/{$token}/accept")->assertSee('Already confirmed');
        $this->get("notification-action/{$id}/{$token}/lock")->assertSee('Already confirmed');
        $this->post("notification-action/{$id}/{$token}/lock")->assertSee('Already confirmed');
        $this->post("notification-action/{$id}/{$token}/accept")->assertSee('Already confirmed');
        $this->assertSame(1, (int) $this->user($this->staffId)->is_active);

        // In the app, Lock is refused too and the buttons are gone.
        $this->asOwner()->post("admin/notifications/lock/{$id}");
        $this->assertSame(1, (int) $this->user($this->staffId)->is_active);
        $page = $this->asOwner()->get('admin/notifications')->getBody();
        $this->assertStringNotContainsString("admin/notifications/accept/{$id}", $page);
        $this->assertStringNotContainsString("admin/notifications/lock/{$id}", $page);
        $this->assertStringContainsString('Confirmed', $page);
    }

    public function testLockByEmailLinkEndsTheStaffSessionAndAcceptStopsWorking(): void
    {
        $this->completeForgot();
        [$id, $token] = $this->emailLinks();

        $this->post("notification-action/{$id}/{$token}/lock")->assertSee("Cashier1's account is locked");

        $this->assertSame(0, (int) $this->user($this->staffId)->is_active);
        $this->assertSame('locked', $this->alert()->status);
        $note = $this->db->table('notifications')->where('title', 'Account locked')->get()->getRow();
        $this->assertSame('You locked the account of Cashier1 on ' . date('M j, Y, g:i A') . '.', $note->message);

        // The staff member is signed out at their next click.
        $this->asStaff()->get('staff/account')->assertRedirectTo(site_url('login/staff'));

        // Accept no longer works, by link or in the app.
        $this->post("notification-action/{$id}/{$token}/accept")->assertDontSee('Confirmed.');
        $this->asOwner()->post("admin/notifications/accept/{$id}");
        $this->assertSame('locked', $this->alert()->status);
        $this->assertSame(0, $this->db->table('notifications')->where('title', 'Password reset confirmed')->countAllResults());
    }

    public function testInAppAcceptAndLock(): void
    {
        $this->completeForgot();
        $id = (int) $this->alert()->notification_id;

        $this->asOwner()->post("admin/notifications/accept/{$id}")->assertRedirectTo(site_url('/admin/notifications'));
        $this->assertSame('confirmed', $this->alert()->status);
        $this->assertSame(1, $this->db->table('notifications')->where('title', 'Password reset confirmed')->countAllResults());

        // The email link is closed as well.
        [, $token] = $this->emailLinksFromEarlierMail();
        $this->get("notification-action/{$id}/{$token}/lock")->assertSee('Already confirmed');
    }

    public function testInAppLockClosesTheAcceptButton(): void
    {
        $this->completeForgot();
        $id = (int) $this->alert()->notification_id;

        $this->asOwner()->post("admin/notifications/lock/{$id}");
        $this->assertSame(0, (int) $this->user($this->staffId)->is_active);

        $this->asOwner()->post("admin/notifications/accept/{$id}");
        $this->assertSame('locked', $this->alert()->status);
        $page = $this->asOwner()->get('admin/notifications')->getBody();
        $this->assertStringNotContainsString("admin/notifications/accept/{$id}", $page);
        $this->assertStringContainsString('Account locked', $page);
    }

    /** The links of the owner's email, kept before $this->mailer->sent was cleared by completeForgot(). */
    private function emailLinksFromEarlierMail(): array
    {
        return $this->emailLinks();
    }

    public function testExpiredLinksAreRefused(): void
    {
        $this->completeForgot();
        [$id, $token] = $this->emailLinks();
        $this->db->table('notifications')->where('notification_id', $id)->update(['action_expires_at' => date('Y-m-d H:i:s', time() - 60)]);

        $this->post("notification-action/{$id}/{$token}/accept")->assertSee('This link is no longer valid.');
        $this->post("notification-action/{$id}/{$token}/lock")->assertSee('This link is no longer valid.');
        $this->assertNull($this->alert()->status);
    }

    public function testWrongTokensAreRefused(): void
    {
        $this->completeForgot();
        [$id] = $this->emailLinks();
        $alert = $this->alert();

        foreach ([str_repeat('a', 64), $alert->action_token] as $wrong) { // a guess, and the stored hash itself
            $this->post("notification-action/{$id}/{$wrong}/accept")->assertSee('This link is no longer valid.');
            $this->post("notification-action/{$id}/{$wrong}/lock")->assertSee('This link is no longer valid.');
        }
        $this->assertNull($this->alert()->status);
        $this->assertSame(1, (int) $this->user($this->staffId)->is_active);
    }

    // ------------------------------------------------ old approval links and requests

    public function testOldApproveAndDeclineLinksSayTheyAreNoLongerUsed(): void
    {
        $this->db->table('notifications')->insert([
            'recipient_role' => 'Admin', 'type' => 'Access Request', 'category' => 'access_request',
            'title' => 'Password reset requested by Cashier1', 'action_token' => 'tok-abcdef', 'action_user_id' => $this->staffId,
        ]);
        $id = (int) $this->db->insertID();
        $hash = $this->user($this->staffId)->password_hash;

        foreach (['approve', 'decline'] as $action) {
            foreach ([$id => 'tok-abcdef', 999 => 'whatever'] as $nid => $tok) {
                $this->get("notification-action/{$nid}/{$tok}/{$action}")->assertSee('This link is no longer used. Staff now reset their password themselves.');
                $this->post("notification-action/{$nid}/{$tok}/{$action}", ['confirmed' => '1'])->assertSee('This link is no longer used. Staff now reset their password themselves.');
            }
        }
        $this->assertSame($hash, $this->user($this->staffId)->password_hash);
        $this->assertSame([], $this->mailer->sent);
        $this->assertNull($this->db->table('notifications')->where('notification_id', $id)->get()->getRow()->status);
    }

    public function testThePendingApprovalRequestsAreClosedByTheMigration(): void
    {
        $rows = [
            'pending'  => ['status' => null],
            'blank'    => ['status' => ''],
            'approved' => ['status' => 'approved'],
            'declined' => ['status' => 'declined'],
        ];
        $ids = [];
        foreach ($rows as $label => $r) {
            $this->db->table('notifications')->insert([
                'recipient_role' => 'Admin', 'type' => 'Access Request', 'category' => 'access_request', 'title' => "Password reset requested by {$label}",
                'status' => $r['status'], 'action_token' => 'tok-' . $label, 'is_read' => 0,
            ]);
            $ids[$label] = (int) $this->db->insertID();
        }
        $this->db->table('notifications')->insert(['recipient_role' => 'Admin', 'type' => 'Security', 'category' => 'security', 'title' => 'Something else', 'status' => null, 'action_token' => 'keep']);
        $other = (int) $this->db->insertID();

        require_once APPPATH . 'Database/Migrations/2026-11-09-100100_ExpirePendingPasswordApprovals.php';
        (new \App\Database\Migrations\ExpirePendingPasswordApprovals())->up();

        $status = fn (int $id) => $this->db->table('notifications')->where('notification_id', $id)->get()->getRow();
        foreach (['pending', 'blank'] as $label) {
            $this->assertSame('expired', $status($ids[$label])->status);
            $this->assertSame(1, (int) $status($ids[$label])->is_read);
            $this->assertNull($status($ids[$label])->action_token);
        }
        $this->assertSame('approved', $status($ids['approved'])->status);
        $this->assertSame('declined', $status($ids['declined'])->status);
        $this->assertNull($status($other)->status);
        $this->assertSame('keep', $status($other)->action_token);
    }

    public function testOldRequestsShowAsClosedHistoryWithNoButtons(): void
    {
        $this->db->table('notifications')->insert([
            'recipient_role' => 'Admin', 'type' => 'Access Request', 'category' => 'access_request',
            'title' => 'Password reset requested by Cashier1', 'status' => 'expired', 'is_read' => 1,
        ]);

        $page = $this->asOwner()->get('admin/notifications')->getBody();

        $this->assertStringContainsString('Password reset requested by Cashier1', $page);
        $this->assertStringContainsString('staff now reset their own password', $page);
        $this->assertStringNotContainsString('notifications/approve', $page);
        $this->assertStringNotContainsString('notifications/decline', $page);
        $this->assertStringNotContainsString('data-approve-open="', $page);
    }

    // ================================================================ FLOW 2: the owner resets it

    private function ownerReset(array $fields = [], ?int $staffId = null)
    {
        $staffId ??= $this->staffId;

        return $this->asOwner()->post("admin/settings/staff-accounts/password/{$staffId}", $fields + [
            'new_password' => 'OwnerChose123', 'confirm_password' => 'OwnerChose123', 'owner_password' => 'OwnerPass123', 'informed' => '1',
        ]);
    }

    public function testOwnerResetNeedsTheOwnersPasswordAndTheTick(): void
    {
        $hash = $this->user($this->staffId)->password_hash;

        $this->ownerReset(['owner_password' => 'wrong'])->assertSessionHas('error', 'Your password is incorrect.');
        $this->ownerReset(['informed' => ''])->assertSessionHas('error', 'Tick "I have informed Cashier1 about this reset" first.');

        $this->assertSame($hash, $this->user($this->staffId)->password_hash);
        $this->assertSame([], $this->mailer->sent);
    }

    public function testOwnerResetEnforcesThePasswordRules(): void
    {
        $hash = $this->user($this->staffId)->password_hash;

        $this->ownerReset(['new_password' => 'short', 'confirm_password' => 'short'])->assertSessionHas('error');
        $this->ownerReset(['new_password' => 'NoDigitsHereAtAll', 'confirm_password' => 'NoDigitsHereAtAll'])->assertSessionHas('error');
        $this->ownerReset(['confirm_password' => 'Different123'])->assertSessionHas('error', 'Passwords do not match.');
        $this->ownerReset(['new_password' => 'Cashier2Pass2', 'confirm_password' => 'Cashier2Pass2'])
            ->assertSessionHas('error', "This password can't be used. Please choose a different one.");
        $this->ownerReset(['new_password' => 'Cashier1Pass1', 'confirm_password' => 'Cashier1Pass1'])
            ->assertSessionHas('error', "That is one of Cashier1's recent passwords. Choose a different one.");

        $this->assertSame($hash, $this->user($this->staffId)->password_hash);
        $this->assertSame([], $this->mailer->sent);
    }

    public function testOwnerResetSetsThePasswordAndForcesANewOneAtNextSignIn(): void
    {
        $this->ownerReset()->assertRedirectTo(site_url('/admin/settings?tab=accounts'));

        $row = $this->user($this->staffId);
        $this->assertTrue(password_verify('OwnerChose123', $row->password_hash));
        $this->assertSame('1', (string) $row->must_change_password);
        $this->assertSame('reset', $row->temp_password_kind);
        $this->assertEqualsWithDelta(time() + 7200, strtotime($row->temp_password_expires_at), 10);
        $this->assertSame(1, $this->db->table('security_log')->where('event', 'password_reset')->countAllResults());
        $this->assertStringNotContainsString('OwnerChose123', json_encode($this->db->table('security_log')->get()->getResultArray()));

        // The staff member signs in with it and is sent to create their own.
        $this->post('login/staff', ['password' => 'OwnerChose123'])->assertRedirectTo(site_url('/staff/set-password'));
        $this->asStaff()->get('staff/dashboard')->assertRedirectTo(site_url('/staff/set-password'));
    }

    public function testOwnerResetEmailsTheStaffMemberWithoutThePassword(): void
    {
        $this->ownerReset();

        $this->assertCount(1, $this->mailer->sent);
        $mail = $this->mailsTo('cashier1@example.test')[0];
        $this->assertSame('Your password was reset by the owner', $mail['setSubject']);
        $this->assertSame('The owner reset your RfourL account password on ' . date('M j, Y, g:i A') . '. Please contact the owner to get your new password.', $mail['setMessage']);
        $this->assertStringNotContainsString('OwnerChose123', json_encode($this->mailer->sent));
        $this->assertSame([], $this->mailsTo('owner@example.test'));
        $this->assertNull(session()->getFlashdata('temp_password_once'));
    }

    public function testNoEmailOnFileMeansNoEmailButTheResetStillWorks(): void
    {
        $this->db->table('users')->where('user_id', $this->staffId)->update(['email' => null]);

        $this->ownerReset()->assertSessionHas('toast');

        $this->assertSame([], $this->mailer->sent);
        $this->assertTrue(password_verify('OwnerChose123', $this->user($this->staffId)->password_hash));
        $this->assertArrayNotHasKey('temp_password_once', $_SESSION);
    }

    public function testOwnerResetUnlocksALockedAccount(): void
    {
        $this->db->table('users')->where('user_id', $this->staffId)->update(['is_active' => 0]);
        $this->post('login/staff', ['password' => 'Cashier1Pass1'])->assertSessionHas('error', 'Sorry, this account was locked by the Owner. Please contact the Owner.');

        $this->ownerReset();

        $this->assertSame(1, (int) $this->user($this->staffId)->is_active);
        (new LoginLockout())->reset('staff');
        $this->post('login/staff', ['password' => 'OwnerChose123'])->assertRedirectTo(site_url('/staff/set-password'));
    }

    public function testOwnerResetReplacesAWaitingForgotPassword(): void
    {
        $temp = $this->forgot();
        $this->mailer->sent = [];

        $this->ownerReset();

        $this->assertNull($this->user($this->staffId)->temp_password_hash);
        $this->post('login/staff', ['password' => $temp])->assertSessionHas('error', 'Incorrect password. Please try again');
    }

    public function testAfterAnOwnerResetTheStaffsNewPasswordAlertsTheOwnerInAppOnly(): void
    {
        $this->ownerReset();
        $this->mailer->sent = [];
        $this->db->table('notifications')->truncate();

        $this->asStaff()->post('staff/set-password', ['new_password' => 'BrandNewPass9', 'confirm_password' => 'BrandNewPass9'])->assertRedirectTo(site_url('/staff/dashboard'));

        $this->assertSame([], $this->mailer->sent, 'no email to the owner for this case');
        $this->assertSame(1, $this->db->table('notifications')->countAllResults());
        $note = $this->db->table('notifications')->get()->getRow();
        $this->assertSame('Cashier1 set a new password on ' . date('M j, Y, g:i A'), $note->title);
        $this->assertSame('Security', $note->type, 'Lock only: no Accept after an owner reset');
        $this->assertSame($this->staffId, (int) $note->action_user_id);
        $this->assertNull($note->action_token);

        $page = $this->asOwner()->get('admin/notifications')->getBody();
        $this->assertStringContainsString("admin/notifications/lock/{$note->notification_id}", $page);
        $this->assertStringNotContainsString("admin/notifications/accept/{$note->notification_id}", $page);
    }

    public function testOwnerResetOfUnknownOrOtherRolesIsRefused(): void
    {
        $this->ownerReset([], 9999)->assertSessionHas('error', 'Staff account not found.');
        $this->ownerReset([], $this->adminId)->assertSessionHas('error', 'Staff account not found.');
    }

    // ================================================================ new accounts (invite)

    public function testNewAccountIsCreatedWithATemporaryPasswordEmailedFor48Hours(): void
    {
        $result = $this->asOwner()->post('admin/settings/staff-accounts', ['full_name' => 'New Person', 'username' => 'newperson', 'contact_email' => 'juan@gmail.com']);

        $result->assertRedirectTo(site_url('/admin/settings?tab=accounts'));
        $result->assertSessionHas('toast');

        $row = $this->db->table('users')->where('username', 'newperson')->get()->getRow();
        $this->assertSame('Staff', $row->role);
        $this->assertSame('1', (string) $row->must_change_password);
        $this->assertSame('invite', $row->temp_password_kind);
        $this->assertEqualsWithDelta(time() + 172800, strtotime($row->temp_password_expires_at), 10);

        $password = $this->emailedPassword('juan@gmail.com');
        $this->assertNotNull($password);
        $this->assertTrue(password_verify($password, $row->password_hash));
        $this->assertStringContainsString('48 hours', $this->mailer->sent[0]['setMessage']);
    }

    public function testNewAccountNeedsAnEmailAndIsNotCreatedIfTheEmailFails(): void
    {
        $this->asOwner()->post('admin/settings/staff-accounts', ['full_name' => 'No Mail', 'username' => 'nomail', 'contact_email' => ''])->assertSessionHas('error');
        $this->assertSame(0, $this->db->table('users')->where('username', 'nomail')->countAllResults());

        $this->mailer->ok = false;
        $this->asOwner()->post('admin/settings/staff-accounts', ['full_name' => 'Bounce', 'username' => 'bounce', 'contact_email' => 'bounce@example.test'])
            ->assertSessionHas('error');
        $this->assertSame(0, $this->db->table('users')->where('username', 'bounce')->countAllResults());
    }

    public function testNewAccountFirstSignInMustSetOwnPassword(): void
    {
        $this->asOwner()->post('admin/settings/staff-accounts', ['full_name' => 'New Person', 'username' => 'newperson', 'contact_email' => 'juan@gmail.com']);
        $temp = $this->emailedPassword('juan@gmail.com');

        $this->post('login/staff', ['password' => $temp])->assertRedirectTo(site_url('/staff/set-password'));
    }

    public function testExpiredInviteCanBeResent(): void
    {
        $this->asOwner()->post('admin/settings/staff-accounts', ['full_name' => 'New Person', 'username' => 'newperson', 'contact_email' => 'juan@gmail.com']);
        $id  = (int) $this->db->table('users')->where('username', 'newperson')->get()->getRow()->user_id;
        $old = $this->emailedPassword('juan@gmail.com');

        $this->db->table('users')->where('user_id', $id)->update(['temp_password_expires_at' => date('Y-m-d H:i:s', time() - 60)]);
        $this->post('login/staff', ['password' => $old])->assertSessionHas('error', 'This temporary password has expired. Ask the owner for a new one.');

        $this->asOwner()->post("admin/settings/staff-accounts/resend/{$id}")->assertRedirectTo(site_url('/admin/settings?tab=accounts'));

        $new = $this->emailedPassword('juan@gmail.com');
        $this->assertNotSame($old, $new);
        $row = $this->user($id);
        $this->assertTrue(password_verify($new, $row->password_hash));
        $this->assertEqualsWithDelta(time() + 172800, strtotime($row->temp_password_expires_at), 10);
    }

    public function testResendOnlyAppliesToPendingInvites(): void
    {
        $this->asOwner()->post("admin/settings/staff-accounts/resend/{$this->staffId}")->assertSessionHas('error');
        $this->assertSame([], $this->mailer->sent);
    }

    public function testAFirstSignInStillTellsTheOwnerByEmailAndInApp(): void
    {
        $this->asOwner()->post('admin/settings/staff-accounts', ['full_name' => 'New Person', 'username' => 'newperson', 'contact_email' => 'juan@gmail.com']);
        $id = (int) $this->db->table('users')->where('username', 'newperson')->get()->getRow()->user_id;
        $this->mailer->sent = [];
        $this->db->table('notifications')->truncate();

        $this->withSession(['isLoggedIn' => true, 'role' => 'Staff', 'user_id' => $id, 'username' => 'newperson', 'full_name' => 'New Person', 'last_activity' => time()])
            ->post('staff/set-password', ['new_password' => 'BrandNewPass9', 'confirm_password' => 'BrandNewPass9']);

        $this->assertSame('New Person signed in and set their password', $this->mailsTo('owner@example.test')[0]['setSubject']);
        $this->assertSame(1, $this->db->table('notifications')->like('title', 'signed in and set their password')->countAllResults());
    }

    public function testStaffCannotUseTheOwnerRoutes(): void
    {
        $this->asStaff()->post('admin/settings/staff-accounts', ['full_name' => 'X', 'username' => 'x', 'contact_email' => 'x@example.test'])->assertRedirectTo(site_url('login/admin'));
        $this->asStaff()->post('admin/settings/staff-accounts/password/' . $this->otherId, [])->assertRedirectTo(site_url('login/admin'));
        $this->asStaff()->post('admin/notifications/accept/1')->assertRedirectTo(site_url('login/admin'));
        $this->asStaff()->post('admin/notifications/lock/1')->assertRedirectTo(site_url('login/admin'));
    }
}
