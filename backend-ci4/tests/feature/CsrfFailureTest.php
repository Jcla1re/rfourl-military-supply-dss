<?php

use App\Libraries\OnceToken;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ReorderFixture;
use Tests\Support\VoidSalesFixture;

/**
 * A form or request whose CSRF token has expired (App\Filters\CsrfFilter around CodeIgniter's own
 * CSRF check). Every test runs twice: with development behaviour (Security::$redirect off, the
 * framework throws: the old plain 403 page) and production behaviour (the framework redirects back).
 * Nothing is ever saved, nothing is retried, the page comes back with what was typed (the POS cart
 * from its posted item IDs and quantities) and a fresh token and one-time key. Plus fixes 3 to 5:
 * "Not enough stock" keeps the cart, and a refused stock log keeps the form with an accurate title.
 * Runs on the in-memory 'tests' database.
 *
 * @internal
 */
final class CsrfFailureTest extends CIUnitTestCase
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
        $this->tearDownVoidSales();
        Factories::reset('config');
        Services::resetSingle('security');
        parent::tearDown();
    }

    /** CSRF on (ReorderFixture turns it off), with development (throw) or production (redirect) behaviour. */
    private function csrf(bool $productionRedirect): void
    {
        $filters = config('Filters');
        if (! in_array('csrf', $filters->globals['before'], true)) {
            array_unshift($filters->globals['before'], 'csrf');
        }
        $security           = config('Security');
        $security->redirect = $productionRedirect;
        Factories::injectMock('config', 'Security', $security);
        Services::resetSingle('security');
    }

    private function staff(array $extra = []): array
    {
        return $extra + ['isLoggedIn' => true, 'role' => 'Staff', 'user_id' => $this->staffId, 'username' => 'staff1', 'full_name' => 'Ana Cruz', 'last_activity' => time()];
    }

    private function rows(string $table): int
    {
        return $this->db->table($table)->countAllResults();
    }

    /** @return array<string, array{0: bool}> */
    public static function modes(): array
    {
        return ['development (throws)' => [false], 'production (redirects)' => [true]];
    }

    private function checkoutWithExpiredToken(string $key)
    {
        return $this->withSession($this->staff())
            ->withHeaders(['Referer' => site_url('staff/sales')])
            ->post('staff/sales/checkout', [
                'csrf_test_name' => 'expired-token', OnceToken::FIELD => $key, '_submit_label' => 'Checkout & Print Receipt',
                'item_id' => ['P-1', 'P-2'], 'quantity' => ['2', '1'], 'payment_method' => 'Cash', 'cash_received' => '700', 'or_number' => 'A-77',
            ]);
    }

    // ---------------------------------------------------------------- a form post

    /** @dataProvider modes */
    public function testAnExpiredTokenOnCheckoutSavesNothingAndBringsTheCartBack(bool $production): void
    {
        $this->csrf($production);
        $key = bin2hex(random_bytes(16));

        $result = $this->checkoutWithExpiredToken($key);

        $this->assertSame(site_url('staff/sales'), $result->getRedirectUrl(), 'Back to the page, no 403.');
        $this->assertSame(['info', 'This page needed a refresh', 'Nothing was saved. Please click "Checkout & Print Receipt" again.'], [$_SESSION['toast']['type'], $_SESSION['toast']['title'], $_SESSION['toast']['message']]);
        $old = $_SESSION['_ci_old_input']['post'];
        $this->assertSame([['P-1', 'P-2'], ['2', '1'], 'A-77'], [$old['item_id'], $old['quantity'], $old['or_number']], 'What was typed is kept.');
        foreach (['csrf_test_name', OnceToken::FIELD, '_submit_label'] as $never) {
            $this->assertArrayNotHasKey($never, $old);
        }
        $this->assertSame([0, 0, 0], [$this->rows('sales_transaction'), $this->rows('sales_item'), $this->rows('form_submissions')], 'Nothing saved, the one-time key not used.');
        $this->assertSame([5, 9], [$this->stock('P-1'), $this->stock('P-2')]);
    }

    /** @dataProvider modes */
    public function testThePosPageRebuildsTheCartFromIdsAndQuantitiesWithAFreshKey(bool $production): void
    {
        $this->csrf($production);
        $posted = bin2hex(random_bytes(16));
        $this->checkoutWithExpiredToken($posted);
        $old = $_SESSION['_ci_old_input'];

        $html = (string) $this->withSession($this->staff(['_ci_old_input' => $old, '__ci_vars' => ['_ci_old_input' => 'new']]))->get('staff/sales')->response()->getBody();

        preg_match('/data-restore="([^"]*)"/', $html, $restore);
        $this->assertSame([['id' => 'P-1', 'qty' => 2], ['id' => 'P-2', 'qty' => 1]], json_decode(html_entity_decode($restore[1]), true), 'Only IDs and quantities: no price.');
        preg_match('/data-restore-payment="([^"]*)"/', $html, $pay);
        $this->assertSame(['method' => 'Cash', 'cash' => '700', 'or_number' => 'A-77'], json_decode(html_entity_decode($pay[1]), true));
        preg_match('/name="' . OnceToken::FIELD . '" value="([0-9a-f]{32})"/', $html, $fresh);
        $this->assertNotSame($posted, $fresh[1], 'A fresh one-time key for the next click.');

        // The cashier's own next click (fresh key, valid page): exactly one sale.
        config('Filters')->globals['before'] = array_values(array_diff(config('Filters')->globals['before'], ['csrf']));
        $this->withSession($this->staff())->post('staff/sales/checkout', ['item_id' => ['P-1', 'P-2'], 'quantity' => ['2', '1'], 'payment_method' => 'Cash', 'cash_received' => '700', OnceToken::FIELD => $fresh[1]]);
        $this->assertSame(1, $this->rows('sales_transaction'));
        $this->assertSame([3, 8], [$this->stock('P-1'), $this->stock('P-2')]);
    }

    /** @dataProvider modes */
    public function testAnExpiredTokenOnABackgroundRequestGets419Json(bool $production): void
    {
        $this->csrf($production);
        $owner = ['isLoggedIn' => true, 'role' => 'Admin', 'user_id' => $this->ownerId, 'username' => 'owner1', 'full_name' => 'Owner One', 'last_activity' => time()];

        $result = $this->withSession($owner)->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->post('admin/inventory/store', ['csrf_test_name' => 'expired', 'item_name' => 'New Cap', 'category' => 'Clothes', 'unit_cost' => '10', 'selling_price' => '20']);

        $result->assertStatus(419);
        $data = json_decode((string) $result->response()->getBody(), true);
        $this->assertTrue($data['csrfExpired']);
        $this->assertSame('This page needed a refresh', $data['title']);
        $this->assertSame(0, $this->db->table('products')->where('item_name', 'New Cap')->countAllResults(), 'Nothing saved.');
    }

    /** @dataProvider modes */
    public function testLogoutStillSignsOutWithAnExpiredToken(bool $production): void
    {
        $this->csrf($production);

        $result = $this->withSession($this->staff())->post('logout', ['csrf_test_name' => 'expired']);

        $this->assertSame(site_url('/'), $result->getRedirectUrl());
        $this->assertArrayNotHasKey('isLoggedIn', $_SESSION ?? []);
    }

    /** @dataProvider modes */
    public function testALoginFormWithAnExpiredTokenAsksToSignInAgainAndKeepsOnlyTheUsername(bool $production): void
    {
        $this->csrf($production);

        $result = $this->withSession([])->post('login/admin', ['csrf_test_name' => 'expired', 'username' => 'owner1', 'password' => 'secret-typed']);

        $this->assertSame(site_url('login/admin'), $result->getRedirectUrl());
        $this->assertSame('This page needed a refresh. Please sign in again.', $_SESSION['error']);
        $this->assertSame(['username' => 'owner1'], $_SESSION['_ci_old_input']['post'], 'Never the password.');
        $this->assertArrayNotHasKey('isLoggedIn', $_SESSION);
    }

    /** @dataProvider modes */
    public function testSignedOutWinsOverAnExpiredToken(bool $production): void
    {
        $this->csrf($production);
        $idle = $this->staff(['last_activity' => time() - 1801]);

        $page = $this->withSession($idle)->withHeaders(['Referer' => site_url('staff/sales')])->post('staff/sales/checkout', ['csrf_test_name' => 'x', 'item_id' => ['P-1'], 'quantity' => ['1']]);
        $this->assertSame(site_url('login/staff'), $page->getRedirectUrl(), 'The login page, not "needed a refresh".');
        $this->assertSame(['idle', '/staff/sales'], [$_SESSION['signed_out_reason'], $_SESSION['return_to']]);

        $ajax = $this->withSession($idle)->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->post('staff/sales/checkout', ['csrf_test_name' => 'x']);
        $ajax->assertStatus(401);
        $this->assertSame(0, $this->rows('sales_transaction'));
    }

    public function testAGoodTokenStillPassesTheFrameworksOwnCheck(): void
    {
        $this->csrf(false);
        $token = csrf_hash(); // the framework's current token (no cookie in tests: one per security service)

        $this->withSession($this->staff())->post('staff/sales/checkout', [
            'csrf_test_name' => $token, OnceToken::FIELD => bin2hex(random_bytes(16)), 'item_id' => ['P-2'], 'quantity' => ['1'], 'payment_method' => 'Cash', 'cash_received' => '500',
        ]);

        $this->assertSame(1, $this->rows('sales_transaction'), 'CodeIgniter accepted it; the sale was saved.');
    }

    // ---------------------------------------------------------------- fix 3: "Not enough stock" keeps the cart

    /** @return array<string, array{0: string}> */
    public static function roles(): array
    {
        return ['owner' => ['admin'], 'staff' => ['staff']];
    }

    /** @dataProvider roles */
    public function testNotEnoughStockKeepsTheCartWithAFreshKeyAndTheRightTitle(string $role): void
    {
        $session = $role === 'admin'
            ? ['isLoggedIn' => true, 'role' => 'Admin', 'user_id' => $this->ownerId, 'username' => 'owner1', 'full_name' => 'Owner One', 'last_activity' => time()]
            : $this->staff();
        $posted = bin2hex(random_bytes(16));

        $this->withSession($session)->post($role . '/sales/checkout', ['item_id' => ['P-1'], 'quantity' => ['7'], 'payment_method' => 'Cash', 'cash_received' => '5000', OnceToken::FIELD => $posted]);

        $this->assertSame(['error', 'Not enough stock'], [$_SESSION['toast']['type'], $_SESSION['toast']['title']]);
        $this->assertStringContainsString('Not enough stock for Patrol Shirt', $_SESSION['toast']['message']);
        $this->assertSame([['P-1'], ['7']], [$_SESSION['_ci_old_input']['post']['item_id'], $_SESSION['_ci_old_input']['post']['quantity']]);
        $this->assertSame([0, 0, 5], [$this->rows('sales_transaction'), $this->rows('form_submissions'), $this->stock('P-1')], 'Nothing sold; the key not used.');

        $html = (string) $this->withSession($session + ['_ci_old_input' => $_SESSION['_ci_old_input'], '__ci_vars' => ['_ci_old_input' => 'new']])->get($role . '/sales')->response()->getBody();
        preg_match('/data-restore="([^"]*)"/', $html, $restore);
        $this->assertSame([['id' => 'P-1', 'qty' => 7]], json_decode(html_entity_decode($restore[1]), true), 'pos.js caps it to the 5 left, with the usual note.');
        preg_match('/name="' . OnceToken::FIELD . '" value="([0-9a-f]{32})"/', $html, $fresh);
        $this->assertNotSame($posted, $fresh[1]);

        $this->withSession($session)->post($role . '/sales/checkout', ['item_id' => ['P-1'], 'quantity' => ['5'], 'payment_method' => 'Cash', 'cash_received' => '5000', OnceToken::FIELD => $fresh[1]]);
        $this->withSession($session)->post($role . '/sales/checkout', ['item_id' => ['P-1'], 'quantity' => ['5'], 'payment_method' => 'Cash', 'cash_received' => '5000', OnceToken::FIELD => $fresh[1]]);
        $this->assertSame([1, 0], [$this->rows('sales_transaction'), $this->stock('P-1')], 'The fresh key still stops a double submit.');
    }

    public function testTheRestoreDataNeverCarriesAPrice(): void
    {
        $view = file_get_contents(APPPATH . 'Views/partials/_pos_tab.php');
        $this->assertMatchesRegularExpression("/\\\$restore\\[\\] = \\['id' => \\\$oldId, 'qty' => /", $view);
        $this->assertStringNotContainsString("old('selling_price'", $view);
        $js = file_get_contents(FCPATH . 'assets/js/pos.js');
        $this->assertStringContainsString('price: v.selling_price', $js, 'Restored prices come from the page\'s own item data.');
    }

    // ---------------------------------------------------------------- fixes 4 and 5: refused stock logs

    public function testARefusedReturnKeepsTheFormAndSaysCantLogThisReturn(): void
    {
        $before = $this->stock('P-1');
        $posted = bin2hex(random_bytes(16));

        $result = $this->withSession($this->staff())->post('staff/log-transaction/store', [
            'type' => 'return', 'item_id' => 'P-1', 'qty_returned' => '2', 'original_sale_ref' => '9999',
            'item_condition' => 'Damaged', 'reason' => 'Wrong Size', 'refund_action' => 'Exchange', OnceToken::FIELD => $posted,
        ]);

        $this->assertSame(site_url('staff/log-transaction/return'), $result->getRedirectUrl());
        $this->assertSame(['error', "Can't log this return", 'Receipt number not found. Check and try again.'], [$_SESSION['toast']['type'], $_SESSION['toast']['title'], $_SESSION['toast']['message']]);
        $this->assertSame($before, $this->stock('P-1'));

        $html = (string) $this->withSession($this->staff(['_ci_old_input' => $_SESSION['_ci_old_input'], '__ci_vars' => ['_ci_old_input' => 'new']]))->get('staff/log-transaction/return')->response()->getBody();
        $this->assertMatchesRegularExpression('/<option value="P-1"[^>]* selected>/', $html, 'The item.');
        $this->assertStringContainsString('name="qty_returned" value="2"', $html);
        $this->assertStringContainsString('name="original_sale_ref" value="9999"', $html);
        foreach (['Damaged', 'Wrong Size', 'Exchange'] as $choice) {
            $this->assertStringContainsString('<option selected>' . $choice . '</option>', $html);
        }
        preg_match('/name="' . OnceToken::FIELD . '" value="([0-9a-f]{32})"/', $html, $fresh);
        $this->assertNotSame($posted, $fresh[1], 'A fresh one-time key.');
        $this->assertStringNotContainsString('Something went wrong', $html);
    }

    public function testOtherRefusedLogsSayCantLogThisTransaction(): void
    {
        $this->withSession($this->staff())->post('staff/log-transaction/store', ['type' => 'damaged', 'item_id' => 'P-1', 'qty_affected' => '99', 'notes' => 'Torn seam']);

        $this->assertSame(["Can't log this transaction", 'Only 5 in stock. Enter 5 or less.'], [$_SESSION['toast']['title'], $_SESSION['toast']['message']]);
        $this->assertSame('Torn seam', $_SESSION['_ci_old_input']['post']['notes']);
    }
}
