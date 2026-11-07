<?php

use App\Libraries\PdfCharts;
use App\Libraries\TrendReport;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The words and the charts of the Trend Analysis PDF, without a database or a PDF engine.
 *
 * @internal
 */
final class TrendReportTest extends CIUnitTestCase
{
    private function data(array $current, array $previous, int $sofar, int $year, string $periodEnd): array
    {
        return ['annualData' => $current, 'annualDataPrev' => $previous, 'monthsSoFar' => $sofar, 'periodEnd' => $periodEnd, 'selectedYear' => $year];
    }

    // ---------------------------------------------------------------- "What this shows"

    public function testInsightsNameTheBestAndWeakestMonthTheBiggestChangesAndTheEmptyRun(): void
    {
        $year = (int) date('Y');
        // September is still in progress (it is the 15th), April to August had no sales.
        $d = $this->data(
            [100, 200, 50, 0, 0, 0, 0, 0, 40, null, null, null],
            [80, 100, 150, 100, 100, 100, 100, 100, 100, 100, 100, 100],
            9,
            $year,
            "{$year}-09-15"
        );

        $this->assertSame([
            'February was the strongest month with 200 items, and March the weakest with 50 items (September, still in progress, is not counted).',
            'Compared with ' . ($year - 1) . ', Feb had the biggest increase (+100 items) and Mar had the biggest drop (-100 items).',
            "No sales were recorded from Apr to Aug {$year}.",
        ], TrendReport::insights($d, $year));
    }

    public function testAnEmptyRunThatReachesTheCurrentMonthIsReported(): void
    {
        $year = (int) date('Y');
        $d    = $this->data([300, 250, 0, 0, 0, 0, 0, 0, 0, 0, 0, null], array_fill(0, 12, 100), 11, $year, "{$year}-11-07");

        $this->assertContains("No sales were recorded from Mar to Nov {$year}.", TrendReport::insights($d, $year));
    }

    public function testSeparateEmptyMonthsAreReportedSeparately(): void
    {
        $year = (int) date('Y') - 1;
        $d    = $this->data([5, 0, 7, 0, 0, 9, 4, 4, 4, 4, 4, 4], array_fill(0, 12, 5), 12, $year, "{$year}-12-31");

        $this->assertContains("No sales were recorded in Feb {$year}, and from Apr to May {$year}.", TrendReport::insights($d, $year));
    }

    public function testAYearWithoutSalesSaysSo(): void
    {
        $year = (int) date('Y') - 1;

        $this->assertSame(["No items have been sold in {$year} yet."], TrendReport::insights($this->data(array_fill(0, 12, 0), array_fill(0, 12, 3), 12, $year, "{$year}-12-31"), $year));
    }

    public function testAFullPastYearHasNoInProgressMonth(): void
    {
        $year = (int) date('Y') - 1;
        $out  = TrendReport::insights($this->data([10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 110, 120], array_fill(0, 12, 60), 12, $year, "{$year}-12-31"), $year);

        $this->assertSame('December was the strongest month with 120 items, and January the weakest with 10 items.', $out[0]);
        $this->assertStringNotContainsString('in progress', implode(' ', $out));
    }

    // ---------------------------------------------------------------- the whole report from the page's data

