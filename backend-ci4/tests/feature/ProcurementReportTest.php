<?php

use App\Libraries\ProcurementReport;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ReorderFixture;

/**
 * The Owner's Procurement Report (admin/procurement-report): name, header, date chip, filter
 * toolbar, read-only table (no Order buttons or dialog), gray default values, the four header
 * tooltips, server-side search / filters / paging, and the PDF / CSV exports (Item ID first,
 * Basis note, current filters, no writes, Owner only). In-memory 'tests' database.
 *
 * @internal
 */
final class ProcurementReportTest extends CIUnitTestCase
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
    }

    protected function tearDown(): void
    {
        $this->tearDownReorderFixture();
        parent::tearDown();
    }

    private function page(string $query = ''): string
    {
        return (string) $this->asOwner()->get('admin/procurement-report' . ($query === '' ? '?tab=items' : $query))->getBody();
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        return new \DOMXPath($dom);
    }

    /** @return list<string> item IDs of the table rows, in order */
    private function rowIds(string $html): array
    {
        $ids = [];
        foreach ($this->xpath($html)->query('//tr[contains(@class,"pr-row")]') as $tr) {
            $ids[] = $tr->getAttribute('data-item-id');
        }

        return $ids;
    }

    private function cell(string $html, string $itemId, string $class): \DOMElement
    {
        return $this->xpath($html)->query('//tr[@data-item-id="' . $itemId . '"]/td[contains(@class,"' . $class . '")]')->item(0);
    }

    private function setClass(string $itemId, string $class): void
    {
        $this->db->table('cluster_segments')->insert(['item_id' => $itemId, 'cluster_label' => 0, 'cluster_name' => $class, 'abc_class' => $class, 'computed_at' => '2026-11-01 10:00:00']);
    }

    // ------------------------------------------------------------ name, header, top row

    /** The green header has no buttons (no Back either); Back sits in the content area instead. */
    public function testPageIsNamedProcurementReportWithOnlyTheTitleAndBellInTheHeader(): void
    {
        $html = $this->page();
        $x    = $this->xpath($html);

        $this->assertSame('Procurement Report | RfourL', trim($x->query('//title')->item(0)->textContent));
        $this->assertSame('Procurement Report', trim($x->query('//div[contains(@class,"topbar")]//h1')->item(0)->textContent));
        $topbar = $x->query('//div[contains(@class,"topbar")]')->item(0);
        $this->assertSame(0, $x->query('.//*[contains(concat(" ",@class," ")," btn ")]', $topbar)->length, 'No buttons in the header.');
        $this->assertStringNotContainsString('Back', $topbar->textContent);
        $this->assertSame(1, $x->query('.//a[contains(@class,"notification-button")]', $topbar)->length);
        $this->assertStringNotContainsString('Ordering Report', $html);
    }

    public function testBackButtonLinksToReorderAlertsBeforeTheDateChip(): void
    {
        $x    = $this->xpath($this->page());
        $back = $x->query('//div[contains(@class,"main-scroll")]//a[contains(@class,"lt-back")]');

        $this->assertSame(1, $back->length, 'One Back button, in the content area.');
        $link = $back->item(0);
        $this->assertStringEndsWith('admin/reorder-alerts', $link->getAttribute('href'));
        $this->assertSame('Back', trim($link->textContent));
        $this->assertSame(1, $x->query('./i[contains(@class,"bi-arrow-left")]', $link)->length, 'The left arrow, as on Log Transaction.');
        $this->assertSame(0, $x->query('//div[contains(@class,"topbar")]//a[contains(@class,"lt-back")]')->length, 'Not in the green header.');

        // Same row as the chip, before it: [← Back] [DSS results from …] … [Export PDF] [Export CSV].
        $row = $x->query('//div[contains(@class,"pr-top")]')->item(0);
        $this->assertSame($row, $link->parentNode->parentNode);
        $next = $link->nextSibling;
        while ($next !== null && ! $next instanceof \DOMElement) {
            $next = $next->nextSibling;
        }
        $this->assertStringContainsString('pr-date-chip', $next->getAttribute('class'));
    }

    public function testBackButtonIsTheSharedLogTransactionStyle(): void
    {
        $css = file_get_contents(FCPATH . 'assets/css/admin-theme.css');
        $this->assertStringContainsString('.lt-back {', $css, 'One shared rule for every page.');
        foreach (['staff/log_transaction_form.php', 'staff/log_transaction_restock.php'] as $view) {
            $source = file_get_contents(APPPATH . 'Views/' . $view);
            $this->assertStringContainsString('class="lt-back"', $source);
            $this->assertStringNotContainsString('.lt-back {', $source, "{$view} uses the shared rule.");
        }
    }

    public function testReorderAlertsButtonIsNamedProcurementReport(): void
    {
        $html = (string) $this->asOwner()->get('admin/reorder-alerts')->getBody();

        $link = $this->xpath($html)->query('//a[contains(@class,"ra-report-btn")]')->item(0);
        $this->assertSame('Procurement Report', trim($link->textContent));
        $this->assertStringEndsWith('admin/procurement-report', $link->getAttribute('href'));
    }

    public function testDateChipShowsTheLatestRunWithTheTooltipCard(): void
    {
        $this->addSize('P-1', 'Pants', 'S', 9, 3);
        $this->db->table('pdss_computation')->insert(['item_id' => 'P-1', 'computed_at' => '2026-11-12 03:41:06', 'eoq_value' => 12, 'safety_stock' => 1, 'reorder_point' => 3]);

        $chip = $this->xpath($this->page())->query('//*[contains(@class,"pr-date-chip")]')->item(0);
        $this->assertStringContainsString('DSS results from Nov 12, 2026', $chip->textContent);
        $this->assertStringContainsString('help-tip', $chip->getAttribute('class'), 'The same tooltip card as the (i) buttons.');
        $this->assertFalse($chip->hasAttribute('title'), 'No browser tooltip.');
        $this->assertStringContainsString('Updates weekly when the owner opens the Dashboard, or through Recalculate now.', html_entity_decode($chip->getAttribute('data-help-body')));
        $this->assertSame(1, $this->xpath($this->page())->query('//*[contains(@class,"pr-date-chip")]//i[contains(@class,"bi-calendar3")]')->length);
    }

    public function testExportButtonsAndNoDssSettingsBar(): void
    {
        $html = $this->page();
        $x    = $this->xpath($html);

        $this->assertStringContainsString('Export PDF', $x->query('//a[@id="exportPdf"]')->item(0)->textContent);
        $this->assertStringContainsString('Export CSV', $x->query('//a[@id="exportCsv"]')->item(0)->textContent);
        foreach (['DSS settings', 'prToggle', 'prDetails', 'pr-params', 'Cost/order'] as $gone) {
            $this->assertStringNotContainsString($gone, $html);
        }
    }

    // ------------------------------------------------------------ read-only table

    public function testTableColumnsAndNoOrderButtonsOrDialog(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 1, 3);
        $html = $this->page();
        $x    = $this->xpath($html);

        $headers = [];
        foreach ($x->query('//table[contains(@class,"pr-table")]/thead/tr/th') as $th) {
            $headers[] = trim(preg_replace('/\s+/', ' ', $th->textContent));
        }
        $this->assertSame(['Item', 'Size', 'Class', 'On Hand', 'Reorder At', 'Status', 'Safety Stock', 'Order Qty'], $headers);
        foreach (['open-order-modal', 'orderModal', 'New Stock Order', 'admin/orders/store', 'pr-order-btn', 'poModal', 'purchase-order-dialog.js', '>Action<'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, "Removed: {$gone}");
        }
        $this->assertSame(0, $x->query('//table//button[not(contains(@class,"help-tip")) and not(contains(@class,"btn-clear-search"))]')->length, 'Only (i) buttons in the table.');
    }

    public function testItemCellIsTheNameOnlyAndSizeDashWhenNone(): void
    {
        $this->addSize('W-1', 'Whistle', null, 20, 3);
        $x  = $this->xpath($this->page());
        $tr = $x->query('//tr[@data-item-id="W-1"]')->item(0);

        $this->assertSame('Whistle', trim($x->query('./td[1]', $tr)->item(0)->textContent));
        $this->assertSame('—', trim($x->query('./td[2]', $tr)->item(0)->textContent));
    }

    /** Display only: a "Long lead time" badge when the item's supplier lead time is 14 days or more. */
    public function testLongLeadTimeBadgeShowsFromFourteenDays(): void
    {
        $this->addSize('W-1', 'Whistle', null, 20, 3);
        $badge = '//tr[@data-item-id="W-1"]//span[contains(@class,"pr-lead-badge")]';

        $this->db->table('suppliers')->where('supplier_id', 'SUP-001')->update(['lead_time_days' => 13]);
        $this->assertSame(0, $this->xpath($this->page())->query($badge)->length);

        $this->db->table('suppliers')->where('supplier_id', 'SUP-001')->update(['lead_time_days' => 14]);
        $found = $this->xpath($this->page())->query($badge);
        $this->assertSame(1, $found->length);
        $this->assertSame('Long lead time', trim($found->item(0)->textContent));
    }

    public function testClassBadgeAndStatusBadgeLikeInventory(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 1, 3);
        $this->setClass('P-S', 'A');
        $x  = $this->xpath($this->page());
        $tr = $x->query('//tr[@data-item-id="P-S"]')->item(0);

        $badge = $x->query('.//span[contains(@class,"abc-badge")]', $tr)->item(0);
        $this->assertSame('A', trim($badge->textContent));
        $this->assertStringContainsString('cls-a', $badge->getAttribute('class'));
        $this->assertFalse($badge->hasAttribute('title'), 'No tooltip on the row.');
        $pill = $x->query('.//span[contains(@class,"status-pill")]', $tr)->item(0);
        $this->assertSame('Reorder Now', trim($pill->textContent));
        $this->assertStringContainsString('red', $pill->getAttribute('class'));
    }

    // ------------------------------------------------------------ gray defaults

    public function testReorderAtShowsAGrayZeroWhenThereIsNone(): void
    {
        $this->addSize('N-1', 'No Rop', null, 5, null);
        $this->addSize('Z-1', 'Zero Rop', null, 5, 0);
        $this->addSize('R-1', 'Real Rop', null, 9, 3);
        $html = $this->page();

        foreach (['N-1', 'Z-1'] as $id) {
            $cell = $this->cell($html, $id, 'pr-rop');
            $this->assertSame('0', trim($cell->textContent), "{$id}: 0, never blank");
            $this->assertStringContainsString('pr-default', $cell->getAttribute('class'));
            $this->assertFalse($cell->hasAttribute('title'));
            $this->assertSame(0, $cell->getElementsByTagName('*')->length, 'Just the number: no icon or tag.');
        }
        $real = $this->cell($html, 'R-1', 'pr-rop');
        $this->assertSame('3', trim($real->textContent));
        $this->assertStringNotContainsString('pr-default', $real->getAttribute('class'));
    }

    public function testOrderQtyIsGrayOnlyWhenItIsTheMinimumBecauseTheEoqWasLower(): void
    {
        // Minimum order 10 (fixture), S = 500, H = 60. Yearly demand 1 -> EOQ 4 (raised to 10);
        // yearly demand 6 -> EOQ 10 on its own; 12 is above the minimum.
        $this->db->table('dss_parameters')->update(['holding_cost_per_unit' => 60.48]);
        $this->addSize('LOW', 'Low', null, 9, 3);
        $this->addSize('EXACT', 'Exact', null, 9, 3);
        $this->addSize('HIGH', 'High', null, 9, 3);
        $this->db->table('pdss_computation')->where('item_id', 'LOW')->update(['eoq_value' => 10, 'annual_demand' => 1]);
        $this->db->table('pdss_computation')->where('item_id', 'EXACT')->update(['eoq_value' => 10, 'annual_demand' => 6]);
        $this->db->table('pdss_computation')->where('item_id', 'HIGH')->update(['eoq_value' => 12, 'annual_demand' => 9]);
        $html = $this->page();

        $this->assertSame('10', trim($this->cell($html, 'LOW', 'pr-eoq')->textContent));
        $this->assertStringContainsString('pr-default', $this->cell($html, 'LOW', 'pr-eoq')->getAttribute('class'));
        $this->assertStringNotContainsString('pr-default', $this->cell($html, 'EXACT', 'pr-eoq')->getAttribute('class'), 'A real EOQ of 10 stays dark.');
        $this->assertStringNotContainsString('pr-default', $this->cell($html, 'HIGH', 'pr-eoq')->getAttribute('class'));
    }

    public function testThePageUsesEoqRawWhenTheRunSavedIt(): void
    {
        // eoq_raw wins over the stopgap: yearly demand 99 alone would say "a real EOQ".
        $this->addSize('RAW-LOW', 'Raw Low', null, 9, 3);
        $this->addSize('RAW-TEN', 'Raw Ten', null, 9, 3);
        $this->db->table('pdss_computation')->where('item_id', 'RAW-LOW')->update(['eoq_value' => 10, 'eoq_raw' => 3, 'annual_demand' => 99]);
        $this->db->table('pdss_computation')->where('item_id', 'RAW-TEN')->update(['eoq_value' => 10, 'eoq_raw' => 10, 'annual_demand' => 1]);
        $html = $this->page();

        $this->assertStringContainsString('pr-default', $this->cell($html, 'RAW-LOW', 'pr-eoq')->getAttribute('class'));
        $this->assertStringNotContainsString('pr-default', $this->cell($html, 'RAW-TEN', 'pr-eoq')->getAttribute('class'));
    }

    public function testEoqRawIsUsedWhenTheRunSavedIt(): void
    {
        $dss = ['minimum_order_qty' => 10, 'ordering_cost' => 500, 'holding_cost_per_unit' => 60];

        $this->assertTrue(ProcurementReport::isMinimumOrder(['eoq_value' => 10, 'eoq_raw' => 3, 'annual_demand' => 999], $dss));
        $this->assertFalse(ProcurementReport::isMinimumOrder(['eoq_value' => 10, 'eoq_raw' => 10, 'annual_demand' => 0], $dss));
        $this->assertFalse(ProcurementReport::isMinimumOrder(null, $dss));
        // An older row (eoq_raw NULL): the stopgap from the saved yearly demand.
        $this->assertTrue(ProcurementReport::isMinimumOrder(['eoq_value' => 10, 'eoq_raw' => null, 'annual_demand' => 1], $dss));
    }

    // ------------------------------------------------------------ header tooltips

    public function testOnlyFourHeaderTooltipsWithShortText(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 1, 3);
        $this->db->table('dss_parameters')->update(['minimum_order_qty' => 15]);
        $x = $this->xpath($this->page());

        $tips = [];
        foreach ($x->query('//table[contains(@class,"pr-table")]/thead//button[contains(@class,"help-tip")]') as $b) {
            $tips[$b->getAttribute('data-help-title')] = trim(strip_tags(str_replace('</p>', "\n", html_entity_decode($b->getAttribute('data-help-body')))));
        }
        $this->assertSame(['Class', 'Reorder At', 'Status', 'Order Qty'], array_keys($tips));
        $this->assertSame("Group from K-Means clustering.\nTarget: A 95% · B 90% · C 85%", $tips['Class']);
        $this->assertSame("Order when stock reaches this number.\nGray 0: too few sales to set one.", $tips['Reorder At']);
        $this->assertSame("Suggested amount to order (EOQ).\nGray 15: the minimum order.", $tips['Order Qty'], 'The real minimum from settings.');
    }

    public function testStatusTooltipIsTheSameAsInventory(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 1, 3);
        $find = fn (string $html) => $this->xpath($html)->query('//thead//button[@data-help-title="Status"]')->item(0)->getAttribute('data-help-body');

        $this->assertSame($find((string) $this->asOwner()->get('admin/inventory')->getBody()), $find($this->page()));
    }

    // ------------------------------------------------------------ server-side filters, search, paging

    public function testTenRowsPerPageAndThePagerCoversEveryItem(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->addSize(sprintf('I-%02d', $i), sprintf('Item %02d', $i), null, 50, 3);
        }

        $this->assertCount(10, $this->rowIds($this->page()));
        $last = $this->page('?page=3');
        $this->assertSame(['I-21', 'I-22', 'I-23', 'I-24', 'I-25'], $this->rowIds($last));
        $this->assertMatchesRegularExpression('/Showing\s*<strong>21(–|&ndash;)25<\/strong>\s*of\s*<strong>25<\/strong> items/u', $last);
        $this->assertStringContainsString('Rows per page', $last);
        $this->assertCount(25, $this->rowIds($this->page('?per_page=50')));
    }

    public function testSearchByNameOrItemIdFindsItemsOnAnyPage(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->addSize(sprintf('I-%02d', $i), sprintf('Item %02d', $i), null, 50, 3);
        }
        $this->addSize('ZZ-9', 'Zulu Boots', '9', 50, 3);

        $this->assertSame(['ZZ-9'], $this->rowIds($this->page('?search=zulu')));
        $this->assertSame(['I-24'], $this->rowIds($this->page('?search=I-24')));
        $this->assertStringContainsString('No items match', $this->page('?search=nothinglikethis'));
    }

    public function testClassAndStatusFilters(): void
    {
        $this->addSize('A-1', 'Alpha', null, 1, 3);   // Reorder Now
        $this->addSize('B-1', 'Bravo', null, 4, 3);   // Low Stock
        $this->addSize('C-1', 'Charlie', null, 50, 3); // In Stock
        $this->addSize('O-1', 'Oscar', null, 0, 0);   // Out of Stock
        $this->setClass('A-1', 'A');
        $this->setClass('B-1', 'B');
        $this->setClass('C-1', 'A');

        $this->assertSame(['A-1', 'C-1'], $this->rowIds($this->page('?class=A')));
        $this->assertSame(['A-1'], $this->rowIds($this->page('?status=' . rawurlencode('Reorder Now'))));
        $this->assertSame(['B-1'], $this->rowIds($this->page('?status=' . rawurlencode('Low Stock'))));
        $this->assertSame(['O-1'], $this->rowIds($this->page('?status=' . rawurlencode('Out of Stock'))));
        $this->assertSame(['C-1'], $this->rowIds($this->page('?class=A&status=' . rawurlencode('In Stock'))));
        $this->assertSame(['A-1', 'B-1', 'C-1', 'O-1'], $this->rowIds($this->page('?class=bogus&status=bogus')), 'Unknown values are ignored.');
    }

    public function testFilterDropdownsAndClearAll(): void
    {
        $this->addSize('A-1', 'Alpha', null, 1, 3);
        $x = $this->xpath($this->page());

        $options = static function (\DOMXPath $x, string $id): array {
            $out = [];
            foreach ($x->query('//select[@id="' . $id . '"]/option') as $o) {
                $out[] = trim($o->textContent);
            }

            return $out;
        };
        $this->assertSame(['All classes', 'Class A', 'Class B', 'Class C'], $options($x, 'classFilter'));
        $this->assertSame(['All statuses', 'Reorder Now', 'Low Stock', 'Out of Stock', 'In Stock'], $options($x, 'statusFilter'));
        $this->assertSame('Search by item name or ID…', $x->query('//input[@id="searchInput"]')->item(0)->getAttribute('placeholder'));
        $this->assertTrue($x->query('//a[@id="clearFilters"]')->item(0)->hasAttribute('hidden'), 'Hidden with no filter.');

        $x    = $this->xpath($this->page('?class=A&status=' . rawurlencode('Reorder Now') . '&search=alp'));
        $link = $x->query('//a[@id="clearFilters"]')->item(0);
        $this->assertFalse($link->hasAttribute('hidden'));
        $this->assertSame('3', trim($x->query('//*[@id="clearFiltersCount"]')->item(0)->textContent));
        $this->assertStringContainsString('class=A', $x->query('//a[@id="exportCsv"]')->item(0)->getAttribute('href'), 'Exports carry the filters.');
    }

    public function testLiveSearchReturnsOnlyTheTable(): void
    {
        $this->addSize('A-1', 'Alpha', null, 1, 3);
        $html = (string) $this->asOwner()->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->get('admin/procurement-report?search=alpha')->response()->getBody();

        $this->assertStringContainsString('<div id="procurementResults"', $html);
        $this->assertStringNotContainsString('topbar', $html);
        $this->assertStringNotContainsString('id="searchInput"', $html, 'Just the table, not the toolbar.');
        $this->assertSame(['A-1'], $this->rowIds($html));
    }

    // ------------------------------------------------------------ exports

    public function testCsvHasItemIdFirstTheFilteredRowsAndTheBasis(): void
    {
        $this->db->table('dss_parameters')->update(['ordering_cost' => 750, 'holding_cost_per_unit' => 60.48, 'minimum_order_qty' => 10, 'demand_lookback_days' => 730]);
        $this->addSize('A-1', 'Alpha', 'M', 1, null);
        $this->addSize('B-1', 'Bravo', null, 50, 3);
        $this->setClass('A-1', 'A');
        $this->db->table('pdss_computation')->where('item_id', 'A-1')->update(['eoq_value' => 10, 'annual_demand' => 0]);

        $result = $this->asOwner()->get('admin/procurement-report/csv?class=A');
        $result->assertOK();
        $this->assertStringContainsString('text/csv', $result->response()->getHeaderLine('Content-Type'));
        $lines = array_map('str_getcsv', array_filter(explode("\n", ltrim((string) $result->response()->getBody(), "\xEF\xBB\xBF")), 'strlen'));

        $this->assertSame(['Item ID', 'Item', 'Size', 'Class', 'On Hand', 'Reorder At', 'Status', 'Safety Stock', 'Order Qty'], $lines[0]);
        $this->assertSame(['A-1', 'Alpha', 'M', 'A', '1', '0', 'In Stock', '1', '10'], $lines[1], 'Defaults as plain numbers.');
        $body = (string) $result->response()->getBody();
        $this->assertStringNotContainsString('B-1', $body, 'Only the filtered rows.');
        foreach (['Basis', 'DSS results date', '₱750 per order', '₱60.48 per item per year', 'A 95% / B 90% / C 85%', '"Minimum order quantity",10', '730 days'] as $text) {
            $this->assertStringContainsString($text, $body);
        }
    }

    public function testCsvListsAllMatchingRowsNotOnlyOnePage(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->addSize(sprintf('I-%02d', $i), sprintf('Item %02d', $i), null, 50, 3);
        }
        $body = (string) $this->asOwner()->get('admin/procurement-report/csv?page=2')->response()->getBody();

        $this->assertSame(25, substr_count($body, "\nI-"));
    }

    public function testPdfExportRendersWithTheBasis(): void
    {
        $this->addSize('A-1', 'Alpha', null, 1, 3);
        $result = $this->asOwner()->get('admin/procurement-report/pdf?search=alpha');

        $result->assertOK();
        $this->assertSame('application/pdf', $result->response()->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $result->response()->getBody());
    }

    public function testPdfViewHasItemIdFirstAndTheBasisNote(): void
    {
        $this->addSize('A-1', 'Alpha', null, 1, 3);
        $this->addSize('B-1', 'Bravo', null, 50, 3);
        $filters = ProcurementReport::filters(['search' => 'alpha']);
        $dss     = (new \App\Models\DssParameterModel())->current();
        $html    = view('reports/procurement_pdf', [
            'rows'        => ProcurementReport::rows($filters, $dss),
            'basis'       => ProcurementReport::basis('2026-11-12 03:41:06', $dss),
            'filterLabel' => ProcurementReport::filterLabel($filters),
            'generatedAt' => 'Nov 16, 2026 9:00 AM',
        ]);
        $x = $this->xpath($html);

        $this->assertSame('Item ID', trim($x->query('//thead//th[1]')->item(0)->textContent));
        $this->assertStringContainsString('A-1', $html);
        $this->assertStringNotContainsString('B-1', $html, 'The PDF uses the same filters.');
        foreach (['Basis', 'Nov 12, 2026', 'Ordering cost', 'Holding (storage) cost', 'A 95% / B 90% / C 85%', 'Minimum order quantity', 'Sales history window'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    public function testPdfViewIsOneSmallTablePerPageNotOneLongTable(): void
    {
        // Dompdf keeps a long table split over ~35 pages in memory (all ~890 sizes needed ~380MB,
        // over the live server's 128M); a table per page keeps it at ~170MB.
        $rows = [];
        for ($i = 1; $i <= 60; $i++) {
            $rows[] = ['item_id' => sprintf('X-%04d', $i), 'item_name' => "Item {$i}", 'size' => 'M', 'class' => 'A', 'stock' => $i,
                'rop' => 5, 'status' => 'In Stock', 'safety_stock' => 2, 'order_qty' => 10];
        }
        $html = view('reports/procurement_pdf', ['rows' => $rows, 'basis' => [['Ordering cost', '₱100 per order']], 'filterLabel' => '', 'generatedAt' => 'now']);
        $x    = $this->xpath($html);

        $this->assertSame(3, $x->query('//table[thead]')->length, '60 rows: 25 + 25 + 10, each table with its own header.');
        $this->assertSame(2, $x->query('//table[contains(@class,"page")]')->length, 'Every table after the first starts a new page.');
        $this->assertSame(60, $x->query('//table[thead]/tbody/tr')->length);
        $this->assertStringContainsString('X-0060', $html);
        $this->assertStringContainsString('Basis', $html);
    }

    public function testViewingAndExportingWriteNothing(): void
    {
        // Reorder Now with no alert: the old page's sync would have raised one.
        $this->addSize('A-1', 'Alpha', null, 1, 3);
        $before = [];
        foreach (['reorder_alert', 'pdss_computation', 'notifications', 'security_log', 'products'] as $t) {
            $before[$t] = $this->db->table($t)->get()->getResultArray();
        }

        foreach (['', '/csv', '/pdf'] as $path) {
            $this->asOwner()->get('admin/procurement-report' . $path)->assertOK();
        }

        foreach ($before as $t => $rows) {
            $this->assertSame($rows, $this->db->table($t)->get()->getResultArray(), "{$t} unchanged");
        }
    }

    public function testOwnerOnly(): void
    {
        foreach (['', '/csv', '/pdf'] as $path) {
            $result = $this->asStaff()->get('admin/procurement-report' . $path);
            $this->assertTrue($result->isRedirect(), "Staff is sent away from {$path}");
            $this->assertStringNotContainsString('Item ID', (string) $result->response()->getBody());
        }
    }
}
