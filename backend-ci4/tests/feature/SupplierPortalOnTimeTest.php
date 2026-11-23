<?php

use App\Libraries\SupplierScorecard;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ReorderFixture;

/**
 * The supplier portal's on-time rate (Dashboard, Completed, Profile) uses the SAME judgement as the Procurement Report
 * scorecard (App\Libraries\SupplierScorecard::judge()): the PO's expected date, else PO date + the supplier's stated
 * delivery time; under 5 received orders it says "Not enough orders yet". The per-order "late by N days" badges stay.
 * In-memory 'tests' database.
 *
 * SUP-001 (stated delivery time 7 days) has 5 received orders, 4 on time; SUP-002 has 3.
 *
 * @internal
 */
final class SupplierPortalOnTimeTest extends CIUnitTestCase
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

        $this->db->table('suppliers')->where('supplier_id', 'SUP-001')->update(['company_name' => 'Tactical Gear Inc', 'lead_time_days' => 7, 'is_active' => 1]);
        $this->db->table('suppliers')->where('supplier_id', 'SUP-002')->update(['company_name' => 'Quiapo Patches Co', 'lead_time_days' => 7, 'is_active' => 1]);
        $this->db->table('users')->insert(['username' => 'quiapo', 'full_name' => 'Quiapo Patches', 'role' => 'Supplier', 'supplier_id' => 'SUP-002', 'email' => 'quiapo@example.test', 'password_hash' => 'x']);

        // SUP-001, day offsets from today. Stated 7 days.
        $this->delivered('D1', 'SUP-001', -40, ['expected_delivery_date' => $this->day(-33), 'received_at' => $this->day(-33) . ' 17:00:00', 'actual_delivery_date' => $this->day(-33)]); // the same day: on time
        $this->delivered('D2', 'SUP-001', -39, ['expected_delivery_date' => $this->day(-32), 'received_at' => $this->day(-29) . ' 10:00:00', 'actual_delivery_date' => $this->day(-29)]); // 3 days late
        $this->delivered('D3', 'SUP-001', -38, ['received_at' => $this->day(-32) . ' 10:00:00', 'actual_delivery_date' => $this->day(-32)]);                                              // no expected date: PO + 7 = -31, received -32: on time
        $this->delivered('D4', 'SUP-001', -37, ['expected_delivery_date' => $this->day(-30), 'actual_delivery_date' => $this->day(-31)]);                                                    // no received_at: the delivery date is used
        $this->delivered('D5', 'SUP-001', -36, ['expected_delivery_date' => $this->day(-29), 'received_at' => $this->day(-29) . ' 09:00:00', 'actual_delivery_date' => $this->day(-29)]);
        // SUP-002: only 3 received, all on time.
        foreach ([1, 2, 3] as $i) {
            $this->delivered("E{$i}", 'SUP-002', -30 + $i, ['expected_delivery_date' => $this->day(-22 + $i), 'received_at' => $this->day(-23 + $i) . ' 10:00:00', 'actual_delivery_date' => $this->day(-23 + $i)]);
        }
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

    private function delivered(string $soId, string $supplier, int $orderOffset, array $extra): void
    {
        $this->db->table('stock_order')->insert($extra + ['so_id' => $soId, 'supplier_id' => $supplier, 'status' => 'Delivered', 'order_date' => $this->day($orderOffset), 'po_number' => 'PO-2026-' . substr($soId, 1, 1) . substr($soId, 0, 1)]);
        $this->db->table('so_item')->insert(['so_id' => $soId, 'item_id' => "ITM-{$soId}", 'order_quantity' => 10, 'shipped_quantity' => 10, 'unit_price' => 50]);
    }

    private function actingSupplier(string $supplierId): self
    {
        $user = $this->db->table('users')->where('supplier_id', $supplierId)->where('role', 'Supplier')->get()->getRow();

        return $this->withSession(['isLoggedIn' => true, 'role' => 'Supplier', 'user_id' => (int) $user->user_id, 'supplier_id' => $supplierId, 'username' => $user->username, 'full_name' => $user->full_name, 'last_activity' => time()]);
    }

    private function body(string $supplierId, string $uri): string
    {
        return (string) $this->actingSupplier($supplierId)->get($uri)->getBody();
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        return new \DOMXPath($dom);
    }

    private function text(string $html, string $xpath): string
    {
        $node = $this->xpath($html)->query($xpath)->item(0);

        return $node ? trim(preg_replace('/\s+/', ' ', html_entity_decode($node->textContent))) : '';
    }

    // ------------------------------------------------------------ the three pages agree with the scorecard

    public function testDashboardCompletedAndProfileShowTheScorecardsRate(): void
    {
        // 4 of 5 received orders on or before the promised date.
        $this->assertSame('80%', $this->text($this->body('SUP-001', 'supplier/dashboard'), '//*[@id="sdOnTime"]'));
        $this->assertSame('80%', $this->text($this->body('SUP-001', 'supplier/completed?range=all'), '//*[@id="cpOnTime"]'));
        $profile = $this->body('SUP-001', 'supplier/profile');
        $this->assertSame('80%', $this->text($profile, '//*[@id="pfOnTime"]'));
        $this->assertSame('80%', $this->text($profile, '//*[@id="pfOnTimeTop"]'));

        // And it is exactly the Procurement Report scorecard's figure.
        $this->assertSame(80, SupplierScorecard::forSuppliers('all')['SUP-001']['on_time']['pct']);
    }

    public function testTheDashboardCountsAndTheCompletedSubLine(): void
    {
        $dash = $this->xpath($this->body('SUP-001', 'supplier/dashboard'));
        $minis = [];
        foreach ($dash->query('//div[contains(@class,"sd-mini")]') as $mini) {
            $minis[trim($dash->query('.//div[@class="l"]', $mini)->item(0)->textContent)] = trim($dash->query('.//div[@class="v"]', $mini)->item(0)->textContent);
        }
        $this->assertSame('5', $minis['Orders delivered']);
        $this->assertSame('4', $minis['Delivered on time']);

        $this->assertStringContainsString('On-Time Rate (4/5)', $this->text($this->body('SUP-001', 'supplier/completed?range=all'), '(//div[contains(@class,"cp-stat")])[3]'));
    }

    // ------------------------------------------------------------ fewer than five received orders

    public function testUnderFiveReceivedOrdersEveryPageSaysNotEnoughOrdersYet(): void
    {
        $dash = $this->body('SUP-002', 'supplier/dashboard');
        $this->assertSame('Not enough orders yet', $this->text($dash, '//*[@id="sdOnTime"]'));
        $this->assertSame('Not enough orders yet (3 of 5 received)', $this->text($dash, '//*[@id="sdOnTimeNote"]'));

        $completed = $this->body('SUP-002', 'supplier/completed?range=all');
        $this->assertSame('Not enough orders yet', $this->text($completed, '//*[@id="cpOnTime"]'));
        $this->assertStringContainsString('(3 of 5 received)', $this->text($completed, '(//div[contains(@class,"cp-stat")])[3]'));

        $profile = $this->body('SUP-002', 'supplier/profile');
        $this->assertSame('Not enough orders yet', $this->text($profile, '//*[@id="pfOnTime"]'));
        $this->assertSame('Not enough orders yet (3 of 5 received)', $this->text($profile, '//*[@id="pfOnTimeNote"]'));
        $this->assertSame('—', $this->text($profile, '//*[@id="pfOnTimeTop"]'), 'No 0% or 100% in the header either.');
    }

    public function testASupplierWithNoDeliveriesAtAllIsNotZeroPercent(): void
    {
        $this->db->table('stock_order')->where('supplier_id', 'SUP-002')->delete();

        $dash = $this->body('SUP-002', 'supplier/dashboard');
        $this->assertSame('Not enough orders yet', $this->text($dash, '//*[@id="sdOnTime"]'));
        $this->assertStringNotContainsString('>0%<', $dash);
    }

    public function testTheCompletedPageJudgesTheRangeItIsShowing(): void
    {
        // "This month" holds only the deliveries made since the 1st (dates are relative to today, so count them).
        $inMonth = $this->db->table('stock_order')->where('supplier_id', 'SUP-001')->where('actual_delivery_date >=', date('Y-m-01'))->countAllResults();
        $month   = $this->body('SUP-001', 'supplier/completed?range=month');

        $this->assertLessThan(5, $inMonth, 'Never enough to judge in one month.');
        $this->assertSame('Not enough orders yet', $this->text($month, '//*[@id="cpOnTime"]'));
        $this->assertStringContainsString("({$inMonth} of 5 received)", $this->text($month, '(//div[contains(@class,"cp-stat")])[3]'));
    }

    // ------------------------------------------------------------ a delivery with no expected date

    public function testNoExpectedDateIsNowJudgedAgainstThePoDatePlusTheStatedDays(): void
    {
        // PO 35 days ago, no expected date, received 23 days ago: 12 days after the PO against 7 stated = 5 days late.
        $this->delivered('D6', 'SUP-001', -35, ['received_at' => $this->day(-23) . ' 10:00:00', 'actual_delivery_date' => $this->day(-23)]);

        $completed = $this->body('SUP-001', 'supplier/completed?range=all');
        // 6 received, 4 on time: the old calculation counted the order with no expected date as on time (5 of 6 = 83%).
        $this->assertSame('67%', $this->text($completed, '//*[@id="cpOnTime"]'));
        $this->assertSame('67%', $this->text($this->body('SUP-001', 'supplier/dashboard'), '//*[@id="sdOnTime"]'));
        $this->assertSame('67%', $this->text($this->body('SUP-001', 'supplier/profile'), '//*[@id="pfOnTime"]'));
        $this->assertSame(67, SupplierScorecard::forSuppliers('all')['SUP-001']['on_time']['pct']);
    }

    // ------------------------------------------------------------ the per-order badges stay

    public function testEachDeliveryKeepsItsOnTimeOrLateByNDaysBadge(): void
    {
        $x      = $this->xpath($this->body('SUP-001', 'supplier/completed?range=all'));
        $badges = [];
        foreach ($x->query('(//div[contains(@class,"page-panel")])[1]//tbody/tr') as $tr) {
            $po           = trim(explode("\n", trim($x->query('./td[1]/strong', $tr)->item(0)->textContent))[0]);
            $badges[$po] = trim($x->query('./td[5]//span[contains(@class,"status-pill")]', $tr)->item(0)->textContent);
        }

        $this->assertCount(5, $badges);
        $this->assertSame(['On Time' => 4, '3 days late' => 1], array_count_values($badges));
    }

    public function testALongerDelayIsShownInWeeks(): void
    {
        // Promised 14 days after the PO, received 15 days after that: 15 days late is about 2 weeks.
        $this->delivered('D7', 'SUP-001', -60, ['expected_delivery_date' => $this->day(-46), 'received_at' => $this->day(-31) . ' 10:00:00', 'actual_delivery_date' => $this->day(-31)]);

        $this->assertStringContainsString('2 weeks late', $this->body('SUP-001', 'supplier/completed?range=all'));
    }

    // ------------------------------------------------------------ only the supplier's own orders, nothing written

    public function testEachSupplierSeesOnlyItsOwnOrders(): void
    {
        $this->assertSame('5', $this->text($this->body('SUP-001', 'supplier/dashboard'), '//div[contains(@class,"sd-mini")][1]//div[@class="v"]'));
        $this->assertSame('3', $this->text($this->body('SUP-002', 'supplier/dashboard'), '//div[contains(@class,"sd-mini")][1]//div[@class="v"]'));
    }

    public function testOpeningThePagesWritesNothing(): void
    {
        $snapshot = fn () => json_encode([$this->db->table('stock_order')->get()->getResultArray(), $this->db->table('so_item')->get()->getResultArray(), $this->db->table('suppliers')->get()->getResultArray()]);
        $before   = $snapshot();

        foreach (['supplier/dashboard', 'supplier/completed?range=all', 'supplier/completed?range=3months', 'supplier/profile'] as $uri) {
            $this->body('SUP-001', $uri);
            $this->body('SUP-002', $uri);
        }

        $this->assertSame($before, $snapshot());
    }
}
