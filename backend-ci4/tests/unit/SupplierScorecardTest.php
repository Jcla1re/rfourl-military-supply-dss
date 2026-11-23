<?php

use App\Libraries\SupplierScorecard;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The supplier scorecard (App\Libraries\SupplierScorecard) on hand-checked orders. Pure arithmetic: no database.
 * "Today" is 2026-11-23 and the supplier's stated delivery time is 7 days throughout.
 *
 * @internal
 */
final class SupplierScorecardTest extends CIUnitTestCase
{
    private const TODAY = '2026-11-23';

    private array $supplier = ['supplier_id' => 'SUP-001', 'is_sample' => 0, 'lead_time_days' => 7];

    /** @param array<string, mixed> $over */
    private function order(string $id, string $status, string $orderDate, array $over = []): array
    {
        return $over + ['so_id' => $id, 'status' => $status, 'order_date' => $orderDate, 'expected_delivery_date' => null, 'actual_delivery_date' => null,
            'received_at' => null, 'accepted_at' => null, 'cancelled_by' => null];
    }

    /** @return array<string, list<array>> one line per [ordered, shipped] pair, at ₱100 */
    private function lines(string $soId, array ...$pairs): array
    {
        $rows = [];
        foreach ($pairs as [$ordered, $shipped]) {
            $rows[] = ['so_id' => $soId, 'order_quantity' => $ordered, 'shipped_quantity' => $shipped, 'unit_price' => 100];
        }

        return [$soId => $rows];
    }

    /**
     * 11 orders:
     *   O1 received Oct 8,  promised Oct 8  -> on time (the same day), lead 7
     *   O2 received Oct 12, promised Oct 9  -> late, lead 10
     *   O3 received Oct 9,  no expected date, promised Oct 3 + 7 = Oct 10 -> on time, lead 6
     *   O4 received_at empty, actual_delivery_date Oct 11, promised Oct 12 -> on time (fallback date), lead 6
     *   O5 received Oct 13, promised Oct 13 -> on time, lead 7
     *   O6 Order Confirmed, promised Nov 10 (passed)        -> in progress, overdue
     *   O7 Preparing, promised Dec 1                         -> in progress
     *   O8 Shipped, no expected date, Nov 10 + 7 = Nov 17    -> in progress, overdue
     *   O9 cancelled by RfourL before the supplier accepted it
     *   O10 cancelled by RfourL after the supplier accepted it
     *   O11 declined by the supplier
     */
    private function elevenOrders(): array
    {
        $orders = [
            $this->order('O1', 'Delivered', '2026-10-01', ['expected_delivery_date' => '2026-10-08', 'received_at' => '2026-10-08 16:30:00']),
            $this->order('O2', 'Delivered', '2026-10-02', ['expected_delivery_date' => '2026-10-09', 'received_at' => '2026-10-12 09:00:00']),
            $this->order('O3', 'Delivered', '2026-10-03', ['received_at' => '2026-10-09 11:00:00']),
            $this->order('O4', 'Delivered', '2026-10-05', ['expected_delivery_date' => '2026-10-12', 'actual_delivery_date' => '2026-10-11']),
            $this->order('O5', 'Delivered', '2026-10-06', ['expected_delivery_date' => '2026-10-13', 'received_at' => '2026-10-13 08:00:00']),
            $this->order('O6', 'Order Confirmed', '2026-11-01', ['expected_delivery_date' => '2026-11-10']),
            $this->order('O7', 'Preparing', '2026-11-15', ['expected_delivery_date' => '2026-12-01']),
            $this->order('O8', 'Shipped', '2026-11-10'),
            $this->order('O9', 'Cancelled', '2026-11-02', ['cancelled_by' => 1]),
            $this->order('O10', 'Cancelled', '2026-11-03', ['cancelled_by' => 1, 'accepted_at' => '2026-11-04 10:00:00']),
            $this->order('O11', 'Cancelled', '2026-11-04'),
        ];
        $lines = array_merge(
            $this->lines('O1', [10, 10]),
            $this->lines('O2', [20, 15]),
            $this->lines('O3', [10, 12]),            // shipped more than ordered
            $this->lines('O4', [5, null]),           // an older order: no shipped quantity recorded
            $this->lines('O5', [10, 10], [5, 0]),
            $this->lines('O6', [10, null]),
            $this->lines('O7', [4, null]),
            $this->lines('O8', [6, null]),
            $this->lines('O9', [7, null]),           // cancelled orders are never ordered value
            $this->lines('O10', [7, null]),
            $this->lines('O11', [7, null])
        );

        return [$orders, $lines];
    }

