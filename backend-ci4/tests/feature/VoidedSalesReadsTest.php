<?php

use App\Controllers\Admin\TrendAnalysisController;
use App\Libraries\AccountingReports;
use App\Libraries\DemandProjection;
use App\Libraries\DuplicateSaleVoid;
use App\Libraries\SalesArchive;
use App\Libraries\SalesCsvExport;
use App\Models\SalesItemModel;
use App\Models\SalesTransactionModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ReorderFixture;
use Tests\Support\VoidSalesFixture;

/**
 * A voided duplicate is left out of every total, count, chart and demand figure, and is still shown
 * (marked "Voided duplicate of #XXXX") on the lists of receipts: one test per screen / report /
 * export that reads sales. Runs on the in-memory 'tests' database.
 *
 * Today: #1001 (2 Brass Medals, 240.00), #1002 (1 Patrol Shirt, 250.00) and its duplicate #1003,
 * voided. The real figures are 490.00, 2 receipts, 3 units; counting the duplicate would give
 * 740.00, 3 receipts, 4 units.
 *
 * @internal
 */
final class VoidedSalesReadsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ReorderFixture;
    use VoidSalesFixture;

    protected $migrate = false;
    protected $refresh = false;

    private string $today;
    private int $duplicate;
    private int $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVoidSales();
        Services::resetSingle('renderer');

        $this->today = date('Y-m-d');
        $this->sale('1001', $this->today . ' 00:00:02', [['P-2', 2, 120.0]], ['user_id' => $this->staffId]);
        [$this->original, $this->duplicate] = $this->doubleClick('1002', '1003', $this->today . ' 00:00:05');
        $this->assertSame(DuplicateSaleVoid::VOIDED, DuplicateSaleVoid::apply($this->duplicate, $this->original, $this->ownerId)['status']);
    }

    protected function tearDown(): void
    {
        $this->tearDownVoidSales();
        parent::tearDown();
    }

    private function html(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        return new \DOMXPath($dom);
    }

    private function page(string $role, string $url): string
    {
        return (string) ($role === 'admin' ? $this->asOwner() : $this->asStaff())->get($url)->response()->getBody();
    }

    private function csvRows(string $csv): array
    {
        return array_map(static fn ($l) => str_getcsv($l, ',', '"', '\\'), explode("\n", trim(substr($csv, 3))));
    }

    // ---------------------------------------------------------------- the helper

    public function testOneHelperDecidesWhatIsVoided(): void
    {
        $this->assertSame('st.voided_at IS NULL', SalesTransactionModel::notVoided('st'));
        $this->assertSame('sales_transaction.voided_at IS NULL', SalesTransactionModel::notVoided());
        $this->assertSame('Voided duplicate of #1002', SalesTransactionModel::voidLabel(['voided_at' => 'x', 'duplicate_of_receipt_no' => '1002']));
        $this->assertNull(SalesTransactionModel::voidLabel(['voided_at' => null]));
        $this->assertSame(490.0, SalesTransactionModel::totalOf([['total_amount' => 240], ['total_amount' => 250], ['total_amount' => 250, 'voided_at' => 'x']]));
    }

    // ---------------------------------------------------------------- dashboards and POS stat cards

    public function testTheSalesFiguresOfTheDashboardsAndPosCardsLeaveItOut(): void
    {
        // The Owner dashboard, the Staff dashboard and the POS receipts-tab cards read these.
        $sales = new SalesTransactionModel();
        $this->assertSame(490.0, $sales->totalForToday());
        $this->assertSame(490.0, $sales->totalForDate($this->today));
        $this->assertSame(490.0, $sales->totalForMonth(date('Y-m')));
        $this->assertSame(2, $sales->countForToday());
        $this->assertSame(2, $sales->countForDate($this->today));
        $this->assertSame(2, $sales->countForMonth(date('Y-m')));
        $this->assertSame(2, $sales->countInRange($this->today, $this->today));
        $this->assertSame(490.0, (float) array_sum($sales->weeklyTotals()['data']));
        $this->assertNotContains('1003', array_column($sales->recent(10), 'receipt_no'));
        $this->assertSame($this->today . ' 00:00:05', $sales->lastSaleDate());
    }

    public function testTopSellersRevenueAndDailyUnitsLeaveItOut(): void
    {
        // Owner dashboard Top Products, Trend Analysis (best sellers, ABC revenue, weekly chart), DSS demand.
        $items = new SalesItemModel();
        $top   = array_column($items->topSellers(5), 'units_sold', 'item_id');
        $this->assertSame(['P-2' => 2, 'P-1' => 1], array_map('intval', $top));
        $this->assertSame(['P-2' => 2], array_map('intval', array_column($items->topSellers(1, (int) date('Y')), 'units_sold', 'item_id')));
        $this->assertEqualsWithDelta(250.0, (float) array_column($items->revenueByItem((int) date('Y')), 'revenue', 'item_id')['P-1'], 0.001);
        $this->assertSame(3, (int) array_sum(array_column($items->dailyQuantities($this->today, $this->today), 'qty')));
        $this->assertSame(1, (int) array_sum(array_column($items->dailyQuantities($this->today, $this->today, 'P-1'), 'qty')));
        $this->assertSame($this->today . ' 00:00:05', $items->maxSaleDateInYear((int) date('Y'), 'P-1'));
        $byItem = $items->dailyQuantitiesByItem($this->today, $this->today);
        ksort($byItem);
        $this->assertSame(['P-1' => [$this->today => 1], 'P-2' => [$this->today => 2]], $byItem);
    }

    public function testTheDssRunReadsDemandThroughTheFilteredMethods(): void
    {
        // dss:run needs the K-Means service, so it can't run here; this guards the wiring.
        $source = file_get_contents(APPPATH . 'Commands/DssRun.php');
        $this->assertStringContainsString('->dailyQuantitiesByItem(', $source);
        $this->assertStringContainsString('->lastSaleDate()', $source);
        $this->assertStringNotContainsString("table('sales_item si')", $source, 'No unfiltered sales query of its own.');
        $this->assertStringNotContainsString("table('sales_transaction')", $source);
    }

    public function testTheStaffDashboardLeavesItOut(): void
    {
        $x = $this->html($this->page('staff', 'staff/dashboard'));
        $text = preg_replace('/\s+/', ' ', $x->query('//body')->item(0)->textContent);

        $this->assertStringContainsString('Sales Today₱490 ', $text);
        $this->assertStringContainsString('Transaction Today2 ', $text);
        $this->assertStringContainsString('Patrol Shirt x1₱250', $text, 'Items Sold Today: one shirt, not two.');
        $this->assertSame(2, $x->query('//*[contains(@class,"recent-row")]')->length, 'Recent transactions: the two real ones.');
        $this->assertStringNotContainsString('#1003', $text);
    }

    // ---------------------------------------------------------------- POS receipts tab, day PDF, POS CSV

    /** @return array<string, array{0: string}> */
    public static function roles(): array
    {
        return ['owner' => ['admin'], 'staff' => ['staff']];
    }

    /** @dataProvider roles */
    public function testTheReceiptsTabListsItMarkedAndLeavesItOutOfTheCards(string $role): void
    {
        $html = $this->page($role, $role . '/sales?tab=receipts&date=' . $this->today);
        $x    = $this->html($html);

        $this->assertSame(3, $x->query('//a[contains(@class,"view-receipt-link")]')->length, 'All three receipts are listed.');
        $voided = $x->query('//tr[contains(@class,"is-voided")]');
        $this->assertSame(1, $voided->length);
        $this->assertStringContainsString('#1003', $voided->item(0)->textContent);
        $this->assertStringContainsString('Voided duplicate of #1002', $voided->item(0)->textContent);
        $this->assertStringContainsString('"void_label":"Voided duplicate of #1002"', html_entity_decode($html), 'The receipt popup says so too.');
        $text = preg_replace('/\s+/', ' ', $x->query('//body')->item(0)->textContent);
        $this->assertStringContainsString('Total Sales Today ₱490 2 transactions', $text);
    }

    /** @dataProvider roles */
    public function testTheDayPdfListsItMarkedAndLeavesItOutOfTheTotal(string $role): void
    {
        $class = $role === 'admin' ? \App\Controllers\Admin\SalesController::class : \App\Controllers\Staff\SalesController::class;
        $rm    = new ReflectionMethod($class, 'transactionsForDate');
        $rm->setAccessible(true);
        $transactions = $rm->invoke(new $class(), $this->today);

        $this->assertCount(3, $transactions);
        $this->assertSame(490.0, SalesTransactionModel::totalOf($transactions), 'What exportPdf() passes as the total.');

        $pdf = view($role . '/sales_pdf', ['date' => $this->today, 'transactions' => $transactions, 'totalSales' => SalesTransactionModel::totalOf($transactions)]);
        $this->assertSame(1, substr_count($pdf, 'Voided duplicate of #1002'));
        $this->assertStringContainsString('Total for period: ₱490.00', $pdf);
        $this->assertStringContainsString('Voided duplicates are listed but not counted in the total.', $pdf);

        $export = ($role === 'admin' ? $this->asOwner() : $this->asStaff())->get($role . '/sales/export-pdf?date=' . $this->today)->response();
        $this->assertStringStartsWith('application/pdf', $export->getHeaderLine('Content-Type'), 'The export itself still renders.');
    }

    public function testThePosSalesCsvHasOnlyTheRealSales(): void
    {
        $rows = $this->csvRows(SalesCsvExport::build($this->today, $this->today));

        $this->assertSame(['1001', '1002'], array_column(array_slice($rows, 1), 0), 'Lines of real sales; the CSV adds up to the sales.');
    }

    // ---------------------------------------------------------------- Reports (Sales Journal, Summary, CSV, PDF)

    public function testTheSalesJournalListsItMarkedButCountsOnlyRealSales(): void
    {
        $journal = AccountingReports::salesJournal($this->today, $this->today);

        $this->assertSame(['1001', '1002', '1003'], array_column($journal['rows'], 'receipt_no'));
        $this->assertSame([null, null, 'Voided duplicate of #1002'], array_column($journal['rows'], 'voided'));
        $this->assertSame([2, 490.0], [$journal['total']['count'], $journal['total']['net']]);
        $this->assertSame([2, 490.0], [$journal['days'][0]['count'], $journal['days'][0]['net']]);

        $summary = AccountingReports::summary($this->today, $this->today);
        $this->assertSame([490.0, 490.0, 2], [$summary['sales'], $summary['net_sales'], $summary['receipts']]);
    }

    public function testTheReportsPageShowsTheLabelAndTheRealTotal(): void
    {
        $x    = $this->html($this->page('admin', 'admin/reports?type=sales&from=' . $this->today . '&to=' . $this->today));
        $rows = $x->query('//table[@id="salesJournal"]//tr[contains(@class,"rp-row")]');

        $this->assertSame(3, $rows->length);
        $this->assertStringContainsString('#1003 Voided duplicate of #1002', preg_replace('/\s+/', ' ', $rows->item(2)->textContent));
        $this->assertStringContainsString('rp-voided', $rows->item(2)->getAttribute('class'));
        $total = preg_replace('/\s+/', ' ', $x->query('//tr[@id="salesTotal"]')->item(0)->textContent);
        $this->assertStringContainsString('Period total (2 receipts)', $total);
        $this->assertStringContainsString('₱490.00', $total);
    }

    public function testTheReportsPdfShowsTheLabelAndTheRealTotal(): void
    {
        $pdf = view('reports/pdf', ['type' => 'sales', 'from' => $this->today, 'to' => $this->today, 'generatedAt' => 'now', 'report' => AccountingReports::salesJournal($this->today, $this->today)]);

        $this->assertSame(1, substr_count($pdf, 'Voided duplicate of #1002'));
        $this->assertStringContainsString('Period total (2 receipts)', $pdf);
        $this->assertStringContainsString('₱490.00', $pdf);
    }

    public function testTheReportsCsvKeepsItsColumnsAndMarksItInTheOrColumn(): void
    {
        $rows = $this->csvRows((string) $this->asOwner()->get('admin/reports/csv?type=sales&from=' . $this->today . '&to=' . $this->today)->response()->getBody());

        $this->assertSame(AccountingReports::csvColumns('sales'), $rows[0], 'The columns are unchanged.');
        $or = array_column(array_filter($rows, static fn ($r) => $r[0] === 'Receipt'), 2, 1);
        $this->assertSame(['1001' => '—', '1002' => '—', '1003' => '— (voided duplicate of #1002)'], $or);
        $total = array_values(array_filter($rows, static fn ($r) => $r[0] === 'Period total'))[0];
        $this->assertSame(['2 receipts', '490.00'], [$total[1], $total[8]]);
    }

    public function testTheSummaryCsvCountsOnlyRealSales(): void
    {
        $rows  = $this->csvRows((string) $this->asOwner()->get('admin/reports/csv?type=summary&from=' . $this->today . '&to=' . $this->today)->response()->getBody());
        $lines = array_column($rows, 1, 0);

        $this->assertSame('490.00', $lines['Gross sales']);
        $this->assertSame('2', $lines['Receipts']);
    }

    public function testTheDuplicateOrLabelAndTheCheckoutWarningIgnoreAVoidedReceipt(): void
    {
        $this->db->table('sales_transaction')->where('sales_id', $this->original)->update(['or_number' => 'Z-1']);
        $this->db->table('sales_transaction')->where('sales_id', $this->duplicate)->update(['or_number' => 'Z-1']);

        $rows = array_column(AccountingReports::salesJournal($this->today, $this->today)['rows'], 'duplicate', 'receipt_no');
        $this->assertFalse($rows['1002'], 'Z-1 is only used once by a real receipt.');
        $this->assertSame('1002', (new SalesTransactionModel())->findByOrNumber('z-1')['receipt_no']);

        $this->db->table('sales_transaction')->where('sales_id', $this->original)->update(['or_number' => null]);
        $this->assertNull((new SalesTransactionModel())->findByOrNumber('Z-1'), 'Only the voided receipt has it.');
    }

    public function testTheNextReceiptNumberStillCountsTheVoidedOne(): void
    {
        $this->assertSame('1004', (new SalesTransactionModel())->generateReceiptNo(), '1003 stays taken.');
    }

    // ---------------------------------------------------------------- Trend Analysis and Demand Projection

    public function testTrendAnalysisTotalsLeaveItOut(): void
    {
        $controller = new TrendAnalysisController();
        $call       = static function (string $method, ...$args) use ($controller) {
            $rm = new ReflectionMethod($controller, $method);
            $rm->setAccessible(true);

            return $rm->invoke($controller, ...$args);
        };
        $year  = (int) date('Y');
        $month = (int) date('n');

        $this->assertSame(3, $call('monthlyTotals', $year, null)[$month]);
        $this->assertSame(1, $call('monthlyTotals', $year, 'P-1')[$month]);
        $this->assertSame([$month => true], $call('monthsWithSales', $year));
        $this->assertSame(3, $call('unitsBetween', $this->today, $this->today, null));
        $this->assertSame(3, (int) array_sum($call('weekSeries', $this->today, null)['data']));

        // Only the voided sale in a year: that year has no sales.
        $this->sale('1500', '2019-05-05 10:00:00', [['P-1', 1, 250.0]]);
        $this->db->table('sales_transaction')->where('receipt_no', '1500')->update(['voided_at' => '2026-11-15 10:00:00']);
        $this->assertNotContains(2019, $call('yearsWithSales'));

        $this->assertSame(200, $this->asOwner()->get('admin/trend-analysis')->response()->getStatusCode());
    }

    public function testTheDemandProjectionLeavesItOut(): void
    {
        // A complete past year (Jan to Dec) with a March double click: March is 1 unit, not 2.
        $this->sale('901', '2025-01-02 10:00:00', [['P-2', 1, 120.0]]);
        [$o, $d] = $this->doubleClick('902', '903', '2025-03-10 10:00:00');
        $this->sale('904', '2025-12-30 10:00:00', [['P-2', 1, 120.0]]);
        $this->assertSame(DuplicateSaleVoid::VOIDED, DuplicateSaleVoid::apply($d, $o, $this->ownerId)['status']);

        $projection = DemandProjection::build('P-1', null, 'Patrol Shirt (M)', 2026);

        $this->assertEqualsWithDelta(1.0, (float) $projection['projection'][2], 0.001, 'March: the one real unit.');
    }

    // ---------------------------------------------------------------- Old Sales Records

    public function testOldSalesRecordsListItMarkedButDoNotCountIt(): void
    {
        $this->sale('501', '2010-03-04 10:00:00', [['P-2', 1, 120.0]]);
        [$o, $d] = $this->doubleClick('502', '503', '2010-03-05 10:00:00');
        $this->assertSame(DuplicateSaleVoid::VOIDED, DuplicateSaleVoid::apply($d, $o, $this->ownerId)['status']);

        $summary = SalesArchive::summary();
        $this->assertSame([2, 1, 3, 2], [$summary['receipts'], $summary['voided'], $summary['records'], (int) $summary['units']]);
        $this->assertEqualsWithDelta(370.0, (float) $summary['revenue'], 0.001);
        $this->assertSame(3, SalesArchive::count(), 'The records the purge removes: all three.');

        $report = SalesArchive::report();
        $this->assertSame(['units' => 2, 'revenue' => 370.0], $report[2010]['monthly'][3]);
        $this->assertSame(['501', '502', '503'], array_column($report[2010]['receipts'], 'receipt_no'));
        $this->assertSame([null, null, 'Voided duplicate of #502'], array_column($report[2010]['receipts'], 'voided'));
        $this->assertSame('1 x Patrol Shirt (M)', $report[2010]['receipts'][2]['items']);
        $this->assertSame(1, (int) array_column($report[2010]['top'], 'units', 'item_name')['Patrol Shirt']);

        $pdf = view('admin/archive_report', ['summary' => $summary, 'report' => $report, 'mode' => 'pdf']);
        $this->assertSame(1, substr_count($pdf, 'Voided duplicate of #502'));
        $this->assertStringContainsString('2 receipts (+ 1 voided duplicate, not counted)', $pdf);

        $page = preg_replace('/\s+/', ' ', $this->page('admin', 'admin/archive'));
        $this->assertStringContainsString('+ 1 voided duplicate (not counted)', $page);
        $this->assertStringContainsString('Remove 3 records', $page, 'The purge removes every record.');
    }

    // ---------------------------------------------------------------- Customer Return

    public function testACustomerReturnAgainstTheVoidedReceiptIsRefused(): void
    {
        $before = $this->stock('P-1');

        $result = $this->asStaff()->post('staff/log-transaction/store', ['type' => 'return', 'item_id' => 'P-1', 'qty_returned' => '1', 'original_sale_ref' => '1003']);

        $this->assertSame(site_url('staff/log-transaction/return'), $result->getRedirectUrl());
        $this->assertSame("Can't log this return", $_SESSION['toast']['title'] ?? null);
        $this->assertSame('Receipt #1003 was voided as a duplicate of #1002. Use receipt #1002 instead.', $_SESSION['toast']['message'] ?? null);
        $this->assertSame($before, $this->stock('P-1'), 'No stock was added.');
        $this->assertSame(0, $this->db->table('inventory_log')->where('log_type', 'RETURN')->countAllResults());
    }
}
