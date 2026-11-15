<?php

use App\Controllers\Admin\TrendAnalysisController;
use App\Libraries\TrendReport;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ReorderFixture;

/**
 * Trend Analysis > Overview: a month with no recorded sales is a gap in the current-year line (not a 0),
 * and "vs. same period last year" compares only the months that have recorded sales with the same months
 * last year, saying which ones. Runs on the in-memory 'tests' database.
 *
 * @internal
 */
final class TrendOverviewGapTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ReorderFixture;

    protected $migrate = false;
    protected $refresh = false;

    private string $originalPrefix = '';
    private int $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPrefix = $this->db->DBPrefix;
        $this->db->setPrefix(''); // Trend Analysis runs raw SQL with no table prefix
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
        $make('sales_transaction', ['sales_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'receipt_no' => $str, 'or_number' => $str, 'user_id' => $int, 'sale_date' => $when, 'payment_method' => $str, 'cash_amount' => $real, 'gcash_amount' => $real, 'gcash_reference_no' => $str, 'subtotal' => $real, 'discount' => $real, 'total_amount' => $real], 'sales_id');
        $make('sales_item', ['sales_item_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'sales_id' => $int, 'item_id' => $str, 'quantity_sold' => $int, 'selling_price' => $real, 'is_bulk' => ['type' => 'INTEGER', 'default' => 0]], 'sales_item_id');
        $make('app_settings', ['key' => $str, 'value' => $str], 'key');

        $this->addSize('P-1', 'Pants', 'S', 10, 3);
        $this->addSize('P-2', 'Shirt', 'M', 10, 3);
        $this->year = (int) date('Y') - 1; // a whole past year: every month has happened, none is in progress
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

    private function sale(int $year, int $month, string $item, int $qty): void
    {
        $this->db->table('sales_transaction')->insert(['receipt_no' => (string) random_int(1000, 9999999), 'user_id' => $this->staffId, 'sale_date' => sprintf('%d-%02d-10 10:00:00', $year, $month), 'payment_method' => 'Cash', 'cash_amount' => 1, 'subtotal' => 1, 'total_amount' => 1]);
        $this->db->table('sales_item')->insert(['sales_id' => (int) $this->db->insertID(), 'item_id' => $item, 'quantity_sold' => $qty, 'selling_price' => 1]);
    }

    /** Year Y-1: P-1 sells 100 a month in Jan-Jul; year Y-2: P-1 sells 50 a month all year round. P-2: 7 in Jan and Jul of Y-1. */
    private function seedJanToJul(): void
    {
        foreach (range(1, 7) as $m) {
            $this->sale($this->year, $m, 'P-1', 100);
        }
        foreach ([1, 7] as $m) {
            $this->sale($this->year, $m, 'P-2', 7);
        }
        foreach (range(1, 12) as $m) {
            $this->sale($this->year - 1, $m, 'P-1', 50);
        }
    }

    private function page(string $query): string
    {
        return (string) $this->asOwner()->get('admin/trend-analysis?' . $query)->response()->getBody();
    }

    /** The current-year series the page plots. */
    private function plotted(string $html): array
    {
        $this->assertSame(1, preg_match('/current: (\[[^\]]*\])/', $html, $m));

        return json_decode($m[1], true);
    }

    private function html(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        return new \DOMXPath($dom);
    }

    // ---------------------------------------------------------------- the gap

    public function testTheChartLineHasGapsWhereTheShopRecordedNothing(): void
    {
        $this->seedJanToJul();

        $series = $this->plotted($this->page("year={$this->year}"));

        $this->assertSame([107, 100, 100, 100, 100, 100, 107], array_slice($series, 0, 7), 'Jan-Jul: Jan and Jul also have the 7 of P-2.');
        $this->assertSame(array_fill(0, 5, null), array_slice($series, 7), 'Aug-Dec: no sales recorded, so a gap (null), not 0.');
    }

    public function testAnItemsQuietMonthInARecordedMonthIsStillAZero(): void
    {
        $this->seedJanToJul();

        $series = $this->plotted($this->page("year={$this->year}&item=P-2"));

        $this->assertSame([7, 0, 0, 0, 0, 0, 7], array_slice($series, 0, 7), 'The shop sold in Feb-Jun (P-1), so P-2 really sold 0.');
        $this->assertSame(array_fill(0, 5, null), array_slice($series, 7), 'Nobody sold anything after July: a gap.');
    }

    public function testThePdfAndTheOtherCalculationsStillSeeTheZeroMonths(): void
    {
        $this->seedJanToJul();
        $_GET['year'] = (string) $this->year;
        Services::resetSingle('request');
        $controller = new TrendAnalysisController();
        $controller->initController(service('request'), service('response'), service('logger'));
        $build = new \ReflectionMethod($controller, 'buildData');
        $build->setAccessible(true);
        $d = $build->invoke($controller);
        unset($_GET['year']);

        $this->assertSame(0, $d['annualData'][8], 'annualData (insights, PDF rows) is unchanged: August is 0.');
        $this->assertNull($d['annualChart'][8], 'Only the page chart has the gap.');
    }

    // ---------------------------------------------------------------- the comparison

    public function testTheCardComparesOnlyTheMonthsWithRecordedSalesAndSaysWhich(): void
    {
        $this->seedJanToJul();

        $x = $this->html($this->page("year={$this->year}"));

        $sub = trim(preg_replace('/\s+/', ' ', $x->query('//*[@id="compareMonths"]')->item(0)->textContent));
        $this->assertSame('Compared Jan–Jul · 350 items in ' . ($this->year - 1), $sub, 'Jan-Jul of the year before: 7 x 50');
        // this year Jan-Jul: 107 + 100 x 5 + 107 = 714 against 350 in the same months last year: +104.0%
        $this->assertSame('104.0', $x->query('//div[contains(@class,"ta-change")]//span[@data-count]')->item(0)->getAttribute('data-count'));
        $this->assertStringContainsString('vs. same period last year', $x->query('//div[contains(@class,"ta-card")][.//*[@id="compareMonths"]]')->item(0)->textContent);
    }

    public function testTheComparisonSkipsAMonthWithNoSalesInTheMiddle(): void
    {
        foreach ([1, 2, 3, 5, 6] as $m) { // nothing in April
            $this->sale($this->year, $m, 'P-1', 100);
            $this->sale($this->year - 1, $m, 'P-1', 40);
        }
        $this->sale($this->year - 1, 4, 'P-1', 1000); // last year's April must not be counted

        $x = $this->html($this->page("year={$this->year}"));

        $this->assertSame('Compared Jan–Mar, May–Jun · 200 items in ' . ($this->year - 1), trim(preg_replace('/\s+/', ' ', $x->query('//*[@id="compareMonths"]')->item(0)->textContent)));
        $this->assertSame('150.0', $x->query('//div[contains(@class,"ta-change")]//span[@data-count]')->item(0)->getAttribute('data-count'), '500 against 200');
    }

    public function testTheMonthStillInProgressIsLeftOutOfTheComparison(): void
    {
        $now = (int) date('n');
        if ($now < 3) {
            $this->markTestSkipped('Needs a complete month before the month in progress.');
        }
        $year = (int) date('Y');
        $this->sale($year, 1, 'P-1', 100);
        $this->sale($year, $now, 'P-1', 5);           // the month in progress: part of it only
        $this->sale($year - 1, 1, 'P-1', 100);
        $this->sale($year - 1, $now, 'P-1', 999);     // last year's whole month: not a fair comparison

        $series = $this->plotted($this->page("year={$year}"));
        $x      = $this->html($this->page("year={$year}"));

        $this->assertSame(5, $series[$now - 1], 'The month in progress is still on the chart.');
        $this->assertSame('Compared Jan · 100 items in ' . ($year - 1), trim(preg_replace('/\s+/', ' ', $x->query('//*[@id="compareMonths"]')->item(0)->textContent)));
        $this->assertSame('0.0', $x->query('//div[contains(@class,"ta-change")]//span[@data-count]')->item(0)->getAttribute('data-count'), 'Jan against Jan: 100 and 100.');
    }

    public function testNothingToCompareWithIsSaidPlainly(): void
    {
        foreach ([1, 2] as $m) {
            $this->sale($this->year, $m, 'P-1', 10); // nothing at all the year before
        }
        $x = $this->html($this->page("year={$this->year}"));

        $card = preg_replace('/\s+/', ' ', $x->query('//div[contains(@class,"ta-card")][.//*[contains(text(),"vs. same period last year")]]')->item(0)->textContent);
        $this->assertStringContainsString('No sales last year', $card);
        $this->assertStringContainsString('Compared Jan–Feb', $card);
    }

    public function testNoSalesAtAllHasNoMonthToCompare(): void
    {
        $card = preg_replace('/\s+/', ' ', $this->html($this->page("year={$this->year}"))->query('//div[contains(@class,"ta-card")][.//*[contains(text(),"vs. same period last year")]]')->item(0)->textContent);

        $this->assertStringContainsString('No full month yet', $card);
        $this->assertStringContainsString('No month with recorded sales to compare', $card);
    }

    public function testTheMonthLabels(): void
    {
        $this->assertSame('Jan–Jul', TrendAnalysisController::monthRuns([1, 2, 3, 4, 5, 6, 7]));
        $this->assertSame('Jan–Mar, May–Jun', TrendAnalysisController::monthRuns([1, 2, 3, 5, 6]));
        $this->assertSame('Apr', TrendAnalysisController::monthRuns([4]));
        $this->assertSame('Jan, Mar, Dec', TrendAnalysisController::monthRuns([12, 1, 3]));
        $this->assertSame('', TrendAnalysisController::monthRuns([]));
    }

    public function testThePdfCardUsesTheSameMonthsAndNumbers(): void
    {
        $d = [
            'selectedYear' => 2026, 'selectedItem' => null, 'itemLabel' => null, 'generatedAt' => 'x', 'lastSaleAt' => null, 'dssRunAt' => null,
            'annualLabels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            'annualData' => [10, 20, 0, 0, 0, 0, 0, 0, 0, 0, 0, null], 'annualDataPrev' => array_fill(0, 12, 10), 'monthsSoFar' => 11,
            'unitsYtd' => 30, 'unitsPrev' => 110, 'changePct' => -72.7, 'periodEnd' => '2026-11-10', 'periodLabel' => 'Jan 1 – Nov 10, 2026', 'prevPeriodLabel' => 'Jan 1 – Nov 10, 2025',
            'bestMonth' => null, 'topItem' => null, 'weeks12' => array_map(static fn ($i) => ['label' => "Week {$i}", 'short' => "W{$i}", 'units' => $i], range(1, 12)), 'topSellers' => [['item_id' => 'P-01', 'item_name' => 'Patrol Shirt', 'units_sold' => 50]], 'abcClassCounts' => ['A' => 1, 'B' => 0, 'C' => 0], 'abcClassTotals' => ['A' => 100.0, 'B' => 0.0, 'C' => 0.0], 'abcUnclassified' => 0,
            'abcByClass' => ['A' => [['rank' => 1, 'label' => 'Patrol Shirt (M)', 'code' => 'X', 'show_code' => false, 'pct' => 100.0]], 'B' => [], 'C' => []], 'variabilityAll' => [],
            'compareMonths' => [1, 2], 'compareMonthsLabel' => 'Jan–Feb', 'compareUnits' => 30, 'comparePrevUnits' => 20, 'comparePct' => 50.0,
        ];

        $card = TrendReport::build($d)['cards'][1];

        $this->assertSame('vs. same period last year', $card['label']);
        $this->assertSame('+50.0%', $card['value']);
        $this->assertSame('20 items, Jan–Feb 2025', $card['sub']);
        $this->assertStringContainsString('Compared with the same months last year (Jan–Feb 2025), sales were 50.0% higher.', $card['sentence']);
        $this->assertStringContainsString('only the months of 2026 that have recorded sales', $card['sentence']);
    }
}
