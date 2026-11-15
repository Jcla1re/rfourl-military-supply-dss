<?php

use App\Libraries\DemandProjection;
use App\Libraries\TrendReport;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ReorderFixture;

/**
 * Trend Analysis > Projection: expected items sold per month next year from plain averages of past
 * years (no machine learning). The averaging, the months that are left out, the item filter, the
 * labels on the page and the PDF section. Runs on the in-memory 'tests' database.
 *
 * @internal
 */
final class DemandProjectionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ReorderFixture;

    protected $migrate = false;
    protected $refresh = false;

    private string $originalPrefix = '';

    protected function setUp(): void
    {
        parent::setUp();
        // Trend Analysis runs raw SQL ("FROM sales_item") with no table prefix.
        $this->originalPrefix = $this->db->DBPrefix;
        $this->db->setPrefix('');
        $this->setUpReorderFixture();
        Services::resetSingle('renderer');
        $this->db->connID->createFunction('YEAR', static fn ($d) => $d === null ? null : (int) substr((string) $d, 0, 4), 1);
        $this->db->connID->createFunction('MONTH', static fn ($d) => $d === null ? null : (int) substr((string) $d, 5, 2), 1);

        $forge = \Config\Database::forge();
        foreach (['sales_transaction', 'sales_item', 'app_settings'] as $t) {
            $forge->dropTable($t, true);
        }
        $str  = ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true];
        $real = ['type' => 'REAL', 'default' => 0];
        $int  = ['type' => 'INTEGER', 'null' => true];
        $when = ['type' => 'DATETIME', 'null' => true];
        $make = static function (string $name, array $fields, string $key) use ($forge) {
            $forge->addField($fields);
            $forge->addKey($key, true);
            $forge->createTable($name);
        };
        $make('sales_transaction', ['sales_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'receipt_no' => $str, 'or_number' => $str, 'user_id' => $int, 'sale_date' => $when, 'payment_method' => $str, 'cash_amount' => $real, 'gcash_amount' => $real, 'gcash_reference_no' => $str, 'subtotal' => $real, 'discount' => $real, 'total_amount' => $real] + \Tests\Support\SalesVoidColumns::FIELDS, 'sales_id');
        $make('sales_item', ['sales_item_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'sales_id' => $int, 'item_id' => $str, 'quantity_sold' => $int, 'selling_price' => $real, 'is_bulk' => ['type' => 'INTEGER', 'default' => 0]], 'sales_item_id');
        $make('app_settings', ['key' => $str, 'value' => $str], 'key');

        $this->addSize('P-1', 'Pants', 'S', 10, 3);   // the DSS run gave it a safety stock of 1 (ReorderFixture::addSize)
        $this->addSize('P-2', 'Shirt', 'M', 10, 3);
    }

    protected function tearDown(): void
    {
        $forge = \Config\Database::forge();
        foreach (['sales_transaction', 'sales_item', 'app_settings'] as $t) {
            $forge->dropTable($t, true);
        }
        $this->tearDownReorderFixture();
        $this->db->setPrefix($this->originalPrefix);
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers (no database)

    /** Months with sales in every month from $from to $to ('YYYY-MM'), as the shop's "has data" set. */
    private static function shop(string $from, string $to): array
    {
        $set = [];
        for ($t = strtotime($from . '-01'); date('Y-m', $t) <= $to; $t = strtotime('+1 month', $t)) {
            $set[date('Y-m', $t)] = true;
        }

        return $set;
    }

    private static function month(array $months, int $m): array
    {
        return $months[$m - 1];
    }

    // ---------------------------------------------------------------- the averaging

    public function testAMonthIsTheAverageOfTheLastThreeCompleteYears(): void
    {
        // March: 2022 is partial (data starts Mar 15), then 100, 130, 160, 190.
        $units  = [2022 => [3 => 999], 2023 => [3 => 100], 2024 => [3 => 130], 2025 => [3 => 160], 2026 => [3 => 190]];
        $months = DemandProjection::project($units, self::shop('2022-03', '2026-10'), '2022-03-15', '2026-10-31', 2027);

        $mar = self::month($months, 3);
        $this->assertSame(160.0, $mar['projection'], '(190 + 160 + 130) / 3');
        $this->assertSame([130, 190], [$mar['min'], $mar['max']], 'The band is the lowest and highest of those years.');
        $this->assertSame([2026, 2025, 2024], $mar['years'], 'Newest first; 2023 is older than the last three, 2022 is partial.');
    }

    public function testAnAverageOfFewerThanThreeYearsUsesWhatThereIs(): void
    {
        $units  = [2025 => [1 => 40], 2026 => [1 => 60]];
        $months = DemandProjection::project($units, self::shop('2025-01', '2026-10'), '2025-01-01', '2026-10-31', 2027);

        $jan = self::month($months, 1);
        $this->assertSame(50.0, $jan['projection']);
        $this->assertSame([40, 60], [$jan['min'], $jan['max']]);
        $this->assertSame([2026, 2025], $jan['years']);
    }

    public function testAZeroMonthInACompleteYearIsRealDataAndPullsTheAverageDown(): void
    {
        // The shop sold in every month, this item did not sell in 2025.
        $units  = [2024 => [5 => 90], 2026 => [5 => 30]];
        $months = DemandProjection::project($units, self::shop('2024-01', '2026-10'), '2024-01-01', '2026-10-31', 2027);

        $this->assertSame(40.0, self::month($months, 5)['projection'], '(30 + 0 + 90) / 3');
    }

    // ---------------------------------------------------------------- months that are left out

    public function testMonthsAfterTheLastRecordedSaleAndThePartialMonthAreLeftOut(): void
    {
        // The last sale was on Nov 11 2026: November 2026 is partial, December 2026 has not happened.
        $units = [];
        foreach ([2023, 2024, 2025, 2026] as $y) {
            foreach (range(1, 12) as $m) {
                $units[$y][$m] = ($y - 2000) * 10 + $m;
            }
        }
        $months = DemandProjection::project($units, self::shop('2023-01', '2026-11'), '2023-01-01', '2026-11-11', 2027);

        $this->assertSame([2025, 2024, 2023], self::month($months, 11)['years'], 'Nov 2026 is partial.');
        $this->assertSame([2025, 2024, 2023], self::month($months, 12)['years'], 'Dec 2026 is after the last sale.');
        $this->assertSame([2026, 2025, 2024], self::month($months, 10)['years'], 'Oct 2026 is complete.');
        $this->assertEqualsWithDelta(((25 * 10 + 11) + (24 * 10 + 11) + (23 * 10 + 11)) / 3, self::month($months, 11)['projection'], 0.001);
    }

    public function testTheLastMonthCountsWhenTheLastSaleWasOnItsLastDay(): void
    {
        $this->assertTrue(DemandProjection::isCompleteMonth(2026, 10, self::shop('2023-01', '2026-10'), '2023-01-01', '2026-10-31'));
        $this->assertFalse(DemandProjection::isCompleteMonth(2026, 10, self::shop('2023-01', '2026-10'), '2023-01-01', '2026-10-30'));
    }

    public function testTheFirstMonthIsPartialUnlessTheFirstSaleWasOnThe1st(): void
    {
        $shop = self::shop('2024-03', '2026-10');

        $this->assertFalse(DemandProjection::isCompleteMonth(2024, 3, $shop, '2024-03-02', '2026-10-31'));
        $this->assertTrue(DemandProjection::isCompleteMonth(2024, 3, $shop, '2024-03-01', '2026-10-31'));
        $this->assertTrue(DemandProjection::isCompleteMonth(2024, 4, $shop, '2024-03-20', '2026-10-31'));
    }

    public function testAMonthWithNoSalesAtAllIsNotDataAndIsSkipped(): void
    {
        $shop = self::shop('2023-01', '2026-10');
        unset($shop['2025-05']); // the shop was closed
        $units  = [2023 => [5 => 10], 2024 => [5 => 20], 2025 => [5 => 0], 2026 => [5 => 30]];
        $months = DemandProjection::project($units, $shop, '2023-01-01', '2026-10-31', 2027);

        $this->assertSame([2026, 2024, 2023], self::month($months, 5)['years']);
        $this->assertSame(20.0, self::month($months, 5)['projection']);
    }

    public function testAMonthWithNoCompleteYearHasNoProjection(): void
    {
        // Only Jan-Oct 2026 are on record: Nov and Dec have nothing to average.
        $units  = [2026 => [1 => 50, 11 => 5]];
        $months = DemandProjection::project($units, self::shop('2026-01', '2026-11'), '2026-01-01', '2026-11-05', 2027);

        $this->assertSame(50.0, self::month($months, 1)['projection']);
        $this->assertNull(self::month($months, 11)['projection'], 'November 2026 is partial.');
        $this->assertNull(self::month($months, 12)['projection']);
        $this->assertSame([null, null, []], [self::month($months, 12)['min'], self::month($months, 12)['max'], self::month($months, 12)['years']]);
    }

    public function testNoSalesAtAllMeansNoProjection(): void
    {
        $p = DemandProjection::assemble([], [], null, null, 2026);

        $this->assertFalse($p['hasData']);
        $this->assertSame([], $p['peaks']);
        $this->assertSame(array_fill(0, 12, null), $p['projection']);
    }

    // ---------------------------------------------------------------- the top 3 months and the suggestion

    private function yearsOfData(): array
    {
        // 2023-2025 complete. Month m sells m * 10 in every year, except March (400) and January (200).
        $units = [];
        foreach ([2023, 2024, 2025] as $y) {
            foreach (range(1, 12) as $m) {
                $units[$y][$m] = $m * 10;
            }
            $units[$y][3] = 400;
            $units[$y][1] = 200;
        }

        return $units;
    }

    public function testTheTopThreeProjectedMonthsAndWhenToOrder(): void
    {
        $p = DemandProjection::assemble($this->yearsOfData(), self::shop('2023-01', '2025-12'), '2023-01-01', '2025-12-31', 2025);

        $this->assertSame(2026, $p['targetYear']);
        $this->assertSame(['March', 'January', 'December'], array_column($p['peaks'], 'name'), '400, 200, 120');
        $this->assertSame([
            'Expected peak in March: order before February so stock arrives in time.',
            'Expected peak in January: order before December so stock arrives in time.',
            'Expected peak in December: order before November so stock arrives in time.',
        ], array_column($p['peaks'], 'text'), 'January wraps to the December before it.');
        $this->assertNull($p['suggestion'], 'No item chosen: no suggested stock.');
    }

    public function testEqualMonthsGoToTheEarlierOne(): void
    {
        $units = [2024 => array_fill(1, 12, 50), 2025 => array_fill(1, 12, 50)];
        $p     = DemandProjection::assemble($units, self::shop('2024-01', '2025-12'), '2024-01-01', '2025-12-31', 2025);

        $this->assertSame(['January', 'February', 'March'], array_column($p['peaks'], 'name'));
    }

    public function testAnItemGetsSuggestedStockForItsPeakMonth(): void
    {
        $p = DemandProjection::assemble($this->yearsOfData(), self::shop('2023-01', '2025-12'), '2023-01-01', '2025-12-31', 2025, 12.0, 'Pants (S)');

        $this->assertSame('Suggested stock for March: about 412 pieces (projected sales + safety stock).', $p['suggestion']['text'], '400 projected + 12 safety stock');
        $this->assertSame([412, 12, true, 'March'], [$p['suggestion']['pieces'], $p['suggestion']['safety_stock'], $p['suggestion']['has_safety_stock'], $p['suggestion']['month']]);
    }

    public function testAnItemWithoutASafetyStockGetsOnlyTheProjectedSales(): void
    {
        $p = DemandProjection::assemble($this->yearsOfData(), self::shop('2023-01', '2025-12'), '2023-01-01', '2025-12-31', 2025, null, 'Pants (S)');

        $this->assertSame('Suggested stock for March: about 400 pieces (projected sales + safety stock).', $p['suggestion']['text']);
        $this->assertFalse($p['suggestion']['has_safety_stock']);
    }

    public function testTheCurrentYearsActualShowsWholeMonthsOnly(): void
    {
        $units = [2025 => [10 => 80], 2026 => [1 => 10, 2 => 20, 10 => 70, 11 => 7]];
        $p     = DemandProjection::assemble($units, self::shop('2025-01', '2026-11'), '2025-01-01', '2026-11-11', 2026);

        $this->assertSame([10, 20, 0, 0, 0, 0, 0, 0, 0, 70, null, null], array_map(static fn ($v) => $v, $p['actual']), 'Nov 2026 is partial, Dec has not happened: both empty, not zero.');
    }

    public function testTheLabels(): void
    {
        $p = DemandProjection::assemble($this->yearsOfData(), self::shop('2023-01', '2025-12'), '2023-01-01', '2025-12-31', 2025);

        $this->assertSame('Projection (average of past years)', $p['seriesName']);
        $this->assertSame(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], $p['labels']);
        $this->assertStringContainsString('last 3 complete years', $p['method']);
        $this->assertStringContainsString('partial months', $p['method']);
        $this->assertSame(0, substr_count($p['method'], '. '), 'One sentence.');
        $this->assertStringEndsWith('.', $p['method']);
    }

    // ---------------------------------------------------------------- the database (and the item filter)

    /**
     * Whole years Y-3..Y-1 (first sale on Jan 1 of Y-3, last on Dec 31 of Y-1).
     * P-1 sells 10*m, 20*m, 30*m in month m of those years (average 20*m: December 240 is its peak);
     * P-2 sells 5 every month (its peak is January: all months tie).
     */
    private function seedYears(): int
    {
        $current = (int) date('Y');
        $sale    = function (string $when, string $item, int $qty) {
            $this->db->table('sales_transaction')->insert(['receipt_no' => (string) random_int(1000, 999999), 'user_id' => $this->staffId, 'sale_date' => $when, 'payment_method' => 'Cash', 'cash_amount' => 1, 'subtotal' => 1, 'total_amount' => 1]);
            $this->db->table('sales_item')->insert(['sales_id' => (int) $this->db->insertID(), 'item_id' => $item, 'quantity_sold' => $qty, 'selling_price' => 1]);
        };
        foreach ([1 => 10, 2 => 20, 3 => 30] as $back => $factor) {
            $y = $current - 4 + $back; // Y-3, Y-2, Y-1
            foreach (range(1, 12) as $m) {
                $day = $m === 1 && $y === $current - 3 ? '01' : '15';
                $sale(sprintf('%d-%02d-%s 10:00:00', $y, $m, $day), 'P-1', $factor * $m);
                $sale(sprintf('%d-%02d-%s 11:00:00', $y, $m, $day), 'P-2', 5);
            }
        }
        $sale(($current - 1) . '-12-31 18:00:00', 'P-2', 0); // the last day of the last year: its December is complete

        return $current;
    }

    public function testTheItemFilterProjectsOnlyThatItem(): void
    {
        $current = $this->seedYears();

        $all = DemandProjection::build(null);
        $p1  = DemandProjection::build('P-1', 1.0, 'Pants (S)');
        $p2  = DemandProjection::build('P-2', null, 'Shirt (M)');

        $this->assertSame($current + 1, $p1['targetYear']);
        $this->assertSame(20.0 * 12, $p1['months'][11]['projection'], 'P-1 in December: (120 + 240 + 360) / 3 ... average of 10m, 20m, 30m');
        $this->assertSame([120, 360], [$p1['months'][11]['min'], $p1['months'][11]['max']]);
        $this->assertSame(5.0, $p2['months'][11]['projection'], 'P-2 sells 5 a month.');
        $this->assertSame(20.0 * 12 + 5, $all['months'][11]['projection'], 'All items add the two.');
        $this->assertSame(['December', 'November', 'October'], array_column($p1['peaks'], 'name'));
        $this->assertSame(['January', 'February', 'March'], array_column($p2['peaks'], 'name'), 'Equal months: the earlier first.');
        $this->assertSame('Suggested stock for December: about 241 pieces (projected sales + safety stock).', $p1['suggestion']['text'], '240 + 1 safety stock');
        $this->assertNull($all['suggestion'], 'Only with an item chosen.');
        $this->assertSame('Pants (S)', $p1['item']);
    }

    // ---------------------------------------------------------------- the page

    private function page(string $query): string
    {
        return (string) $this->asOwner()->get('admin/trend-analysis?' . $query)->response()->getBody();
    }

    private function html(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        return new \DOMXPath($dom);
    }

    public function testTheProjectionTabShowsTheChartTheTopThreeMonthsAndTheMethod(): void
    {
        $current = $this->seedYears();
        $html    = $this->page('tab=projection');
        $x       = $this->html($html);

        $tabs = array_map(static fn ($a) => trim($a->textContent), iterator_to_array($x->query('//nav[contains(@class,"ta-tabs")]/a')));
        $this->assertContains('Projection', $tabs);
        $this->assertFalse($x->query('//section[@data-panel="projection"]')->item(0)->hasAttribute('hidden'));
        $this->assertNotNull($x->query('//section[@data-panel="projection"]//canvas[@id="projectionChart"]')->item(0));
        $this->assertStringContainsString('Expected items sold per month in ' . ($current + 1), $x->query('//h6[@id="projTitle"]')->item(0)->textContent);
        $this->assertStringContainsString('Projection (average of past years)', $html);
        $this->assertStringContainsString('last 3 complete years', $x->query('//*[@id="projMethod"]')->item(0)->textContent);

        $peaks = array_map(static fn ($li) => trim(preg_replace('/\s+/', ' ', $li->textContent)), iterator_to_array($x->query('//ol[@id="projPeaks"]/li')));
        $this->assertCount(3, $peaks);
        $this->assertStringStartsWith('Expected peak in December: order before November so stock arrives in time.', $peaks[0]);
        $this->assertStringStartsWith('Expected peak in November: order before October so stock arrives in time.', $peaks[1]);
        $this->assertNull($x->query('//*[@id="projSuggest"]')->item(0), 'No item chosen: no suggested stock.');
        $this->assertStringContainsString('"seriesName":"Projection (average of past years)"', $html, 'The chart data carries its label.');
    }

    public function testChoosingAnItemProjectsItAndSuggestsStock(): void
    {
        $this->seedYears();
        $x = $this->html($this->page('tab=projection&item=P-1'));

        $this->assertStringContainsString('Pants · S', $x->query('//h6[@id="projTitle"]')->item(0)->textContent);
        $this->assertStringContainsString('Suggested stock for December: about 241 pieces (projected sales + safety stock).', preg_replace('/\s+/', ' ', $x->query('//*[@id="projSuggest"]')->item(0)->textContent));
        $second = $this->html($this->page('tab=projection&item=P-2'));
        $this->assertStringStartsWith('Expected peak in January', trim($second->query('//ol[@id="projPeaks"]/li')->item(0)->textContent));
        $this->assertStringContainsString('Suggested stock for January: about 6 pieces', preg_replace('/\s+/', ' ', $second->query('//*[@id="projSuggest"]')->item(0)->textContent), '5 + the fixture item\'s safety stock of 1');
    }

    public function testWithoutHistoryThePageSaysThereIsNotEnough(): void
    {
        $x = $this->html($this->page('tab=projection'));

        $this->assertStringContainsString('Not enough sales history yet to project', $x->query('//*[@id="projEmpty"]')->item(0)->textContent);
        $this->assertNull($x->query('//canvas[@id="projectionChart"]')->item(0));
    }

    public function testOtherTabsStillWork(): void
    {
        $this->seedYears();

        foreach (['overview', 'products', 'abc', 'daily'] as $tab) {
            $panels = $this->html($this->page('tab=' . $tab))->query('//section[contains(@class,"ta-panel") and not(@hidden)]');
            $this->assertSame(1, $panels->length, $tab);
            $this->assertSame($tab, $panels->item(0)->getAttribute('data-panel'));
        }
    }

    // ---------------------------------------------------------------- the PDF

    private function pageData(array $projection): array
    {
        $year = (int) date('Y');

        return [
            'selectedYear' => $year, 'selectedItem' => null, 'itemLabel' => null, 'generatedAt' => 'Nov 15, 2026 9:00 AM',
            'lastSaleAt' => "{$year}-08-21 10:00:00", 'dssRunAt' => "{$year}-11-05 02:00:00",
            'annualLabels' => DemandProjection::MONTH_SHORT,
            'annualData' => [100, 200, 50, 0, 0, 0, 0, 0, 0, 0, 0, null], 'annualDataPrev' => array_fill(0, 12, 100), 'monthsSoFar' => 11,
            'unitsYtd' => 350, 'unitsPrev' => 2300, 'changePct' => -84.78, 'periodEnd' => "{$year}-11-07",
            'periodLabel' => "Jan 1 – Nov 7, {$year}", 'prevPeriodLabel' => 'Jan 1 – Nov 7, ' . ($year - 1),
            'bestMonth' => ['name' => 'February', 'units' => 200], 'topItem' => ['item_id' => 'P-01', 'item_name' => 'Patrol Shirt', 'units_sold' => 120],
            'weeks12' => array_map(static fn ($i) => ['label' => "Week {$i}", 'short' => "W{$i}", 'units' => $i * 3], range(1, 12)),
            'topSellers' => [['item_id' => 'P-01', 'item_name' => 'Patrol Shirt', 'units_sold' => 50]],
            'abcClassCounts' => ['A' => 1, 'B' => 0, 'C' => 0], 'abcClassTotals' => ['A' => 100.0, 'B' => 0.0, 'C' => 0.0], 'abcUnclassified' => 0,
            'abcByClass' => ['A' => [['rank' => 1, 'label' => 'Patrol Shirt (M)', 'code' => 'X', 'show_code' => false, 'pct' => 100.0]], 'B' => [], 'C' => []],
            'variabilityAll' => [], 'projection' => $projection,
        ];
    }

    public function testThePdfHasAProjectionSectionWithTheMethodInOneSentence(): void
    {
        $projection = DemandProjection::assemble($this->yearsOfData(), self::shop('2023-01', '2025-12'), '2023-01-01', '2025-12-31', 2025, 12.0, 'Pants (S)');
        $report     = TrendReport::build($this->pageData($projection));
        $section    = $report['projection'];

        $this->assertSame('Projection for 2026 · Pants (S)', $section['title']);
        $this->assertSame($projection['method'], $section['method']);
        $this->assertCount(12, $section['rows']);
        $this->assertSame(['month' => 'March', 'proj' => '400', 'range' => '400 – 400', 'years' => '2023, 2024, 2025'], $section['rows'][2]);
        $this->assertSame(array_column($projection['peaks'], 'text'), $section['peaks']);
        $this->assertSame('Suggested stock for March: about 412 pieces (projected sales + safety stock).', $section['suggestion']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $section['chart']);
        $svg = base64_decode(substr($section['chart'], strlen('data:image/svg+xml;base64,')));
        $this->assertStringContainsString('Projection (average of past years)', $svg, 'The chart legend.');

        $html = view('admin/trend_analysis_pdf', $this->pageData($projection) + ['report' => $report]);
        $this->assertStringContainsString('<h2>Projection for 2026 · Pants (S)</h2>', $html);
        $this->assertStringContainsString('Method: ' . $projection['method'], $html);
        $this->assertStringContainsString('Expected peak in March: order before February so stock arrives in time.', $html);
        $this->assertStringContainsString('Suggested stock for March: about 412 pieces', $html);
    }

    public function testThePdfSaysWhenThereIsNothingToProject(): void
    {
        $report = TrendReport::build($this->pageData(DemandProjection::assemble([], [], null, null, 2026)));

        $this->assertFalse($report['projection']['hasData']);
        $this->assertNull($report['projection']['chart']);
        $this->assertStringContainsString('Not enough sales history yet to project', view('admin/trend_analysis_pdf', $this->pageData(DemandProjection::assemble([], [], null, null, 2026)) + ['report' => $report]));
    }

    public function testTheTrendPdfStillRendersEndToEnd(): void
    {
        $this->seedYears();

        $result = $this->asOwner()->get('admin/trend-analysis/pdf?item=P-1');

        $result->assertStatus(200);
        $this->assertStringStartsWith('%PDF', $result->response()->getBody());
    }
}
