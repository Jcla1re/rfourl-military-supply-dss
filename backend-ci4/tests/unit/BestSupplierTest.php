<?php

use App\Libraries\BestSupplier;
use App\Libraries\DssFormulas;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The best-supplier comparison (App\Libraries\BestSupplier) on hand-checked numbers. Pure arithmetic: no database.
 *
 * Settings used throughout: S (ordering cost) = 500, H (storage cost per piece a year) = 60.48, system minimum = 20.
 *
 * @internal
 */
final class BestSupplierTest extends CIUnitTestCase
{
    /** @param array<string, mixed> $over */
    private function item(array $over = []): array
    {
        return $over + ['annual_demand' => 50, 'std_dev' => 3.696, 'z' => 1.645, 'class' => 'A', 'ordering_cost' => 500.0, 'holding_cost' => 60.48, 'minimum_order_qty' => 20];
    }

    private function offer(string $id, float $price, ?int $lead, array $over = []): array
    {
        return $over + ['supplier_id' => $id, 'name' => $id, 'is_main' => false, 'is_sample' => false, 'unit_price' => $price, 'lead_time_days' => $lead, 'min_order_qty' => null];
    }

    /** @return array<string, array<string, mixed>> supplier id => the comparison's row */
    private function rowsById(array $result): array
    {
        return array_column($result['rows'], null, 'supplier_id');
    }

    // ------------------------------------------------------------ the formula, by hand

    /**
     * Pepper Spray (class A): D = 50 a year, sigma = 3.696, Z = 1.645, so EOQ = round(sqrt(2 x 50 x 500 / 60.48)) = 29.
     *   main:   price 132, L = 12 -> SS = ceil(1.645 x 3.696 x sqrt(12)) = ceil(21.06) = 22, Q = max(29, 20) = 29
     *           cost = 50 x 132 + (50 / 29) x 500 + (29 / 2 + 22) x 60.48 = 6600 + 862.07 + 2207.52 = 9669.59
     *   sample: price 139, L = 5  -> SS = ceil(6.07992 x sqrt(5)) = ceil(13.60) = 14
     *           cost = 6950 + 862.07 + (14.5 + 14) x 60.48 = 6950 + 862.07 + 1723.68 = 9535.75
     */
    public function testPepperSprayByHand(): void
    {
        $result = BestSupplier::compare($this->item(), [
            $this->offer('MAIN', 132, 12, ['is_main' => true, 'name' => 'Tactical Gear']),
            $this->offer('SMP', 139, 5, ['is_sample' => true, 'name' => 'Pasig Tactical Supply Depot']),
        ]);
        $rows = $this->rowsById($result);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(29, $result['inputs']['eoq']);
        $this->assertSame([29, 22, 9669.59], [$rows['MAIN']['q'], $rows['MAIN']['ss'], $rows['MAIN']['yearly_cost']]);
        $this->assertSame([29, 14, 9535.75], [$rows['SMP']['q'], $rows['SMP']['ss'], $rows['SMP']['yearly_cost']]);
        // Recommended = lowest yearly cost (the sample), Cheapest = lowest price (main), Fastest = shortest lead (the sample).
        $this->assertSame(['SMP', 'MAIN', 'SMP'], [$result['recommended'], $result['cheapest'], $result['fastest']]);
        $this->assertSame(['Recommended', 'Fastest'], $rows['SMP']['tags']);
        $this->assertSame(['Cheapest'], $rows['MAIN']['tags']);
        $this->assertSame(133.84, $result['saving_vs_main'], '9669.59 - 9535.75');
    }

