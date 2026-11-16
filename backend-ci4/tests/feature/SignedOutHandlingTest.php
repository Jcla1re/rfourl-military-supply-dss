<?php

use App\Libraries\ReturnTo;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Auth;
use Config\Services;
use Tests\Support\ReorderFixture;
use Tests\Support\VoidSalesFixture;

/**
 * Signed out while a page was open (idle 30 minutes, deactivated, locked, wrong role):
 * a background request (fetch / AJAX) gets 401 JSON, never the login page HTML; a normal page is
 * sent to the login page and remembers the page to return to; the idle notice; return-to-page after
 * signing in (ReturnTo's rules); the session status / CSRF endpoints never count as activity; the
 * X-Background-Poll header; Cache-Control: no-store; test mode; the 30-minute password reset.
 * Runs on the in-memory 'tests' database.
 *
 * @internal
 */
final class SignedOutHandlingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ReorderFixture;
    use VoidSalesFixture;

    protected $migrate = false;
    protected $refresh = false;

    private const XHR = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVoidSales();
        Services::resetSingle('renderer');
    }

    protected function tearDown(): void
    {
        $this->tearDownVoidSales();
        parent::tearDown();
    }

    private function signedIn(string $role, int $idleFor = 0, array $extra = []): array
    {
        $id = $role === 'Admin' ? $this->ownerId : $this->staffId;

        return $extra + [
            'isLoggedIn' => true, 'role' => $role, 'user_id' => $id, 'username' => $role === 'Admin' ? 'owner1' : 'staff1',
            'full_name' => 'Someone', 'supplier_id' => null, 'previous_login' => '2026-11-01 10:00:00', 'last_activity' => time() - $idleFor,
        ];
    }

    private function json($result): array
    {
        return json_decode((string) $result->response()->getBody(), true) ?? [];
    }

    // ---------------------------------------------------------------- 401 JSON for background requests

    /** @return array<string, array{0: string}> */
    public static function areas(): array
    {
        return ['owner' => ['admin/inventory'], 'staff' => ['staff/inventory'], 'supplier' => ['supplier/dashboard']];
    }

    /** @dataProvider areas */
    public function testABackgroundRequestWhenNotSignedInGetsJsonNotTheLoginPage(string $url): void
    {
        $result = $this->withHeaders(self::XHR)->get($url);

        $result->assertStatus(401);
        $body = (string) $result->response()->getBody();
        $this->assertStringNotContainsString('<html', strtolower($body), 'Never the login page HTML.');
        $data = $this->json($result);
        $this->assertTrue($data['signedOut']);
        $this->assertSame('signed_out', $data['reason']);
        $this->assertSame(site_url('login'), $data['login']);
        $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
    }

    public function testAcceptJsonAloneAlsoGetsJson(): void
    {
        $result = $this->withHeaders(['Accept' => 'application/json'])->get('staff/sales/check-or?or_number=1');

        $result->assertStatus(401);
        $this->assertTrue($this->json($result)['signedOut']);
    }

    public function testAnIdleBackgroundRequestIsSignedOutWithReasonIdleAndTheSessionCleared(): void
    {
        $result = $this->withSession($this->signedIn('Staff', 1801) + ['pwd_reset_user_id' => 9, 'attempt_login_staff' => ['n' => 1, 'exp' => time() + 60]])
            ->withHeaders(self::XHR)->get('staff/inventory?search=shirt');

        $result->assertStatus(401);
        $this->assertSame('idle', $this->json($result)['reason']);
        $left = array_values(array_diff(array_keys($_SESSION), ['__ci_last_regenerate', '__ci_vars']));
        sort($left);
        $this->assertSame(['attempt_login_staff', 'signed_out_reason'], $left, 'Only the sign-in lockout counter and the reason are left.');
        $this->assertSame('idle', $_SESSION['signed_out_reason']);
    }

    public function testAnIdleFullPageGoesToTheLoginPageAndRemembersThePage(): void
    {
        $result = $this->withSession($this->signedIn('Staff', 1801))->get('staff/log-transaction/return?x=1');

        $this->assertSame(site_url('login'), $result->getRedirectUrl());
        $this->assertSame('/staff/log-transaction/return?x=1', $_SESSION['return_to']);
        $this->assertSame('idle', $_SESSION['signed_out_reason']);
        $this->assertArrayNotHasKey('error', $_SESSION, 'The login page shows its own idle notice, no error.');
        $left = array_values(array_diff(array_keys($_SESSION), ['__ci_last_regenerate', '__ci_vars']));
        sort($left);
        $this->assertSame(['return_to', 'signed_out_reason'], $left, 'Nothing else is left after an idle sign-out.');
    }

    public function testAFormPostedAfterTheIdleLimitReturnsToThePageItCameFromNeverToThePostUrl(): void
    {
        $result = $this->withSession($this->signedIn('Staff', 1801))
            ->withHeaders(['Referer' => site_url('staff/log-transaction/return')])
            ->post('staff/log-transaction/store', ['type' => 'return', 'item_id' => 'P-1', 'qty_returned' => '1']);

        $this->assertSame(site_url('login'), $result->getRedirectUrl());
        $this->assertSame('/staff/log-transaction/return', $_SESSION['return_to']);
        $this->assertSame(0, $this->db->table('inventory_log')->where('log_type', 'RETURN')->countAllResults(), 'Nothing saved.');
    }

    public function testDeactivatedLockedAndWrongRoleGetTheirOwnReason(): void
    {
        $this->db->table('users')->where('user_id', $this->staffId)->update(['is_active' => 0]);
        $locked = $this->withSession($this->signedIn('Staff'))->withHeaders(self::XHR)->get('staff/inventory');
        $locked->assertStatus(401);
        $this->assertSame('locked', $this->json($locked)['reason']);
        $this->assertSame('Your account was locked by the Owner. Please contact the Owner.', $this->json($locked)['message']);
        $this->assertArrayNotHasKey('user_id', $_SESSION);

        $this->db->table('users')->where('user_id', $this->staffId)->update(['is_active' => 1, 'deleted_at' => '2026-11-01 10:00:00']);
        $gone = $this->withSession($this->signedIn('Staff'))->withHeaders(self::XHR)->get('staff/inventory');
        $this->assertSame('deactivated', $this->json($gone)['reason']);

        $wrong = $this->withSession($this->signedIn('Admin'))->withHeaders(self::XHR)->get('staff/inventory');
        $wrong->assertStatus(403);
        $this->assertSame(['signedOut' => false, 'reason' => 'no_access'], array_intersect_key($this->json($wrong), ['signedOut' => 1, 'reason' => 1]));
        $this->assertSame(site_url('login'), $this->json($wrong)['login']);
    }

    public function testANormalPageStillRedirectsAsBefore(): void
    {
        $this->get('staff/dashboard')->assertRedirectTo(site_url('login'));
        $this->assertSame('Please log in to continue.', $_SESSION['error'] ?? null);
        $this->assertSame('/staff/dashboard', $_SESSION['return_to']);
    }

    // ---------------------------------------------------------------- what counts as activity

    public function testANormalBackgroundRequestCountsAsActivityAndAMarkedPollDoesNot(): void
    {
        // Inventory search (fetch, X-Requested-With): the user typing, so it resets the clock.
        $this->withSession($this->signedIn('Staff', 600))->withHeaders(self::XHR)->get('staff/inventory?search=shirt')->assertStatus(200);
        $this->assertEqualsWithDelta(time(), $_SESSION['last_activity'], 2, 'Search resets the 30 minutes.');

        // The DSS status poll (X-Background-Poll): checked, but the clock does not move.
        $before = time() - 600;
        $this->withSession($this->signedIn('Admin', 600))->withHeaders(self::XHR + [Auth::BACKGROUND_HEADER => '1'])->get('admin/dss/status')->assertStatus(200);
        $this->assertSame($before, $_SESSION['last_activity'], 'The poll did not reset the clock.');
    }

    public function testAMarkedPollIsStillSignedOutWhenIdle(): void
    {
        $result = $this->withSession($this->signedIn('Admin', 1801))->withHeaders(self::XHR + [Auth::BACKGROUND_HEADER => '1'])->get('admin/dss/status');

        $result->assertStatus(401);
        $this->assertSame('idle', $this->json($result)['reason']);
    }

    public function testSignedInPagesAreNeverStoredByTheBrowser(): void
    {
        $result = $this->withSession($this->signedIn('Staff'))->get('staff/log-transaction/return');

        $result->assertStatus(200);
        $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
    }

    // ---------------------------------------------------------------- session/status and session/csrf

    public function testTheStatusCheckReportsTimeLeftAndNeverResetsTheClock(): void
    {
        $last   = time() - 300;
        $result = $this->withSession($this->signedIn('Staff', 300))->withHeaders(self::XHR + [Auth::BACKGROUND_HEADER => '1'])->get('session/status');

        $data = $this->json($result);
        $this->assertTrue($data['signedIn']);
        $this->assertSame('Staff', $data['role']);
        $this->assertEqualsWithDelta(1500, $data['secondsLeft'], 2);
        $this->assertSame($last, $_SESSION['last_activity'], 'Asking does not count as activity.');
    }

    public function testTheStatusCheckSignsOutAnIdleSession(): void
    {
        $data = $this->json($this->withSession($this->signedIn('Staff', 1801))->get('session/status'));

        $this->assertSame(['signedIn' => false, 'reason' => 'idle', 'login' => site_url('login')], $data);
        $this->assertArrayNotHasKey('isLoggedIn', $_SESSION);
        $this->assertSame(['signedIn' => false, 'reason' => 'signed_out', 'login' => null], $this->json($this->withSession([])->get('session/status')));
    }

    public function testTheCsrfEndpointGivesATokenOnlyWhileSignedInAndNeverResetsTheClock(): void
    {
        $last = time() - 300;
        $data = $this->json($this->withSession($this->signedIn('Staff', 300))->withHeaders(self::XHR)->get('session/csrf'));

        $this->assertSame(['name' => csrf_token(), 'header' => csrf_header()], array_intersect_key($data, ['name' => 1, 'header' => 1]));
        $this->assertNotEmpty($data['token']);
        $this->assertSame($last, $_SESSION['last_activity']);

        $this->withSession([])->withHeaders(self::XHR)->get('session/csrf')->assertStatus(401);
        $this->withSession($this->signedIn('Staff', 1801))->withHeaders(self::XHR)->get('session/csrf')->assertStatus(401);
    }

    // ---------------------------------------------------------------- the login page

    public function testTheIdleNoticeShowsOnlyAfterAnIdleSignOut(): void
    {
        $notice = 'You were signed out after 30 minutes of inactivity. Sign in to continue where you left off.';

        $this->assertStringContainsString($notice, (string) $this->withSession(['signed_out_reason' => 'idle'])->get('login')->response()->getBody());
        // "/" is the same page.
        $this->assertStringContainsString($notice, (string) $this->withSession(['signed_out_reason' => 'idle'])->get('/')->response()->getBody());
        $this->assertStringNotContainsString($notice, (string) $this->withSession(['signed_out_reason' => 'locked'])->get('login')->response()->getBody());
        $this->assertStringNotContainsString($notice, (string) $this->withSession([])->get('login')->response()->getBody(), 'Not for a plain "please sign in".');
    }

    public function testTheLogoutButtonNeverShowsTheIdleNotice(): void
    {
        $this->withSession($this->signedIn('Staff') + ['signed_out_reason' => 'idle'])->post('logout')->assertRedirectTo(site_url('/'));

        $this->assertArrayNotHasKey('signed_out_reason', $_SESSION ?? [], 'Logging out ends the whole session.');
        $this->assertStringNotContainsString('of inactivity', (string) $this->withSession([])->get('login')->response()->getBody());
    }

    private function ownerSignsIn(array $session)
    {
        $this->db->table('users')->where('user_id', $this->ownerId)->update(['password_hash' => password_hash('Owner-Pass-1', PASSWORD_DEFAULT)]);

        return $this->withSession($session)->post('login', ['username' => 'owner1', 'password' => 'Owner-Pass-1']);
    }

    public function testSigningInReturnsToThePageTheUserWasOn(): void
    {
        $result = $this->ownerSignsIn(['return_to' => '/admin/inventory?search=shirt', 'signed_out_reason' => 'idle']);

        $this->assertSame(site_url('admin/inventory?search=shirt'), $result->getRedirectUrl());
        $this->assertArrayNotHasKey('return_to', $_SESSION);
        $this->assertArrayNotHasKey('signed_out_reason', $_SESSION);
    }

    /** @return array<string, array{0: string}> */
    public static function badReturns(): array
    {
        return [
            'another role\'s page' => ['/staff/sales'],
            'an outside link'      => ['https://evil.example/admin/dashboard'],
            'protocol-relative'    => ['//evil.example/admin/dashboard'],
            'a POST-only URL'      => ['/admin/inventory/store'],
            'the logout URL'       => ['/logout'],
        ];
    }

    /** @dataProvider badReturns */
    public function testAPageThatIsNotAllowedGoesToTheDashboard(string $return): void
    {
        $this->assertSame(site_url('admin/dashboard'), $this->ownerSignsIn(['return_to' => $return])->getRedirectUrl());
    }

    public function testTheLoginPageAcceptsTheReturnPageOnlyWhenItIsAllowed(): void
    {
        $this->withSession([])->get('login?return=' . urlencode('/admin/inventory?search=x'));
        $this->assertSame('/admin/inventory?search=x', $_SESSION['return_to']);

        $this->withSession([])->get('login?return=' . urlencode('https://evil.example/x'));
        $this->assertArrayNotHasKey('return_to', $_SESSION);
    }

    public function testReturnToRules(): void
    {
        $this->assertSame('/staff/sales?tab=receipts', ReturnTo::clean('/staff/sales?tab=receipts', 'Staff'));
        $this->assertSame('/staff/sales', ReturnTo::clean(site_url('staff/sales'), 'Staff'), 'A full URL on this app.');
        $this->assertSame('/admin/orders/SO-0001', ReturnTo::clean('/admin/orders/SO-0001#top', 'Admin'), 'A route with a placeholder; no fragment.');
        $this->assertSame('/staff/sales', ReturnTo::clean('/staff/sales'), 'No role: any role area.');

        foreach ([
            null, '', 'staff/sales', '//evil.example/staff/sales', 'https://evil.example/staff/sales', 'http://localhost:9999/staff/sales',
            "/staff/sales\\x", "/staff/sales\n", '/staff/../admin/dashboard', '/login/staff', '/logout', '/session/status', '/',
            '/staff/sales/checkout', '/staff/no-such-page', '/admin/dashboard' . str_repeat('x', 2000), 'javascript:alert(1)',
        ] as $bad) {
            $this->assertNull(ReturnTo::clean($bad, 'Staff'), 'Refused: ' . var_export($bad, true));
        }
        $this->assertNull(ReturnTo::clean('/admin/dashboard', 'Staff'), 'Another role\'s page.');
        $this->assertSame('/supplier/dashboard', ReturnTo::dashboard('Supplier'));
    }

    // ---------------------------------------------------------------- test mode and the session lifetime

    public function testTestModeOnlyWorksOnADevelopmentMachine(): void
    {
        $auth = new Auth();
        $this->assertSame(1800, $auth->idleLimit('development'), 'Off by default.');

        $auth->idleTestSeconds = 60;
        $this->assertSame(60, $auth->idleLimit('development'));
        $this->assertTrue($auth->testModeActive('development'));
        $this->assertSame(1800, $auth->idleLimit('production'), 'Never on the live server.');
        $this->assertFalse($auth->testModeActive('production'));
        $this->assertSame(1800, $auth->idleLimit('testing'));
    }

    public function testTheIdleLimitStaysThirtyMinutesWhileTheSessionLivesEightHours(): void
    {
        $this->assertSame(1800, (new Auth())->idleSeconds);
        $this->assertSame(1800, config(Auth::class)->idleLimit());
        $this->assertSame(28800, config('Session')->expiration);
    }

    public function testAStartedPasswordResetStillEndsAfterThirtyMinutesWithoutAStep(): void
    {
        $pending = fn (int $touched) => ['pwd_reset' => [
            'token' => 'abc', 'kind' => 'code', 'user_id' => $this->ownerId, 'ident' => 'owner1', 'verified' => true,
            'burned' => false, 'sent_at' => time() - 100, 'expires' => time() + 500, 'touched' => $touched,
        ]];

        $this->withSession($pending(time() - 1801))->get('login/forgot/new-password')->assertRedirectTo(site_url('login/forgot'));
        $this->assertArrayNotHasKey('pwd_reset', $_SESSION);

        $this->withSession($pending(time() - 1700))->get('login/forgot/new-password')->assertStatus(200);
        $this->assertEqualsWithDelta(time(), $_SESSION['pwd_reset']['touched'], 2, 'Each step keeps it going, as each request did before.');
    }
}
