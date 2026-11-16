<?php

use App\Controllers\AuthController;
use App\Libraries\AttemptStore;
use App\Libraries\LoginLockout;
use App\Libraries\SavedCart;
use App\Libraries\SignedOut;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthFixture;

/**
 * The one sign-in page: the account decides the portal; a wrong username and a wrong password look
 * the same (message, and the work done); nothing about the role shows before the password is right;
 * the lockout counts per IP + typed username (any case, spaces trimmed) with a 30-per-15-minutes IP
 * backstop; the old per-role URLs 302 to /login keeping ?reason= and ?return=. In-memory database.
 *
 * @internal
 */
final class SignInTest extends CIUnitTestCase
{
    use AuthFixture;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuthFixture();
    }

    protected function tearDown(): void
    {
        $this->tearDownAuthFixture();
        parent::tearDown();
    }

    private function signIn(string $username, string $password, array $session = [])
    {
        return $this->withSession($session)->post('login', ['username' => $username, 'password' => $password]);
    }

    // ---------------------------------------------------------------- the account decides the portal

    public static function portals(): array
    {
        return [
            'owner'    => ['owner1', 'OwnerPass123', 'Admin', '/admin/dashboard'],
            'staff'    => ['cashier1', 'Cashier1Pass1', 'Staff', '/staff/dashboard'],
            'supplier' => ['tgi', 'SupplierPass1', 'Supplier', '/supplier/dashboard'],
        ];
    }

    /** @dataProvider portals */
    public function testEachAccountOpensItsOwnPortal(string $username, string $password, string $role, string $home): void
    {
        $result = $this->signIn($username, $password);

        $result->assertRedirectTo(site_url($home));
        $this->assertSame($role, $_SESSION['role']);
        $this->assertSame($username, $_SESSION['username']);
        $this->assertTrue($_SESSION['isLoggedIn']);
        $this->assertSame(1, $this->db->table('security_log')->where('event', 'login_success')->countAllResults());
    }

    /** @dataProvider portals */
    public function testTheUsernameIgnoresCaseAndSpacesForEveryRole(string $username, string $password, string $role, string $home): void
    {
        $this->signIn('  ' . strtoupper($username) . "\t", $password)->assertRedirectTo(site_url($home));
    }

    // ---------------------------------------------------------------- the same answer for a wrong username or password

    public static function wrongOnes(): array
    {
        return [
            'no such username'          => ['ghost', 'OwnerPass123'],
            'wrong password, owner'     => ['owner1', 'not-it-1'],
            'wrong password, staff'     => ['cashier1', 'not-it-1'],
            'wrong password, supplier'  => ['tgi', 'not-it-1'],
            "another account's password" => ['tgi', 'OwnerPass123'],
        ];
    }

    /** @dataProvider wrongOnes */
    public function testAWrongUsernameAndAWrongPasswordGetTheSameAnswer(string $username, string $password): void
    {
        $result = $this->signIn($username, $password);

        $result->assertRedirectTo(site_url('login'));
        $this->assertSame('Incorrect username or password.', $this->flash('error'));
        $this->assertSame(2, $this->flash('tries_left'));
        $this->assertSame(trim($username), $this->flash('login_username'), 'The username stays in the field.');
        $this->assertArrayNotHasKey('isLoggedIn', $_SESSION);
        $this->assertArrayNotHasKey('role', $_SESSION, 'Nothing about the role.');
        $this->assertArrayNotHasKey('lock_seconds', $_SESSION);
    }

    public function testANonExistentUsernameIsCheckedAgainstADummyHashSoItTakesAsLong(): void
    {
        // The source shows the work done is the same: always two password_verify() calls per try,
        // against the real hashes or the dummy one (a bcrypt hash of the same cost).
        $source = (string) file_get_contents(APPPATH . 'Controllers/AuthController.php');
        $this->assertMatchesRegularExpression('/DUMMY_HASH = \'\$2y\$10\$[.\/A-Za-z0-9]{53}\'/', $source);
        $dummy = (new ReflectionClassConstant(AuthController::class, 'DUMMY_HASH'))->getValue();
        $this->assertSame(password_get_info(password_hash('x', PASSWORD_DEFAULT))['options']['cost'], password_get_info($dummy)['options']['cost'], 'Same cost as real hashes.');

        // And in practice: an unknown username takes about as long as a wrong password (no fast path).
        $time = function (string $username): float {
            $best = INF;
            for ($i = 0; $i < 3; $i++) {
                $this->clearAuthLimits();
                $start = microtime(true);
                $this->signIn($username, 'not-the-password-1');
                $best = min($best, microtime(true) - $start);
            }

            return $best;
        };
        $unknown = $time('ghost');
        $wrong   = $time('owner1');
        $this->assertGreaterThan($wrong * 0.6, $unknown, sprintf('unknown %.3fs vs wrong password %.3fs', $unknown, $wrong));
    }

    public function testRemovedStaffAreTreatedAsNoSuchUsername(): void
    {
        $id = $this->makeAccount('removed1', 'Removed', 'Staff', 'RemovedPass1', null);
        $this->db->table('users')->where('user_id', $id)->update(['deleted_at' => '2026-10-01 08:00:00']);

        $this->signIn('removed1', 'RemovedPass1');
        $this->assertSame(AuthController::WRONG, $this->flash('error'));
    }

    // ---------------------------------------------------------------- only after the right password: the account's own situation

    public function testADeactivatedOwnerOrArchivedSupplierIsToldOnlyWithTheRightPassword(): void
    {
        $this->db->table('users')->where('user_id', $this->ownerId)->update(['is_active' => 0]);
        $this->signIn('owner1', 'wrong-pass-1');
        $this->assertSame(AuthController::WRONG, $this->flash('error'));
        $this->clearAuthLimits();
        $this->signIn('owner1', 'OwnerPass123');
        $this->assertSame('This account has been deactivated', $this->flash('error'));

        $archived = $this->makeAccount('archived1', 'Old Supplier', 'Supplier', 'ArchivedPass1', null, 'SUP-002');
        $this->signIn('archived1', 'wrong-pass-1');
        $this->assertSame(AuthController::WRONG, $this->flash('error'));
        $this->signIn('archived1', 'ArchivedPass1');
        $this->assertSame('This supplier account is archived. Please contact the owner.', $this->flash('error'));
        $this->assertArrayNotHasKey('isLoggedIn', $_SESSION);
        $this->assertNotNull($archived);
    }

    // ---------------------------------------------------------------- lockout

    public function testTheLockoutCannotBeDodgedWithLetterCaseOrSpaces(): void
    {
        foreach (['owner1', 'OWNER1', '  Owner1  '] as $typed) {
            $this->signIn($typed, 'wrong-pass-1');
        }

        $this->assertStringStartsWith('That was 3 wrong tries, so sign-in is locked.', (string) $this->flash('error'));
        $this->assertStringEndsWith('or reset your password with "Forgot password?".', (string) $this->flash('error'));
        $this->assertGreaterThan(0, (new LoginLockout())->secondsRemaining('owner1'));

        // Still locked for every spelling, even with the right password.
        $this->signIn('OWNER1 ', 'OwnerPass123')->assertSessionMissing('isLoggedIn');
        $this->assertGreaterThan(0, $this->flash('lock_seconds'));

        // A different username from the same place is not locked by it.
        $this->signIn('cashier1', 'Cashier1Pass1')->assertRedirectTo(site_url('/staff/dashboard'));
    }

    public function testAnUnknownUsernameLocksExactlyLikeARealOne(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->signIn('ghost', 'wrong-pass-1');
        }

        $this->assertStringStartsWith('That was 3 wrong tries, so sign-in is locked. Wait ', (string) $this->flash('error'));
    }

    public function testThirtyWrongTriesFromOneAddressLockEveryUsernameForAMinute(): void
    {
        $lockout = new LoginLockout();
        for ($i = 1; $i <= LoginLockout::IP_MAX_FAILURES - 1; $i++) {
            $lockout->registerFailure('spray' . $i); // a different username each time: no account lock
        }
        $this->assertSame(0, $lockout->secondsRemaining('cashier1'), '29 is still fine.');

        $this->signIn('spray30', 'wrong-pass-1');
        $this->assertSame(30, LoginLockout::IP_MAX_FAILURES);
        $seconds = $lockout->secondsRemaining('cashier1');
        $this->assertGreaterThan(0, $seconds);
        $this->assertLessThanOrEqual(60, $seconds);
        $this->assertStringStartsWith('Too many wrong tries from this network, so sign-in is locked. Wait ', (string) $this->flash('error'));
        $this->assertStringEndsWith('or reset your password with "Forgot password?".', (string) $this->flash('error'));

        // Even the right password of an account nobody tried waits.
        $this->signIn('cashier1', 'Cashier1Pass1')->assertSessionMissing('isLoggedIn');

        $lockout->resetIp();
        $this->signIn('cashier1', 'Cashier1Pass1')->assertRedirectTo(site_url('/staff/dashboard'));
        for ($i = 1; $i < LoginLockout::IP_MAX_FAILURES; $i++) {
            $lockout->reset('spray' . $i);
        }
        $lockout->reset('spray30');
    }

    public function testTheIpBackstopIsCountedInFifteenMinuteWindows(): void
    {
        $this->assertSame(900, LoginLockout::IP_WINDOW_SECONDS);
        $this->assertSame(60, LoginLockout::LOCKOUT_SECONDS);
    }

    public function testASignInResetsOnlyThatUsernamesCount(): void
    {
        $this->signIn('owner1', 'wrong-pass-1');
        $this->signIn('cashier1', 'wrong-pass-1');
        $this->signIn('owner1', 'OwnerPass123');

        $lockout = new LoginLockout();
        $this->assertSame(3, $lockout->triesLeft('owner1'));
        $this->assertSame(2, $lockout->triesLeft('cashier1'));
    }

    // ---------------------------------------------------------------- Parts 1 and 2 still hold

    public function testAfterAnIdleSignOutEachRoleReturnsToItsPageAndThePosCartMayComeBack(): void
    {
        $this->signIn('cashier1', 'Cashier1Pass1', ['return_to' => '/staff/sales', 'signed_out_reason' => 'idle'])
            ->assertRedirectTo(site_url('staff/sales'));
        $this->assertArrayHasKey(SavedCart::SESSION_KEY, $_SESSION, 'The POS page may bring the saved cart back.');
        $this->assertArrayNotHasKey('signed_out_reason', $_SESSION);

        // Another role's page is never used: the account's own dashboard.
        $this->signIn('tgi', 'SupplierPass1', ['return_to' => '/staff/sales', 'signed_out_reason' => 'idle'])
            ->assertRedirectTo(site_url('supplier/dashboard'));

        // An ordinary sign-in never brings a cart back.
        $this->signIn('cashier1', 'Cashier1Pass1', [SavedCart::SESSION_KEY => time()]);
        $this->assertArrayNotHasKey(SavedCart::SESSION_KEY, $_SESSION);
    }

    public function testEveryLoginUrlIsSlashLogin(): void
    {
        foreach ([null, 'Admin', 'Staff', 'Supplier'] as $role) {
            $this->assertSame('/login', SignedOut::loginPath($role));
        }
        $this->assertSame(site_url('login'), SignedOut::pageConfig('Staff')['loginUrl']);

        $json = json_decode((string) $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->get('staff/inventory')->response()->getBody(), true);
        $this->assertSame(site_url('login'), $json['login']);

        $this->withSession(['isLoggedIn' => true, 'role' => 'Admin', 'user_id' => $this->ownerId])->post('logout')->assertRedirectTo(site_url('/'));
    }

    public function testTheIdleNoticeAndTestModeLineAreOnTheSignInPage(): void
    {
        foreach (['/', 'login'] as $path) {
            $body = (string) $this->withSession(['signed_out_reason' => 'idle'])->get($path)->response()->getBody();
            $this->assertStringContainsString('class="auth-notice" role="status">You were signed out after 30 minutes of inactivity.', $body, $path);
        }

        $auth                  = config(\Config\Auth::class);
        $auth->idleTestSeconds = 60;
        try {
            if ($auth->testModeActive()) {
                $this->assertStringContainsString('Test mode: idle sign-out after 60 s', (string) $this->withSession([])->get('login')->response()->getBody());
            } else {
                $this->assertStringNotContainsString('Test mode:', (string) $this->withSession([])->get('login')->response()->getBody(), 'Development only.');
            }
        } finally {
            $auth->idleTestSeconds = 0;
        }
    }

    // ---------------------------------------------------------------- old URLs

    public static function oldUrls(): array
    {
        return [
            'owner GET'           => ['get', 'login/admin', '/login'],
            'staff GET'           => ['get', 'login/staff', '/login'],
            'supplier GET'        => ['get', 'login/supplier', '/login'],
            'owner POST'          => ['post', 'login/admin', '/login'],
            'staff POST'          => ['post', 'login/staff', '/login'],
            'supplier POST'       => ['post', 'login/supplier', '/login'],
            'owner forgot'        => ['get', 'login/admin/forgot', '/login/forgot'],
            'staff forgot'        => ['get', 'login/staff/forgot', '/login/forgot'],
            'staff forgot POST'   => ['post', 'login/staff/forgot', '/login/forgot'],
            'supplier forgot'     => ['get', 'login/supplier/forgot', '/login/forgot'],
            'otp page'            => ['get', 'login/admin/otp', '/login/forgot'],
            'otp POST'            => ['post', 'login/supplier/otp', '/login/forgot'],
            'otp resend'          => ['post', 'login/admin/otp/resend', '/login/forgot'],
            'reset-password'      => ['get', 'login/supplier/reset-password', '/login/forgot'],
            'reset-password POST' => ['post', 'login/admin/reset-password', '/login/forgot'],
        ];
    }

    /** @dataProvider oldUrls */
    public function testOldUrlsAre302sToTheNewPages(string $method, string $url, string $to): void
    {
        $result = $this->{$method}($url, $method === 'post' ? ['username' => 'owner1', 'password' => 'OwnerPass123'] : []);

        $result->assertStatus(302);
        $this->assertSame(site_url(ltrim($to, '/')), $result->getRedirectUrl());
        $this->assertArrayNotHasKey('isLoggedIn', $_SESSION, 'An old form post signs nobody in.');
    }

    public function testOldUrlsKeepReasonAndReturn(): void
    {
        $result = $this->get('login/staff?reason=idle&return=' . urlencode('/staff/sales?tab=receipts') . '&other=x');

        $result->assertStatus(302);
        $this->assertSame(site_url('login') . '?reason=idle&return=' . urlencode('/staff/sales?tab=receipts'), $result->getRedirectUrl());

        // And the sign-in page then keeps the return page.
        $this->withSession([])->get('login?reason=idle&return=' . urlencode('/staff/sales?tab=receipts'));
        $this->assertSame('/staff/sales?tab=receipts', $_SESSION['return_to']);

        $this->assertSame(site_url('login/forgot') . '?reason=idle', $this->get('login/admin/forgot?reason=idle')->getRedirectUrl());
        $this->assertSame(site_url('login'), $this->get('login/admin')->getRedirectUrl(), 'Nothing added when there was nothing.');
    }

    // ---------------------------------------------------------------- usernames for new accounts

    public function testNewStaffUsernamesAreTrimmedAndFollowTheRenameRules(): void
    {
        $owner = ['isLoggedIn' => true, 'role' => 'Admin', 'user_id' => $this->ownerId, 'username' => 'owner1', 'last_activity' => time()];

        foreach (['ab', 'has space', 'bad@name', str_repeat('a', 51), 'owner1', ' owner1 '] as $bad) {
            $this->withSession($owner)->post('admin/settings/staff-accounts', ['full_name' => 'New Person', 'username' => $bad, 'contact_email' => 'new' . md5($bad) . '@example.test']);
            $this->assertNotEmpty($_SESSION['error'] ?? null, "Refused: {$bad}");
            $this->assertSame(0, $this->db->table('users')->where('full_name', 'New Person')->countAllResults(), "Refused: {$bad}");
        }

        $this->withSession($owner)->post('admin/settings/staff-accounts', ['full_name' => 'New Person', 'username' => '  new_person-2 ', 'contact_email' => 'np@example.test']);
        $this->assertSame('new_person-2', $this->db->table('users')->where('full_name', 'New Person')->get()->getRow()->username);
    }

    public function testNewSupplierPortalUsernamesFollowTheSameRules(): void
    {
        $rules = (string) file_get_contents(APPPATH . 'Controllers/Admin/SupplierController.php');
        $this->assertStringContainsString("'username'    => 'required|min_length[4]|max_length[50]|alpha_dash|is_unique[users.username]'", $rules);
        $this->assertStringContainsString("\$username   = trim((string) \$this->request->getPost('username'));", $rules);
    }

    public function testNormaliseIsTrimAndLowercase(): void
    {
        $this->assertSame('owner1', UserModel::normalise("  OWNER1\n"));
        $this->assertSame('', UserModel::normalise(null));
        $this->assertNull((new UserModel())->findForLogin('   '));
        $this->assertSame($this->ownerId, (int) (new UserModel())->findForLogin(' Owner1 ')['user_id']);
        $this->assertSame(0, AttemptStore::count('nothing'));
    }
}