    /**
     * Name Cloth (class A): D = 100, sigma = 7.392, EOQ = round(sqrt(2 x 100 x 500 / 60.48)) = 41.
     *   main     price 72, L 4:            SS 25, Q 41  -> 7200 + 1219.51 + (20.5 + 25) x 60.48 = 11171.35
     *   Caloocan price 61, L 3, minimum 100: SS 22, Q 100 -> 6100 + 500    + (50 + 22)   x 60.48 = 10954.56
     *   Tondo    price 66, L 7:            SS 33, Q 41  -> 6600 + 1219.51 + (20.5 + 33) x 60.48 = 11055.19
     * One supplier (Caloocan) holds all three tags.
     */
    public function testNameClothByHandWithASupplierMinimumAndOneSupplierHoldingEveryTag(): void
    {
        $result = BestSupplier::compare($this->item(['annual_demand' => 100, 'std_dev' => 7.392]), [
            $this->offer('MAIN', 72, 4, ['is_main' => true]),
            $this->offer('CAL', 61, 3, ['is_sample' => true, 'min_order_qty' => 100]),
            $this->offer('TON', 66, 7, ['is_sample' => true]),
        ]);
        $rows = $this->rowsById($result);

        $this->assertSame(41, $result['inputs']['eoq']);
        $this->assertSame([41, 25, 11171.35], [$rows['MAIN']['q'], $rows['MAIN']['ss'], $rows['MAIN']['yearly_cost']]);
        $this->assertSame([100, 22, 10954.56], [$rows['CAL']['q'], $rows['CAL']['ss'], $rows['CAL']['yearly_cost']]);
        $this->assertSame([41, 33, 11055.19], [$rows['TON']['q'], $rows['TON']['ss'], $rows['TON']['yearly_cost']]);
        $this->assertSame(['Recommended', 'Cheapest', 'Fastest'], $rows['CAL']['tags']);
        $this->assertSame([], $rows['MAIN']['tags']);
        $this->assertSame(216.79, $result['saving_vs_main'], '11171.35 - 10954.56');
    }

    public function testTheMainSuppliersSafetyStockIsTheDssOwnFormula(): void
    {
        $result = BestSupplier::compare($this->item(), [$this->offer('MAIN', 132, 12, ['is_main' => true]), $this->offer('X', 140, 6)]);

        $this->assertSame(DssFormulas::safetyStock(1.645, 3.696, 12), $this->rowsById($result)['MAIN']['ss']);
        $this->assertSame(DssFormulas::safetyStock(1.645, 3.696, 6), $this->rowsById($result)['X']['ss']);
    }

    /**
     * Q is the largest of the EOQ, the system minimum and the supplier's own minimum.
     * D = 10, sigma 0 (SS 0): EOQ = round(sqrt(2 x 10 x 500 / 60.48)) = 13. Price 10, so
     *   Q 13 (system minimum 5):   100 + (10 / 13) x 500 + 6.5 x 60.48 = 877.74
     *   Q 20 (system minimum 20):  100 + (10 / 20) x 500 + 10  x 60.48 = 954.80
     *   Q 50 (supplier minimum 50): 100 + (10 / 50) x 500 + 25  x 60.48 = 1712.00
     */
    public function testQIsTheLargestOfEoqSystemMinimumAndSupplierMinimum(): void
    {
        $base = ['annual_demand' => 10, 'std_dev' => 0.0, 'class' => 'B'];

        $eoq = BestSupplier::compare($this->item($base + ['minimum_order_qty' => 5]), [$this->offer('A', 10, 7), $this->offer('B', 10, 7)]);
        $sys = BestSupplier::compare($this->item($base + ['minimum_order_qty' => 20]), [$this->offer('A', 10, 7), $this->offer('B', 10, 7)]);
        $sup = BestSupplier::compare($this->item($base + ['minimum_order_qty' => 20]), [$this->offer('A', 10, 7, ['min_order_qty' => 50]), $this->offer('B', 10, 7)]);

        $this->assertSame([13, 877.74], [$this->rowsById($eoq)['A']['q'], $this->rowsById($eoq)['A']['yearly_cost']]);
        $this->assertSame([20, 954.8], [$this->rowsById($sys)['A']['q'], $this->rowsById($sys)['A']['yearly_cost']]);
        $this->assertSame([50, 1712.0], [$this->rowsById($sup)['A']['q'], $this->rowsById($sup)['A']['yearly_cost']]);
        $this->assertSame(20, $this->rowsById($sup)['B']['q'], 'Another supplier is not held to this supplier\'s minimum.');
    }