    private function pageData(): array
    {
        $year = (int) date('Y');

        return [
            'selectedYear' => $year, 'selectedItem' => null, 'itemLabel' => null, 'generatedAt' => 'Nov 7, 2026 3:21 PM',
            'lastSaleAt' => "{$year}-08-21 10:00:00", 'dssRunAt' => "{$year}-11-05 02:00:00",
            'annualLabels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            'annualData' => [100, 200, 50, 0, 0, 0, 0, 0, 0, 0, 0, null], 'annualDataPrev' => array_fill(0, 12, 100), 'monthsSoFar' => 11,
            'unitsYtd' => 350, 'unitsPrev' => 2300, 'changePct' => -84.78, 'periodEnd' => "{$year}-11-07",
            'periodLabel' => "Jan 1 – Nov 7, {$year}", 'prevPeriodLabel' => 'Jan 1 – Nov 7, ' . ($year - 1),
            'bestMonth' => ['name' => 'February', 'units' => 200], 'topItem' => ['item_id' => 'P-01', 'item_name' => 'Patrol Shirt', 'units_sold' => 120],
            'weeks12' => array_map(static fn ($i) => ['label' => "Week {$i}", 'short' => "W{$i}", 'units' => $i * 3], range(1, 12)),
            'topSellers' => [['item_id' => 'P-01', 'item_name' => 'Patrol Shirt', 'units_sold' => 50], ['item_id' => 'P-02', 'item_name' => 'Boots', 'units_sold' => 30]],
            'abcClassCounts' => ['A' => 2, 'B' => 3, 'C' => 4], 'abcClassTotals' => ['A' => 60.5, 'B' => 30.0, 'C' => 9.5], 'abcUnclassified' => 0,
            'abcByClass' => ['A' => [['rank' => 1, 'label' => 'Patrol Shirt (M)', 'code' => 'X', 'show_code' => false, 'pct' => 30.25]], 'B' => [], 'C' => []],
            'variabilityAll' => [['rank' => 1, 'label' => 'Patrol Shirt (M)', 'code' => 'X', 'show_code' => true, 'avg_daily_demand' => 4.2, 'safety_stock' => 18, 'class' => 'A']],
        ];
    }

    public function testTheCardsHaveThePagesValuesAndOneSentenceEach(): void
    {
        $year  = (int) date('Y');
        $cards = TrendReport::build($this->pageData())['cards'];

        $this->assertSame(["Items sold in {$year}", 'vs. same period last year', 'Best month', "Top item in {$year}"], array_column($cards, 'label'));
        $this->assertSame(['350', '-84.8%', 'February', 'Patrol Shirt'], array_column($cards, 'value'));
        $this->assertSame("Jan 1 – Nov 7, {$year}", $cards[0]['sub']);
        $this->assertSame('2,300 items, Jan 1 – Nov 7, ' . ($year - 1), $cards[1]['sub']);
        $this->assertSame('200 items sold', $cards[2]['sub']);
        $this->assertSame('120 items sold', $cards[3]['sub']);
        $this->assertSame('down', $cards[1]['tone']);

        $this->assertStringContainsString('Compared with the same dates last year (Jan 1 – Nov 7, ' . ($year - 1) . '), sales were 84.8% lower.', $cards[1]['sentence']);
        $this->assertStringContainsString('same dates in both years, not the full years', $cards[1]['sentence']);
    }

    public function testNoSalesLastYearAndHigherSalesAreWorded(): void
    {
        $d               = $this->pageData();
        $d['changePct']  = null;
        $d['unitsPrev']  = 0;
        $cards           = TrendReport::build($d)['cards'];
        $this->assertSame('No sales last year', $cards[1]['value']);
        $this->assertStringContainsString('nothing to compare with', $cards[1]['sentence']);

        $d['changePct'] = 12.34;
        $d['unitsPrev'] = 311;
        $cards          = TrendReport::build($d)['cards'];
        $this->assertSame('+12.3%', $cards[1]['value']);
        $this->assertSame('up', $cards[1]['tone']);
        $this->assertStringContainsString('sales were 12.3% higher', $cards[1]['sentence']);
    }