    public function testTheCountsOnElevenHandCheckedOrders(): void
    {
        [$orders, $lines] = $this->elevenOrders();
        $card = SupplierScorecard::compute($this->supplier, $orders, $lines, 'all', self::TODAY);

        $this->assertSame('ok', $card['status']);
        $this->assertSame(
            ['placed' => 11, 'received' => 5, 'in_progress' => 3, 'cancelled_by_owner' => 2, 'cancelled_after_accept' => 1, 'declined' => 1, 'received_late' => 1, 'open_overdue' => 2],
            $card['counts']
        );
    }

    public function testOnTimeAverageLeadTimeFillRateAndOrderedValueByHand(): void
    {
        [$orders, $lines] = $this->elevenOrders();
        $card = SupplierScorecard::compute($this->supplier, $orders, $lines, 'all', self::TODAY);

        // On time: O1, O3, O4, O5 of 5 judged = 80%. O2 is a day-count late.
        $this->assertSame(['pct' => 80, 'on_time' => 4, 'of' => 5], $card['on_time']);
        // Lead time: (7 + 10 + 6 + 6 + 7) / 5 = 7.2 days against 7 stated: +0.2.
        $this->assertSame(['avg' => 7.2, 'of' => 5, 'stated' => 7, 'diff' => 0.2], $card['lead']);
        // Shipped vs ordered over the lines that have a shipped quantity: ordered 10 + 20 + 10 + 10 + 5 = 55; shipped
        // 10 + 15 + min(12, 10) = 10 + 10 + 0 = 45; 45 / 55 = 81.8%. O4 has no shipped quantity: left out.
        $this->assertSame(['pct' => 82, 'filled' => 45, 'ordered' => 55, 'orders' => 4, 'left_out' => 1], $card['fill']);
        // Ordered value, cancelled left out: (10 + 20 + 10 + 5 + 15 + 10 + 4 + 6) = 80 pieces x 100.
        $this->assertSame(8000.0, $card['ordered_value']);
        $this->assertSame(['no_received_date' => 0, 'date_problems' => 0, 'no_promised_date' => 0], $card['notes']);
    }

    public function testShippingMoreThanOrderedDoesNotRaiseTheFillRate(): void
    {
        $orders = [];
        $lines  = [];
        for ($i = 1; $i <= 5; $i++) {
            $orders[] = $this->order("O{$i}", 'Delivered', '2026-10-0' . $i, ['expected_delivery_date' => '2026-10-20', 'received_at' => '2026-10-10 10:00:00']);
            $lines   += $this->lines("O{$i}", [10, 50]);
        }

        $this->assertSame(100, SupplierScorecard::compute($this->supplier, $orders, $lines, 'all', self::TODAY)['fill']['pct'], 'Capped at the ordered quantity.');
    }

    // ------------------------------------------------------------ not enough orders

    public function testFewerThanFiveReceivedOrdersShowCountsButNoPercentages(): void
    {
        $orders = [];
        $lines  = [];
        for ($i = 1; $i <= 4; $i++) {
            $orders[] = $this->order("O{$i}", 'Delivered', '2026-10-0' . $i, ['expected_delivery_date' => '2026-10-20', 'received_at' => '2026-10-10 10:00:00']);
            $lines   += $this->lines("O{$i}", [10, 10]);
        }
        $card = SupplierScorecard::compute($this->supplier, $orders, $lines, 'all', self::TODAY);

        $this->assertFalse($card['enough']);
        $this->assertSame('Not enough orders yet (4 of 5 received)', $card['message']);
        $this->assertSame(4, $card['counts']['received']);
        $this->assertSame(4000.0, $card['ordered_value'], 'The value is still shown.');
        $this->assertNull($card['on_time']['pct']);
        $this->assertNull($card['lead']['avg']);
        $this->assertNull($card['fill']['pct']);
        $this->assertSame('Not enough orders yet (4 of 5 received)', SupplierScorecard::summary($card));
    }

