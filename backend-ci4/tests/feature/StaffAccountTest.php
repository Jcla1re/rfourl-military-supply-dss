<?php

use App\Libraries\AttemptStore;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Staff "My Account" password change, the role walls around the profile
 * pages, and the owner password change landing back on Settings > My Profile.
 * Runs against the in-memory 'tests' database group, never the live one.
 *
 * @internal
 */
final class StaffAccountTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    private const IN_USE = "This password can't be used. Please choose a different one.";

    private int $staffId;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        $filters = config('Filters');
        $filters->globals['before'] = array_values(array_diff($filters->globals['before'], ['csrf']));

        $forge = \Config\Database::forge();
        $forge->dropTable('users', true);
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

        // The staff layout counts unread notifications when it renders a page.
        $forge->dropTable('notifications', true);
        $forge->addField([
            'notification_id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'recipient_role'  => ['type' => 'VARCHAR', 'constraint' => 20],
            'recipient_id'    => ['type' => 'INTEGER', 'null' => true],
            'is_read'         => ['type' => 'INTEGER', 'default' => 0],
        ]);
        $forge->addKey('notification_id', true);
        $forge->createTable('notifications');

        $this->staffId = $this->makeUser('cashier1', 'Staff', 'Cashier1Pass1');
        $this->makeUser('cashier2', 'Staff', 'Cashier2Pass2');
        $this->adminId = $this->makeUser('owner1', 'Admin', 'OwnerPass123');

        AttemptStore::forget('staff_pw_change_' . $this->staffId);
    }

    protected function tearDown(): void
    {
        AttemptStore::forget('staff_pw_change_' . $this->staffId);
        \Config\Database::forge()->dropTable('users', true);
        \Config\Database::forge()->dropTable('notifications', true);

        parent::tearDown();
    }

    private function makeUser(string $username, string $role, string $password): int
    {
        $this->db->table('users')->insert([
            'username'      => $username,
            'full_name'     => ucfirst($username),
            'role'          => $role,
            'is_active'     => 1,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        return (int) $this->db->insertID();
    }

    private function hashOf(int $userId): string
    {
        return $this->db->table('users')->where('user_id', $userId)->get()->getRow()->password_hash;
    }

    private function asStaff(): self
    {
        return $this->withSession(['isLoggedIn' => true, 'role' => 'Staff', 'user_id' => $this->staffId, 'username' => 'cashier1', 'last_activity' => time()]);
    }

    private function asOwner(): self
    {
        return $this->withSession(['isLoggedIn' => true, 'role' => 'Admin', 'user_id' => $this->adminId, 'username' => 'owner1', 'last_activity' => time()]);
    }

    private function change(string $current, string $new, ?string $confirm = null)
    {
        return $this->asStaff()->post('staff/account/password', [
            'current_password' => $current,
            'new_password'     => $new,
            'confirm_password' => $confirm ?? $new,
        ]);
    }

    public function testStaffCanChangeTheirOwnPassword(): void
    {
        $result = $this->change('Cashier1Pass1', 'BrandNewPass9');

        $result->assertRedirectTo(site_url('/staff/account'));
        $result->assertSessionHas('toast');
        $this->assertTrue(password_verify('BrandNewPass9', $this->hashOf($this->staffId)));
    }

    public function testWrongCurrentPasswordFails(): void
    {
        $result = $this->change('NotMyPassword1', 'BrandNewPass9');

        $result->assertSessionHas('error', 'Current password is incorrect.');
        $this->assertTrue(password_verify('Cashier1Pass1', $this->hashOf($this->staffId)));
    }

    public function testPasswordUsedByAnotherStaffAccountIsRefused(): void
    {
        $result = $this->change('Cashier1Pass1', 'Cashier2Pass2');

        $result->assertSessionHas('error', self::IN_USE);
        $this->assertTrue(password_verify('Cashier1Pass1', $this->hashOf($this->staffId)));
    }

    public function testNewPasswordMustDifferFromCurrent(): void
    {
        $this->change('Cashier1Pass1', 'Cashier1Pass1')
            ->assertSessionHas('error', 'New password must be different from your current password.');
    }

    public function testWeakOrMismatchedPasswordsAreRefused(): void
    {
        $this->change('Cashier1Pass1', 'short')->assertSessionHas('error');
        $this->change('Cashier1Pass1', 'BrandNewPass9', 'Different9Pass')->assertSessionHas('error', 'Passwords do not match.');
        $this->assertTrue(password_verify('Cashier1Pass1', $this->hashOf($this->staffId)));
    }

    public function testCurrentPasswordCannotBeGuessedWithoutLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->change('wrong-guess-' . $i, 'BrandNewPass9')->assertSessionHas('error', 'Current password is incorrect.');
        }

        // Even the right password is refused once the allowance is used up.
        $this->change('Cashier1Pass1', 'BrandNewPass9')
            ->assertSessionHas('error', 'Too many attempts. Please wait a few minutes and try again.');
        $this->assertTrue(password_verify('Cashier1Pass1', $this->hashOf($this->staffId)));
    }

    public function testStaffSeesTheirAccountPage(): void
    {
        $result = $this->asStaff()->get('staff/account');

        $result->assertOK();
        $result->assertSee('To change your name or email, ask the owner.');
        $result->assertSee('Cashier1');
    }

    public function testStaffCannotReachAdminOrSupplierProfileRoutes(): void
    {
        $this->asStaff()->get('admin/settings')->assertRedirectTo(site_url('login/admin'));
        $this->asStaff()->post('admin/settings/owner-password', [])->assertRedirectTo(site_url('login/admin'));
        $this->asStaff()->get('supplier/profile')->assertRedirectTo(site_url('login/supplier'));
        $this->asStaff()->post('supplier/profile/password', [])->assertRedirectTo(site_url('login/supplier'));
    }

    public function testOwnerCannotReachStaffAccountPage(): void
    {
        $this->asOwner()->get('staff/account')->assertRedirectTo(site_url('login/staff'));
    }

    public function testOwnerPasswordChangeReturnsToMyProfileTab(): void
    {
        $ok = $this->asOwner()->post('admin/settings/owner-password', [
            'current_password' => 'OwnerPass123',
            'new_password'     => 'OwnerNewPass45',
            'confirm_password' => 'OwnerNewPass45',
        ]);
        $ok->assertRedirectTo(site_url('/admin/settings?tab=profile'));
        $ok->assertSessionHas('success');

        $bad = $this->asOwner()->post('admin/settings/owner-password', [
            'current_password' => 'wrong',
            'new_password'     => 'OwnerNewPass46',
            'confirm_password' => 'OwnerNewPass46',
        ]);
        $bad->assertRedirectTo(site_url('/admin/settings?tab=profile'));
        $bad->assertSessionHas('error');
    }
}