    public function testMonthlyRowsAndTotals(): void
    {
        $year    = (int) date('Y');
        $monthly = TrendReport::build($this->pageData())['monthly'];

        $this->assertSame(['month' => 'Jan', 'this' => '100', 'prev' => '100', 'diff' => '0', 'pct' => '0.0%'], $monthly['rows'][0]);
        $this->assertSame(['month' => 'Mar', 'this' => '50', 'prev' => '100', 'diff' => '-50', 'pct' => '-50.0%'], $monthly['rows'][2]);
        $this->assertSame(['month' => 'Dec', 'this' => '', 'prev' => '100', 'diff' => '', 'pct' => ''], $monthly['rows'][11]);

        $this->assertSame('Total (Jan 1 – Nov 7)', $monthly['totals'][0]['label']);
        $this->assertSame(['350', '2,300', '-1,950', '-84.8%'], [$monthly['totals'][0]['this'], $monthly['totals'][0]['prev'], $monthly['totals'][0]['diff'], $monthly['totals'][0]['pct']]);
        $this->assertSame('Full year ' . ($year - 1), $monthly['totals'][1]['label']);
        $this->assertSame('1,200', $monthly['totals'][1]['prev']);
    }

    public function testAPercentChangeIsADashWhenLastYearWasZero(): void
    {
        $d                   = $this->pageData();
        $d['annualDataPrev'] = array_fill(0, 12, 0);

        $this->assertSame('–', TrendReport::build($d)['monthly']['rows'][0]['pct']);
    }

    public function testAClassTableCarriesPriorityServiceLevelShareAndMeaning(): void
    {
        $abc = TrendReport::build($this->pageData())['abc'];

        $this->assertSame(['A', 'B', 'C'], array_column($abc['classes'], 'class'));
        $this->assertSame(['Highest priority', 'Medium priority', 'Lowest priority'], array_column($abc['classes'], 'priority'));
        $this->assertSame(['95%', '90%', '85%'], array_column($abc['classes'], 'service'));
        $this->assertSame(['60.5%', '30.0%', '9.5%'], array_column($abc['classes'], 'share'));
        $this->assertSame('Highest priority: keep the highest safety stock (95% service level).', $abc['classes'][0]['meaning']);
        $this->assertSame('Patrol Shirt (M)', $abc['classes'][0]['top'][0]['label']);
        $this->assertSame('30.3%', $abc['classes'][0]['top'][0]['pct']);
    }

    public function testTheProductSentenceNamesTheTopItemAndItsShare(): void
    {
        $this->assertSame('Patrol Shirt is the top item with 50 items, 62.5% of the 80 items sold by these five.', TrendReport::build($this->pageData())['products']['sentence']);
    }

    public function testTheNotesNameTheDssRunDate(): void
    {
        $year  = (int) date('Y');
        $notes = TrendReport::build($this->pageData())['notes'];

        $this->assertSame("The classes, daily sales and safety stock values come from the latest DSS run on Nov 5, {$year}.", $notes['dss']);
        $this->assertGreaterThanOrEqual(6, count($notes['lines']));
    }

    public function testTheFooterHasThePageNumberPlaceholders(): void
    {
        $year = (int) date('Y');

        $this->assertSame("RfourL Military Apparel · Trend Analysis {$year} · Page {PAGE_NUM} of {PAGE_COUNT}", TrendReport::build($this->pageData())['footer']);
    }

    // ---------------------------------------------------------------- charts

    private function xml(string $svg): SimpleXMLElement
    {
        $xml = simplexml_load_string($svg);
        $this->assertNotFalse($xml, 'the chart must be well-formed SVG');

        return $xml;
    }

    public function testTheLineChartHasBothSeriesThePeakTheShadedFutureAndALegend(): void
    {
        $svg = PdfCharts::lineChart(
            ['Jan', 'Feb', 'Mar', 'Apr'],
            [10, 40, 20, null],
            [15, 30, 25, 20],
            ['currentName' => '2026', 'previousName' => '2025', 'futureFrom' => 3]
        );
        $this->xml($svg);

        $this->assertStringContainsString('Peak: 40', $svg);
        $this->assertStringContainsString('Not yet', $svg);
        $this->assertStringContainsString('stroke-dasharray="6 5"', $svg);
        $this->assertStringContainsString('#4A6741', $svg);
        $this->assertStringContainsString('#A9A493', $svg);
        $this->assertStringContainsString('>2026<', $svg);
        $this->assertStringContainsString('>2025<', $svg);
        $this->assertStringContainsString('>Jan<', $svg);
        $this->assertStringContainsString('>Items sold<', $svg);
    }

