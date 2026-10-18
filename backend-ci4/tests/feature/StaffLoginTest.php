<?php

use App\Libraries\LoginLockout;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Staff sign in with their own username and password. A removed or
 * deactivated staff member, a wrong role, an unknown username and a wrong
 * password must all fail with the same generic message. Runs against the
 * in-memory 'tests' database group, never the live one.
 *
 * @internal
 */
final class StaffLoginTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    private const PASSWORD = 'Sup3rSecret1';
    private const GENERIC  = 'Incorrect password. Please try again';

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

        $this->makeUser('cashier1', 'Staff', 1, null);
        $this->makeUser('cashier2', 'Staff', 1, null);
        $this->makeUser('inactive1', 'Staff', 0, null);
        $this->makeUser('removed1', 'Staff', 0, '2026-10-01 08:00:00');
        // A removed account whose is_active flag was somehow left on must still be refused.
        $this->makeUser('removed2', 'Staff', 1, '2026-10-01 08:00:00');
        $this->makeUser('owner1', 'Admin', 1, null);

        (new LoginLockout())->reset('staff');
    }

    protected function tearDown(): void
    {
        (new LoginLockout())->reset('staff');
        \Config\Database::forge()->dropTable('users', true);

        parent::tearDown();
    }

    private function makeUser(string $username, string $role, int $active, ?string $deletedAt): void
    {
        $this->db->table('users')->insert([
            'username'      => $username,
            'full_name'     => ucfirst($username),
            'role'          => $role,
            'is_active'     => $active,
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'deleted_at'    => $deletedAt,
        ]);
    }

    public function testActiveStaffSignsInWithOwnUsername(): void
    {
        $result = $this->post('login/staff', ['username' => 'cashier2', 'password' => self::PASSWORD]);

        $result->assertRedirectTo(site_url('/staff/dashboard'));
        $result->assertSessionHas('username', 'cashier2');
    }

    public function testWrongPasswordIsRefused(): void
    {
        $result = $this->post('login/staff', ['username' => 'cashier1', 'password' => 'not-the-password']);

        $result->assertSessionHas('error', self::GENERIC);
        $result->assertSessionMissing('isLoggedIn');
    }

    public static function refusedAccounts(): array
    {
        return [
            'unknown username'   => ['nobody'],
            'inactive staff'     => ['inactive1'],
            'removed staff'      => ['removed1'],
            'removed but active' => ['removed2'],
            'admin on staff form' => ['owner1'],
        ];
    }

    /**
     * @dataProvider refusedAccounts
     */
    public function testRefusedAccountsGetTheSameGenericError(string $username): void
    {
        $result = $this->post('login/staff', ['username' => $username, 'password' => self::PASSWORD]);

        $result->assertSessionHas('error', self::GENERIC);
        $result->assertSessionMissing('isLoggedIn');
    }
}
