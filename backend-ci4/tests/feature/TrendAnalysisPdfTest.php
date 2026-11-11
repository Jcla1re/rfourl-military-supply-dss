<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\View\View;
use Config\Services;
use Tests\Support\TrendFixture;

/**
 * The Trend Analysis PDF: the route returns a PDF built from the same data as the page, scoped by
 * year and by the item filter, with every section of the report.
 *
 * Runs on the in-memory 'tests' database (see Tests\Support\TrendFixture).
 *
 * @internal
 */
final class TrendAnalysisPdfTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use TrendFixture;

    protected $migrate = false;
    protected $refresh = false;

    private object $views;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTrendFixture();

        // Records what each view is given and what it renders.
        $this->views = new class (config('View'), APPPATH . 'Views/', service('locator'), CI_DEBUG, service('logger')) extends View {
            public array $rendered = [];

            public function render(string $view, ?array $options = null, ?bool $saveData = null): string
            {
                $data = array_merge($this->data, $this->tempData ?? []);
                $html = parent::render($view, $options, $saveData);
                $this->rendered[$view] = ['data' => $data, 'html' => $html];

                return $html;
            }
        };
        Services::injectMock('renderer', $this->views);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('renderer');
        $this->dropTrendFixture();
        parent::tearDown();
    }

    private function owner(): self
    {
        return $this->withSession(['isLoggedIn' => true, 'role' => 'Admin', 'user_id' => 1, 'username' => 'owner1', 'last_activity' => time()]);
    }

    /** @return array{data: array, html: string, response: \CodeIgniter\HTTP\ResponseInterface} */
    private function pdf(string $query = ''): array
    {
        $response = $this->owner()->get('admin/trend-analysis/pdf' . $query)->response();
        $this->assertStringStartsWith('%PDF', $response->getBody());

        return ['response' => $response] + $this->views->rendered['admin/trend_analysis_pdf'];
    }

    private function page(string $query = ''): string
    {
        return $this->owner()->get('admin/trend-analysis' . $query)->response()->getBody();
    }

    // ---------------------------------------------------------------- the route

    public function testTheRouteReturnsAPdfAndTheViewGetsTheSummaryMonthlyAndAbcData(): void
    {
        $year   = (int) date('Y');
        $result = $this->pdf();

        $this->assertSame('application/pdf', $result['response']->getHeaderLine('Content-Type'));
        $this->assertStringContainsString("trend-analysis-{$year}.pdf", $result['response']->getHeaderLine('Content-Disposition'));

        $data = $result['data'];
        foreach (['unitsYtd', 'unitsPrev', 'changePct', 'periodLabel', 'prevPeriodLabel', 'bestMonth', 'topItem'] as $key) {
            $this->assertArrayHasKey($key, $data, "summary: {$key}");
        }
        foreach (['annualLabels', 'annualData', 'annualDataPrev', 'monthsSoFar', 'weeks12'] as $key) {
            $this->assertArrayHasKey($key, $data, "monthly: {$key}");
        }
        foreach (['abcClassCounts', 'abcClassTotals', 'abcByClass', 'variabilityAll', 'topSellers'] as $key) {
            $this->assertArrayHasKey($key, $data, "abc: {$key}");
        }
        $this->assertSame(['A' => 3, 'B' => 5, 'C' => 5], $data['abcClassCounts']);
        $this->assertSame(1, $data['abcUnclassified']);
        $this->assertCount(12, $data['annualData']);
        $this->assertCount(12, $data['weeks12']);

        $report = $data['report'];
        foreach (['header', 'cards', 'monthly', 'weekly', 'products', 'abc', 'demand', 'notes'] as $section) {
            $this->assertArrayHasKey($section, $report);
        }
        $this->assertCount(4, $report['cards']);
    }

    public function testEverySectionIsInTheReportInOrder(): void
    {
        $html  = $this->pdf()['html'];
        $last = -1;
        foreach (['Trend Analysis Report', '>Summary<', 'Items sold per month', 'What this shows', 'Weekly view', 'Product performance', 'ABC classification (K-Means)', 'Top 5 items by sales share in each class', 'Daily demand', '>Notes<'] as $marker) {
            $pos = strpos($html, $marker);
            $this->assertNotFalse($pos, "missing: {$marker}");
            $this->assertGreaterThan($last, $pos, "out of order: {$marker}");
            $last = $pos;
        }
        $this->assertSame(4, substr_count($html, 'data:image/svg+xml;base64,'), 'a chart each for months, weeks, products and ABC');
    }

    public function testTheHeaderAndSummaryHaveTheRightWords(): void
    {
        $year = (int) date('Y');
        $html = $this->pdf()['html'];

        foreach (["Year</td><td>{$year}", 'All items', 'Data covers Jan 1', '(Philippine time)', 'Last recorded sale', "Items sold in {$year}", 'vs. same period last year', 'Best month', "Top item in {$year}"] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringContainsString('Compared with the same dates last year (Jan 1 – ', html_entity_decode($html));
        $this->assertStringContainsString('not the full years', $html);
    }

    // ---------------------------------------------------------------- same numbers as the page

    public function testTheNumbersMatchTheOnScreenPage(): void
    {
        $page = $this->page();
        $pdf  = $this->pdf();
        $d    = $pdf['data'];

        // The 4 cards.
        $this->assertSame(1, preg_match('/data-count="(\d+)"/', $page, $first));
        $this->assertSame((int) $d['unitsYtd'], (int) $first[1]);
        $this->assertStringContainsString(number_format($d['unitsYtd']), $page);
        $this->assertStringContainsString(number_format($d['unitsYtd']), $pdf['html']);
        $this->assertStringContainsString(number_format($d['changePct'], 1) . '%', $page);
        $this->assertStringContainsString(number_format($d['changePct'], 1) . '%', $pdf['html']);
        $this->assertStringContainsString($d['bestMonth']['name'], $page);
        $this->assertStringContainsString($d['bestMonth']['name'], $pdf['html']);
        $this->assertStringContainsString($d['topItem']['item_name'], $page);
        $this->assertStringContainsString($d['topItem']['item_name'], $pdf['html']);

        // The monthly series are the page's own chart data.
        preg_match('/current: (\[[^\]]*\])/', $page, $cur);
        preg_match('/previous: (\[[^\]]*\])/', $page, $prev);
        $this->assertNotEmpty($cur);
        $this->assertSame(array_values($d['annualData']), json_decode($cur[1], true));
        $this->assertSame(array_values($d['annualDataPrev']), json_decode($prev[1], true));

        // The latest of the 12 weeks is the page's Weekly view ("this week"); the one before is the week it compares with.
        $weeks = $d['weeks12'];
        preg_match_all('/current: (\[[^\]]*\])/', $page, $all);
        preg_match_all('/previous: (\[[^\]]*\])/', $page, $allPrev);
        $this->assertSame(array_sum(json_decode($all[1][1], true)), $weeks[11]['units']);
        $this->assertSame(array_sum(json_decode($allPrev[1][1], true)), $weeks[10]['units']);
    }

    public function testTheAbcSectionHasTheSameClassesAsTheEngineSavedAndTheirShares(): void
    {
        $d = $this->pdf()['data'];

        $this->assertGreaterThan(80.0, array_sum($d["abcClassTotals"])); // the classified items hold most of the sales value
        $this->assertLessThanOrEqual(100.0001, array_sum($d['abcClassTotals']));
        $classes = $d['report']['abc']['classes'];
        $this->assertSame(['A', 'B', 'C'], array_column($classes, 'class'));
        $this->assertSame([
            'Group with the highest average demand · 95% availability target',
            'Group with medium average demand · 90% availability target',
            'Group with the lowest average demand · 85% availability target',
        ], array_column($classes, 'group'));
        $this->assertSame(['3', '5', '5'], array_column($classes, 'items'));
        $this->assertCount(3, $classes[0]['top']);
        $this->assertCount(5, $classes[1]['top']);
        $this->assertStringContainsString('Items are grouped by unit cost, daily demand, demand variability and lead time together. The class is a group, not a sales rank, so a best seller can be in any class.', $d['report']['abc']['sentence']);
        $this->assertStringContainsString('1 item sold', $d['report']['abc']['unclassedNote']);
    }

    public function testTheDailyDemandTableListsEveryItemWithItsClass(): void
    {
        $rows = $this->pdf()['data']['report']['demand']['rows'];

        $this->assertCount(14, $rows);
        $this->assertSame('Patrol Shirt (M)', $rows[0]['item']);
        $this->assertSame('4.20', $rows[0]['daily']);
        $this->assertSame('18', $rows[0]['safety']);
        $this->assertSame('A', $rows[0]['class']);
        $this->assertSame('–', end($rows)['class']);
    }

    // ---------------------------------------------------------------- year and item filters

    public function testASingleItemScopesTheReportAndSaysSo(): void
    {
        $all  = $this->pdf()['data'];
        $one  = $this->pdf('?item=P-01');
        $d    = $one['data'];

        $this->assertSame('P-01', $d['selectedItem']);
        $this->assertSame('Patrol Shirt (M)', $d['itemLabel']);
        $this->assertLessThan($all['unitsYtd'], $d['unitsYtd']);
        // The months add up to the card (all the sales are in the past).
        $this->assertSame($d['unitsYtd'], array_sum(array_filter($d['annualData'], static fn ($v) => $v !== null)));
        $this->assertSame($all['unitsYtd'], array_sum(array_filter($all['annualData'], static fn ($v) => $v !== null)));

        // Same figures as the page for that item.
        $page = $this->page('?item=P-01');
        $this->assertStringContainsString(number_format($d['unitsYtd']), $page);

        $html = $one['html'];
        $this->assertStringContainsString('Item filter</td><td>Patrol Shirt (M)', $html);
        $this->assertStringContainsString('Items sold per month · Patrol Shirt (M)', $html);
        $this->assertStringContainsString('items of Patrol Shirt (M) were sold', $html);
        $this->assertStringContainsString('Weekly view · Patrol Shirt (M)', $html);
        $this->assertStringContainsString('Not filtered by item: this section shows all items, like the page.', $html);
        $this->assertStringNotContainsString('Not filtered by item', $this->pdf()['html']);
    }

    public function testAPastYearIsComplete(): void
    {
        $year   = (int) date('Y') - 1;
        $result = $this->pdf("?year={$year}");
        $d      = $result['data'];

        $this->assertSame($year, $d['selectedYear']);
        $this->assertSame(12, $d['monthsSoFar']);
        $this->assertNotContains(null, $d['annualData']);
        $this->assertSame("Jan 1 – Dec 31, {$year}", html_entity_decode((string) $d['periodLabel']));
        $this->assertStringContainsString("Total (Jan 1 – Dec 31)", html_entity_decode($result['html']));
        $this->assertStringContainsString("Full year " . ($year - 1), $result['html']);
        $this->assertStringNotContainsString('still in progress', $result['html']);
        $this->assertStringContainsString("trend-analysis-{$year}.pdf", $result['response']->getHeaderLine('Content-Disposition'));
    }

    public function testTheMonthlyTableHasBlankFutureMonthsAndDashesWhenLastYearIsZero(): void
    {
        $year = (int) date('Y');
        $rows = $this->pdf()['data']['report']['monthly']['rows'];

        $this->assertSame('', $rows[11]['this'], 'December has not happened: blank, not 0');
        $this->assertSame('', $rows[11]['diff']);
        $this->assertSame('', $rows[11]['pct']);

        // A past year whose previous year has no sales: "–" for % change.
        $past = $this->pdf('?year=' . ($year - 1))['data']['report']['monthly']['rows'];
        $this->assertSame('–', $past[0]['pct']);
    }

    public function testOnlyTheOwnerCanOpenIt(): void
    {
        $this->get('admin/trend-analysis/pdf')->assertRedirectTo(site_url('login/admin'));
    }
}
