<?php

use App\Libraries\AttemptStore;
use App\Libraries\LoginLockout;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * The staff temporary-password flow: forgot request -> owner approves (with the
 * "I confirmed with them" tick) -> staff gets a temporary password by email ->
 * signs in -> must set their own -> owner is told. Also owner resets from Manage
 * Accounts, new accounts, resend, and expiry. Email is captured by a fake mailer.
 *
 * @internal
 */
final class StaffPasswordFlowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;
    protected $refresh = false;

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

        (new LoginLockout())->reset('staff');
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        (new LoginLockout())->reset('staff');
        foreach (['users', 'notifications', 'security_log'] as $table) {
            \Config\Database::forge()->dropTable($table, true);
        }
        parent::tearDown();
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

    private function asStaff(int $id = null): self
    {
        return $this->withSession(['isLoggedIn' => true, 'role' => 'Staff', 'user_id' => $id ?? $this->staffId, 'username' => 'cashier1', 'full_name' => 'Cashier1', 'last_activity' => time()]);
    }

    /** A pending "Can't access your account?" request for the staff member, with its email-link token. */
    private function request(?int $userId = null, string $token = 'tok-abcdef'): int
    {
        $this->db->table('notifications')->insert([
            'recipient_role' => 'Admin', 'type' => 'Access Request', 'category' => 'access_request',
            'title' => 'Password reset requested by Cashier1', 'action_token' => $token, 'action_user_id' => $userId ?? $this->staffId,
        ]);

        return (int) $this->db->insertID();
    }

    /** The temporary password found in the last email sent to this address. */
    private function emailedPassword(string $to): ?string
    {
        foreach (array_reverse($this->mailer->sent) as $mail) {
            if (($mail['setTo'] ?? '') === $to && preg_match('#letter-spacing:2px;">([A-Za-z0-9]{12})</p>#', $mail['setMessage'], $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private function approveViaLink(int $id, string $token = 'tok-abcdef', bool $confirmed = true)
    {
        return $this->post("notification-action/{$id}/{$token}/approve", $confirmed ? ['confirmed' => '1'] : []);
    }

    // ------------------------------------------------ 1. staff asks for a reset

    public function testForgotRequestIsMatchedToTheStaffAccountAndTheOwnerIsEmailed(): void
    {
        $this->post('login/staff/forgot', ['staff_name' => 'cashier1', 'reason' => 'forgot'])->assertSessionHas('sent');

        $n = $this->db->table('notifications')->get()->getRow();
        $this->assertSame('access_request', $n->category);
        $this->assertSame($this->staffId, (int) $n->action_user_id);

        $mail = $this->mailer->sent[0];
        $this->assertSame('owner@example.test', $mail['setTo']);
        $this->assertStringContainsString('Matches staff account: Cashier1', $mail['setMessage']);
        $this->assertStringContainsString('Approve reset', $mail['setMessage']);
    }

    public function testUnknownNameLooksTheSameToTheRequesterButIsNotMatched(): void
    {
        $this->post('login/staff/forgot', ['staff_name' => 'Nobody Here', 'reason' => 'x'])->assertSessionHas('sent');

        $this->assertNull($this->db->table('notifications')->get()->getRow()->action_user_id);
        $this->assertStringContainsString('No single staff account matches', $this->mailer->sent[0]['setMessage']);
    }

    // ------------------------------------------------ 2. owner approves

    public function testApproveNeedsTheTickAndShowsTheOwnerTheCheckbox(): void
    {
        $id = $this->request();

        $page = $this->get("notification-action/{$id}/tok-abcdef/approve");
        $page->assertOK();
        $page->assertSee('I confirmed with them');
        $page->assertSee('Approve reset');
        $this->assertSame([], $this->mailer->sent);

        $this->approveViaLink($id, 'tok-abcdef', false)->assertSee('Tick');
        $this->assertSame('0', (string) $this->user($this->staffId)->must_change_password);
        $this->assertSame([], $this->mailer->sent);
    }

    public function testApproveEmailsATemporaryPasswordTheOwnerNeverSees(): void
    {
        $id     = $this->request();
        $result = $this->approveViaLink($id);

        $result->assertSee('A temporary password was emailed to Cashier1');
        $password = $this->emailedPassword('cashier1@example.test');
        $this->assertNotNull($password);
        $this->assertSame(12, strlen($password));
        $this->assertMatchesRegularExpression('/[A-Za-z]/', $password);
        $this->assertMatchesRegularExpression('/\d/', $password);

        // The owner's page, the notification and the log never contain it.
        $this->assertStringNotContainsString($password, (string) $result->getBody());
        $this->assertStringNotContainsString($password, json_encode($this->db->table('notifications')->get()->getResultArray()));
        $this->assertStringNotContainsString($password, json_encode($this->db->table('security_log')->get()->getResultArray()));
        $this->assertSame([], array_filter($this->mailer->sent, fn ($m) => $m['setTo'] === 'owner@example.test'));

        $staff = $this->user($this->staffId);
        $this->assertTrue(password_verify($password, $staff->password_hash));
        $this->assertSame('1', (string) $staff->must_change_password);
        $this->assertSame('reset', $staff->temp_password_kind);
        $this->assertEqualsWithDelta(time() + 7200, strtotime($staff->temp_password_expires_at), 10);
        $this->assertSame('approved', $this->db->table('notifications')->where('notification_id', $id)->get()->getRow()->status);
    }

    public function testApproveIsSingleUse(): void
    {
        $id = $this->request();
        $this->approveViaLink($id);
        $count = count($this->mailer->sent);

        $this->approveViaLink($id)->assertSee('invalid');
        $this->assertCount($count, $this->mailer->sent);
    }

    public function testApproveFailsSafelyWithoutAnEmailOrAMatchOrWhenTheEmailFails(): void
    {
        $hash = $this->user($this->staffId)->password_hash;

        // No email on file.
        $this->db->table('users')->where('user_id', $this->staffId)->update(['email' => null]);
        $this->approveViaLink($this->request())->assertSee('no email on file');

        // Not matched to an account.
        $this->db->table('users')->where('user_id', $this->staffId)->update(['email' => 'cashier1@example.test']);
        $unmatched = $this->request();
        $this->db->table('notifications')->where('notification_id', $unmatched)->update(['action_user_id' => null, 'action_token' => 'tok-unmatched']);
        $this->approveViaLink($unmatched, 'tok-unmatched')->assertSee("couldn't match");

        // Email fails: nothing changes.
        $this->mailer->ok = false;
        $this->approveViaLink($this->request(null, 'tok-fail'), 'tok-fail')->assertSee("couldn't send the email");

        $staff = $this->user($this->staffId);
        $this->assertSame($hash, $staff->password_hash);
        $this->assertSame('0', (string) $staff->must_change_password);
    }

    public function testOwnerCanApproveFromNotificationsOnlyWithTheTick(): void
    {
        $id = $this->request();

        $this->asOwner()->post("admin/notifications/approve/{$id}", [])->assertRedirectTo(site_url('/admin/notifications'));
        $this->assertSame('0', (string) $this->user($this->staffId)->must_change_password);

        $this->asOwner()->post("admin/notifications/approve/{$id}", ['confirmed' => '1'])->assertRedirectTo(site_url('/admin/notifications'));
        $this->assertSame('1', (string) $this->user($this->staffId)->must_change_password);
        $this->assertNotNull($this->emailedPassword('cashier1@example.test'));
    }

    // ------------------------------------------------ 3. staff signs in and sets their own

    private function issueTemp(string $kind = 'reset', int $ttl = 7200): string
    {
        $id = $this->request();
        $this->approveViaLink($id);
        $password = $this->emailedPassword('cashier1@example.test');
        $this->db->table('users')->where('user_id', $this->staffId)->update([
            'temp_password_kind' => $kind, 'temp_password_expires_at' => date('Y-m-d H:i:s', time() + $ttl),
        ]);

        return $password;
    }

    public function testTemporaryPasswordSignsInButOnlyToTheSetPasswordPage(): void
    {
        $temp = $this->issueTemp();

        $this->post('login/staff', ['password' => $temp])->assertRedirectTo(site_url('/staff/set-password'));

        $this->asStaff()->get('staff/dashboard')->assertRedirectTo(site_url('/staff/set-password'));
        $this->asStaff()->get('staff/account')->assertRedirectTo(site_url('/staff/set-password'));
        $this->asStaff()->get('staff/set-password')->assertOK();
    }

    public function testExpiredTemporaryPasswordIsRefused(): void
    {
        $temp = $this->issueTemp('reset', -60);

        $result = $this->post('login/staff', ['password' => $temp]);

        $result->assertSessionHas('error', 'This temporary password has expired. Ask the owner for a new one.');
        $result->assertSessionMissing('isLoggedIn');
    }

    public function testNewPasswordRules(): void
    {
        $temp = $this->issueTemp();

        $this->asStaff()->post('staff/set-password', ['new_password' => 'short', 'confirm_password' => 'short'])->assertSessionHas('error');
        $this->asStaff()->post('staff/set-password', ['new_password' => 'BrandNewPass9', 'confirm_password' => 'Different9Pass'])->assertSessionHas('error', 'Passwords do not match.');
        $this->asStaff()->post('staff/set-password', ['new_password' => $temp, 'confirm_password' => $temp])->assertSessionHas('error', 'Choose a different password from the temporary one.');
        $this->asStaff()->post('staff/set-password', ['new_password' => 'Cashier2Pass2', 'confirm_password' => 'Cashier2Pass2'])
            ->assertSessionHas('error', "This password can't be used. Please choose a different one.");

        $this->assertSame('1', (string) $this->user($this->staffId)->must_change_password);
    }

    public function testSettingOwnPasswordFinishesTheFlowAndTellsTheOwner(): void
    {
        $temp = $this->issueTemp();
        $this->mailer->sent = [];

        $this->asStaff()->post('staff/set-password', ['new_password' => 'BrandNewPass9', 'confirm_password' => 'BrandNewPass9'])
            ->assertRedirectTo(site_url('/staff/dashboard'));

        $staff = $this->user($this->staffId);
        $this->assertSame('0', (string) $staff->must_change_password);
        $this->assertNull($staff->temp_password_expires_at);
        $this->assertTrue(password_verify('BrandNewPass9', $staff->password_hash));

        // The owner gets the "done" email and a notification; neither has any password in it.
        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('owner@example.test', $this->mailer->sent[0]['setTo']);
        $this->assertStringContainsString('Cashier1 set a new password', $this->mailer->sent[0]['setSubject']);
        $this->assertStringNotContainsString('BrandNewPass9', json_encode($this->mailer->sent));
        $this->assertStringNotContainsString($temp, json_encode($this->mailer->sent));
        $this->assertSame(1, $this->db->table('notifications')->like('title', 'set a new password')->countAllResults());

        // The temporary password no longer works; the new one does, and goes straight to the dashboard.
        $this->post('login/staff', ['password' => $temp])->assertSessionHas('error');
        (new LoginLockout())->reset('staff');
        $this->post('login/staff', ['password' => 'BrandNewPass9'])->assertRedirectTo(site_url('/staff/dashboard'));
    }

    // ------------------------------------------------ 4. owner resets from Manage Accounts

    public function testOwnerResetNeedsTheirPasswordAndTheTick(): void
    {
        $hash = $this->user($this->staffId)->password_hash;
        $url  = 'admin/settings/staff-accounts/password/' . $this->staffId;

        $this->asOwner()->post($url, ['owner_password' => 'wrong', 'confirmed' => '1'])->assertSessionHas('error', 'Your password is incorrect.');
        $this->asOwner()->post($url, ['owner_password' => 'OwnerPass123'])->assertSessionHas('error', 'Tick "I confirmed with them" first.');

        $this->assertSame($hash, $this->user($this->staffId)->password_hash);
        $this->assertSame([], $this->mailer->sent);
    }

    public function testOwnerResetEmailsTheStaffMember(): void
    {
        $result = $this->asOwner()->post('admin/settings/staff-accounts/password/' . $this->staffId, ['owner_password' => 'OwnerPass123', 'confirmed' => '1']);

        $result->assertRedirectTo(site_url('/admin/settings?tab=accounts'));
        $password = $this->emailedPassword('cashier1@example.test');
        $this->assertNotNull($password);
        $this->assertTrue(password_verify($password, $this->user($this->staffId)->password_hash));
        $this->assertSame('1', (string) $this->user($this->staffId)->must_change_password);
        $this->assertStringNotContainsString($password, json_encode($this->db->table('security_log')->get()->getResultArray()));
        $this->assertSame('', (string) session()->getFlashdata('temp_password_once'));
    }

    public function testOwnerResetShowsThePasswordOnceWhenThereIsNoEmail(): void
    {
        $this->db->table('users')->where('user_id', $this->staffId)->update(['email' => null]);

        $result = $this->asOwner()->post('admin/settings/staff-accounts/password/' . $this->staffId, ['owner_password' => 'OwnerPass123', 'confirmed' => '1']);

        $result->assertSessionHas('temp_password_once');
        $shown = $_SESSION['temp_password_once'] ?? null;
        $this->assertSame([], $this->mailer->sent);
        $this->assertSame('1', (string) $this->user($this->staffId)->must_change_password);
    }

    public function testOwnerResetFailsSafelyWhenTheEmailFails(): void
    {
        $hash = $this->user($this->staffId)->password_hash;
        $this->mailer->ok = false;

        $this->asOwner()->post('admin/settings/staff-accounts/password/' . $this->staffId, ['owner_password' => 'OwnerPass123', 'confirmed' => '1'])
            ->assertSessionHas('error');

        $this->assertSame($hash, $this->user($this->staffId)->password_hash);
    }

    // ------------------------------------------------ 5. owner adds a new staff account

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
        $this->assertSame('Account created', $_SESSION['toast']['title'] ?? 'Account created');
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
        $id = (int) $this->db->table('users')->where('username', 'newperson')->get()->getRow()->user_id;
        $old = $this->emailedPassword('juan@gmail.com');

        // The page shows "Invite expired" once the time is up.
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

    public function testStaffCannotUseTheOwnerRoutes(): void
    {
        $this->asStaff()->post('admin/settings/staff-accounts', ['full_name' => 'X', 'username' => 'x', 'contact_email' => 'x@example.test'])->assertRedirectTo(site_url('login/admin'));
        $this->asStaff()->post('admin/settings/staff-accounts/password/' . $this->otherId, [])->assertRedirectTo(site_url('login/admin'));
        $this->asStaff()->post('admin/notifications/approve/1', ['confirmed' => '1'])->assertRedirectTo(site_url('login/admin'));
    }
}