    public function testFiveReceivedOrdersIsEnough(): void
    {
        [$orders, $lines] = $this->elevenOrders();
        $card = SupplierScorecard::compute($this->supplier, $orders, $lines, 'all', self::TODAY);

        $this->assertTrue($card['enough']);
        $this->assertNull($card['message']);
        $this->assertSame('On time 80% · Shipped vs ordered 82% · 5 received', SupplierScorecard::summary($card));
    }

    public function testASupplierWithNoOrdersAtAllIsNotEnough(): void
    {
        $card = SupplierScorecard::compute($this->supplier, [], [], '12', self::TODAY);

        $this->assertSame(0, $card['counts']['placed']);
        $this->assertSame('Not enough orders yet (0 of 5 received)', $card['message']);
        $this->assertSame(0.0, $card['ordered_value']);
    }

    // ------------------------------------------------------------ sample suppliers

    public function testASampleSupplierIsNeverScoredEvenWithAStrayOrder(): void
    {
        [$orders, $lines] = $this->elevenOrders();
        $card = SupplierScorecard::compute(['supplier_id' => 'SMP-001', 'is_sample' => 1, 'lead_time_days' => 7], $orders, $lines, 'all', self::TODAY);

        $this->assertSame('sample', $card['status']);
        $this->assertSame('Sample – no order history', $card['message']);
        $this->assertArrayNotHasKey('counts', $card);
        $this->assertSame('Sample – no order history', SupplierScorecard::summary($card));
    }

    // ------------------------------------------------------------ periods

    public function testPeriodsStartAtTheRightPoDate(): void
    {
        $this->assertSame('2026-08-23', SupplierScorecard::period('3', self::TODAY)['from']);
        $this->assertSame('2026-05-23', SupplierScorecard::period('6', self::TODAY)['from']);
        $this->assertSame('2025-11-23', SupplierScorecard::period('12', self::TODAY)['from']);
        $this->assertNull(SupplierScorecard::period('all', self::TODAY)['from']);
        $this->assertSame('12', SupplierScorecard::period('nonsense', self::TODAY)['key'], 'Anything else is the default.');
        $this->assertSame('12', SupplierScorecard::period(null, self::TODAY)['key']);
        $this->assertSame('Last 3 months', SupplierScorecard::period('3', self::TODAY)['label']);
    }

    public function testTheBoundaryPoDateIsInTheDayBeforeIsOut(): void
    {
        $orders = [
            $this->order('IN', 'Order Confirmed', '2026-08-23'),
            $this->order('OUT', 'Order Confirmed', '2026-08-22'),
        ];

        $this->assertSame(1, SupplierScorecard::compute($this->supplier, $orders, [], '3', self::TODAY)['counts']['placed']);
        $this->assertSame(2, SupplierScorecard::compute($this->supplier, $orders, [], '6', self::TODAY)['counts']['placed']);
        $this->assertSame(2, SupplierScorecard::compute($this->supplier, $orders, [], 'all', self::TODAY)['counts']['placed']);
    }

    // ------------------------------------------------------------ edge cases

    public function testADeliveredOrderWithNoReceivedDateCountsAsReceivedButIsLeftOutOfTheTiming(): void
    {
        $orders = [$this->order('O1', 'Delivered', '2026-10-01', ['expected_delivery_date' => '2026-10-08'])];
        $card   = SupplierScorecard::compute($this->supplier, $orders, $this->lines('O1', [10, 10]), 'all', self::TODAY);

        $this->assertSame(1, $card['counts']['received']);
        $this->assertSame(1, $card['notes']['no_received_date']);
        $this->assertSame(0, $card['lead']['of']);
        $this->assertSame(0, $card['on_time']['of']);
        $this->assertSame(0, $card['counts']['received_late'], 'Not judged, so not late.');
    }

    public function testAnOrderReceivedBeforeItWasOrderedIsADateProblemNotALeadTime(): void
    {
        $orders = [$this->order('O1', 'Delivered', '2026-10-10', ['expected_delivery_date' => '2026-10-17', 'received_at' => '2026-10-05 09:00:00'])];
        $card   = SupplierScorecard::compute($this->supplier, $orders, [], 'all', self::TODAY);

        $this->assertSame(1, $card['notes']['date_problems']);
        $this->assertSame(0, $card['lead']['of']);
    }

