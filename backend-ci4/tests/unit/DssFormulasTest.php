<?php

use App\Libraries\DssFormulas;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Probabilistic Engine unit tests: UT-09, UT-10, UT-11, UT-13, UT-14, UT-15, UT-16.
 *
 * Pass criterion from Section 4.4: the system output must match the manual
 * computation within +/-1 unit after rounding, so computed values are
 * compared with a delta of 1. Where the code differs from the document, the
 * comment says so.
 *
 * @internal
 */
final class DssFormulasTest extends CIUnitTestCase
{
    /** UT-09 */
    public function testAnnualDemandFromDailyDemand(): void
    {
        $this->assertSame(7300, DssFormulas::annualDemand(20));
    }

    /** UT-10: sqrt(2 x 7300 x 250 / 18) = 450.4 */
    public function testEoqOfBenchmarkProduct(): void
    {
        $this->assertSame(450, DssFormulas::eoq(7300, 250, 18));
    }

    /** UT-11: no division by zero. The engine returns 0; the caller floors it at minimum_order_qty. */
    public function testEoqWithZeroHoldingCostDoesNotDivideByZero(): void
    {
        $this->assertSame(0, DssFormulas::eoq(7300, 250, 0));
        $this->assertSame(0, DssFormulas::eoq(7300, 250, -5));
        $this->assertSame(0, DssFormulas::eoq(0, 250, 18));
    }

    /**
     * UT-13: 1.645 x 4.14 x sqrt(7) = 18.02. The engine rounds UP (ceil), so it
     * returns 19; the document's manual value is 18. Within the +/-1 tolerance.
     */
    public function testSafetyStockClassA(): void
    {
        $this->assertEqualsWithDelta(18, DssFormulas::safetyStock(1.645, 4.14, 7), 1);
    }

    /** UT-14: ROP = (20 x 7) + SS. 158 with SS 18; 159 with the engine's ceil'd SS of 19. */
    public function testReorderPointClassA(): void
    {
        $ss = DssFormulas::safetyStock(1.645, 4.14, 7);

        $this->assertEqualsWithDelta(158, DssFormulas::reorderPoint(20, 7, $ss), 1);
        $this->assertSame(158, DssFormulas::reorderPoint(20, 7, 18));
    }

    /** UT-15 */
    public function testSafetyStockAndRopForClassBAndC(): void
    {
        $ssB = DssFormulas::safetyStock(1.282, 4.14, 7);
        $ssC = DssFormulas::safetyStock(1.036, 4.14, 7);

        $this->assertEqualsWithDelta(14, $ssB, 1);
        $this->assertEqualsWithDelta(154, DssFormulas::reorderPoint(20, 7, $ssB), 1);
        $this->assertEqualsWithDelta(11, $ssC, 1);
        $this->assertEqualsWithDelta(151, DssFormulas::reorderPoint(20, 7, $ssC), 1);
    }

    /** ST-06 (unit level): a higher service level always gives a higher safety stock. */
    public function testHigherClassGetsHigherSafetyStock(): void
    {
        $this->assertGreaterThan(
            DssFormulas::safetyStock(1.036, 4.14, 7),
            DssFormulas::safetyStock(1.645, 4.14, 7)
        );
    }

    public function testSafetyStockIsNeverNegative(): void
    {
        $this->assertSame(0, DssFormulas::safetyStock(1.645, 0, 7));
        $this->assertSame(0, DssFormulas::safetyStock(-1.0, 4.14, 7));
    }

    /**
     * UT-16: sales 18, 22, 20, 24, 16 -> mean 20. The document expects the SAMPLE
     * standard deviation (3.16); the engine uses the POPULATION one (2.83).
     * Both round to 3, so the case passes at +/-1.
     */
    public function testDemandStatistics(): void
    {
        $stats = DssFormulas::demandStats([18, 22, 20, 24, 16]);

        $this->assertEqualsWithDelta(20.0, $stats['avg'], 0.0001);
        $this->assertEqualsWithDelta(3.16, $stats['std'], 1.0);
        $this->assertSame(3, (int) round($stats['std']));
    }

    public function testDemandStatisticsOfEmptyOrConstantSeries(): void
    {
        $this->assertSame(['avg' => 0.0, 'std' => 0.0], DssFormulas::demandStats([]));
        $this->assertEqualsWithDelta(0.0, DssFormulas::demandStats([5, 5, 5])['std'], 0.0001);
    }

    /** UT-17: with a 90-day lookback, sales 30 and 90 days back are in the window; 91 days back is not. */
    public function testLookbackWindowBoundary(): void
    {
        $asOf  = new \DateTimeImmutable('2026-07-29');
        $start = DssFormulas::windowStart($asOf, 90);

        $inWindow = static fn (int $daysAgo): bool => $asOf->modify("-{$daysAgo} days") >= $start;

        $this->assertTrue($inWindow(30));
        $this->assertTrue($inWindow(90));
        $this->assertFalse($inWindow(91));
        $this->assertSame('2026-04-30', $start->format('Y-m-d'));
    }
}
