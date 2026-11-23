<?php

use App\Libraries\SupplierScorecard;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ReorderFixture;

/**
 * The supplier scorecard on the Procurement Report > Suppliers tab (App\Libraries\SupplierScorecard): the detail page's
 * section (?supplier=ID) and the one line under each supplier's name in the list, with ?score=3|6|12|all (12 by default).
 * Read-only, from real orders only. In-memory 'tests' database.
 *
 * SUP-001 has 8 orders in the last 12 months (5 received: 4 on time and 1 late), SUP-002 has 2 received, SMP-001 is a
 * sample supplier with a stray order, SUP-003 has none.
 *
 * @internal
 */
final class SupplierScorecardTabTest extends CIUnitTestCase
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

        $this->db->table('suppliers')->where('supplier_id', 'SUP-001')->update(['company_name' => 'Tactical Gear Inc', 'lead_time_days' => 7, 'is_sample' => 0, 'is_active' => 1]);
        $this->db->table('suppliers')->where('supplier_id', 'SUP-002')->update(['company_name' => 'Quiapo Patches Co', 'lead_time_days' => 4, 'is_sample' => 0, 'is_active' => 1]);
        $this->db->table('suppliers')->insertBatch([
            ['supplier_id' => 'SUP-003', 'company_name' => 'Empty Supply Co', 'lead_time_days' => 9, 'is_sample' => 0, 'is_active' => 1],
            ['supplier_id' => 'SMP-001', 'company_name' => 'Sample Stitch Works', 'lead_time_days' => 3, 'is_sample' => 1, 'is_active' => 1],
        ]);

        // SUP-001, all within the last 12 months: expected = order + 7. Received after 7, 7, 6, 10 and 5 days: the 10 is late.
        foreach ([[1, 7, 10], [2, 7, 10], [3, 6, 10], [4, 10, 8], [5, 5, 10]] as [$i, $days, $shipped]) {
            $this->order("D{$i}", 'SUP-001', 'Delivered', $this->day(-60 + $i), [
                'expected_delivery_date' => $this->day(-60 + $i + 7), 'received_at' => $this->day(-60 + $i + $days) . ' 10:00:00', 'po_number' => "PO-2026-000{$i}",
            ], [[10, $shipped]]);
        }
        $this->order('P1', 'SUP-001', 'Preparing', $this->day(-5), ['expected_delivery_date' => $this->day(5)], [[10, null]]);
        $this->order('C1', 'SUP-001', 'Cancelled', $this->day(-20), ['cancelled_by' => $this->ownerId, 'accepted_at' => $this->day(-19) . ' 09:00:00'], [[7, null]]);
        $this->order('X1', 'SUP-001', 'Cancelled', $this->day(-15), ['decline_reason' => 'Out of stock'], [[7, null]]);
        // SUP-002: two received, an old one outside the 3-month period.
        $this->order('E1', 'SUP-002', 'Delivered', $this->day(-30), ['expected_delivery_date' => $this->day(-25), 'received_at' => $this->day(-26) . ' 10:00:00'], [[10, 10]]);
        $this->order('E2', 'SUP-002', 'Delivered', $this->day(-200), ['expected_delivery_date' => $this->day(-195), 'received_at' => $this->day(-195) . ' 10:00:00'], [[10, 10]]);
        // A stray order against a sample supplier must never become a score.
        $this->order('S1', 'SMP-001', 'Delivered', $this->day(-10), ['expected_delivery_date' => $this->day(-3), 'received_at' => $this->day(-4) . ' 10:00:00'], [[10, 10]]);
    }

    protected function tearDown(): void
    {
        $this->tearDownReorderFixture();
        parent::tearDown();
    }

    // ------------------------------------------------------------ helpers

    private function day(int $offset): string
    {
        return date('Y-m-d', strtotime("{$offset} days"));
    }

    /** @param list<array{0: int, 1: ?int}> $lines [ordered, shipped] pairs at ₱50 */
    private function order(string $soId, string $supplier, string $status, string $orderDate, array $extra, array $lines): void
    {
        $this->db->table('stock_order')->insert($extra + ['so_id' => $soId, 'supplier_id' => $supplier, 'status' => $status, 'order_date' => $orderDate]);
        foreach ($lines as $i => [$ordered, $shipped]) {
            $this->db->table('so_item')->insert(['so_id' => $soId, 'item_id' => "ITM-{$soId}-{$i}", 'order_quantity' => $ordered, 'shipped_quantity' => $shipped, 'unit_price' => 50]);
        }
    }

    private function page(string $query = ''): string
    {
        return (string) $this->asOwner()->get('admin/procurement-report?tab=suppliers' . $query)->getBody();
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        return new \DOMXPath($dom);
    }

    private function clean(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode($text)));
    }

    /** @return array<string, array{0: string, 1: string}> tile key => [value, sub text] */
    private function tiles(string $html): array
    {
        $x   = $this->xpath($html);
        $out = [];
        foreach ($x->query('//div[contains(@class,"sp-tile")][@data-tile]') as $tile) {
            $sub = $x->query('.//div[contains(@class,"sp-tile-sub")]', $tile)->item(0);
            $out[$tile->getAttribute('data-tile')] = [
                $this->clean($x->query('.//div[contains(@class,"sp-tile-value")]', $tile)->item(0)->textContent),
                $sub !== null ? $this->clean($sub->textContent) : '',
            ];
        }

        return $out;
    }

    private function line(string $html, string $supplier): string
    {
        return $this->clean($this->xpath($html)->query('//tr[@data-supplier="' . $supplier . '"]//span[contains(@class,"sp-score-line")]')->item(0)->textContent);
    }

    private function snapshot(): string
    {
        $out = [];
        foreach (['stock_order', 'so_item', 'suppliers', 'products', 'pdss_computation', 'supplier_prices'] as $t) {
            $out[$t] = $this->db->table($t)->get()->getResultArray();
        }

        return json_encode($out);
    }

    // ------------------------------------------------------------ the supplier's page

    public function testASupplierWithFiveReceivedOrdersShowsEveryMeasure(): void
    {
        $tiles = $this->tiles($this->page('&supplier=SUP-001'));

        $this->assertSame(['8', ''], $tiles['placed']);
        $this->assertSame(['5', 'of 8 placed'], $tiles['received']);
        $this->assertSame('1', $tiles['in_progress'][0]);
        $this->assertSame(['1', '1 after the supplier accepted it'], $tiles['cancelled']);
        $this->assertSame('1', $tiles['declined'][0]);
        // 4 of the 5 received on or before the promised date.
        $this->assertSame(['80%', '4 of 5 received on or before the promised date'], $tiles['on_time']);
        $this->assertSame('1', $tiles['late'][0]);
        $this->assertSame('0', $tiles['overdue'][0], 'The open order is promised for a future date.');
        // (7 + 7 + 6 + 10 + 5) / 5 = 7 days against 7 stated.
        $this->assertSame(['7 days', 'Stated delivery time: 7 days (0 days)'], $tiles['lead']);
        // Shipped 10 + 10 + 10 + 8 + 10 = 48 of 50 ordered.
        $this->assertSame(['96%', '48 of 50 ordered units shipped'], $tiles['fill']);
        // 5 received x 10 + the open order's 10 = 60 pieces at ₱50; the two cancelled orders are left out.
        $this->assertSame('₱3,000.00', $tiles['value'][0]);
    }

    public function testTheSectionExplainsTheTermsAndLabelsFillRateShippedVsOrdered(): void
    {
        $html = $this->page('&supplier=SUP-001');

        $this->assertStringContainsString('Shipped vs ordered', $html);
        $this->assertStringContainsString('Receiving records the quantity the supplier says it shipped', $html, 'The tooltip.');
        $this->assertStringContainsString('expected delivery date', $html, 'The on-time tooltip names the promised date.');
        $this->assertStringContainsString('Real orders placed in the last 12 months', $html);
        $this->assertStringNotContainsString('Fill rate', $html);
    }

    public function testFewerThanFiveReceivedOrdersSaysNotEnoughAndStillShowsCounts(): void
    {
        $html  = $this->page('&supplier=SUP-002');
        $tiles = $this->tiles($html);

        $this->assertSame(['2', ''], $tiles['placed']);
        $this->assertSame('2', $tiles['received'][0]);
        $this->assertSame('Not enough orders yet', $tiles['on_time'][0]);
        $this->assertSame('Not enough orders yet', $tiles['lead'][0]);
        $this->assertSame('Not enough orders yet', $tiles['fill'][0]);
        $this->assertSame('₱1,000.00', $tiles['value'][0], 'The value is still shown.');
        $this->assertStringContainsString('Not enough orders yet (2 of 5 received)', $this->clean($this->xpath($html)->query('//*[@id="spScoreNotEnough"]')->item(0)->textContent));
    }

    public function testASupplierWithNoOrdersAtAll(): void
    {
        $tiles = $this->tiles($this->page('&supplier=SUP-003'));

        $this->assertSame('0', $tiles['placed'][0]);
        $this->assertSame('Not enough orders yet', $tiles['on_time'][0]);
        $this->assertSame('₱0.00', $tiles['value'][0]);
    }

    public function testASampleSupplierSaysSoAndIsNeverScored(): void
    {
        $x = $this->xpath($this->page('&supplier=SMP-001'));

        $this->assertSame('Sample – no order history', $this->clean($x->query('//*[@id="spScoreSample"]')->item(0)->textContent));
        $this->assertSame(0, $x->query('//div[@data-tile]')->length, 'Not a single tile for a sample supplier, even with a stray order.');
        $this->assertSame(0, $x->query('//div[contains(@class,"sp-score-seg")]')->length, 'No period switch either.');
    }

    // ------------------------------------------------------------ periods

    public function testThePeriodSwitchChangesWhichOrdersCount(): void
    {
        // SUP-002's old order (200 days ago) is only in the 12-month and all-time periods.
        $this->assertSame('2', $this->tiles($this->page('&supplier=SUP-002'))['placed'][0], 'The default is 12 months.');
        $this->assertSame('2', $this->tiles($this->page('&supplier=SUP-002&score=12'))['placed'][0]);
        $this->assertSame('2', $this->tiles($this->page('&supplier=SUP-002&score=all'))['placed'][0]);
        $this->assertSame('1', $this->tiles($this->page('&supplier=SUP-002&score=6'))['placed'][0], 'Neither 200 days nor 6 months: 200 days is older than 6 months.');
        $this->assertSame('1', $this->tiles($this->page('&supplier=SUP-002&score=3'))['placed'][0]);
    }

    public function testTheSwitchLinksKeepTheSupplierAndMarkTheCurrentPeriod(): void
    {
        $x    = $this->xpath($this->page('&supplier=SUP-001&score=6'));
        $seg  = [];
        foreach ($x->query('//div[contains(@class,"sp-score-seg")]/a') as $a) {
            $seg[] = [$this->clean($a->textContent), str_contains($a->getAttribute('class'), 'active'), $a->getAttribute('href')];
        }

        $this->assertSame(['Last 3 months', 'Last 6 months', 'Last 12 months', 'All time'], array_column($seg, 0));
        $this->assertSame([false, true, false, false], array_column($seg, 1));
        foreach ($seg as [, , $href]) {
            $this->assertStringContainsString('supplier=SUP-001', $href);
        }
        $this->assertStringContainsString('score=3', $seg[0][2]);
    }

    public function testABadPeriodFallsBackToTwelveMonths(): void
    {
        $x = $this->xpath($this->page('&supplier=SUP-001&score=banana'));

        $this->assertSame('Last 12 months', $this->clean($x->query('//div[contains(@class,"sp-score-seg")]/a[contains(@class,"active")]')->item(0)->textContent));
    }

    // ------------------------------------------------------------ the list

    public function testEachSupplierInTheListHasOneLine(): void
    {
        $html = $this->page();

        $this->assertSame('On time 80% · Shipped vs ordered 96% · 5 received', $this->line($html, 'SUP-001'));
        $this->assertSame('Not enough orders yet (2 of 5 received)', $this->line($html, 'SUP-002'));
        $this->assertSame('Not enough orders yet (0 of 5 received)', $this->line($html, 'SUP-003'));
        $this->assertSame('Sample – no order history', $this->line($html, 'SMP-001'));
    }

    public function testTheListFollowsThePeriodAndItsSwitchIsThere(): void
    {
        $x = $this->xpath($this->page('&score=3'));

        $this->assertSame('Last 3 months', $this->clean($x->query('//div[contains(@class,"sp-score-seg")]/a[contains(@class,"active")]')->item(0)->textContent));
        $this->assertSame('Not enough orders yet (1 of 5 received)', $this->line($this->page('&score=3'), 'SUP-002'));
        // Links to a supplier keep the period.
        $this->assertStringContainsString('score=3', $x->query('//tr[@data-supplier="SUP-001"]//a[contains(@class,"sp-link")]')->item(0)->getAttribute('href'));
    }

    // ------------------------------------------------------------ the loader

    public function testTheLoaderScoresEveryActiveSupplierAndNeverQueriesASampleSupplierForOrders(): void
    {
        $cards = SupplierScorecard::forSuppliers('12');

        $this->assertEqualsCanonicalizing(['SUP-001', 'SUP-002', 'SUP-003', 'SMP-001'], array_keys($cards));
        $this->assertSame('sample', $cards['SMP-001']['status']);
        $this->assertSame(8, $cards['SUP-001']['counts']['placed']);
        $this->assertSame(80, $cards['SUP-001']['on_time']['pct']);
        $this->assertSame(2, $cards['SUP-002']['counts']['received'], 'Two received in 12 months.');
    }

    public function testAnArchivedSupplierIsNotScored(): void
    {
        $this->db->table('suppliers')->where('supplier_id', 'SUP-003')->update(['is_active' => 0]);

        $this->assertArrayNotHasKey('SUP-003', SupplierScorecard::forSuppliers('12'));
    }

    // ------------------------------------------------------------ read-only, other tabs unchanged

    public function testOpeningTheListTheDetailAndEveryPeriodChangesNothing(): void
    {
        $before = $this->snapshot();

        $this->page();
        foreach (['3', '6', '12', 'all'] as $score) {
            $this->page('&score=' . $score);
            $this->page('&supplier=SUP-001&score=' . $score);
        }
        $this->page('&supplier=SMP-001');

        $this->assertSame($before, $this->snapshot(), 'No row anywhere was written.');
    }

    public function testTheScorecardNeverMentionsSampleOrdersOrCreatesAny(): void
    {
        $this->page('&supplier=SMP-001');

        $this->assertSame(1, $this->db->table('stock_order')->where('supplier_id', 'SMP-001')->countAllResults(), 'Only the stray order the test made: no order is ever created.');
    }

    public function testTheOtherTabsAreUnchangedAndOnlyTheOwnerSeesIt(): void
    {
        foreach (['?tab=items', '?tab=comparison', '?tab=best'] as $query) {
            $html = (string) $this->asOwner()->get('admin/procurement-report' . $query)->getBody();
            $x = $this->xpath($html);
            $this->assertSame(0, $x->query('//*[contains(@class,"sp-score-line")] | //*[@id="supplierScorecard"] | //*[contains(@class,"sp-tile")]')->length, $query);
        }
        $this->asStaff()->get('admin/procurement-report?tab=suppliers&supplier=SUP-001')->assertRedirect();
    }

    public function testTheSupplierReportPdfAndCsvAreUnchanged(): void
    {
        $csv = (string) $this->asOwner()->get('admin/procurement-report/csv?tab=suppliers')->getBody();

        $this->assertStringNotContainsString('Scorecard', $csv);
        $this->assertStringNotContainsString('On time', $csv);
    }
}