    public function testNoPromisedDateAndNoStatedDeliveryTimeMeansNotJudged(): void
    {
        $orders = [$this->order('O1', 'Delivered', '2026-10-01', ['received_at' => '2026-10-09 09:00:00'])];
        $card   = SupplierScorecard::compute(['supplier_id' => 'X', 'is_sample' => 0, 'lead_time_days' => 0], $orders, [], 'all', self::TODAY);

        $this->assertSame(1, $card['notes']['no_promised_date']);
        $this->assertSame([0, 1], [$card['on_time']['of'], $card['lead']['of']], 'Its lead time still counts; it cannot be on time or late.');
    }

    public function testAnOpenOrderIsOverdueOnlyAfterThePromisedDate(): void
    {
        $due  = $this->order('O1', 'Preparing', '2026-11-16', ['expected_delivery_date' => self::TODAY]); // promised today: not overdue
        $late = $this->order('O2', 'Preparing', '2026-11-16', ['expected_delivery_date' => '2026-11-22']); // promised yesterday: overdue

        $this->assertSame(0, SupplierScorecard::compute($this->supplier, [$due], [], 'all', self::TODAY)['counts']['open_overdue']);
        $this->assertSame(1, SupplierScorecard::compute($this->supplier, [$late], [], 'all', self::TODAY)['counts']['open_overdue']);
    }

    public function testACancelledOrderIsNeverPartOfTheDeliveryMeasuresOrTheValue(): void
    {
        $orders = [$this->order('O1', 'Cancelled', '2026-10-01', ['cancelled_by' => 1, 'expected_delivery_date' => '2026-10-08'])];
        $card   = SupplierScorecard::compute($this->supplier, $orders, $this->lines('O1', [10, null]), 'all', self::TODAY);

        $this->assertSame([1, 1, 0, 0], [$card['counts']['placed'], $card['counts']['cancelled_by_owner'], $card['counts']['received'], $card['counts']['in_progress']]);
        $this->assertSame(0.0, $card['ordered_value']);
        $this->assertSame(0, $card['counts']['declined']);
    }

    public function testADeclineIsNotACancellationByRfourL(): void
    {
        $orders = [$this->order('O1', 'Cancelled', '2026-10-01', ['decline_reason' => 'Out of stock'])];
        $card   = SupplierScorecard::compute($this->supplier, $orders, [], 'all', self::TODAY);

        $this->assertSame([1, 0, 0], [$card['counts']['declined'], $card['counts']['cancelled_by_owner'], $card['counts']['cancelled_after_accept']]);
    }

    public function testAMultiLineOrderAddsEveryLine(): void
    {
        $orders = [$this->order('O1', 'Preparing', '2026-11-20', ['expected_delivery_date' => '2026-12-01'])];
        $card   = SupplierScorecard::compute($this->supplier, $orders, $this->lines('O1', [12, null], [20, null], [8, null]), 'all', self::TODAY);

        $this->assertSame(4000.0, $card['ordered_value'], '12 + 20 + 8 pieces at ₱100.');
    }

    public function testNonOrdersAreIgnoredWithoutAnError(): void
    {
        $orders = [['so_id' => 'X', 'status' => 'Delivered', 'order_date' => null], ['so_id' => 'Y', 'status' => 'Strange', 'order_date' => '2026-10-01']];
        $card   = SupplierScorecard::compute($this->supplier, $orders, [], 'all', self::TODAY);

        $this->assertSame(1, $card['counts']['placed'], 'A row with no PO date is skipped; an unknown status is placed but nothing else.');
        $this->assertSame([0, 0], [$card['counts']['received'], $card['counts']['in_progress']]);
    }

    // ------------------------------------------------------------ the shared judgement (also used by the supplier portal)

    public function testJudgeAnOrderWithAnExpectedDate(): void
    {
        $on   = SupplierScorecard::judge($this->order('A', 'Delivered', '2026-10-01', ['expected_delivery_date' => '2026-10-08', 'received_at' => '2026-10-08 18:00:00']), 7);
        $late = SupplierScorecard::judge($this->order('B', 'Delivered', '2026-10-01', ['expected_delivery_date' => '2026-10-08', 'received_at' => '2026-10-11 09:00:00']), 7);
        $week = SupplierScorecard::judge($this->order('C', 'Delivered', '2026-10-01', ['expected_delivery_date' => '2026-10-08', 'actual_delivery_date' => '2026-10-15']), 7);

        $this->assertSame([true, 0, '2026-10-08'], [$on['on_time'], $on['days_late'], $on['promised']], 'The same day is on time.');
        $this->assertSame([false, 3], [$late['on_time'], $late['days_late']]);
        $this->assertSame([false, 7, '2026-10-15'], [$week['on_time'], $week['days_late'], $week['received']], 'actual_delivery_date is the received date when received_at is empty.');
    }