    public function testQIsNeverZeroEvenWithNoMinimumsAndNoHoldingCost(): void
    {
        $result = BestSupplier::compare($this->item(['holding_cost' => 0.0, 'minimum_order_qty' => 0]), [$this->offer('A', 10, 7), $this->offer('B', 11, 7)]);

        $this->assertSame(1, $this->rowsById($result)['A']['q']);
        $this->assertNotNull($this->rowsById($result)['A']['yearly_cost']);
    }

    /** Lead time: the offer's, else the supplier's (resolved by the loader), else 7 days. SS for L = 7: ceil(6.07992 x sqrt(7)) = 17. */
    public function testALeadTimeThatIsNotKnownIsSevenDays(): void
    {
        $result = BestSupplier::compare($this->item(), [$this->offer('A', 100, null), $this->offer('B', 100, 0), $this->offer('C', 100, 7)]);

        foreach ($result['rows'] as $r) {
            $this->assertSame([7, 17], [$r['lead_time_days'], $r['ss']], $r['supplier_id']);
        }
    }

    // ------------------------------------------------------------ tags and ties

    public function testASupplierCanHoldSeveralTagsAndEachTagHasOneWinner(): void
    {
        $result = BestSupplier::compare($this->item(), [
            $this->offer('CHEAP', 90, 10),
            $this->offer('FAST', 120, 2),
            $this->offer('MID', 100, 6),
        ]);

        $this->assertSame('CHEAP', $result['cheapest']);
        $this->assertSame('FAST', $result['fastest']);
        $this->assertContains($result['recommended'], ['CHEAP', 'FAST', 'MID']);
        $this->assertCount(3, array_merge(...array_column($result['rows'], 'tags')), 'Exactly one winner for each of the three tags.');
    }

    public function testAnExactTieGoesToTheMainSupplierEvenWhenAnotherNameSortsFirst(): void
    {
        $result = BestSupplier::compare($this->item(), [
            $this->offer('Z-MAIN', 100, 7, ['is_main' => true, 'name' => 'Zeta Main']),
            $this->offer('A-OTHER', 100, 7, ['name' => 'Alpha Other']),
        ]);

        $this->assertSame(['Z-MAIN', 'Z-MAIN', 'Z-MAIN'], [$result['recommended'], $result['cheapest'], $result['fastest']]);
    }

    public function testAnExactTieBetweenOtherSuppliersGoesToTheNameThenAlphabetically(): void
    {
        $result = BestSupplier::compare($this->item(), [
            $this->offer('MAIN', 150, 7, ['is_main' => true, 'name' => 'Main']),
            $this->offer('B', 100, 7, ['name' => 'Beta']),
            $this->offer('A', 100, 7, ['name' => 'Alpha']),
        ]);

        // Recommended and Cheapest: Alpha and Beta tie exactly and both beat the dearer main supplier, so the name decides.
        // Fastest: all three have a 7-day lead time, an exact tie, so the main supplier keeps it.
        $this->assertSame(['A', 'A', 'MAIN'], [$result['recommended'], $result['cheapest'], $result['fastest']]);
    }

    public function testACheapestTieGoesToTheLowerYearlyCostAndAFastestTieToo(): void
    {
        // Same price, different lead time: the shorter lead needs less safety stock, so it costs less a year and wins Cheapest.
        $cheapest = BestSupplier::compare($this->item(), [$this->offer('SLOW', 100, 14), $this->offer('QUICK', 100, 3)]);
        $this->assertSame('QUICK', $cheapest['cheapest']);

        // Same lead time, different price: the lower price costs less a year and wins Fastest.
        $fastest = BestSupplier::compare($this->item(), [$this->offer('DEAR', 120, 5), $this->offer('LOW', 100, 5)]);
        $this->assertSame('LOW', $fastest['fastest']);
    }

