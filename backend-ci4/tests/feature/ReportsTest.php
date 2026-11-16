<?php

use App\Libraries\AccountingReports;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ReorderFixture;

/**
 * The owner's Reports (Sales Journal, Purchases Journal, Period Summary): totals match the database for a
 * seeded range, the date filter, the CSV columns and rows, the PDFs, and owner-only access.
 * Runs on the in-memory 'tests' database.
 *
 * @internal
 */
final class ReportsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ReorderFixture;

    protected $migrate = false;
    protected $refresh = false;

    private const FROM = '2026-11-02';
    private const TO   = '2026-11-08';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReorderFixture();
        Services::resetSingle('renderer');

        $forge = \Config\Database::forge();
        foreach (['sales_transaction', 'sales_item', 'inventory_log', 'app_settings'] as $t) {
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
        $make('inventory_log', ['log_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'item_id' => $str, 'user_id' => $int, 'log_type' => $str, 'quantity_changed' => $int, 'stock_after_change' => $int, 'reference_so_id' => $str, 'notes' => $str, 'timestamp' => $when], 'log_id');

        $this->addSize('P-1', 'Pants', 'S', 10, 3);
        $this->addSize('P-2', 'Shirt', 'M', 10, 3);
        $this->seed();
    }

    protected function tearDown(): void
    {
        $forge = \Config\Database::forge();
        foreach (['sales_transaction', 'sales_item', 'inventory_log', 'app_settings'] as $t) {
            $forge->dropTable($t, true);
        }
        $this->tearDownReorderFixture();
        parent::tearDown();
    }

    /**
     * In range (Nov 2 - Nov 8): 3 receipts on 2 days. Outside: one the day before, one the day after.
     * Orders: two Delivered in range (one has no received_at), one Delivered outside, one still Shipped.
     */
    private function seed(): void
    {
        $sale = fn (string $no, string $when, float $gross, float $discount, float $cash, float $gcash, ?string $or = null, ?int $cashier = null) => $this->db->table('sales_transaction')->insert([
            'receipt_no' => $no, 'or_number' => $or, 'user_id' => $cashier ?? $this->staffId, 'sale_date' => $when, 'payment_method' => $gcash > 0 ? ($cash > 0 ? 'Split' : 'GCash') : 'Cash',
            'cash_amount' => $cash, 'gcash_amount' => $gcash, 'subtotal' => $gross, 'discount' => $discount, 'total_amount' => $gross - $discount,
        ]);
        $sale('1001', '2026-11-01 23:59:00', 999.00, 0, 999.00, 0);          // the day before: out
        $sale('1002', '2026-11-02 09:15:00', 1000.00, 50.00, 950.00, 0, 'A-0001');
        $sale('1003', '2026-11-02 14:30:00', 350.50, 0, 100.50, 250.00);
        $sale('1004', '2026-11-08 23:59:00', 200.00, 10.00, 0, 190.00, null, $this->ownerId); // the last day, late: in
        $sale('1005', '2026-11-09 00:00:00', 777.00, 0, 777.00, 0);          // the day after: out

        $order = function (string $id, string $status, ?string $po, ?string $dr, ?string $receivedAt, ?string $deliveredOn, array $lines, string $supplier = 'SUP-001') {
            $this->db->table('stock_order')->insert(['so_id' => $id, 'supplier_id' => $supplier, 'status' => $status, 'order_date' => '2026-10-25', 'po_number' => $po, 'dr_number' => $dr, 'received_at' => $receivedAt, 'actual_delivery_date' => $deliveredOn]);
            foreach ($lines as [$item, $ordered, $shipped, $cost]) {
                $this->db->table('so_item')->insert(['so_id' => $id, 'item_id' => $item, 'order_quantity' => $ordered, 'shipped_quantity' => $shipped, 'unit_price' => $cost]);
            }
        };
        // amount = quantity RECEIVED (what was shipped) x unit cost: 10 x 50 + 4 x 80 = 820
        $order('SO-0001', 'Delivered', 'PO-2026-0001', 'DR-2026-0001', '2026-11-04 10:00:00', '2026-11-04', [['P-1', 12, 10, 50.0], ['P-2', 4, 4, 80.0]]);
        // received before received_at was recorded: counted by the delivery date; no shipped_quantity: the ordered quantity: 5 x 20 = 100
        $order('SO-0002', 'Delivered', null, null, null, '2026-11-06', [['P-1', 5, null, 20.0]], 'SUP-002');
        $order('SO-0003', 'Delivered', 'PO-2026-0003', 'DR-2026-0003', '2026-11-20 10:00:00', '2026-11-20', [['P-1', 100, 100, 1.0]]); // out
        $order('SO-0004', 'Shipped', 'PO-2026-0004', 'DR-2026-0004', null, null, [['P-1', 50, 50, 10.0]]);                              // not delivered
    }

    private function owner(string $url): string
    {
        return (string) $this->asOwner()->get($url)->response()->getBody();
    }

    private function html(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        return new \DOMXPath($dom);
    }

    /** @return list<list<string>> the CSV's rows (BOM stripped) */
    private function csvRows(string $csv): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'UTF-8 BOM for Excel');
        $rows = [];
        foreach (explode("\n", trim(substr($csv, 3))) as $line) {
            $rows[] = str_getcsv($line, ',', '"', '\\');
        }

        return $rows;
    }

    private function dbSum(string $column): float
    {
        return round((float) $this->db->query("SELECT SUM({$column}) AS s FROM db_sales_transaction WHERE sale_date >= '2026-11-02 00:00:00' AND sale_date < '2026-11-09 00:00:00'")->getRow()->s, 2);
    }

    // ---------------------------------------------------------------- totals match the database

    public function testTheSalesJournalTotalsMatchTheDatabaseForTheRange(): void
    {
        $report = AccountingReports::salesJournal(self::FROM, self::TO);

        $this->assertSame(['1002', '1003', '1004'], array_column($report['rows'], 'receipt_no'), 'Only receipts in the range, oldest first.');
        $this->assertSame(3, $report['total']['count']);
        $this->assertSame($this->dbSum('subtotal'), $report['total']['gross']);
        $this->assertSame($this->dbSum('discount'), $report['total']['discount']);
        $this->assertSame($this->dbSum('total_amount'), $report['total']['net']);
        $this->assertSame($this->dbSum('cash_amount'), $report['total']['cash']);
        $this->assertSame($this->dbSum('gcash_amount'), $report['total']['gcash']);
        $this->assertSame([1550.50, 60.00, 1490.50, 1050.50, 440.00], [$report['total']['gross'], $report['total']['discount'], $report['total']['net'], $report['total']['cash'], $report['total']['gcash']]);
        $this->assertEqualsWithDelta($report['total']['net'], $report['total']['cash'] + $report['total']['gcash'], 0.001, 'Cash + GCash is the net.');
    }

    public function testTheSalesJournalHasDailySubtotals(): void
    {
        $report = AccountingReports::salesJournal(self::FROM, self::TO);

        $this->assertSame(['2026-11-02', '2026-11-08'], array_column($report['days'], 'date'));
        $nov2 = $report['days'][0];
        $this->assertSame([2, 1350.50, 50.00, 1300.50, 1050.50, 250.00], [$nov2['count'], $nov2['gross'], $nov2['discount'], $nov2['net'], $nov2['cash'], $nov2['gcash']]);
        $nov8 = $report['days'][1];
        $this->assertSame([1, 200.00, 10.00, 190.00, 0.0, 190.00], [$nov8['count'], $nov8['gross'], $nov8['discount'], $nov8['net'], $nov8['cash'], $nov8['gcash']]);
        $this->assertEqualsWithDelta($report['total']['net'], array_sum(array_column($report['days'], 'net')), 0.001, 'Days add up to the period.');
    }

    public function testTheSalesJournalRowsCarryReceiptOrCashierAndTime(): void
    {
        $rows = AccountingReports::salesJournal(self::FROM, self::TO)['rows'];

        $this->assertSame('A-0001', $rows[0]['or_number']);
        $this->assertNull($rows[1]['or_number'], 'No OR number recorded.');
        $this->assertSame(['Ana Cruz', '9:15 AM', '2026-11-02'], [$rows[0]['cashier'], $rows[0]['time'], $rows[0]['date']]);
        $this->assertSame('Owner One', $rows[2]['cashier']);
        $this->assertSame([1000.00, 50.00, 950.00, 950.00, 0.0], [$rows[0]['gross'], $rows[0]['discount'], $rows[0]['net'], $rows[0]['cash'], $rows[0]['gcash']]);
    }

    public function testThePurchasesJournalCountsDeliveredOrdersByQuantityReceived(): void
    {
        $report = AccountingReports::purchasesJournal(self::FROM, self::TO);

        $this->assertSame(['SO-0001', 'SO-0002'], array_column($report['rows'], 'so_id'), 'Delivered and received in the range only.');
        [$one, $two] = $report['rows'];
        $this->assertSame(['PO-2026-0001', 'DR-2026-0001', 'Tactical Gear Inc', '2026-11-04', 2, 820.00], [$one['po_number'], $one['dr_number'], $one['supplier'], $one['received'], $one['items'], $one['amount']], '10 shipped x 50 + 4 x 80, not the 12 ordered.');
        $this->assertSame([null, null, 'Quiapo Patches Co', '2026-11-06', 1, 100.00], [$two['po_number'], $two['dr_number'], $two['supplier'], $two['received'], $two['items'], $two['amount']], 'No numbers: null (shown as "—"); counted by delivery date; ordered quantity when nothing was declared shipped.');
        $this->assertSame(['count' => 2, 'amount' => 920.00], $report['total']);

        $byHand = $this->db->query("SELECT SUM(COALESCE(si.shipped_quantity, si.order_quantity) * si.unit_price) AS s FROM db_so_item si WHERE si.so_id IN ('SO-0001', 'SO-0002')")->getRow()->s;
        $this->assertEqualsWithDelta((float) $byHand, $report['total']['amount'], 0.001);
    }

    public function testThePeriodSummaryAddsUpTheTwoJournals(): void
    {
        $s = AccountingReports::summary(self::FROM, self::TO);

        $this->assertSame(1550.50, $s['sales']);
        $this->assertSame(60.00, $s['discounts']);
        $this->assertSame(1490.50, $s['net_sales']);
        $this->assertSame($this->dbSum('cash_amount'), $s['cash']);
        $this->assertSame($this->dbSum('gcash_amount'), $s['gcash']);
        $this->assertSame(920.00, $s['purchases']);
        $this->assertSame(3, $s['receipts']);
        $this->assertSame(2, $s['orders']);
    }

    // ---------------------------------------------------------------- the date range

    public function testTheDateFilterNarrowsEveryReport(): void
    {
        $nov2 = AccountingReports::salesJournal('2026-11-02', '2026-11-02');
        $this->assertSame(['1002', '1003'], array_column($nov2['rows'], 'receipt_no'));
        $this->assertSame(1300.50, $nov2['total']['net']);

        $this->assertSame(['SO-0002'], array_column(AccountingReports::purchasesJournal('2026-11-05', '2026-11-07')['rows'], 'so_id'));
        $this->assertSame(0, AccountingReports::summary('2026-12-01', '2026-12-31')['receipts'], 'An empty range is zero, not an error.');
        $this->assertSame(['rows' => [], 'days' => [], 'total' => ['count' => 0, 'gross' => 0.0, 'discount' => 0.0, 'net' => 0.0, 'cash' => 0.0, 'gcash' => 0.0]], AccountingReports::salesJournal('2026-12-01', '2026-12-31'));
        // both edges are whole days: 23:59 on the last day is in, 00:00 the day after is out
        $this->assertContains('1004', array_column(AccountingReports::salesJournal('2026-11-08', '2026-11-08')['rows'], 'receipt_no'));
        $this->assertNotContains('1005', array_column(AccountingReports::salesJournal('2026-11-08', '2026-11-08')['rows'], 'receipt_no'));
    }

    public function testTheRangeDefaultsToThisMonthAndRepairsBadInput(): void
    {
        $this->assertSame([date('Y-m-01'), date('Y-m-t')], AccountingReports::range(null, null));
        $this->assertSame([date('Y-m-01'), date('Y-m-t')], AccountingReports::range('nonsense', '2026-13-45'));
        $this->assertSame(['2026-11-02', '2026-11-08'], AccountingReports::range('2026-11-08', '2026-11-02'), 'Typed backwards: put right.');
        $this->assertSame('sales', AccountingReports::type('whatever'));

        $x = $this->html($this->owner('admin/reports'));
        $this->assertSame(date('Y-m-01'), $x->query('//input[@name="from"]')->item(0)->getAttribute('value'));
        $this->assertSame(date('Y-m-t'), $x->query('//input[@name="to"]')->item(0)->getAttribute('value'));
    }

    // ---------------------------------------------------------------- the page

    public function testTheSalesJournalPageShowsRowsSubtotalsAndTheTotal(): void
    {
        $x = $this->html($this->owner('admin/reports?type=sales&from=' . self::FROM . '&to=' . self::TO));

        $this->assertSame(3, $x->query('//table[@id="salesJournal"]//tr[contains(@class,"rp-row")]')->length);
        $this->assertSame(2, $x->query('//table[@id="salesJournal"]//tr[contains(@class,"rp-sub")]')->length, 'One subtotal per day.');
        $headers = array_map(static fn ($th) => trim($th->textContent), iterator_to_array($x->query('//table[@id="salesJournal"]/thead//th')));
        $this->assertSame(['Receipt No.', 'OR No.', 'Date & Time', 'Cashier', 'Gross', 'Discount', 'Net', 'Cash', 'GCash'], $headers);
        $first = preg_replace('/\s+/', ' ', $x->query('//table[@id="salesJournal"]//tr[contains(@class,"rp-row")]')->item(0)->textContent);
        $this->assertStringContainsString('#1002', $first);
        $this->assertStringContainsString('A-0001', $first);
        $this->assertStringContainsString('₱1,000.00', $first);
        $second = preg_replace('/\s+/', ' ', $x->query('//table[@id="salesJournal"]//tr[contains(@class,"rp-row")]')->item(1)->textContent);
        $this->assertStringContainsString('#1003 — ', $second, 'No OR number: "—".');
        $total = preg_replace('/\s+/', ' ', $x->query('//tr[@id="salesTotal"]')->item(0)->textContent);
        $this->assertStringContainsString('₱1,550.50', $total);
        $this->assertStringContainsString('₱60.00', $total);
        $this->assertStringContainsString('₱1,490.50', $total);
    }

    public function testThePurchasesJournalPageShowsDashesForMissingNumbers(): void
    {
        $x    = $this->html($this->owner('admin/reports?type=purchases&from=' . self::FROM . '&to=' . self::TO));
        $rows = $x->query('//table[@id="purchasesJournal"]//tr[contains(@class,"rp-row")]');

        $this->assertSame(2, $rows->length);
        $this->assertStringContainsString('PO-2026-0001 DR-2026-0001 Tactical Gear Inc Nov 4, 2026 2 ₱820.00', preg_replace('/\s+/', ' ', $rows->item(0)->textContent));
        $this->assertStringContainsString('— — Quiapo Patches Co Nov 6, 2026 1 ₱100.00', preg_replace('/\s+/', ' ', $rows->item(1)->textContent));
        $this->assertStringContainsString('₱920.00', $x->query('//tr[@id="purchasesTotal"]')->item(0)->textContent);
    }

    public function testThePeriodSummaryPageIsLabelledForTheAccountant(): void
    {
        $html = $this->owner('admin/reports?type=summary&from=' . self::FROM . '&to=' . self::TO);
        $x    = $this->html($html);

        $this->assertStringContainsString('Totals for posting to the trial balance (prepared by the accountant)', $html);
        $values = [];
        foreach ($x->query('//table[@id="periodSummary"]//tr') as $tr) {
            $values[trim($tr->childNodes->item(0)->textContent)] = trim($tr->childNodes->item(1)->textContent);
        }
        $this->assertSame([
            'Total sales (gross)' => '₱1,550.50', 'Discounts' => '₱60.00', 'Net sales' => '₱1,490.50', 'Cash' => '₱1,050.50', 'GCash' => '₱440.00',
            'Total purchases' => '₱920.00', 'Number of receipts' => '3', 'Number of orders received' => '2',
        ], array_filter($values, static fn ($k) => $k !== '', ARRAY_FILTER_USE_KEY));
    }

    public function testThePageHasTheThreeTabsAndTheExportButtonsKeepingTheRange(): void
    {
        $x = $this->html($this->owner('admin/reports?type=purchases&from=' . self::FROM . '&to=' . self::TO));

        $this->assertSame(['Sales Journal', 'Purchases Journal', 'Period Summary'], array_map(static fn ($a) => trim($a->textContent), iterator_to_array($x->query('//nav[contains(@class,"rp-tabs")]/a'))));
        $this->assertStringContainsString('Purchases Journal', $x->query('//nav[contains(@class,"rp-tabs")]/a[contains(@class,"active")]')->item(0)->textContent);
        foreach (['pdf' => 'Export PDF', 'csv' => 'Export CSV'] as $kind => $label) {
            $a = $x->query('//a[contains(@href,"admin/reports/' . $kind . '")]')->item(0);
            $this->assertNotNull($a, $label);
            $this->assertStringContainsString($label, $a->textContent);
            foreach (['type=purchases', 'from=' . self::FROM, 'to=' . self::TO] as $part) {
                $this->assertStringContainsString($part, $a->getAttribute('href'));
            }
        }
    }

    public function testTheSidebarHasReportsUnderSales(): void
    {
        $html = $this->owner('admin/reports');

        $sales = strpos($html, 'nav-section-label">SALES');
        $reports = strpos($html, 'href="' . site_url('admin/reports') . '"');
        $ordering = strpos($html, 'nav-section-label">ORDERING');
        $this->assertTrue($sales < $reports && $reports < $ordering, 'Reports sits between SALES and ORDERING.');
    }

    // ---------------------------------------------------------------- CSV

    private function csv(string $query): \CodeIgniter\Test\TestResponse
    {
        return $this->asOwner()->get('admin/reports/csv?' . $query);
    }

    public function testTheSalesCsvColumnsAndRows(): void
    {
        $result = $this->csv('type=sales&from=' . self::FROM . '&to=' . self::TO);

        $this->assertStringContainsString('text/csv', $result->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('sales-2026-11-02-to-2026-11-08.csv', $result->response()->getHeaderLine('Content-Disposition'));
        $rows = $this->csvRows($result->response()->getBody());

        $this->assertSame(['Row', 'Receipt No', 'OR No', 'Date', 'Time', 'Cashier', 'Gross', 'Discount', 'Net', 'Cash', 'GCash'], $rows[0]);
        $this->assertSame(['Receipt', '1002', 'A-0001', '2026-11-02', '9:15 AM', 'Ana Cruz', '1000.00', '50.00', '950.00', '950.00', '0.00'], $rows[1]);
        $this->assertSame(['Receipt', '1003', '—', '2026-11-02', '2:30 PM', 'Ana Cruz', '350.50', '0.00', '350.50', '100.50', '250.00'], $rows[2]);
        $this->assertSame(['Daily subtotal', '2 receipts', '', '2026-11-02', '', '', '1350.50', '50.00', '1300.50', '1050.50', '250.00'], $rows[3]);
        $this->assertSame('Daily subtotal', $rows[5][0]);
        $this->assertSame(['Period total', '3 receipts', '', '2026-11-02 to 2026-11-08', '', '', '1550.50', '60.00', '1490.50', '1050.50', '440.00'], end($rows));
        $this->assertCount(7, $rows, 'Header, 3 receipts, 2 daily subtotals, the period total.');
    }

    public function testThePurchasesCsvColumnsAndRows(): void
    {
        $rows = $this->csvRows($this->csv('type=purchases&from=' . self::FROM . '&to=' . self::TO)->response()->getBody());

        $this->assertSame(['PO No', 'DR No', 'Supplier', 'Date Received', 'Items', 'Amount'], $rows[0]);
        $this->assertSame(['PO-2026-0001', 'DR-2026-0001', 'Tactical Gear Inc', '2026-11-04', '2', '820.00'], $rows[1]);
        $this->assertSame(['—', '—', 'Quiapo Patches Co', '2026-11-06', '1', '100.00'], $rows[2]);
        $this->assertSame(['Period total', '', '', '', '2 orders', '920.00'], $rows[3]);
    }

    public function testThePeriodSummaryCsv(): void
    {
        $rows = $this->csvRows($this->csv('type=summary&from=' . self::FROM . '&to=' . self::TO)->response()->getBody());

        $this->assertSame(['Period Summary', '2026-11-02 to 2026-11-08'], $rows[0]);
        $this->assertSame('Totals for posting to the trial balance (prepared by the accountant)', $rows[1][0]);
        $this->assertSame(['Item', 'Amount'], $rows[2]);
        $this->assertSame(['Total sales (gross)', '1550.50'], $rows[3]);
        $this->assertSame(['Net sales', '1490.50'], $rows[5]);
        $this->assertSame(['Total purchases', '920.00'], $rows[8]);
        $this->assertSame(['Number of orders received', '2'], end($rows));
    }

    public function testTheCsvDefusesSpreadsheetFormulasInNames(): void
    {
        $this->db->table('users')->where('user_id', $this->staffId)->update(['full_name' => '=HYPERLINK("http://x")']);

        $csv = $this->csv('type=sales&from=' . self::FROM . '&to=' . self::TO)->response()->getBody();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
    }

    // ---------------------------------------------------------------- PDF

    public function testEachReportExportsAsAPdf(): void
    {
        foreach (['sales', 'purchases', 'summary'] as $type) {
            $result = $this->asOwner()->get("admin/reports/pdf?type={$type}&from=" . self::FROM . '&to=' . self::TO);

            $result->assertStatus(200);
            $this->assertStringContainsString('application/pdf', $result->response()->getHeaderLine('Content-Type'));
            $this->assertStringStartsWith('%PDF', $result->response()->getBody());
            $this->assertStringContainsString("{$type}-2026-11-02-to-2026-11-08.pdf", $result->response()->getHeaderLine('Content-Disposition'));
        }
    }

    public function testThePdfViewsCarryTheTotalsAndTheAccountantLabel(): void
    {
        $data = ['from' => self::FROM, 'to' => self::TO, 'generatedAt' => 'Nov 15, 2026 9:00 AM'];

        $sales = view('reports/pdf', $data + ['type' => 'sales', 'report' => AccountingReports::salesJournal(self::FROM, self::TO)]);
        foreach (['Sales Journal', 'November 2, 2026 to November 8, 2026', '#1002', 'A-0001', '₱1,550.50', '₱1,490.50', 'Subtotal'] as $text) {
            $this->assertStringContainsString($text, $sales);
        }
        $purchases = view('reports/pdf', $data + ['type' => 'purchases', 'report' => AccountingReports::purchasesJournal(self::FROM, self::TO)]);
        foreach (['Purchases Journal', 'PO-2026-0001', 'DR-2026-0001', '₱820.00', '₱920.00'] as $text) {
            $this->assertStringContainsString($text, $purchases);
        }
        $summary = view('reports/pdf', $data + ['type' => 'summary', 'report' => AccountingReports::summary(self::FROM, self::TO)]);
        foreach (['Period Summary', 'Totals for posting to the trial balance (prepared by the accountant)', '₱1,490.50', '₱920.00'] as $text) {
            $this->assertStringContainsString($text, $summary);
        }
    }

    // ---------------------------------------------------------------- owner only

    public function testOnlyTheOwnerCanOpenTheReports(): void
    {
        foreach (['admin/reports', 'admin/reports/pdf', 'admin/reports/csv'] as $url) {
            $this->asOwner()->get($url . '?type=sales')->assertStatus(200);

            $this->asStaff()->get($url)->assertRedirectTo(site_url('login'));
            $this->withSession([
                'isLoggedIn' => true, 'role' => 'Supplier', 'user_id' => (int) $this->db->table('users')->where('username', 'tgi')->get()->getRow()->user_id,
                'username' => 'tgi', 'full_name' => 'Tactical Gear', 'supplier_id' => 'SUP-001', 'last_activity' => time(),
            ])->get($url)->assertRedirect();
            $guest = $this->withSession([])->get($url);
            $guest->assertRedirect();
            $this->assertStringNotContainsString('Sales Journal', (string) $guest->response()->getBody());
        }
    }

    public function testStaffNeverGetTheSalesFiguresOrTheSidebarLink(): void
    {
        $staff = (string) $this->asStaff()->get('staff/sales')->response()->getBody();

        $this->assertStringNotContainsString('admin/reports', $staff);
    }

    // ---------------------------------------------------------------- duplicate OR numbers

    /** Receipts in December: Z-9 twice (once typed in lower case with spaces), Z-10 once, one with no OR number. */
    private function seedDecember(): void
    {
        foreach ([['2001', '2026-12-03 10:00:00', 'Z-9'], ['2002', '2026-12-04 11:00:00', ' z-9 '], ['2003', '2026-12-05 12:00:00', 'Z-10'], ['2004', '2026-12-06 12:00:00', null]] as [$no, $when, $or]) {
            $this->db->table('sales_transaction')->insert(['receipt_no' => $no, 'or_number' => $or, 'user_id' => $this->staffId, 'sale_date' => $when, 'payment_method' => 'Cash', 'cash_amount' => 100, 'subtotal' => 100, 'discount' => 0, 'total_amount' => 100]);
        }
    }

    public function testRepeatedOrNumbersAreMarkedInTheSalesJournal(): void
    {
        $this->seedDecember();
        $rows = array_column(AccountingReports::salesJournal('2026-12-01', '2026-12-31')['rows'], 'duplicate', 'receipt_no');

        $this->assertSame(['2001' => true, '2002' => true, '2003' => false, '2004' => false], $rows, 'Case and spaces ignored; unique and missing OR numbers are not marked.');
        $this->assertFalse(AccountingReports::salesJournal(self::FROM, self::TO)['rows'][0]['duplicate'], 'A-0001 is used once.');
    }

    public function testAnOrNumberRepeatedInAnotherPeriodIsStillADuplicate(): void
    {
        $this->db->table('sales_transaction')->insert(['receipt_no' => '3001', 'or_number' => 'A-0001', 'user_id' => $this->staffId, 'sale_date' => '2027-01-15 10:00:00', 'payment_method' => 'Cash', 'cash_amount' => 10, 'subtotal' => 10, 'total_amount' => 10]);

        $rows = array_column(AccountingReports::salesJournal(self::FROM, self::TO)['rows'], 'duplicate', 'receipt_no');

        $this->assertTrue($rows['1002'], 'The Nov 2 receipt shares its OR number with one in January.');
        $this->assertFalse($rows['1003']);
    }

    public function testTheDuplicateLabelShowsOnThePageThePdfAndTheCsv(): void
    {
        $this->seedDecember();
        $query = 'type=sales&from=2026-12-01&to=2026-12-31';

        $x    = $this->html($this->owner('admin/reports?' . $query));
        $rows = $x->query('//table[@id="salesJournal"]//tr[contains(@class,"rp-row")]');
        $this->assertSame(2, $x->query('//table[@id="salesJournal"]//span[contains(@class,"rp-dup")]')->length, 'Only the two Z-9 receipts.');
        $this->assertStringContainsString('Z-9 duplicate', preg_replace('/\s+/', ' ', $rows->item(0)->textContent));
        $this->assertStringNotContainsString('duplicate', $rows->item(2)->textContent, 'Z-10 is used once.');
        $this->assertStringNotContainsString('duplicate', $rows->item(3)->textContent);

        $pdf = view('reports/pdf', ['type' => 'sales', 'from' => '2026-12-01', 'to' => '2026-12-31', 'generatedAt' => 'now', 'report' => AccountingReports::salesJournal('2026-12-01', '2026-12-31')]);
        $this->assertSame(2, substr_count($pdf, '<span class="rp-dup"'));
        $this->assertStringContainsString('>duplicate</span>', $pdf);

        $csv = $this->csvRows($this->csv($query)->response()->getBody());
        $or = array_column(array_filter($csv, static fn ($r) => $r[0] === 'Receipt'), 2, 1); // receipt no => OR No
        $this->assertSame('Z-9 (duplicate)', $or['2001']);
        $this->assertStringEndsWith('(duplicate)', $or['2002']);
        $this->assertSame('Z-10', $or['2003']);
        $this->assertSame('—', $or['2004']);
        $this->assertSame(AccountingReports::csvColumns('sales'), $csv[0], 'The CSV columns are unchanged.');
    }

    public function testTheSalesJournalPdfIsOneSmallTablePerPageNotOneLongTable(): void
    {
        // Dompdf keeps a long table split over many pages in memory: a year's journal needed ~120MB
        // (live limit 128M) and several years went over 512MB. A table per page keeps it far lower.
        $money = ['gross' => 100.0, 'discount' => 0.0, 'net' => 100.0, 'cash' => 100.0, 'gcash' => 0.0];
        $rows  = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = ['receipt_no' => (string) (5000 + $i), 'or_number' => null, 'date' => '2026-11-02', 'time' => '9:00 AM',
                'cashier' => 'Owner', 'voided' => null, 'duplicate' => false] + $money;
        }
        $report = ['rows' => $rows, 'days' => [['date' => '2026-11-02', 'count' => 30] + $money], 'total' => ['count' => 30] + $money];
        $pdf    = view('reports/pdf', ['type' => 'sales', 'from' => self::FROM, 'to' => self::TO, 'generatedAt' => 'now', 'report' => $report]);

        // 30 receipts + 1 subtotal + 1 total = 32 rows: 22 on the first page, 10 on the second.
        $this->assertSame(2, substr_count($pdf, '<thead>'), 'Each page repeats the header.');
        $this->assertSame(1, substr_count($pdf, '<table class="page">'));
        $this->assertSame(1, substr_count($pdf, 'Subtotal'));
        $this->assertStringContainsString('#5030', $pdf);
        $this->assertStringContainsString('Period total (30 receipts)', $pdf);
    }

    // ---------------------------------------------------------------- POS: warn about a used OR number, still allow it

    /** @return array<string, array{0: string}> */
    public static function posRoles(): array
    {
        return ['owner' => ['admin'], 'staff' => ['staff']];
    }

    /** @dataProvider posRoles */
    public function testTheCheckoutCheckReportsTheReceiptAnOrNumberWasUsedOn(string $role): void
    {
        $this->seedDecember();
        $this->db->table('sales_transaction')->insert(['receipt_no' => '2999', 'or_number' => 'Z-9', 'user_id' => $this->staffId, 'sale_date' => '2026-12-20 10:00:00', 'payment_method' => 'Cash', 'cash_amount' => 1, 'subtotal' => 1, 'total_amount' => 1]);
        $session = fn () => $role === 'admin' ? $this->asOwner() : $this->asStaff();

        $used = json_decode($session()->get($role . '/sales/check-or?or_number=' . urlencode('  z-9 '))->response()->getBody(), true);
        $this->assertSame(['used' => true, 'receipt_no' => '2999'], $used, 'Latest receipt, case and spaces ignored.');
        $this->assertSame(['used' => false], json_decode($session()->get($role . '/sales/check-or?or_number=NEW-1')->response()->getBody(), true));
        $this->assertSame(['used' => false], json_decode($session()->get($role . '/sales/check-or')->response()->getBody(), true), 'No number: nothing to warn about.');
    }

    public function testTheCheckoutCheckIsForSignedInCashiersOnly(): void
    {
        $this->seedDecember();

        $this->withSession([])->get('admin/sales/check-or?or_number=Z-9')->assertRedirect();
        $this->withSession([])->get('staff/sales/check-or?or_number=Z-9')->assertRedirect();
        $this->asStaff()->get('admin/sales/check-or?or_number=Z-9')->assertRedirectTo(site_url('login'));
    }

    /** @dataProvider posRoles */
    public function testAUsedOrNumberIsOnlyAWarningTheSaleStillGoesThrough(string $role): void
    {
        $this->db->table('sales_transaction')->insert(['receipt_no' => '1733', 'or_number' => 'Z-9', 'user_id' => $this->staffId, 'sale_date' => '2026-12-03 10:00:00', 'payment_method' => 'Cash', 'cash_amount' => 100, 'subtotal' => 100, 'total_amount' => 100]);
        $this->db->table('products')->where('item_id', 'P-1')->update(['selling_price' => 100]);
        $session = $role === 'admin' ? $this->asOwner() : $this->asStaff();

        $session->post($role . '/sales/checkout', ['item_id' => ['P-1'], 'quantity' => ['1'], 'payment_method' => 'Cash', 'cash_received' => '500', 'or_number' => 'Z-9']);

        $this->assertSame(2, $this->db->table('sales_transaction')->where('or_number', 'Z-9')->countAllResults(), 'The new sale was saved with the repeated number.');
        $this->assertArrayNotHasKey('error', $_SESSION, 'No refusal.');
        $this->assertSame(9, (int) $this->db->table('products')->where('item_id', 'P-1')->get()->getRow()->current_stock, 'Stock was taken as usual.');
    }

    /** @dataProvider posRoles */
    public function testTheCheckoutFormAsksBeforeSubmitting(string $role): void
    {
        $html = (string) ($role === 'admin' ? $this->asOwner() : $this->asStaff())->get($role . '/sales')->response()->getBody();
        $x    = $this->html($html);

        $this->assertSame(site_url($role . '/sales/check-or'), $x->query('//input[@id="orNumber"]')->item(0)->getAttribute('data-check-url'));
        $this->assertStringContainsString('/assets/js/pos-or-check.js', $html);
        $js = file_get_contents(FCPATH . 'assets/js/pos-or-check.js');
        $this->assertStringContainsString("'OR no. ' + orNo + ' was already used on receipt #' + result.receipt_no + '. Continue anyway?'", $js);
        $this->assertStringContainsString('.catch(proceed)', $js, 'A failed check never blocks a sale.');
    }
}
