<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ReorderFixture;

/**
 * The Orders page calendar (active orders only, a dot when one is due today, amber when
 * overdue), its empty-state texts, and the Supplier Profile / Dashboard order numbers.
 * Runs on the in-memory 'tests' database.
 *
 * @internal
 */
final class OrdersCalendarTest extends CIUnitTestCase
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
        Services::resetSingle('renderer');
        $this->addSize('P-1', 'Tactical Pants', 'M', 5, 3);
    }

    protected function tearDown(): void
    {
        $this->tearDownReorderFixture();
        parent::tearDown();
    }

    private function ordersPage(string $query = ''): string
    {
        Services::resetSingle('renderer');

        return (string) $this->asOwner()->get('admin/orders' . $query)->getBody();
    }

    /** day number => its calendar classes, for the month that contains $date. */
    private function calendar(string $date): array
    {
        $html = $this->ordersPage('?tab=status&month=' . (int) date('n', strtotime($date)) . '&year=' . date('Y', strtotime($date)));
        preg_match_all('#<div class="cal-day ?([^"]*)">(\d+)</div>#', $html, $m, PREG_SET_ORDER);

        return array_column($m, 1, 2);
    }

    private function day(string $date): int
    {
        return (int) date('j', strtotime($date));
    }

    // ------------------------------------------------------------ 1. active orders only

    public function testOnlyActiveOrdersMarkTheCalendar(): void
    {
        $base      = date('Y-m-d', strtotime('+3 days'));
        $cancelled = $base;
        $delivered = date('Y-m-d', strtotime('+4 days'));
        $declined  = date('Y-m-d', strtotime('+5 days'));
        $active    = date('Y-m-d', strtotime('+6 days'));
        $this->openOrder('SO-0001', ['P-1'], $cancelled, 'Cancelled');
        $this->openOrder('SO-0002', ['P-1'], $delivered, 'Delivered');
        $this->openOrder('SO-0003', ['P-1'], $declined, 'Cancelled');
        $this->db->table('stock_order')->update(['decline_reason' => 'Out of stock'], ['so_id' => 'SO-0003']);
        $this->openOrder('SO-0004', ['P-1'], $active);

        foreach ([[$cancelled, ''], [$delivered, ''], [$declined, ''], [$active, 'restock']] as [$date, $class]) {
            $this->assertSame($class, $this->calendar($date)[$this->day($date)], "{$date}: only the active order marks its date.");
        }
    }

    // ------------------------------------------------------------ 2. today and overdue

    public function testAnOrderDueTodayKeepsTheTodaySquareWithADot(): void
    {
        $today = date('Y-m-d');
        $this->assertSame('today', $this->calendar($today)[$this->day($today)], 'Nothing due: just Today.');

        $this->openOrder('SO-0001', ['P-1'], $today);
        $this->assertSame('today has-due', $this->calendar($today)[$this->day($today)]);
    }

    public function testAnOverdueActiveOrderIsAmberWithAnOverdueLegend(): void
    {
        $past = date('Y-m-d', strtotime('-3 days'));
        $this->openOrder('SO-0001', ['P-1'], $past, 'Preparing');
        $this->openOrder('SO-0002', ['P-1'], date('Y-m-d', strtotime('-5 days')), 'Cancelled'); // not overdue: cancelled

        $this->assertSame('overdue', $this->calendar($past)[$this->day($past)]);
        $html = $this->ordersPage('?tab=status&month=' . (int) date('n', strtotime($past)) . '&year=' . date('Y', strtotime($past)));
        $this->assertStringContainsString('Restock Event</span>', $html);
        $this->assertMatchesRegularExpression('#<span><i style="background:\#FCF1D8[^"]*"></i> Overdue</span>#', $html);
        $cancelledDate = date('Y-m-d', strtotime('-5 days'));
        $this->assertSame('', $this->calendar($cancelledDate)[$this->day($cancelledDate)], 'The cancelled date stays plain.');
    }

    public function testNoOverdueLegendWithoutAnOverdueDate(): void
    {
        $this->openOrder('SO-0001', ['P-1'], date('Y-m-d', strtotime('+2 days')));

        $html = $this->ordersPage();
        $this->assertStringContainsString('Restock Event</span>', $html);
        $this->assertStringNotContainsString('</i> Overdue</span>', $html);
    }

    // ------------------------------------------------------------ 3. empty states

    public function testEmptyStates(): void
    {
        $none = $this->ordersPage();
        $this->assertStringContainsString('No active orders.', $none);
        $this->assertStringContainsString('>Add New Order</button>', $none);
        $this->assertStringNotContainsString('View history', $none, 'No history yet: no link.');
        $this->assertStringNotContainsString('No orders yet.', $none);
        $this->assertStringContainsString('No delivered or cancelled orders yet.', $this->ordersPage('?tab=history'));

        $this->openOrder('SO-0001', ['P-1'], date('Y-m-d', strtotime('+2 days')), 'Cancelled');
        $withHistory = $this->ordersPage();
        $this->assertStringContainsString('No active orders.', $withHistory);
        $this->assertStringContainsString('<a class="ord-history-link" href="' . site_url('admin/orders') . '?tab=history">View history</a>', $withHistory);
        $this->assertStringContainsString('No orders match your filters.', $this->ordersPage('?tab=history&search=nothing-like-this'));
    }

    // ------------------------------------------------------------ 4–5. supplier numbers

    private function supplierPage(string $url): string
    {
        Services::resetSingle('renderer');
        $u = $this->db->table('users')->where('username', 'tgi')->get()->getRow();

        return (string) $this->withSession(['isLoggedIn' => true, 'role' => 'Supplier', 'user_id' => (int) $u->user_id, 'username' => 'tgi', 'full_name' => 'Tactical Gear', 'supplier_id' => 'SUP-001', 'last_activity' => time()])
            ->get($url)->getBody();
    }

    /** "Orders Completed (Month)" and its bar, and "Orders fulfilled", as the Profile shows them. */
    private function supplierRates(): array
    {
        $html = $this->supplierPage('supplier/profile');
        preg_match('#Orders Completed \(Month\)</span><strong>([^<]+)</strong></div>\s*<div class="pf-perf-track"><span style="width: (\d+)%"></span></div>#', $html, $month);
        preg_match('#<div class="v">([^<]+)</div><div class="l">Orders fulfilled</div>#', $html, $fulfilled);

        return ['month' => $month[1] ?? null, 'bar' => isset($month[2]) ? (int) $month[2] : null, 'fulfilled' => $fulfilled[1] ?? null];
    }

    /** An order placed today for SUP-001. */
    private function supplierOrder(string $soId, string $status, array $extra = []): void
    {
        $this->openOrder($soId, ['P-1'], date('Y-m-d', strtotime('+3 days')), $status);
        $this->db->table('stock_order')->update($extra + ['order_date' => date('Y-m-d')], ['so_id' => $soId]);
    }

    public function testAnOwnerCancelDoesNotLowerTheRateButADeclineDoes(): void
    {
        $this->assertSame(['month' => '0 / 0', 'bar' => 0, 'fulfilled' => '0 / 0'], $this->supplierRates(), 'No orders: an empty bar.');

        $this->supplierOrder('SO-0001', 'Delivered', ['actual_delivery_date' => date('Y-m-d')]);
        $this->supplierOrder('SO-0002', 'Cancelled', ['cancelled_by' => $this->ownerId, 'cancel_reason' => 'Ordered by mistake', 'cancelled_at' => date('Y-m-d H:i:s')]);
        $this->assertSame(['month' => '1 / 1', 'bar' => 100, 'fulfilled' => '1 / 1'], $this->supplierRates(), "The owner's cancel doesn't count.");

        $this->supplierOrder('SO-0003', 'Cancelled', ['decline_reason' => 'Out of stock', 'cancelled_at' => date('Y-m-d H:i:s')]);
        $this->assertSame(['month' => '1 / 2', 'bar' => 50, 'fulfilled' => '1 / 2'], $this->supplierRates(), 'A decline counts as not fulfilled.');

        // An order still in progress counts as received, not yet fulfilled.
        $this->supplierOrder('SO-0004', 'Preparing');
        $this->assertSame(['month' => '1 / 3', 'bar' => 33, 'fulfilled' => '1 / 3'], $this->supplierRates());
    }

    public function testTheSupplierDashboardLabelSaysThisMonth(): void
    {
        $html = $this->supplierPage('supplier/dashboard');
        $this->assertStringContainsString('<div class="label">Units Delivered This Month</div>', $html);
        $this->assertStringNotContainsString('Total Units Supplied', $html);
    }
}
