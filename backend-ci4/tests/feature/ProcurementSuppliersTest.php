<?php

use App\Libraries\ProcurementReport;
use App\Libraries\SupplierProcurement;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ReorderFixture;

/**
 * Procurement Report > Suppliers (Task A2): the supplier-first tab (the default), the supplier detail, sample
 * suppliers without order stats, and the weekly / monthly supplier report with its PDF and CSV. The Items and Cost
 * comparison tabs and their exports keep working as they did. Runs on the in-memory 'tests' database.
 *
 * @internal
 */
final class ProcurementSuppliersTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ReorderFixture;

    protected $migrate = false;
    protected $refresh = false;

    private const WEEK  = 'period=weekly&date=2026-11-18';  // Mon Nov 16 - Sun Nov 22, 2026
    private const MONTH = 'period=monthly&date=2026-11-18'; // November 2026

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReorderFixture();
        Services::resetSingle('renderer');

        $forge = \Config\Database::forge();
        $forge->dropTable('app_settings', true);
        $forge->addField(['key' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true], 'value' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true]]);
        $forge->createTable('app_settings');
        $forge->addColumn('suppliers', ['category' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true]]);

        // Suppliers: Tactical Gear (SUP-001) and Quiapo Patches (SUP-002) come with the fixture; an empty one and a sample one.
        $this->db->table('suppliers')->where('supplier_id', 'SUP-001')->update(['lead_time_days' => 5, 'payment_terms' => 'Cash', 'category' => 'Clothes']);
        $this->db->table('suppliers')->where('supplier_id', 'SUP-002')->update(['lead_time_days' => 4, 'payment_terms' => 'Check', 'category' => 'Patches']);
        $this->db->table('suppliers')->insertBatch([
            ['supplier_id' => 'SUP-003', 'company_name' => 'Empty Supply Co', 'lead_time_days' => 9, 'payment_terms' => null, 'is_sample' => 0, 'category' => 'Metal'],
            ['supplier_id' => 'SMP-001', 'company_name' => 'Sample Stitch Works', 'lead_time_days' => 3, 'payment_terms' => 'Cash or Check', 'is_sample' => 1, 'category' => 'Patches'],
        ]);

        // SUP-001: Pants S (1 on hand, Reorder At 3: Reorder Now) and M (9: In Stock), Shirt (4: Low Stock), Cap (50: In Stock)
        $this->addSize('P-S', 'Pants', 'S', 1, 3, ['unit_cost' => 50, 'supplier_id' => 'SUP-001']);
        $this->addSize('P-M', 'Pants', 'M', 9, 3, ['unit_cost' => 50, 'supplier_id' => 'SUP-001']);
        $this->addSize('SH-1', 'Shirt', null, 4, 3, ['unit_cost' => 80, 'supplier_id' => 'SUP-001']);
        $this->addSize('CAP-1', 'Cap', null, 50, 3, ['unit_cost' => 20, 'supplier_id' => 'SUP-001']);
        // SUP-002: Patch S and M (both Reorder Now), Logo (In Stock)
        $this->addSize('PT-S', 'Patch', 'S', 2, 3, ['unit_cost' => 30, 'supplier_id' => 'SUP-002', 'category' => 'Patches']);
        $this->addSize('PT-M', 'Patch', 'M', 1, 3, ['unit_cost' => 30, 'supplier_id' => 'SUP-002', 'category' => 'Patches']);
        $this->addSize('LG-1', 'Logo', null, 30, 3, ['unit_cost' => 12, 'supplier_id' => 'SUP-002', 'category' => 'Patches']);
        $this->db->table('cluster_segments')->insert(['item_id' => 'P-S', 'cluster_label' => 0, 'cluster_name' => 'A', 'abc_class' => 'A', 'service_level' => 95, 'z_score' => 1.645, 'computed_at' => '2026-11-01 10:00:00']);

        // The sample supplier's price list (it owns no items)
        $this->db->table('supplier_prices')->insertBatch([
            ['supplier_id' => 'SMP-001', 'item_id' => 'PT-S', 'unit_price' => 27, 'lead_time_days' => null, 'min_order_qty' => 100, 'is_sample' => 1],
            ['supplier_id' => 'SMP-001', 'item_id' => 'LG-1', 'unit_price' => 10, 'lead_time_days' => 2, 'min_order_qty' => null, 'is_sample' => 1],
        ]);

        // Orders. SUP-001: SO-1 Preparing (Nov 16), SO-2 Delivered (Nov 10, 8 of 10 shipped), SO-3 Cancelled (Nov 25, must not count), SO-4 Shipped (Nov 20). SUP-002: SO-5 Order Confirmed (Nov 3).
        $order = function (string $id, string $supplier, string $status, string $date, string $po, array $lines) {
            $this->db->table('stock_order')->insert(['so_id' => $id, 'supplier_id' => $supplier, 'status' => $status, 'order_date' => $date, 'po_number' => $po]);
            foreach ($lines as [$item, $qty, $price, $shipped]) {
                $this->db->table('so_item')->insert(['so_id' => $id, 'item_id' => $item, 'order_quantity' => $qty, 'unit_price' => $price, 'shipped_quantity' => $shipped]);
            }
        };
        $order('SO-0001', 'SUP-001', 'Preparing', '2026-11-16', 'PO-2026-0001', [['P-S', 10, 50, null], ['SH-1', 4, 80, null]]);
        $order('SO-0002', 'SUP-001', 'Delivered', '2026-11-10', 'PO-2026-0002', [['P-M', 10, 50, 8]]);
        $order('SO-0003', 'SUP-001', 'Cancelled', '2026-11-25', 'PO-2026-0003', [['CAP-1', 99, 20, null]]);
        $order('SO-0004', 'SUP-001', 'Shipped', '2026-11-20', 'PO-2026-0004', [['CAP-1', 5, 20, 5]]);
        $order('SO-0005', 'SUP-002', 'Order Confirmed', '2026-11-03', 'PO-2026-0005', [['PT-S', 20, 30, null]]);
    }

    protected function tearDown(): void
    {
        \Config\Database::forge()->dropTable('app_settings', true);
        $this->tearDownReorderFixture();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function owner(string $query = ''): string
    {
        return (string) $this->asOwner()->get('admin/procurement-report' . $query)->response()->getBody();
    }

    private function html(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        return new \DOMXPath($dom);
    }

    private function clean(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /** @return array<string, list<string>> supplier id => the list row's cell texts */
    private function listRows(string $html): array
    {
        $x    = $this->html($html);
        $rows = [];
        foreach ($x->query('//table[@id="supplierList"]//tr[contains(@class,"sp-row")]') as $tr) {
            // The "Create PO for low items" button (SupplierPoTest) sits in the supplier cell; these cells are the data.
            foreach (iterator_to_array($x->query('.//*[contains(@class,"sp-po-wrap")]', $tr)) as $button) {
                $button->parentNode->removeChild($button);
            }
            $rows[$tr->getAttribute('data-supplier')] = array_map(fn ($td) => $this->clean($td->textContent), iterator_to_array($x->query('./td', $tr)));
        }

        return $rows;
    }

    // ================================================================ tabs

    public function testTheBareUrlOpensSuppliersAndTheTabsSwitch(): void
    {
        $bare = $this->html($this->owner());

        $this->assertSame(['Suppliers', 'Items', 'Cost comparison', 'Best supplier'], array_map(fn ($a) => $this->clean($a->textContent), iterator_to_array($bare->query('//nav[contains(@class,"pr-tabs")]/a'))));
        $this->assertStringContainsString('active', $bare->query('//nav[contains(@class,"pr-tabs")]/a')->item(0)->getAttribute('class'), 'Suppliers is the default tab.');
        $this->assertNotNull($bare->query('//table[@id="supplierList"]')->item(0));
        $this->assertNull($bare->query('//table[contains(@class,"pr-table")]')->item(0), 'Not the Items table.');
        $this->assertNull($bare->query('//*[@id="searchInput"]')->item(0), 'The Items filters are not on Suppliers.');

        $hrefs = array_map(static fn ($a) => $a->getAttribute('href'), iterator_to_array($bare->query('//nav[contains(@class,"pr-tabs")]/a')));
        $this->assertStringEndsWith('admin/procurement-report?tab=suppliers', $hrefs[0]);
        $this->assertStringEndsWith('admin/procurement-report?tab=items', $hrefs[1]);
        $this->assertStringEndsWith('admin/procurement-report?tab=comparison', $hrefs[2]);

        $items = $this->html($this->owner('?tab=items'));
        $this->assertNotNull($items->query('//table[contains(@class,"pr-table")]')->item(0), 'Items: the current report table.');
        $this->assertNull($items->query('//table[@id="supplierList"]')->item(0));
        $this->assertStringContainsString('active', $items->query('//nav[contains(@class,"pr-tabs")]/a')->item(1)->getAttribute('class'));

        $comparison = $this->html($this->owner('?tab=comparison'));
        $this->assertNotNull($comparison->query('//table[@id="pcTable"]')->item(0), 'Cost comparison, unchanged.');
        $this->assertStringContainsString('active', $comparison->query('//nav[contains(@class,"pr-tabs")]/a')->item(2)->getAttribute('class'));
        $this->assertNotNull($this->html($this->owner('?tab=suppliers'))->query('//table[@id="supplierList"]')->item(0));
    }

    public function testABareUrlWithItemsParametersStillOpensItems(): void
    {
        foreach (['?search=pants', '?class=A', '?status=Reorder+Now', '?page=1', '?per_page=25', '?search=&class=&status='] as $query) {
            $x = $this->html($this->owner($query));

            $this->assertNotNull($x->query('//table[contains(@class,"pr-table")]')->item(0), "{$query} opens Items.");
            $this->assertNull($x->query('//table[@id="supplierList"]')->item(0), $query);
        }
    }

    public function testTheItemsTabAndItsLinksStayOnTheItemsTab(): void
    {
        $x = $this->html($this->owner('?tab=items&status=Reorder+Now&per_page=25'));

        $this->assertStringContainsString('tab=items', $x->query('//a[@id="clearFilters"]')->item(0)->getAttribute('href'), '"Clear all" stays on Items.');
        $this->assertStringContainsString('per_page=25', $x->query('//a[@id="clearFilters"]')->item(0)->getAttribute('href'));
        $this->assertNotNull($x->query('//select[@id="statusFilter"]')->item(0));
    }

    public function testTheItemsAndComparisonExportsAreUnchanged(): void
    {
        $csv = array_map(static fn ($l) => str_getcsv($l, ',', '"', '\\'), explode("\n", trim(substr($this->asOwner()->get('admin/procurement-report/csv')->response()->getBody(), 3))));
        $this->assertSame(['Item ID', 'Item', 'Size', 'Class', 'On Hand', 'Reorder At', 'Status', 'Safety Stock', 'Order Qty'], $csv[0], 'No tab: the Items export, as before.');

        $pdf = $this->asOwner()->get('admin/procurement-report/pdf');
        $pdf->assertStatus(200);
        $this->assertStringContainsString('procurement-report-', $pdf->response()->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('cost-comparison-', $this->asOwner()->get('admin/procurement-report/pdf?tab=comparison')->response()->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('cost-comparison-', $this->asOwner()->get('admin/procurement-report/csv?tab=comparison')->response()->getHeaderLine('Content-Disposition'));

        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        $this->assertSame(3, substr_count($routes, "ReorderAlertController::report"), 'No new routes: the page, /pdf and /csv.');
    }

    // ================================================================ the supplier rows

    public function testOneRowPerActiveSupplierMostNeedingActionFirstSamplesLast(): void
    {
        $this->db->table('suppliers')->insert(['supplier_id' => 'SUP-004', 'company_name' => 'Archived Co', 'lead_time_days' => 5, 'is_active' => 0, 'is_sample' => 0]);

        $rows = $this->listRows($this->owner());

        $this->assertSame(['SUP-001', 'SUP-002', 'SUP-003', 'SMP-001'], array_keys($rows), 'Archived suppliers are not listed. SUP-001 has 2 items needing action, SUP-002 1, SUP-003 none; the sample is last.');
    }

    public function testASuppliersCountsStockOrdersDeliveryAndTerms(): void
    {
        $rows = $this->listRows($this->owner());

        // Supplier | Category | Items | Needs action | Stock on hand | Orders in progress | Last order | Delivery time | Payment terms
        $this->assertSame(['Tactical Gear Inc', 'Clothes', '3 items 4 sizes', '2 items 2 sizes', '64', '2', 'Nov 20, 2026', '5 days', 'Cash'], $rows['SUP-001'], 'Pants (S, M), Shirt, Cap; Pants S is Reorder Now and Shirt is Low Stock; 1 + 9 + 4 + 50 = 64 on hand; SO-1 and SO-4 in progress; the Cancelled SO-3 (Nov 25) is ignored.');
        $this->assertSame(['Quiapo Patches Co', 'Patches', '2 items 3 sizes', '1 item 2 sizes', '33', '1', 'Nov 3, 2026', '4 days', 'Check'], $rows['SUP-002']);
        $this->assertSame(['Empty Supply Co', 'Metal', '0 items 0 sizes', '0 items 0 sizes', '0', '0', '—', '9 days', '—'], $rows['SUP-003']);
    }

    public function testAnItemNeedsActionWhenAnyOfItsSizesDoes(): void
    {
        $this->assertSame(
            ['items' => 3, 'sizes' => 4, 'needs_items' => 2, 'needs_sizes' => 2, 'stock' => 64],
            SupplierProcurement::counts(array_values(array_filter(ProcurementReport::rows(ProcurementReport::filters([])), static fn ($r) => $r['supplier_id'] === 'SUP-001'))),
            'Pants has one size at Reorder Now and one In Stock: 1 item, 1 size.'
        );
        $this->assertSame(['items' => 0, 'sizes' => 0, 'needs_items' => 0, 'needs_sizes' => 0, 'stock' => 0], SupplierProcurement::counts([]));
    }

    public function testTheSizeCountsMatchTheItemsTab(): void
    {
        $suppliers = SupplierProcurement::suppliers();
        $real      = array_filter($suppliers, static fn ($s) => ! $s['is_sample']);
        $itemsTab  = fn (string $query) => $this->html($this->owner('?tab=items&per_page=50' . $query))->query('//tr[contains(@class,"pr-row")]')->length;

        $this->assertSame($itemsTab(''), array_sum(array_map(static fn ($s) => $s['counts']['sizes'], $real)), 'Every size listed on Items belongs to one supplier here.');
        $this->assertSame(
            $itemsTab('&status=Reorder+Now') + $itemsTab('&status=Low+Stock'),
            array_sum(array_map(static fn ($s) => $s['counts']['needs_sizes'], $real)),
            'Needs-action sizes = the Items tab\'s Reorder Now + Low Stock rows.'
        );
        $this->assertSame(4, $itemsTab('&status=Reorder+Now') + $itemsTab('&status=Low+Stock'), 'Pants S, Patch S and M are at Reorder Now; Shirt is Low Stock.');
        $this->assertSame(7, array_sum(array_map(static fn ($s) => $s['counts']['sizes'], $real)));
    }

    public function testSampleSuppliersShowOnlyTheirOffersAndNoOrderStats(): void
    {
        // Even a stray order against a sample supplier must not show as an order stat.
        $this->db->table('stock_order')->insert(['so_id' => 'SO-0099', 'supplier_id' => 'SMP-001', 'status' => 'Preparing', 'order_date' => '2026-11-17', 'po_number' => 'PO-2026-0099']);
        $x    = $this->html($this->owner());
        $row  = $x->query('//tr[@data-supplier="SMP-001"]')->item(0);
        $cells = array_map(fn ($td) => $this->clean($td->textContent), iterator_to_array($x->query('./td', $row)));

        $this->assertSame(['Sample Stitch Works Sample', 'Patches', 'Sample offers: 2 items', '3 days', 'Cash or Check'], $cells);
        $this->assertSame('5', $x->query('./td[3]', $row)->item(0)->getAttribute('colspan'), 'One cell in place of the five count and order columns.');
        $this->assertSame('Sample', $this->clean($x->query('.//span[contains(@class,"sp-badge")]', $row)->item(0)->textContent));
        $this->assertNull($x->query('.//*[contains(@class,"sp-needs")]', $row)->item(0));
        $this->assertSame(1, $x->query('//tr[contains(@class,"is-sample")]')->length);
    }

    public function testEachSupplierNameLinksToItsDetail(): void
    {
        $x = $this->html($this->owner());

        $link = $x->query('//tr[@data-supplier="SUP-001"]//a[contains(@class,"sp-link")]')->item(0);
        $this->assertStringContainsString('tab=suppliers', $link->getAttribute('href'));
        $this->assertStringContainsString('supplier=SUP-001', $link->getAttribute('href'));
    }

    // ================================================================ the supplier detail

    /** @return array<string, array<int, string>> item id => cell texts of the detail row */
    private function detailRows(string $html): array
    {
        $x = $this->html($html);
        $rows = [];
        foreach ($x->query('//table[@id="supplierItems"]//tr[contains(@class,"sp-size")]') as $tr) {
            $rows[$tr->getAttribute('data-item-id')] = array_map(fn ($td) => $this->clean($td->textContent), iterator_to_array($x->query('./td', $tr)));
        }

        return $rows;
    }

    public function testTheDetailListsTheSuppliersItemsWithTheItemsTabsNumbers(): void
    {
        $html = $this->owner('?tab=suppliers&supplier=SUP-001');
        $rows = $this->detailRows($html);

        $this->assertEqualsCanonicalizing(['P-S', 'P-M', 'SH-1', 'CAP-1'], array_keys($rows), 'Only SUP-001\'s items.');
        // Item | Size | Class | Unit cost | On hand | Reorder at | Status | Order qty
        $this->assertSame(['Pants', 'S', 'A', '₱50.00', '1', '3', 'Reorder Now', '12'], $rows['P-S']);
        $this->assertSame(['Pants', 'M', '—', '₱50.00', '9', '3', 'In Stock', '12'], $rows['P-M']);
        $this->assertSame('Low Stock', $rows['SH-1'][6]);

        // the same on-hand, status and Order Qty as the Items tab shows for those sizes
        $x = $this->html($this->owner('?tab=items&per_page=50'));
        foreach ($rows as $id => $cells) {
            $tds = array_map(fn ($td) => $this->clean($td->textContent), iterator_to_array($x->query('//tr[@data-item-id="' . $id . '"]/td')));
            $this->assertSame([$cells[4], $cells[5], $cells[6], $cells[7]], [$tds[3], $tds[4], $tds[5], $tds[7]], "{$id}: on hand, Reorder at, status and Order Qty agree with Items.");
        }
    }

    public function testTheDetailHeaderShowsTheSuppliersFacts(): void
    {
        $x = $this->html($this->owner('?tab=suppliers&supplier=SUP-001'));

        $facts = $this->clean($x->query('//div[contains(@class,"sp-facts")]')->item(0)->textContent);
        foreach (['Category Clothes', 'Delivery time 5 days', 'Payment terms Cash', 'Items 3 items (4 sizes)', 'Needs action 2 items (2 sizes)', 'Stock on hand 64'] as $fact) {
            $this->assertStringContainsString($fact, $facts);
        }
        $this->assertStringContainsString('Tactical Gear Inc', $x->query('//div[contains(@class,"sp-detail-head")]//h2')->item(0)->textContent);
        $this->assertNull($x->query('//table[@id="supplierList"]')->item(0), 'The detail replaces the list.');
        $this->assertStringContainsString('tab=suppliers', $x->query('//a[@id="spBack"]')->item(0)->getAttribute('href'));
    }

    public function testNeedsActionOnlyShowsOnlyReorderNowAndLowStock(): void
    {
        $html = $this->owner('?tab=suppliers&supplier=SUP-001&needs=1');
        $x    = $this->html($html);

        $this->assertEqualsCanonicalizing(['P-S', 'SH-1'], array_keys($this->detailRows($html)));
        $toggle = $x->query('//a[@id="spNeedsToggle"]')->item(0);
        $this->assertSame('true', $toggle->getAttribute('aria-checked'));
        $this->assertStringNotContainsString('needs=1', $toggle->getAttribute('href'), 'Turning it off goes back to every item.');

        $off = $this->html($this->owner('?tab=suppliers&supplier=SUP-001'))->query('//a[@id="spNeedsToggle"]')->item(0);
        $this->assertSame('false', $off->getAttribute('aria-checked'));
        $this->assertStringContainsString('needs=1', $off->getAttribute('href'));

        $none = $this->owner('?tab=suppliers&supplier=SUP-003&needs=1');
        $this->assertStringContainsString('Nothing needs action for this supplier.', $none);
    }

    public function testASampleSuppliersDetailListsItsOffers(): void
    {
        $html = $this->owner('?tab=suppliers&supplier=SMP-001');
        $x    = $this->html($html);

        $this->assertStringContainsString('Sample', $x->query('//div[contains(@class,"sp-detail-head")]//h2')->item(0)->textContent);
        $this->assertNotNull($x->query('//*[@id="spSampleNote"]')->item(0));
        $this->assertNull($x->query('//table[@id="supplierItems"]')->item(0), 'No items of its own.');
        $this->assertNull($x->query('//a[@id="spNeedsToggle"]')->item(0), 'Nothing to filter.');
        $offers = [];
        foreach ($x->query('//table[@id="supplierOffers"]//tr[contains(@class,"sp-offer")]') as $tr) {
            $offers[] = array_map(fn ($td) => $this->clean($td->textContent), iterator_to_array($x->query('./td', $tr)));
        }
        // Item | Size | Main supplier's cost | Offer price | Delivery days | Minimum order
        $this->assertSame([
            ['Logo', '—', '₱12.00', '₱10.00', '2', 'None'],
            ['Patch', 'S', '₱30.00', '₱27.00', '3', '100'],
        ], $offers, "The offer's own delivery days (2), else the supplier's (3).");
        $this->assertStringNotContainsString('Orders in progress', $html);
    }

    public function testAnUnknownOrArchivedSupplierFallsBackToTheList(): void
    {
        $this->db->table('suppliers')->where('supplier_id', 'SUP-003')->update(['is_active' => 0]);

        foreach (['NOPE', 'SUP-003', ''] as $id) {
            $x = $this->html($this->owner('?tab=suppliers&supplier=' . $id));
            $this->assertNotNull($x->query('//table[@id="supplierList"]')->item(0), "supplier={$id}");
        }
    }

    // ================================================================ the weekly / monthly report

    /** @return array<string, array<string, mixed>> supplier id => ['lines' => rows, 'total' => cells] */
    private function reportOf(string $html): array
    {
        $x   = $this->html($html);
        $out = [];
        foreach ($x->query('//div[contains(@class,"sp-supplier")]') as $block) {
            $lines = [];
            foreach ($x->query('.//tr[contains(@class,"sp-line")]', $block) as $tr) {
                $lines[] = array_map(fn ($td) => $this->clean($td->textContent), iterator_to_array($x->query('./td', $tr)));
            }
            $total = array_map(fn ($td) => $this->clean($td->textContent), iterator_to_array($x->query('.//tr[contains(@class,"sp-subtotal")]/td', $block)));
            $out[$block->getAttribute('data-supplier')] = ['lines' => $lines, 'total' => $total];
        }

        return $out;
    }

    public function testTheWeeklyReportListsOneLinePerItemOfTheWeeksRealOrders(): void
    {
        $html   = $this->owner('?tab=suppliers&' . self::WEEK);
        $report = $this->reportOf($html);

        $this->assertSame(['SUP-001'], array_keys($report), 'Only SUP-001 ordered in Nov 16-22 (SUP-002 ordered on Nov 3).');
        // Order | Date | Item | Size | Qty | Price per piece | Line total | Status | Received | Stock now
        $this->assertSame([
            ['PO-2026-0001', 'Nov 16, 2026', 'Pants', 'S', '10', '₱50.00', '₱500.00', 'Preparing', '—', '1'],
            ['PO-2026-0001', 'Nov 16, 2026', 'Shirt', '—', '4', '₱80.00', '₱320.00', 'Preparing', '—', '4'],
            ['PO-2026-0004', 'Nov 20, 2026', 'Cap', '—', '5', '₱20.00', '₱100.00', 'Shipped', '—', '50'],
        ], $report['SUP-001']['lines'], 'The Cancelled SO-0003 and the Nov 10 order are not here.');
        $this->assertSame(['Total · Tactical Gear Inc (3 lines)', '19', '', '₱920.00', '', '0', ''], $report['SUP-001']['total']);
        $this->assertSame('Nov 16 – Nov 22, 2026', $this->clean($this->html($html)->query('//*[@id="spPeriodLabel"]')->item(0)->textContent));

        $grand = $this->clean($this->html($html)->query('//*[@id="spGrandTotal"]')->item(0)->textContent);
        $this->assertSame('Grand total · Nov 16 – Nov 22, 2026 (3 lines) Qty 19 Received 0 ₱920.00', $grand);
    }

    public function testTheMonthlyReportAddsTheDeliveredOrderWithItsReceivedQuantity(): void
    {
        $html   = $this->owner('?tab=suppliers&' . self::MONTH);
        $report = $this->reportOf($html);

        $this->assertEqualsCanonicalizing(['SUP-001', 'SUP-002'], array_keys($report));
        $delivered = array_values(array_filter($report['SUP-001']['lines'], static fn ($l) => $l[7] === 'Delivered'))[0];
        $this->assertSame(['PO-2026-0002', 'Nov 10, 2026', 'Pants', 'M', '10', '₱50.00', '₱500.00', 'Delivered', '8', '9'], $delivered, 'Received = what the supplier shipped (8 of the 10 ordered).');
        $this->assertSame(['Total · Tactical Gear Inc (4 lines)', '29', '', '₱1,420.00', '', '8', ''], $report['SUP-001']['total']);
        $this->assertSame(['Total · Quiapo Patches Co (1 line)', '20', '', '₱600.00', '', '0', ''], $report['SUP-002']['total']);
        $this->assertSame('November 2026', $this->clean($this->html($html)->query('//*[@id="spPeriodLabel"]')->item(0)->textContent));
        $this->assertSame('Grand total · November 2026 (5 lines) Qty 49 Received 8 ₱2,020.00', $this->clean($this->html($html)->query('//*[@id="spGrandTotal"]')->item(0)->textContent));
    }

    public function testTheReportComesFromRealOrdersOnlyAndCancelledAreLeftOut(): void
    {
        $this->db->table('stock_order')->insert(['so_id' => 'SO-0098', 'supplier_id' => 'SMP-001', 'status' => 'Delivered', 'order_date' => '2026-11-17', 'po_number' => 'PO-2026-0098']);
        $this->db->table('so_item')->insert(['so_id' => 'SO-0098', 'item_id' => 'PT-S', 'order_quantity' => 7, 'unit_price' => 27, 'shipped_quantity' => 7]);

        $report = SupplierProcurement::report(SupplierProcurement::period('monthly', '2026-11-18'));

        $this->assertSame(['SUP-002', 'SUP-001'], array_column($report['suppliers'], 'id'), 'By supplier name; a sample supplier is never in the report.');
        $this->assertSame(5, $report['grand']['lines'], 'The Cancelled order (99 caps) is not counted either.');
        $this->assertSame(2020.0, $report['grand']['amount']);
    }

    public function testAPeriodWithNoOrdersSaysSo(): void
    {
        $html = $this->owner('?tab=suppliers&period=weekly&date=2026-03-04');

        $this->assertStringContainsString('No orders were placed in this period.', $html);
        $this->assertSame('Grand total · Mar 2 – Mar 8, 2026 (0 lines) Qty 0 Received 0 ₱0.00', $this->clean($this->html($html)->query('//*[@id="spGrandTotal"]')->item(0)->textContent));
    }

    public function testThePeriodSelectorPrevNextAndTheDefault(): void
    {
        $x = $this->html($this->owner('?tab=suppliers&' . self::WEEK));

        $seg = array_map(fn ($a) => [$this->clean($a->textContent), str_contains($a->getAttribute('class'), 'active')], iterator_to_array($x->query('//div[contains(@class,"sp-seg")]/a')));
        $this->assertSame([['Weekly', true], ['Monthly', false]], $seg);
        $nav = array_map(static fn ($a) => $a->getAttribute('href'), iterator_to_array($x->query('//div[contains(@class,"sp-nav")]/a')));
        $this->assertStringContainsString('date=2026-11-09', $nav[0], 'Previous week.');
        $this->assertStringContainsString('date=2026-11-23', $nav[1], 'Next week.');
        $this->assertStringContainsString('This week', $this->owner('?tab=suppliers&period=weekly&date=2026-03-04'), 'A past week offers a way back to this one.');

        $default = $this->html($this->owner());
        $today   = SupplierProcurement::period(null, null);
        $this->assertSame('weekly', $today['type'], 'Weekly is the default.');
        $this->assertSame($today['label'], $this->clean($default->query('//*[@id="spPeriodLabel"]')->item(0)->textContent), 'The current week by default.');
        $this->assertStringNotContainsString('This week', $this->owner(), 'No link back when already on it.');
    }

    public function testThePeriodMath(): void
    {
        $this->assertSame(['2026-11-16', '2026-11-22'], [SupplierProcurement::period('weekly', '2026-11-16')['from'], SupplierProcurement::period('weekly', '2026-11-16')['to']], 'A Monday starts its week.');
        $this->assertSame(['2026-11-16', '2026-11-22'], [SupplierProcurement::period('weekly', '2026-11-22')['from'], SupplierProcurement::period('weekly', '2026-11-22')['to']], 'A Sunday ends it.');
        $this->assertSame(['2026-11-23', '2026-11-29'], [SupplierProcurement::period('weekly', '2026-11-23')['from'], SupplierProcurement::period('weekly', '2026-11-23')['to']]);
        $m = SupplierProcurement::period('monthly', '2026-02-10');
        $this->assertSame(['2026-02-01', '2026-02-28', 'February 2026', '2026-01-01', '2026-03-01'], [$m['from'], $m['to'], $m['label'], $m['prev'], $m['next']]);
        $this->assertSame('weekly', SupplierProcurement::period('yearly', 'garbage')['type'], 'Bad input falls back.');
        $this->assertSame(SupplierProcurement::period('weekly', date('Y-m-d'))['from'], SupplierProcurement::period('weekly', '2026-13-45')['from'], 'A bad date means today.');
        $this->assertTrue(SupplierProcurement::period('monthly', null)['is_current']);
    }

    // ================================================================ layout (no overlapping text)

    public function testTheListHasShortHeadersAndItemsAndSizesOnTwoLines(): void
    {
        $x       = $this->html($this->owner());
        $headers = array_map(fn ($th) => $this->clean($th->textContent), iterator_to_array($x->query('//table[@id="supplierList"]//th')));
        foreach (['In progress', 'Last order', 'Delivery', 'Terms'] as $short) {
            $this->assertContains($short, $headers);
        }
        $this->assertNotContains('Orders in progress', $headers);

        $cell = $x->query('//tr[@data-supplier="SUP-001"]/td[contains(@class,"sp-items")]')->item(0);
        $this->assertSame('3 items', $this->clean($x->query('./span[contains(@class,"sp-stack")]', $cell)->item(0)->textContent));
        $this->assertSame('4 sizes', $this->clean($x->query('./span[contains(@class,"sp-line2")]', $cell)->item(0)->textContent));
        $this->assertSame(1, $x->query('//tr[@data-supplier="SUP-001"]/td[contains(@class,"sp-needs-cell")]/span[contains(@class,"sp-line2")]')->length);
    }

    public function testTheTablesSizeToTheirContentAndScrollInsteadOfOverlapping(): void
    {
        $html = $this->owner('?tab=suppliers&' . self::MONTH);
        $this->assertStringContainsString('.data-table.inv-table.sp-table { table-layout: auto', $html);
        $this->assertStringContainsString('.inv-table-card .sp-scroll { overflow-x: auto', $html);
        $x = $this->html($html);
        $this->assertGreaterThan(0, $x->query('//div[contains(@class,"sp-scroll")]//table[contains(@class,"sp-table")]')->length);
        $this->assertGreaterThan(0, $x->query('//table[contains(@class,"sp-lines")]//th[contains(@class,"sp-c-order")]')->length, 'Order and Date have their own wide columns.');
        $this->assertGreaterThan(0, $x->query('//table[contains(@class,"sp-lines")]//td[contains(@class,"sp-c-date")]')->length);
        $this->assertStringContainsString('.sp-table td, .sp-table th { white-space: nowrap', $html);
    }

    public function testThePdfKeepsOrderDateAndStatusOnOneLine(): void
    {
        $period = SupplierProcurement::period('monthly', '2026-11-18');
        $view   = view('reports/procurement_suppliers_pdf', ['period' => $period, 'report' => SupplierProcurement::report($period), 'generatedAt' => 'now']);
        $this->assertStringContainsString('.nw { white-space: nowrap; }', $view);
        $this->assertStringContainsString('<td class="nw">PO-2026-0002</td>', $view);
        $this->assertStringContainsString('<th class="nw">Order</th><th class="nw">Date</th>', $view);
    }

    // ================================================================ exports

    public function testThePdfOfTheSupplierReport(): void
    {
        $pdf = $this->asOwner()->get('admin/procurement-report/pdf?tab=suppliers&' . self::MONTH);

        $pdf->assertStatus(200);
        $this->assertStringStartsWith('%PDF', $pdf->response()->getBody());
        $this->assertStringContainsString('supplier-report-monthly-2026-11-01.pdf', $pdf->response()->getHeaderLine('Content-Disposition'));

        $period = SupplierProcurement::period('monthly', '2026-11-18');
        $view   = view('reports/procurement_suppliers_pdf', ['period' => $period, 'report' => SupplierProcurement::report($period), 'generatedAt' => 'now']);
        foreach (['Supplier report', 'Monthly', 'November 2026', 'Tactical Gear Inc', 'Quiapo Patches Co', 'PO-2026-0002', 'Delivered', '₱1,420.00', '₱2,020.00', 'Grand total', 'Price per piece', 'Received', 'Stock now'] as $text) {
            $this->assertStringContainsString($text, $view);
        }
        $this->assertStringNotContainsString('Sample Stitch Works', $view);
        $weekly = $this->asOwner()->get('admin/procurement-report/pdf?tab=suppliers&' . self::WEEK);
        $this->assertStringContainsString('supplier-report-weekly-2026-11-16.pdf', $weekly->response()->getHeaderLine('Content-Disposition'));
    }

    /** @return list<list<string>> */
    private function csvRows(string $csv): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        return array_map(static fn ($l) => str_getcsv($l, ',', '"', '\\'), explode("\n", trim(substr($csv, 3))));
    }

    public function testTheCsvOfTheSupplierReport(): void
    {
        $result = $this->asOwner()->get('admin/procurement-report/csv?tab=suppliers&' . self::MONTH);
        $rows   = $this->csvRows($result->response()->getBody());

        $this->assertStringContainsString('text/csv', $result->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('supplier-report-monthly-2026-11-01.csv', $result->response()->getHeaderLine('Content-Disposition'));
        $this->assertSame(['Row', 'Supplier', 'Order', 'Order Date', 'Item', 'Size', 'Quantity', 'Price per Piece', 'Line Total', 'Status', 'Received', 'Current Stock'], $rows[0]);
        $lines = array_values(array_filter($rows, static fn ($r) => $r[0] === 'Line'));
        $this->assertCount(5, $lines);
        $this->assertContains(['Line', 'Tactical Gear Inc', 'PO-2026-0002', '2026-11-10', 'Pants', 'M', '10', '50.00', '500.00', 'Delivered', '8', '9'], $lines);
        $this->assertContains(['Line', 'Tactical Gear Inc', 'PO-2026-0001', '2026-11-16', 'Shirt', '—', '4', '80.00', '320.00', 'Preparing', '—', '4'], $lines, 'No received quantity: "—".');
        $totals = array_values(array_filter($rows, static fn ($r) => $r[0] === 'Supplier total'));
        $this->assertSame(['Supplier total', 'Quiapo Patches Co', '', '', '1 lines', '', '20', '', '600.00', '', '0', ''], $totals[0]);
        $this->assertSame(['Supplier total', 'Tactical Gear Inc', '', '', '4 lines', '', '29', '', '1420.00', '', '8', ''], $totals[1]);
        $grand = array_values(array_filter($rows, static fn ($r) => $r[0] === 'Grand total'))[0];
        $this->assertSame(['Grand total', '', '', '', '5 lines', '', '49', '', '2020.00', '', '8', ''], $grand);
        $this->assertSame(['Period', 'Monthly', '2026-11-01 to 2026-11-30'], $rows[count($rows) - 2]);
        $this->assertStringNotContainsString('Sample', $result->response()->getBody());
    }

    public function testTheCsvDefusesSpreadsheetFormulas(): void
    {
        $this->db->table('suppliers')->where('supplier_id', 'SUP-001')->update(['company_name' => '=HYPERLINK("http://x")']);

        $csv = $this->asOwner()->get('admin/procurement-report/csv?tab=suppliers&' . self::MONTH)->response()->getBody();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
    }

    public function testTheExportLinksOnTheSuppliersTabAreTheSupplierReport(): void
    {
        $x = $this->html($this->owner('?tab=suppliers&' . self::MONTH));

        foreach (['exportPdf', 'exportCsv'] as $id) {
            $href = $x->query('//a[@id="' . $id . '"]')->item(0)->getAttribute('href');
            $this->assertStringContainsString('tab=suppliers', $href);
            $this->assertStringContainsString('period=monthly', $href);
            $this->assertStringContainsString('date=2026-11-01', $href);
        }
    }

    // ================================================================ owner only

    public function testOnlyTheOwnerCanSeeTheSuppliersTabItsDetailAndItsExports(): void
    {
        $supplier = $this->db->table('users')->where('username', 'tgi')->get()->getRow();

        foreach (['admin/procurement-report', 'admin/procurement-report?tab=suppliers&supplier=SUP-001', 'admin/procurement-report/pdf?tab=suppliers&' . self::WEEK, 'admin/procurement-report/csv?tab=suppliers&' . self::WEEK] as $url) {
            $this->asOwner()->get($url)->assertStatus(200);
            $this->assertTrue($this->asStaff()->get($url)->isRedirect(), "staff: {$url}");
            $this->assertTrue($this->withSession(['isLoggedIn' => true, 'role' => 'Supplier', 'user_id' => (int) $supplier->user_id, 'username' => 'tgi', 'full_name' => 'Tactical Gear', 'supplier_id' => 'SUP-001', 'last_activity' => time()])->get($url)->isRedirect(), "supplier: {$url}");
            $guest = $this->withSession([])->get($url);
            $this->assertTrue($guest->isRedirect(), "guest: {$url}");
            $this->assertStringNotContainsString('Tactical Gear Inc', (string) $guest->response()->getBody());
        }
    }

    public function testNothingOnTheSuppliersTabWritesAnything(): void
    {
        $before = [$this->db->table('stock_order')->get()->getResultArray(), $this->db->table('products')->get()->getResultArray(), $this->db->table('pdss_computation')->get()->getResultArray(), $this->db->table('reorder_alert')->get()->getResultArray()];

        $this->owner('?tab=suppliers&supplier=SUP-001&needs=1');
        $this->owner('?tab=suppliers&' . self::MONTH);
        $this->asOwner()->get('admin/procurement-report/csv?tab=suppliers&' . self::MONTH);

        $this->assertSame($before, [$this->db->table('stock_order')->get()->getResultArray(), $this->db->table('products')->get()->getResultArray(), $this->db->table('pdss_computation')->get()->getResultArray(), $this->db->table('reorder_alert')->get()->getResultArray()]);
    }
}