    public function testTheLineChartWithoutFutureMonthsHasNoShading(): void
    {
        $svg = PdfCharts::lineChart(['Jan', 'Feb'], [1, 2], [2, 1], ['futureFrom' => null]);

        $this->xml($svg);
        $this->assertStringNotContainsString('Not yet', $svg);
    }

    public function testAllZeroDataStillDrawsAValidChart(): void
    {
        $this->xml(PdfCharts::lineChart(['Jan', 'Feb'], [0, 0], [0, 0]));
        $this->xml(PdfCharts::barChart(['a', 'b'], [0, 0]));
        $this->xml(PdfCharts::stackedBar([['label' => 'A', 'value' => 0, 'color' => '#000000']]));
    }

    public function testTheBarChartLabelsEveryBarAndHighlightsTheLast(): void
    {
        $svg = PdfCharts::barChart(['W1', 'W2', 'W3'], [5, 0, 9], ['highlightLast' => true]);
        $this->xml($svg);

        foreach (['>W1<', '>W2<', '>W3<', '>5<', '>0<', '>9<'] as $text) {
            $this->assertStringContainsString($text, $svg);
        }
        $this->assertStringContainsString(PdfCharts::DARK, $svg);
    }

    public function testHorizontalBarsShowEachCountAndShortenLongNames(): void
    {
        $svg = PdfCharts::horizontalBars([[str_repeat('Long name ', 12), 120], ['Short', 60]]);
        $this->xml($svg);

        $this->assertStringContainsString('>120<', $svg);
        $this->assertStringContainsString('>60<', $svg);
        $this->assertStringContainsString('…', $svg);
        $this->assertStringContainsString('>Short<', $svg);
    }

    public function testTheStackedBarHasASegmentAndALegendEntryPerClass(): void
    {
        $svg = PdfCharts::stackedBar([
            ['label' => 'Class A: 3 items', 'value' => 3, 'color' => PdfCharts::DARK],
            ['label' => 'Class B: 7 items', 'value' => 7, 'color' => PdfCharts::MEDIUM],
        ]);
        $this->xml($svg);

        $this->assertStringContainsString('>Class A: 3 items<', $svg);
        $this->assertStringContainsString('>Class B: 7 items<', $svg);
        $this->assertStringContainsString('>3<', $svg);
        $this->assertStringContainsString('>7<', $svg);
    }

    public function testTheAxisScaleIsRoundAndCoversTheData(): void
    {
        foreach ([1, 7, 99, 282, 585, 1234, 99999] as $max) {
            [$top, $ticks] = PdfCharts::niceScale($max);
            $this->assertGreaterThanOrEqual($max, $top);
            $this->assertSame(0, $ticks[0]);
            $this->assertSame($top, end($ticks));
            $this->assertLessThanOrEqual(6, count($ticks));
        }
    }

    public function testTextIsEscapedInTheChart(): void
    {
        $svg = PdfCharts::horizontalBars([['Tom & "Jerry" <Co>', 5]]);

        $this->xml($svg);
        $this->assertStringContainsString('Tom &amp; &quot;Jerry&quot; &lt;Co&gt;', $svg);
    }

    public function testTheDataUriIsAnSvgImage(): void
    {
        $uri = PdfCharts::dataUri('<svg xmlns="http://www.w3.org/2000/svg"/>');

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);
        $this->assertSame('<svg xmlns="http://www.w3.org/2000/svg"/>', base64_decode(substr($uri, strlen('data:image/svg+xml;base64,'))));
    }
}
