<?php

use App\Controllers\AuthController;
use App\Libraries\LoginLockout;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Staff sign in on the one sign-in page with their username and password, like everyone else.
 * Two staff members may now have the same password: the username tells them apart. (Temporary
 * passwords for new and reset accounts are covered in StaffPasswordFlowTest.)
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

    private const GENERIC = AuthController::WRONG;
    private const LOCKED  = 'Sorry, this account was locked by the Owner. Please contact the Owner.';

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
        $this->makeUser('owner1', 'Admin', 1, null, 'OwnerPass123');

        $this->resetLockout();
    }

    protected function tearDown(): void
    {
        $this->resetLockout();
        \Config\Database::forge()->dropTable('users', true);

        parent::tearDown();
    }

    private function resetLockout(): void
    {
        $lockout = new LoginLockout();
        foreach (['cashier1', 'cashier2', 'inactive1', 'removed1', 'removed2', 'owner1', 'dup1', 'dup2', 'nobody'] as $name) {
            $lockout->reset($name);
        }
        $lockout->resetIp();
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

    private function signIn(string $username, string $password)
    {
        return $this->post('login', ['username' => $username, 'password' => $password]);
    }

    // --- signing in ---

    public function testUsernameAndPasswordSignTheStaffMemberIn(): void
    {
        $result = $this->signIn('cashier2', 'Cashier2Pass2');

        $result->assertRedirectTo(site_url('/staff/dashboard'));
        $result->assertSessionHas('username', 'cashier2');
        $result->assertSessionHas('role', 'Staff');
    }

    public function testTheUsernameIgnoresLetterCaseAndSpacesAtTheEnds(): void
    {
        $this->signIn('  CaShIeR1 ', 'Cashier1Pass1')->assertSessionHas('username', 'cashier1');
    }

    public function testThePasswordAloneNoLongerSignsAnyoneIn(): void
    {
        $result = $this->post('login', ['password' => 'Cashier1Pass1']);

        $result->assertSessionHas('error', 'Please fill in all fields');
        $result->assertSessionMissing('isLoggedIn');
    }

    public function testWrongPasswordIsRefused(): void
    {
        $result = $this->signIn('cashier1', 'not-a-password');

        $result->assertSessionHas('error', self::GENERIC);
        $result->assertSessionMissing('isLoggedIn');
    }

    public static function refused(): array
    {
        return [
            'removed staff'                    => ['removed1', 'Removed1Pass4'],
            'removed but flagged active'       => ['removed2', 'Removed2Pass5'],
            "another staff member's password"  => ['cashier1', 'Cashier2Pass2'],
            'no such username'                 => ['nobody', 'Cashier1Pass1'],
        ];
    }

    /**
     * @dataProvider refused
     */
    public function testRemovedUnknownAndWrongAccountsGetTheGenericError(string $username, string $password): void
    {
        $result = $this->signIn($username, $password);

        $result->assertSessionHas('error', self::GENERIC);
        $result->assertSessionMissing('isLoggedIn');
    }

    public function testLockedStaffWithTheirCorrectPasswordSeeTheLockedMessage(): void
    {
        $result = $this->signIn('inactive1', 'Inactive1Pass3');

        $result->assertSessionHas('error', self::LOCKED);
        $result->assertSessionMissing('isLoggedIn');
    }

    public function testALockedStaffMemberWithAWrongPasswordGetsOnlyTheGenericMessage(): void
    {
        // Nothing about the account is shown before the password is right.
        $this->signIn('inactive1', 'wrong-password-1')->assertSessionHas('error', self::GENERIC);
    }

    public function testTheLockedMessageIsNotACountedFailedAttempt(): void
    {
        $lockout = new LoginLockout();

        // More tries than the 3-try limit: none of them counts, so the form is never locked out.
        for ($i = 0; $i < LoginLockout::MAX_ATTEMPTS + 2; $i++) {
            $this->signIn('inactive1', 'Inactive1Pass3')->assertSessionHas('error', self::LOCKED);
            $this->assertSame(0, $lockout->secondsRemaining('inactive1'));
        }

        // Nor did they use up the tries of a real mistake: two wrong passwords still do not lock it.
        $this->signIn('cashier1', 'wrong-1')->assertSessionHas('error', self::GENERIC);
        $this->signIn('cashier1', 'wrong-2')->assertSessionHas('error', self::GENERIC);
        $this->assertSame(0, $lockout->secondsRemaining('cashier1'));
        $this->signIn('cashier1', 'Cashier1Pass1')->assertSessionHas('username', 'cashier1');
    }

    public function testTwoStaffOnTheSamePasswordEachSignInWithTheirOwnUsername(): void
    {
        $this->makeUser('dup1', 'Staff', 1, null, 'SharedPass12');
        $this->makeUser('dup2', 'Staff', 1, null, 'SharedPass12');

        $this->signIn('dup1', 'SharedPass12')->assertSessionHas('username', 'dup1');
        $this->signIn('dup2', 'SharedPass12')->assertSessionHas('username', 'dup2');
    }
}