    public function testJudgeAnOrderWithNoExpectedDateUsesThePoDatePlusTheStatedDays(): void
    {
        // PO Oct 1 + 7 stated days = promised Oct 8. It used to count as on time whatever the date.
        $order = $this->order('A', 'Delivered', '2026-10-01', ['received_at' => '2026-10-13 09:00:00']);
        $late  = SupplierScorecard::judge($order, 7);

        $this->assertSame(['2026-10-08', false, 5], [$late['promised'], $late['on_time'], $late['days_late']]);
        $this->assertTrue(SupplierScorecard::judge($order, 14)['on_time'], 'A longer stated delivery time moves the promised date.');
    }

    public function testJudgeLeavesWhatItCannotJudgeAsNull(): void
    {
        $noDate  = SupplierScorecard::judge($this->order('A', 'Delivered', '2026-10-01', ['expected_delivery_date' => '2026-10-08']), 7);
        $noLead  = SupplierScorecard::judge($this->order('B', 'Delivered', '2026-10-01', ['received_at' => '2026-10-09 09:00:00']), 0);
        $before  = SupplierScorecard::judge($this->order('C', 'Delivered', '2026-10-10', ['expected_delivery_date' => '2026-10-17', 'received_at' => '2026-10-05 09:00:00']), 7);

        $this->assertSame([null, 0, false], [$noDate['on_time'], $noDate['days_late'], $noDate['date_problem']], 'No received date.');
        $this->assertSame([null, null], [$noLead['on_time'], $noLead['promised']], 'No expected date and no stated delivery time: nothing to judge by.');
        $this->assertSame([null, true], [$before['on_time'], $before['date_problem']], 'Received before it was ordered: a date problem.');
    }

    public function testThePortalRateNeedsFiveReceivedOrders(): void
    {
        $four = [];
        for ($i = 1; $i <= 4; $i++) {
            $four[] = $this->order("O{$i}", 'Delivered', '2026-10-01', ['expected_delivery_date' => '2026-10-08', 'received_at' => '2026-10-08 10:00:00']);
        }
        $rate = SupplierScorecard::portalRate($four, 7);

        $this->assertSame([false, 4, 4, 4, null, 'Not enough orders yet (4 of 5 received)'], [$rate['enough'], $rate['received'], $rate['judged'], $rate['on_time'], $rate['pct'], $rate['message']]);

        $five = array_merge($four, [$this->order('O5', 'Delivered', '2026-10-01', ['expected_delivery_date' => '2026-10-08', 'received_at' => '2026-10-10 10:00:00'])]);
        $rate = SupplierScorecard::portalRate($five, 7);
        $this->assertSame([true, 5, 4, 80, null], [$rate['enough'], $rate['received'], $rate['on_time'], $rate['pct'], $rate['message']], '4 of 5 on time.');
    }

    public function testThePortalRateAndTheScorecardAlwaysAgree(): void
    {
        [$orders, $lines] = $this->elevenOrders();
        $delivered        = array_values(array_filter($orders, static fn ($o) => $o['status'] === 'Delivered'));

        $portal = SupplierScorecard::portalRate($delivered, 7);
        $card   = SupplierScorecard::compute($this->supplier, $orders, $lines, 'all', self::TODAY);

        $this->assertSame($card['on_time']['pct'], $portal['pct']);
        $this->assertSame([$card['on_time']['on_time'], $card['on_time']['of'], $card['counts']['received']], [$portal['on_time'], $portal['judged'], $portal['received']]);
    }

    public function testNoExpectedDateIsNoLongerAutomaticallyOnTime(): void
    {
        // Five received orders, none with an expected date, each received 10 days after the PO against 7 stated days.
        $orders = [];
        for ($i = 1; $i <= 5; $i++) {
            $orders[] = $this->order("O{$i}", 'Delivered', '2026-10-01', ['received_at' => '2026-10-11 10:00:00']);
        }

        $this->assertSame(0, SupplierScorecard::portalRate($orders, 7)['pct'], 'The old portal calculation said 100% for these.');
        $this->assertSame(5, SupplierScorecard::compute($this->supplier, $orders, [], 'all', self::TODAY)['counts']['received_late']);
    }
}
