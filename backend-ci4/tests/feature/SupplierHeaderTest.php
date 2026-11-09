<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ReorderFixture;

/**
 * The Supplier pages' green header is the title only (no subtitle line) on Dashboard,
 * New Orders, Deliveries, Completed (every period), Notifications and My Profile.
 * Runs on the in-memory 'tests' database.
 *
 * @internal
 */
final class SupplierHeaderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ReorderFixture;

    protected $migrate = false;
    protected $refresh = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReorderFixture();
        Services::resetSingle('renderer'); // a fresh renderer, as in a real request (no view data from earlier tests)

        $this->addSize('P-1', 'Tactical Pants', 'M', 4, 6);
        $this->openOrder('SO-0001', ['P-1'], date('Y-m-d', strtotime('+3 days')));
        $this->openOrder('SO-0002', ['P-1'], date('Y-m-d', strtotime('+5 days')), 'Preparing');
        $this->openOrder('SO-0003', ['P-1'], date('Y-m-d', strtotime('-2 days')), 'Delivered');
        $this->db->table('stock_order')->update(['actual_delivery_date' => date('Y-m-d', strtotime('-2 days'))], ['so_id' => 'SO-0003']);
    }

    protected function tearDown(): void
    {
        $this->tearDownReorderFixture();
        parent::tearDown();
    }

    private function supplierPage(string $url): string
    {
        $u = $this->db->table('users')->where('username', 'tgi')->get()->getRow();

        return (string) $this->withSession(['isLoggedIn' => true, 'role' => 'Supplier', 'user_id' => (int) $u->user_id, 'username' => 'tgi', 'full_name' => 'Tactical Gear', 'supplier_id' => 'SUP-001', 'last_activity' => time()])
            ->get($url)->getBody();
    }

    public static function pages(): array
    {
        return [
            'Dashboard'             => ['supplier/dashboard', 'Dashboard'],
            'New Orders'            => ['supplier/new-orders', 'New Orders'],
            'Deliveries'            => ['supplier/deliveries', 'Deliveries'],
            'Completed, this month' => ['supplier/completed', 'Completed'],
            'Completed, 3 months'   => ['supplier/completed?range=3months', 'Completed'],
            'Completed, all time'   => ['supplier/completed?range=all', 'Completed'],
            'Notifications'         => ['supplier/notifications', 'Notifications'],
            'My Profile'            => ['supplier/profile', 'My Profile'],
        ];
    }

    /** @dataProvider pages */
    public function testTheHeaderIsTheTitleOnly(string $url, string $title): void
    {
        $html = $this->supplierPage($url);

        $this->assertMatchesRegularExpression('/<h1>' . $title . '<\/h1>\s*<\/div>/', $html);
        $this->assertStringNotContainsString('text-white-50 small mt-1', $html);
        foreach (['new order</div>', 'new orders</div>', 'waiting for you', 'active shipment', 'Delivered this month', ' unread</div>', 'Your account</div>'] as $old) {
            $this->assertStringNotContainsString($old, $html);
        }
    }

    public function testCompletedStillSwitchesPeriod(): void
    {
        foreach (['month' => 'This Month', '3months' => 'Last 3 Months', 'all' => 'All Time'] as $range => $label) {
            $html = $this->supplierPage('supplier/completed?range=' . $range);
            $this->assertStringContainsString('<a href="?range=' . $range . '" class="active">' . $label . '</a>', $html);
            $this->assertStringContainsString('SO-0003', $html, "The delivered order shows for {$label}.");
        }
    }
}