    // ------------------------------------------------------------ only supplier, class A warning

    public function testOneOfferIsOnlySupplierWithNoTags(): void
    {
        $result = BestSupplier::compare($this->item(['class' => 'B']), [$this->offer('MAIN', 132, 12, ['is_main' => true])]);

        $this->assertTrue($result['only_supplier']);
        $this->assertSame('ok', $result['status']);
        $this->assertSame([], $result['rows'][0]['tags']);
        $this->assertNull($result['recommended']);
        $this->assertNotNull($result['rows'][0]['yearly_cost'], 'Its cost is still shown.');
        $this->assertNull($result['single_source_warning'], 'Only class A gets the warning.');
        $this->assertSame('Only supplier', BestSupplier::note($result));
    }

    public function testClassAWithOneSupplierGetsTheSingleSourceWarning(): void
    {
        $result = BestSupplier::compare($this->item(['class' => 'A']), [$this->offer('MAIN', 132, 12, ['is_main' => true])]);

        $this->assertSame('Only one supplier for a fast-moving item.', $result['single_source_warning']);
    }

    public function testTheWarningCountsRealSuppliersOnlySoASampleOfferIsNotASecondSource(): void
    {
        $result = BestSupplier::compare($this->item(['class' => 'A']), [$this->offer('MAIN', 132, 12, ['is_main' => true]), $this->offer('SMP', 139, 5, ['is_sample' => true])]);

        $this->assertFalse($result['only_supplier'], '"Only supplier" counts every offer, samples included.');
        $this->assertSame('Only one real supplier for a fast-moving item (other offers shown are sample data).', $result['single_source_warning']);
        $this->assertSame([1, 1], [$result['real_count'], $result['sample_count']]);
    }

    public function testNoWarningWithTwoRealSuppliersOrForOtherClassesOrNoClass(): void
    {
        $two = [$this->offer('MAIN', 132, 12, ['is_main' => true]), $this->offer('REAL', 140, 5)];

        $this->assertNull(BestSupplier::compare($this->item(['class' => 'A']), $two)['single_source_warning']);
        $this->assertNull(BestSupplier::compare($this->item(['class' => 'B']), [$two[0]])['single_source_warning']);
        $this->assertNull(BestSupplier::compare($this->item(['class' => 'C']), [$two[0]])['single_source_warning']);
        $this->assertNull(BestSupplier::compare($this->item(['class' => null]), [$two[0]])['single_source_warning']);
    }

    // ------------------------------------------------------------ no sales, no price

    public function testNoSalesCannotBeCompared(): void
    {
        $result = BestSupplier::compare($this->item(['annual_demand' => 0, 'class' => 'C']), [$this->offer('MAIN', 132, 12, ['is_main' => true]), $this->offer('SMP', 139, 5, ['is_sample' => true])]);

        $this->assertSame('no_sales', $result['status']);
        $this->assertSame("No sales in the window – can't compare", BestSupplier::note($result));
        $this->assertNull($result['recommended']);
        $this->assertNull($result['saving_vs_main']);
        foreach ($result['rows'] as $r) {
            $this->assertSame([null, null, null, []], [$r['q'], $r['ss'], $r['yearly_cost'], $r['tags']], 'No costs and no tags without sales.');
        }
    }

    public function testAMainSupplierWithNoPriceIsShownButLeftOutOfTheTagsAndTheSaving(): void
    {
        $result = BestSupplier::compare($this->item(), [
            $this->offer('MAIN', 0, 12, ['is_main' => true]),
            $this->offer('X', 139, 5),
            $this->offer('Y', 150, 3),
        ]);
        $rows = $this->rowsById($result);

        $this->assertFalse($rows['MAIN']['priced']);
        $this->assertNull($rows['MAIN']['yearly_cost']);
        $this->assertSame([], $rows['MAIN']['tags']);
        $this->assertSame('ok', $result['status'], 'The rest are still compared.');
        $this->assertNotContains($result['recommended'], ['MAIN']);
        $this->assertSame('Y', $result['fastest'], 'Shortest lead time among the suppliers that have a price.');
        $this->assertNull($result['saving_vs_main'], 'No main price to save against.');
    }

