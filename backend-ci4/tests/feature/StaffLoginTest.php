<?php

use App\Libraries\LoginLockout;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Staff sign in with their password alone; each staff account has its own
 * unique password, which is what identifies them. (Temporary passwords for new
 * and reset accounts are covered in StaffPasswordFlowTest.)
 * Runs against the in-memory 'tests' database group, never the live one.
 *
 * @internal
 */
final class StaffLoginTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    private const GENERIC = 'Incorrect password. Please try again';
    private const SHARED  = "This password can't be used. Ask the owner to reset it.";
    private const LOCKED  = 'Sorry, this account was locked by the Owner. Please contact the Owner.';

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

        $this->makeUser('cashier1', 'Staff', 1, null, 'Cashier1Pass1');
        $this->makeUser('cashier2', 'Staff', 1, null, 'Cashier2Pass2');
        $this->makeUser('inactive1', 'Staff', 0, null, 'Inactive1Pass3');
        $this->makeUser('removed1', 'Staff', 0, '2026-10-01 08:00:00', 'Removed1Pass4');
        // A removed account whose is_active flag was somehow left on must still be refused.
        $this->makeUser('removed2', 'Staff', 1, '2026-10-01 08:00:00', 'Removed2Pass5');
        $this->adminId = $this->makeUser('owner1', 'Admin', 1, null, 'OwnerPass123');

        (new LoginLockout())->reset('staff');
    }

    protected function tearDown(): void
    {
        (new LoginLockout())->reset('staff');
        \Config\Database::forge()->dropTable('users', true);

        parent::tearDown();
    }

    private function makeUser(string $username, string $role, int $active, ?string $deletedAt, string $password): int
    {
        $this->db->table('users')->insert([
            'username'      => $username,
            'full_name'     => ucfirst($username),
            'role'          => $role,
            'is_active'     => $active,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'deleted_at'    => $deletedAt,
        ]);

        return (int) $this->db->insertID();
    }

    private function userId(string $username): int
    {
        return (int) $this->db->table('users')->where('username', $username)->get()->getRow()->user_id;
    }

    private function asOwner(): self
    {
        return $this->withSession(['isLoggedIn' => true, 'role' => 'Admin', 'user_id' => $this->adminId, 'last_activity' => time()]);
    }

    // --- login ---

    public function testPasswordAloneSignsInTheMatchingAccount(): void
    {
        $result = $this->post('login/staff', ['password' => 'Cashier2Pass2']);

        $result->assertRedirectTo(site_url('/staff/dashboard'));
        $result->assertSessionHas('username', 'cashier2');
    }

    public function testEachStaffMemberIsIdentifiedByTheirOwnPassword(): void
    {
        $this->post('login/staff', ['password' => 'Cashier1Pass1'])->assertSessionHas('username', 'cashier1');
    }

    public function testWrongPasswordIsRefused(): void
    {
        $result = $this->post('login/staff', ['password' => 'not-a-password']);

        $result->assertSessionHas('error', self::GENERIC);
        $result->assertSessionMissing('isLoggedIn');
    }

    public static function refusedPasswords(): array
    {
        return [
            'removed staff'                => ['Removed1Pass4'],
            'removed but flagged active'   => ['Removed2Pass5'],
            'admin password on staff form' => ['OwnerPass123'],
        ];
    }

    /**
     * @dataProvider refusedPasswords
     */
    public function testRemovedAndNonStaffPasswordsGetTheGenericError(string $password): void
    {
        $result = $this->post('login/staff', ['password' => $password]);

        $result->assertSessionHas('error', self::GENERIC);
        $result->assertSessionMissing('isLoggedIn');
    }

    public function testLockedStaffWithTheirCorrectPasswordSeeTheLockedMessage(): void
    {
        $result = $this->post('login/staff', ['password' => 'Inactive1Pass3']);

        $result->assertSessionHas('error', self::LOCKED);
        $result->assertSessionHas('attempted_role', 'staff');
        $result->assertSessionMissing('isLoggedIn');
    }

    public function testTheLockedMessageIsNotACountedFailedAttempt(): void
    {
        $lockout = new LoginLockout();

        // More tries than the 3-try limit: none of them counts, so the form is never locked out.
        for ($i = 0; $i < LoginLockout::MAX_ATTEMPTS + 2; $i++) {
            $this->post('login/staff', ['password' => 'Inactive1Pass3'])->assertSessionHas('error', self::LOCKED);
            $this->assertSame(0, $lockout->secondsRemaining('staff'));
        }

        // Nor did they use up the tries of a real mistake: two wrong passwords still do not lock the form.
        $this->post('login/staff', ['password' => 'wrong-1'])->assertSessionHas('error', self::GENERIC);
        $this->post('login/staff', ['password' => 'wrong-2'])->assertSessionHas('error', self::GENERIC);
        $this->assertSame(0, $lockout->secondsRemaining('staff'));
        $this->post('login/staff', ['password' => 'Cashier1Pass1'])->assertSessionHas('username', 'cashier1');
    }

    public function testWrongAndRemovedPasswordsStillGetTheGenericMessage(): void
    {
        $this->post('login/staff', ['password' => 'definitely-wrong'])->assertSessionHas('error', self::GENERIC);
        $this->post('login/staff', ['password' => 'Removed1Pass4'])->assertSessionHas('error', self::GENERIC);
    }

    public function testTwoActiveStaffOnTheSamePasswordBothFail(): void
    {
        $this->makeUser('dup1', 'Staff', 1, null, 'SharedPass12');
        $this->makeUser('dup2', 'Staff', 1, null, 'SharedPass12');

        $result = $this->post('login/staff', ['password' => 'SharedPass12']);

        $result->assertSessionHas('error', self::SHARED);
        $result->assertSessionMissing('isLoggedIn');
    }

    public function testRemovedOrInactiveAccountSharingAPasswordDoesNotBlockTheActiveOne(): void
    {
        $this->makeUser('dup1', 'Staff', 1, null, 'SharedPass12');
        $this->makeUser('dup2', 'Staff', 0, null, 'SharedPass12');
        $this->makeUser('dup3', 'Staff', 1, '2026-10-01 08:00:00', 'SharedPass12');

        $this->post('login/staff', ['password' => 'SharedPass12'])->assertSessionHas('username', 'dup1');
    }

    // --- unique-password rule ---

    public function testStaffPasswordInUseCoversActiveAndInactiveButNotRemoved(): void
    {
        $users = new UserModel();

        $this->assertTrue($users->staffPasswordInUse('Cashier1Pass1'));
        $this->assertTrue($users->staffPasswordInUse('Inactive1Pass3'), 'an inactive account could be reactivated');
        $this->assertFalse($users->staffPasswordInUse('Removed1Pass4'), 'removed accounts never sign in again');
        $this->assertFalse($users->staffPasswordInUse('OwnerPass123'), 'only Staff accounts count');
        $this->assertFalse($users->staffPasswordInUse('BrandNewPass9'));
        $this->assertFalse($users->staffPasswordInUse('Cashier1Pass1', $this->userId('cashier1')), 'an account may keep its own password');
    }
}
