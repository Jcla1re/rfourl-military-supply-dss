<?php

use App\Libraries\AttemptStore;
use App\Libraries\StaffPasswordAlert;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * The always-on owner alerts about a staff member's account: a password changed in My Account is an
 * in-app alert only (with a Lock button, no email), and the alerts that do carry an email (a changed
 * login email here; Forgot password in StaffPasswordFlowTest) have a single-use "Lock account" link.
 * Runs against the in-memory 'tests' database group; outgoing email is captured by a fake, never sent.
 *
 * @internal
 */
final class StaffPasswordAlertTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    private const OLD_PASSWORD = 'Cashier1Pass1';
    private const NEW_PASSWORD = 'BrandNewPass9';
    private const INVALID      = 'This link is no longer valid.';

    private int $staffId;
    private int $adminId;
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
            'user_id'       => ['type' => 'INTEGER', 'auto_increment' => true],
            'username'      => ['type' => 'VARCHAR', 'constraint' => 50],
            'full_name'     => ['type' => 'VARCHAR', 'constraint' => 100],
            'email'         => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'role'          => ['type' => 'VARCHAR', 'constraint' => 20],
            'is_active'     => ['type' => 'INTEGER', 'default' => 1],
            'password_hash' => ['type' => 'VARCHAR', 'constraint' => 255],
            'last_login'    => ['type' => 'DATETIME', 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'    => ['type' => 'DATETIME', 'null' => true],
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

        $this->staffId = $this->makeUser('cashier1', 'Staff', self::OLD_PASSWORD, null);
        $this->adminId = $this->makeUser('owner1', 'Admin', 'OwnerPass123', 'owner@example.test');

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

        AttemptStore::forget('staff_pw_change_' . $this->staffId);
    }

    protected function tearDown(): void
    {
        AttemptStore::forget('staff_pw_change_' . $this->staffId);
        Services::resetSingle('email');

        foreach (['users', 'notifications', 'security_log'] as $table) {
            \Config\Database::forge()->dropTable($table, true);
        }

        parent::tearDown();
    }

    private function makeUser(string $username, string $role, string $password, ?string $email): int
    {
        $this->db->table('users')->insert([
            'username'      => $username,
            'full_name'     => ucfirst($username),
            'email'         => $email,
            'role'          => $role,
            'is_active'     => 1,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        return (int) $this->db->insertID();
    }

    private function asStaff(): self
    {
        return $this->withSession(['isLoggedIn' => true, 'role' => 'Staff', 'user_id' => $this->staffId, 'username' => 'cashier1', 'last_activity' => time()]);
    }

    private function asOwner(): self
    {
        return $this->withSession(['isLoggedIn' => true, 'role' => 'Admin', 'user_id' => $this->adminId, 'username' => 'owner1', 'last_activity' => time()]);
    }

    private function change(string $current, string $new)
    {
        return $this->asStaff()->post('staff/account/password', [
            'current_password' => $current,
            'new_password'     => $new,
            'confirm_password' => $new,
        ]);
    }

    private function notification(): ?object
    {
        return $this->db->table('notifications')->get()->getRow();
    }

    private function staffActive(): bool
    {
        return (bool) $this->db->table('users')->where('user_id', $this->staffId)->get()->getRow()->is_active;
    }

    /** Raises the alert that carries an emailed Lock link (a changed login email); returns [notification id, raw token]. */
    private function alertLink(): array
    {
        $staff = (array) $this->db->table('users')->where('user_id', $this->staffId)->get()->getRow();
        StaffPasswordAlert::emailChanged($staff, 'old@example.test', 'cashier1@example.test');

        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame(1, preg_match('#notification-action/(\d+)/([0-9a-f]{64})/lock#', $this->mailer->sent[0]['setMessage'], $m));

        return [(int) $m[1], $m[2]];
    }

    public function testAPasswordChangeInMyAccountIsAnInAppAlertOnlyWithALockButton(): void
    {
        $this->change(self::OLD_PASSWORD, self::NEW_PASSWORD);

        $n = $this->notification();
        $this->assertSame('Admin', $n->recipient_role);
        $this->assertSame('Security', $n->type);
        $this->assertSame('security', $n->category);
        $this->assertSame(0, (int) $n->is_read);
        $this->assertSame('Cashier1 set a new password on ' . date('M j, Y, g:i A'), $n->title);
        $this->assertStringContainsString('Changed from ', $n->message);
        $this->assertStringContainsString("If this wasn't expected, lock the account.", $n->message);
        $this->assertSame('/admin/settings?tab=accounts', $n->link_url);
        $this->assertSame($this->staffId, (int) $n->action_user_id);
        $this->assertNull($n->action_token, 'no email link, so no token');
        $this->assertSame(1, $this->db->table('notifications')->countAllResults());

        // No email to the owner (or anyone) for this case.
        $this->assertSame([], $this->mailer->sent);

        // The owner can lock from the app, with the Lock button and nothing else to choose.
        $page = $this->asOwner()->get('admin/notifications')->getBody();
        $this->assertStringContainsString("admin/notifications/lock/{$n->notification_id}", $page);
        $this->assertStringNotContainsString('notifications/accept', $page);
    }

    public function testAnAlertWithAnEmailHasAHashedSingleUseLockLink(): void
    {
        [$id, $token] = $this->alertLink();

        $n = $this->notification();
        $this->assertSame('Admin', $n->recipient_role);
        $this->assertSame('Security', $n->type);
        $this->assertSame('security', $n->category);
        $this->assertSame('Staff email changed', $n->title);
        $this->assertSame($this->staffId, (int) $n->action_user_id);
        $this->assertSame($id, (int) $n->notification_id);

        $mail = $this->mailer->sent[0];
        $this->assertSame('owner@example.test', $mail['setTo']);
        $this->assertSame('Staff email changed', $mail['setSubject']);
        $this->assertStringContainsString('Lock this account', $mail['setMessage']);
        $this->assertStringContainsString('/lock', $mail['setAltMessage']);

        // Only a hash is stored, never the token in the link.
        $this->assertSame(hash('sha256', $token), $n->action_token);
        $this->assertNotSame($token, $n->action_token);
    }

    public function testPasswordNeverAppearsInAlertsOrLogs(): void
    {
        $this->change(self::OLD_PASSWORD, self::NEW_PASSWORD);
        $this->alertLink();

        $everything = json_encode([
            $this->db->table('notifications')->get()->getResultArray(),
            $this->db->table('security_log')->get()->getResultArray(),
            $this->mailer->sent,
        ]);

        $this->assertStringNotContainsString(self::NEW_PASSWORD, $everything);
        $this->assertStringNotContainsString(self::OLD_PASSWORD, $everything);
    }

    public function testFailedChangeCreatesNoNotificationOrEmail(): void
    {
        $this->change('WrongCurrent1', self::NEW_PASSWORD);
        $this->change(self::OLD_PASSWORD, 'short');
        $this->change(self::OLD_PASSWORD, self::OLD_PASSWORD);

        $this->assertNull($this->notification());
        $this->assertSame([], $this->mailer->sent);
    }

    public function testGetOnlyShowsTheConfirmPageAndChangesNothing(): void
    {
        [$id, $token] = $this->alertLink();

        $result = $this->get("notification-action/{$id}/{$token}/lock");

        $result->assertOK();
        $result->assertSee('Staff email changed');
        $result->assertSee('Lock account');
        $this->assertTrue($this->staffActive());
        $this->assertNull($this->notification()->status);
    }

    public function testPostLocksTheAccountEndsItsSessionAndIsSingleUse(): void
    {
        [$id, $token] = $this->alertLink();

        $result = $this->post("notification-action/{$id}/{$token}/lock");
        $result->assertSee("Cashier1's account is locked. Reset their password from Manage Accounts to unlock it.");

        $this->assertFalse($this->staffActive());
        $n = $this->notification();
        $this->assertSame('locked', $n->status);
        $this->assertNull($n->action_token);
        $this->assertSame(1, $this->db->table('security_log')->where('event', 'account_locked_via_email')->countAllResults());

        // The staff member's open session stops working (RoleAuthFilter re-checks is_active).
        $this->asStaff()->get('staff/account')->assertRedirectTo(site_url('login/staff'));

        // Used once: both the page and a second POST are refused.
        $this->get("notification-action/{$id}/{$token}/lock")->assertSee(self::INVALID);
        $this->post("notification-action/{$id}/{$token}/lock")->assertSee(self::INVALID);
    }

    public function testExpiredLinkIsRefusedAndChangesNothing(): void
    {
        [$id, $token] = $this->alertLink();
        $this->db->table('notifications')->update(['action_expires_at' => date('Y-m-d H:i:s', time() - 60)]);

        $this->get("notification-action/{$id}/{$token}/lock")->assertSee(self::INVALID);
        $this->post("notification-action/{$id}/{$token}/lock")->assertSee(self::INVALID);
        $this->assertTrue($this->staffActive());
    }

    public function testLinkLastsTwentyFourHours(): void
    {
        $this->alertLink();

        $expires = strtotime($this->notification()->action_expires_at);
        $this->assertEqualsWithDelta(time() + 86400, $expires, 10);
    }

    public function testWrongOrStoredHashTokensAreRefused(): void
    {
        [$id, $token] = $this->alertLink();
        $stored = $this->notification()->action_token;

        foreach ([str_repeat('a', 64), $stored] as $bad) {
            $this->get("notification-action/{$id}/{$bad}/lock")->assertSee(self::INVALID);
            $this->post("notification-action/{$id}/{$bad}/lock")->assertSee(self::INVALID);
        }
        $this->assertTrue($this->staffActive());
    }

    public function testSecurityAlertCannotBeUsedAsAnApproveOrDeclineLink(): void
    {
        [$id] = $this->alertLink();
        $stored = $this->notification()->action_token;

        // The old Approve link is retired: it only explains that staff now reset their own password.
        $this->post("notification-action/{$id}/{$stored}/approve")->assertSee('This link is no longer used. Staff now reset their password themselves.');
        $this->assertNull($this->notification()->status);
    }

    public function testLoggedInOwnerCanLockFromTheNotification(): void
    {
        [$id] = $this->alertLink();

        $this->asOwner()->post("admin/notifications/lock/{$id}")->assertRedirectTo(site_url('/admin/notifications'));

        $this->assertFalse($this->staffActive());
        $this->assertSame('locked', $this->notification()->status);
        $this->assertSame(1, $this->db->table('security_log')->where('event', 'account_locked_via_notification')->countAllResults());
    }

    public function testLockingFromTheNotificationTellsTheOwnerWhoWasLockedAndWhen(): void
    {
        [$id] = $this->alertLink();
        $this->db->table('notifications')->where('notification_id', $id)->update(['is_read' => 1]);
        $before = $this->db->table('notifications')->countAllResults();

        $this->asOwner()->post("admin/notifications/lock/{$id}");

        $this->assertSame($before + 1, $this->db->table('notifications')->countAllResults());
        $note = $this->db->table('notifications')->where('title', 'Account locked')->get()->getRow();
        $this->assertNotNull($note);
        $this->assertSame('Admin', $note->recipient_role);
        $this->assertSame(0, (int) $note->is_read);
        $this->assertNull($note->action_user_id);
        $this->assertNull($note->status);
        $this->assertSame('You locked the account of Cashier1 on ' . date('M j, Y, g:i A') . '.', $note->message);
        $this->assertSame('Asia/Manila', config('App')->appTimezone);
        $this->assertSame('Asia/Manila', date_default_timezone_get());
    }

    public function testLockingFromTheEmailLinkTellsTheOwnerToo(): void
    {
        [$id, $token] = $this->alertLink();

        $this->post("notification-action/{$id}/{$token}/lock");

        $note = $this->db->table('notifications')->where('title', 'Account locked')->get()->getRow();
        $this->assertNotNull($note);
        $this->assertStringContainsString('You locked the account of Cashier1 on ', $note->message);
        $this->assertSame(1, $this->db->table('notifications')->where('title', 'Account locked')->countAllResults());
    }

    public function testNoLockNoticeWhenTheLockDidNotHappen(): void
    {
        [$id] = $this->alertLink();

        $this->post("notification-action/{$id}/wrong-token/lock");

        $this->assertSame(0, $this->db->table('notifications')->where('title', 'Account locked')->countAllResults());
        $this->assertTrue($this->staffActive());
    }

    public function testExactlyOneAlertIsVisibleToTheAdminBellAndNotificationsList(): void
    {
        $this->change(self::OLD_PASSWORD, self::NEW_PASSWORD);

        $model = new \App\Models\NotificationModel();

        // The same two queries the Admin bell and the Admin Notifications page run.
        $this->assertSame(1, $model->unreadCount('Admin', $this->adminId));
        $list = $model->forRole('Admin', null, false);
        $this->assertCount(1, $list);
        $this->assertSame('security', $list[0]['category']);
        $this->assertNull($list[0]['recipient_id']);
        $this->assertSame(0, $model->unreadCount('Staff', $this->staffId));
    }

    public function testAlertStillReachesTheOwnerWhenTheLockMigrationIsMissing(): void
    {
        $forge = \Config\Database::forge();
        $forge->dropColumn('notifications', 'action_user_id');
        $forge->dropColumn('notifications', 'action_expires_at');

        $staff = (array) $this->db->table('users')->where('user_id', $this->staffId)->get()->getRow();
        StaffPasswordAlert::emailChanged($staff, 'old@example.test', 'cashier1@example.test');
        $this->change(self::OLD_PASSWORD, self::NEW_PASSWORD);

        // In-app alerts and the email still go out; only the Lock link is missing.
        $this->assertSame(2, $this->db->table('notifications')->where('category', 'security')->countAllResults());
        $this->assertCount(1, $this->mailer->sent);
        $this->assertStringNotContainsString('/lock', $this->mailer->sent[0]['setMessage']);
    }

    public function testStaffCannotUseTheOwnerLockRoute(): void
    {
        [$id] = $this->alertLink();

        $this->asStaff()->post("admin/notifications/lock/{$id}")->assertRedirectTo(site_url('login/admin'));
        $this->assertTrue($this->staffActive());
    }
}
