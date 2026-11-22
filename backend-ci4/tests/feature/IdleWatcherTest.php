<?php

use App\Libraries\OnceToken;
use App\Libraries\SavedCart;
use App\Libraries\SignedOut;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Auth;
use Config\Services;
use Tests\Support\ReorderFixture;
use Tests\Support\VoidSalesFixture;

/**
 * Part 2, the server side of the idle watcher (public/assets/js/session-guard.js) and the POS cart
 * saved in the browser (public/assets/js/pos.js): what each layout gives the watcher, and when the
 * POS page may bring a saved cart back (only the first POS page after signing back in from an idle
 * sign-out, App\Libraries\SavedCart). The browser behaviour itself (timers, tabs, localStorage) is
 * the manual test list. Runs on the in-memory 'tests' database.
 *
 * @internal
 */
final class IdleWatcherTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ReorderFixture;
    use VoidSalesFixture;

    protected $migrate = false;
    protected $refresh = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVoidSales();
        Services::resetSingle('renderer');
    }

    protected function tearDown(): void
    {
        config(Auth::class)->idleTestSeconds = 0;
        $this->tearDownVoidSales();
        parent::tearDown();
    }

    private function owner(array $extra = []): array
    {
        return $extra + ['isLoggedIn' => true, 'role' => 'Admin', 'user_id' => $this->ownerId, 'username' => 'owner1', 'full_name' => 'Owner', 'supplier_id' => null, 'last_activity' => time()];
    }

    private function page(array $session, string $url): string
    {
        Services::resetSingle('renderer');

        return (string) $this->withSession($session)->get($url)->response()->getBody();
    }

    /** window.APP_SESSION as the page has it. */
    private function appSession(string $html): array
    {
        $this->assertSame(1, preg_match('#<script>window\.APP_SESSION = (\{.*?\});</script>#', $html, $m), 'The layout sets window.APP_SESSION.');

        return json_decode($m[1], true);
    }

    private function savedCartFlag(string $html): string
    {
        $this->assertSame(1, preg_match('/data-restore-saved="([01])"/', $html, $m), 'The POS form says whether the saved cart may come back.');

        return $m[1];
    }

    private function ownerSignsIn(array $session)
    {
        $this->db->table('users')->where('user_id', $this->ownerId)->update(['password_hash' => password_hash('Owner-Pass-1', PASSWORD_DEFAULT)]);

        return $this->withSession($session)->post('login', ['username' => 'owner1', 'password' => 'Owner-Pass-1']);
    }

    // ---------------------------------------------------------------- what the layouts give the watcher

    public function testEveryLayoutGivesTheWatcherTheIdleLimitRoleUserAndUrls(): void
    {
        $supplier = $this->db->table('users')->where('username', 'tgi')->get()->getRow();
        $pages    = [
            'Admin'    => [$this->owner(), 'admin/inventory', 'login', $this->ownerId],
            'Staff'    => [['isLoggedIn' => true, 'role' => 'Staff', 'user_id' => $this->staffId, 'username' => 'staff1', 'full_name' => 'Staff', 'supplier_id' => null, 'last_activity' => time()], 'staff/inventory', 'login', $this->staffId],
            'Supplier' => [['isLoggedIn' => true, 'role' => 'Supplier', 'user_id' => (int) $supplier->user_id, 'username' => 'tgi', 'full_name' => 'Tactical Gear', 'supplier_id' => 'SUP-001', 'last_activity' => time()], 'supplier/profile', 'login', (int) $supplier->user_id],
        ];

        foreach ($pages as $role => [$session, $url, $login, $userId]) {
            $cfg = $this->appSession($this->page($session, $url));

            $this->assertSame([
                'role' => $role, 'userId' => (int) $userId, 'idleSeconds' => 1800, 'testMode' => false,
                'loginUrl' => site_url($login), 'statusUrl' => site_url('session/status'), 'csrfUrl' => site_url('session/csrf'),
            ], $cfg, $role);
        }
    }

    public function testTheWatcherNeverGetsATokenOrAnythingSecret(): void
    {
        $html = $this->page($this->owner(), 'admin/inventory');
        preg_match('#<script>window\.APP_SESSION = (\{.*?\});</script>#', $html, $m);

        $this->assertStringNotContainsString(csrf_hash(), $m[1]);
        foreach (['token', 'hash', 'password', 'username', 'email'] as $never) {
            $this->assertStringNotContainsStringIgnoringCase($never, $m[1]);
        }
    }

    public function testTestModeGivesTheWatcherTheShortLimitOnlyOnADevelopmentMachine(): void
    {
        config(Auth::class)->idleTestSeconds = 60;

        $dev = SignedOut::pageConfig('Staff', 'development');
        $this->assertSame([60, true], [$dev['idleSeconds'], $dev['testMode']], 'The watcher and the server use the same test-mode limit.');
        $this->assertSame(60, config(Auth::class)->idleLimit('development'));

        $live = SignedOut::pageConfig('Staff', 'production');
        $this->assertSame([1800, false], [$live['idleSeconds'], $live['testMode']], 'Never on the live server.');
    }

    // ---------------------------------------------------------------- when the saved POS cart may come back

    public function testSigningBackInAfterAnIdleSignOutLetsTheFirstPosPageBringTheCartBack(): void
    {
        $result = $this->ownerSignsIn(['return_to' => '/admin/sales', 'signed_out_reason' => 'idle']);
        $this->assertSame(site_url('admin/sales'), $result->getRedirectUrl());
        $session = $_SESSION;
        $this->assertArrayHasKey(SavedCart::SESSION_KEY, $session);

        $first = $this->page($session, 'admin/sales');
        $this->assertSame('1', $this->savedCartFlag($first), 'The first POS page after the idle sign-in.');
        $this->assertArrayNotHasKey(SavedCart::SESSION_KEY, $_SESSION, 'Taken once.');

        $this->assertSame('0', $this->savedCartFlag($this->page($_SESSION, 'admin/sales')), 'Never on the next load.');
    }

    public function testAnOrdinarySignInNeverBringsASavedCartBack(): void
    {
        foreach ([[], ['signed_out_reason' => 'signed_out'], ['signed_out_reason' => 'deactivated']] as $before) {
            $this->ownerSignsIn($before);
            $this->assertArrayNotHasKey(SavedCart::SESSION_KEY, $_SESSION, json_encode($before));
            $this->assertSame('0', $this->savedCartFlag($this->page($_SESSION, 'admin/sales')), json_encode($before));
        }
    }

    public function testAnOrdinaryPosPageNeverBringsASavedCartBack(): void
    {
        $this->assertSame('0', $this->savedCartFlag($this->page($this->owner(), 'admin/sales')));
        $staff = ['isLoggedIn' => true, 'role' => 'Staff', 'user_id' => $this->staffId, 'username' => 'staff1', 'full_name' => 'Staff', 'supplier_id' => null, 'last_activity' => time()];
        $this->assertSame('0', $this->savedCartFlag($this->page($staff, 'staff/sales')));
    }

    public function testSigningInOnAnotherPageFirstStillLetsThePosBringTheCartBackForTenMinutes(): void
    {
        $this->ownerSignsIn(['return_to' => '/admin/inventory', 'signed_out_reason' => 'idle']);
        $this->page($_SESSION, 'admin/inventory');
        $this->assertArrayHasKey(SavedCart::SESSION_KEY, $_SESSION, 'Another page does not use it up.');

        $this->assertSame('1', $this->savedCartFlag($this->page($_SESSION, 'admin/sales')));
    }

    public function testAfterTenMinutesThePosNoLongerBringsTheCartBack(): void
    {
        $late = $this->page($this->owner([SavedCart::SESSION_KEY => time() - SavedCart::WINDOW - 1]), 'admin/sales');

        $this->assertSame('0', $this->savedCartFlag($late));
        $this->assertArrayNotHasKey(SavedCart::SESSION_KEY, $_SESSION, 'An old mark is dropped.');
    }

    public function testAnOldMarkIsDroppedByTheNextOrdinarySignIn(): void
    {
        $this->ownerSignsIn([SavedCart::SESSION_KEY => time()]);

        $this->assertArrayNotHasKey(SavedCart::SESSION_KEY, $_SESSION);
    }

    public function testLoggingOutDropsTheMark(): void
    {
        $this->withSession($this->owner([SavedCart::SESSION_KEY => time()]))->post('logout');

        $this->assertArrayNotHasKey(SavedCart::SESSION_KEY, $_SESSION ?? []);
    }

    public function testThePart1CartWinsOverTheSavedOne(): void
    {
        // "Not enough stock" or "This page needed a refresh" right after an idle sign-in: the posted
        // cart (old input) comes back exactly as in Part 1, and the saved one does not.
        $old  = ['get' => [], 'post' => ['item_id' => ['P-1'], 'quantity' => ['2'], 'payment_method' => 'Cash']];
        $html = $this->page($this->owner([SavedCart::SESSION_KEY => time(), '_ci_old_input' => $old, '__ci_vars' => ['_ci_old_input' => 'new']]), 'admin/sales');

        preg_match('/data-restore="([^"]*)"/', $html, $restore);
        $this->assertSame([['id' => 'P-1', 'qty' => 2]], json_decode(html_entity_decode($restore[1]), true));
        $this->assertSame('0', $this->savedCartFlag($html));
    }

    public function testThePageAfterASuccessfulCheckoutNeverBringsTheCartBack(): void
    {
        config('Filters')->globals['before'] = array_values(array_diff(config('Filters')->globals['before'], ['csrf']));
        $html = $this->page($this->owner(), 'admin/sales');
        preg_match('/name="' . OnceToken::FIELD . '" value="([0-9a-f]{32})"/', $html, $key);

        $this->withSession($this->owner())->post('admin/sales/checkout', ['item_id' => ['P-1'], 'quantity' => ['1'], 'payment_method' => 'Cash', 'cash_received' => '500', OnceToken::FIELD => $key[1]] + \Tests\Support\PosPinFixture::field());
        $this->assertSame(1, $this->db->table('sales_transaction')->countAllResults());

        $after = $this->page($_SESSION, 'admin/sales');
        $this->assertSame('0', $this->savedCartFlag($after), 'pos.js then clears the saved cart.');
        preg_match('/data-restore="([^"]*)"/', $after, $restore);
        $this->assertSame([], json_decode(html_entity_decode($restore[1]), true), 'No cart comes back.');
    }
}