    public function testNoPriceAnywhereIsNothingToCompare(): void
    {
        $result = BestSupplier::compare($this->item(), [$this->offer('MAIN', 0, 12, ['is_main' => true]), $this->offer('X', 0, 5)]);

        $this->assertSame('no_prices', $result['status']);
        $this->assertSame(BestSupplier::NO_PRICES, BestSupplier::note($result));
        $this->assertNull($result['recommended']);
    }

    public function testASampleSupplierCanWinAndIsLabelled(): void
    {
        $result = BestSupplier::compare($this->item(), [
            $this->offer('MAIN', 200, 12, ['is_main' => true]),
            $this->offer('SMP', 100, 3, ['is_sample' => true]),
        ]);
        $rows = $this->rowsById($result);

        $this->assertSame('SMP', $result['recommended']);
        $this->assertTrue($rows['SMP']['is_sample']);
        $this->assertFalse($rows['MAIN']['is_sample']);
    }

    public function testTheSavingIsZeroWhenTheMainSupplierIsAlreadyTheRecommendedOne(): void
    {
        $result = BestSupplier::compare($this->item(), [$this->offer('MAIN', 90, 5, ['is_main' => true]), $this->offer('X', 140, 9)]);

        $this->assertSame('MAIN', $result['recommended']);
        $this->assertSame(0.0, $result['saving_vs_main']);
    }

    // ------------------------------------------------------------ filters and CSV

    public function testTheListFilters(): void
    {
        $results = [
            'a' => ['offer_count' => 2, 'single_source_warning' => null],
            'b' => ['offer_count' => 1, 'single_source_warning' => 'warn'],
            'c' => ['offer_count' => 1, 'single_source_warning' => null],
        ];

        $this->assertSame(['a'], array_keys(BestSupplier::filter($results, 'other')));
        $this->assertSame(['a'], array_keys(BestSupplier::filter($results, 'nonsense')), 'Anything else is the default.');
        $this->assertSame(['b'], array_keys(BestSupplier::filter($results, 'warn')));
        $this->assertSame(['a', 'b', 'c'], array_keys(BestSupplier::filter($results, 'all')));
    }

    public function testTheCsvHasOneLinePerItemAndSupplierAndNeutralisesFormulas(): void
    {
        $compare = BestSupplier::compare($this->item(), [
            $this->offer('MAIN', 132, 12, ['is_main' => true, 'name' => 'Tactical Gear']),
            $this->offer('SMP', 139, 5, ['is_sample' => true, 'name' => '=HYPERLINK("x")']),
        ]) + ['item_id' => '0181', 'item_name' => 'Pepper Spray', 'size' => null, 'class' => 'A'];

        $csv   = BestSupplier::csv(['0181' => $compare]);
        $lines = array_map('str_getcsv', array_values(array_filter(explode("\n", ltrim($csv, "\xEF\xBB\xBF")), static fn ($l) => trim($l) !== '')));

        $this->assertSame('Item ID', $lines[0][0]);
        $this->assertCount(1 + 2 + 1, $lines, 'The header, one line per supplier, then the Basis note.');
        $this->assertSame(['0181', 'Pepper Spray', '—', 'A', '50', 'Tactical Gear', 'Main supplier', '', '132.00', '12', '', '29', '22', '9669.59', 'Cheapest'], array_slice($lines[1], 0, 15));
        $this->assertSame('Sample', $lines[2][7]);
        $this->assertSame("'=HYPERLINK(\"x\")", $lines[2][5], 'A leading = is neutralised.');
        $this->assertStringContainsString('Only one real supplier for a fast-moving item', $lines[1][15]);
        $this->assertSame('Basis', $lines[3][0]);
    }
}
