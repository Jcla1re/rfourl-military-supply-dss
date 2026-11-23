<?php

use App\Libraries\BestSupplier;
use App\Libraries\DssFormulas;
use App\Libraries\ProcurementReport;
use App\Models\SupplierPriceModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ReorderFixture;

/**
 * Procurement Report > Best supplier: a read-only comparison of the suppliers that offer each size (App\Libraries\BestSupplier).
 * The list opens on "Items with other suppliers", one size's comparison is at ?tab=best&item=ID, and the CSV has one line per
 * item and supplier. Nothing on this tab writes anything or changes the main supplier. In-memory 'tests' database.
 *
 * Settings: ordering cost 500, storage cost 60.48, system minimum 20.
 *
 * @internal
 */
final class BestSupplierTabTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ReorderFixture;

    protected $migrate = false;
    protected $refresh = false;

    private const TABLES = ['products', 'pdss_computation', 'supplier_prices', 'cluster_segments', 'suppliers', 'stock_order', 'so_item', 'reorder_alert', 'dss_parameters'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReorderFixture();

        $this->db->table('dss_parameters')->update(['ordering_cost' => 500, 'holding_cost_per_unit' => 60.48, 'minimum_order_qty' => 20]);
        $this->db->table('suppliers')->where('supplier_id', 'SUP-001')->update(['company_name' => 'Tactical Gear Inc', 'lead_time_days' => 12, 'is_sample' => 0, 'is_active' => 1]);
        $this->db->table('suppliers')->where('supplier_id', 'SUP-002')->update(['company_name' => 'Quiapo Patches Co', 'lead_time_days' => 4, 'is_sample' => 0, 'is_active' => 1]);
        $this->db->table('suppliers')->insertBatch([
            ['supplier_id' => 'SMP-001', 'company_name' => 'Pasig Tactical Supply Depot', 'lead_time_days' => 5, 'is_sample' => 1, 'is_active' => 1],
            ['supplier_id' => 'SMP-002', 'company_name' => 'Caloocan Embroidery Hub', 'lead_time_days' => 3, 'is_sample' => 1, 'is_active' => 1],
            ['supplier_id' => 'SUP-009', 'company_name' => 'Archived Offer Co', 'lead_time_days' => 2, 'is_sample' => 0, 'is_active' => 0],
        ]);

        // Pepper Spray (class A): D 50, sigma 3.696, Z 1.645; main SUP-001 at 132 (L 12), a sample at 139 (L 5).
        $this->addItem('P-PEP', 'Pepper Spray', null, 132, 'SUP-001', 50, 3.696, 1.645, 'A', 22, 29);
        $this->offer('P-PEP', 'SMP-001', 139, null, null, 1);
        $this->offer('P-PEP', 'SUP-009', 1, 1, null, 0); // an archived supplier cannot be ordered from: left out
        // Name Cloth (class A): main SUP-002 at 72 (L 4); Caloocan sample at 61 (L 3, minimum 100); Pasig sample at 66 (own lead 7).
        $this->addItem('P-NC', 'Name Cloth', null, 72, 'SUP-002', 100, 7.392, 1.645, 'A', 25, 41);
        $this->offer('P-NC', 'SMP-002', 61, 3, 100, 1);
        $this->offer('P-NC', 'SMP-001', 66, 7, null, 1);
        // Only one supplier: class A (warning) and class C (none).
        $this->addItem('P-ONLY', 'Whistle', null, 40, 'SUP-001', 30, 1.2, 1.645, 'A', 6, 20);
        $this->addItem('P-C', 'Cord', null, 10, 'SUP-001', 4, 0.2, 1.036, 'C', 1, 20);
        // No sales in the window, but another supplier's price exists.
        $this->addItem('P-NOSALES', 'Old Badge', null, 50, 'SUP-002', 0, 0.0, 1.036, 'C', 0, 20);
        $this->offer('P-NOSALES', 'SMP-001', 45, null, null, 1);
        // A real second supplier for a class A item: no single-source warning.
        $this->addItem('P-TWO', 'Shirt', 'M', 200, 'SUP-001', 80, 5.0, 1.645, 'A', 18, 36);
        $this->offer('P-TWO', 'SUP-002', 210, null, null, 0);
    }

    protected function tearDown(): void
    {
        $this->tearDownReorderFixture();
        parent::tearDown();
    }

    // ------------------------------------------------------------ helpers

    private function addItem(string $id, string $name, ?string $size, float $cost, string $supplier, int $demand, float $std, float $z, string $class, int $ss, int $eoq): void
    {
        $this->addSize($id, $name, $size, 50, 10, ['unit_cost' => $cost, 'supplier_id' => $supplier]);
        $this->db->table('pdss_computation')->where('item_id', $id)->update([
            'annual_demand' => $demand, 'avg_daily_demand' => $demand / 365, 'demand_std_dev' => $std, 'service_level_z' => $z,
            'safety_stock' => $ss, 'eoq_value' => $eoq, 'eoq_raw' => $eoq, 'lead_time_days' => (int) $this->db->table('suppliers')->where('supplier_id', $supplier)->get()->getRow()->lead_time_days,
        ]);
        $this->db->table('cluster_segments')->insert(['item_id' => $id, 'cluster_label' => 0, 'cluster_name' => $class, 'abc_class' => $class, 'z_score' => $z, 'computed_at' => '2026-11-01 10:00:00']);
    }

    private function offer(string $item, string $supplier, float $price, ?int $lead, ?int $min, int $sample): void
    {
        $this->db->table('supplier_prices')->insert(['supplier_id' => $supplier, 'item_id' => $item, 'unit_price' => $price, 'lead_time_days' => $lead, 'min_order_qty' => $min, 'is_sample' => $sample, 'created_at' => '2026-11-01 10:00:00']);
    }

    private function page(string $query = '?tab=best'): string
    {
        return (string) $this->asOwner()->get('admin/procurement-report' . $query)->getBody();
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        return new \DOMXPath($dom);
    }

    private function clean(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode($text)));
    }

    /** @return list<string> item ids of the list's rows, in order */
    private function listIds(string $html): array
    {
        $ids = [];
        foreach ($this->xpath($html)->query('//table[@id="bestList"]//tr[contains(@class,"bs-item")]') as $tr) {
            $ids[] = $tr->getAttribute('data-item-id');
        }

        return $ids;
    }

    /** Every table this feature could touch, as one comparable snapshot. */
    private function snapshot(): string
    {
        $out = [];
        foreach (self::TABLES as $t) {
            $out[$t] = $this->db->table($t)->get()->getResultArray();
        }

        return json_encode($out);
    }

    // ------------------------------------------------------------ the tab and the list

    public function testTheBestSupplierTabIsThere(): void
    {
        $x = $this->xpath($this->page());

        $this->assertSame(['Suppliers', 'Items', 'Cost comparison', 'Best supplier'], array_map(fn ($a) => $this->clean($a->textContent), iterator_to_array($x->query('//nav[contains(@class,"pr-tabs")]/a'))));
        $this->assertSame('Best supplier', $this->clean($x->query('//nav[contains(@class,"pr-tabs")]/a[contains(@class,"active")]')->item(0)->textContent));
    }

    public function testTheListOpensOnItemsWithOtherSuppliersBiggestSavingFirst(): void
    {
        $html = $this->page();

        // P-NC saves 216.79 a year, P-PEP 133.84; P-NOSALES has another offer but no sales; P-TWO's second supplier is dearer (no saving).
        $ids = $this->listIds($html);
        $this->assertSame(['P-NC', 'P-PEP'], array_slice($ids, 0, 2), 'Biggest yearly saving first.');
        $this->assertEqualsCanonicalizing(['P-NC', 'P-PEP', 'P-TWO', 'P-NOSALES'], $ids, 'Only items with more than one offer; the archived supplier\'s offer is not one.');
        $this->assertNotContains('P-ONLY', $ids);
        $this->assertNotContains('P-C', $ids);
    }

    public function testTheShowFiltersAndTheirCounts(): void
    {
        $x = $this->xpath($this->page());
        $chips = array_map(fn ($a) => $this->clean($a->textContent), iterator_to_array($x->query('//div[contains(@class,"bs-seg")]/a')));

        $this->assertSame(['Items with other suppliers 4', 'Single-source class A 3', 'All items 6'], $chips);

        // Class A with one REAL supplier: Pepper Spray (its other offer is a sample), Name Cloth (both others are samples) and Whistle.
        $this->assertEqualsCanonicalizing(['P-PEP', 'P-NC', 'P-ONLY'], $this->listIds($this->page('?tab=best&show=warn')));
        $this->assertCount(6, $this->listIds($this->page('?tab=best&show=all')));
    }

    public function testSearchAndClassFiltersApplyToTheList(): void
    {
        $this->assertSame(['P-PEP'], $this->listIds($this->page('?tab=best&search=Pepper')));
        $this->assertSame(['P-C'], $this->listIds($this->page('?tab=best&show=all&class=C&search=Cord')));
        $this->assertEqualsCanonicalizing(['P-NOSALES'], $this->listIds($this->page('?tab=best&class=C')), 'Class C items that have another supplier.');
    }

    public function testRowsShowEachTagsSupplierWithSampleLabelsAndTheSaving(): void
    {
        $x   = $this->xpath($this->page());
        $row = $x->query('//tr[@data-item-id="P-PEP"]')->item(0);
        $td  = array_map(fn ($c) => $this->clean($c->textContent), iterator_to_array($x->query('./td', $row)));

        // Item | Size | Class | Suppliers | Recommended | Cheapest | Fastest | Saving a year
        $this->assertSame('Pepper Spray', $td[0]);
        $this->assertSame('A', $td[2]);
        $this->assertStringStartsWith('2 Single source', $td[3]);
        $this->assertSame('Pasig Tactical Supply Depot Sample', $td[4], 'Recommended: the sample, labelled.');
        $this->assertSame('Tactical Gear Inc', $td[5], 'Cheapest: the main supplier.');
        $this->assertSame('Pasig Tactical Supply Depot Sample', $td[6], 'Fastest.');
        $this->assertSame('₱133.84', $td[7]);
    }

    public function testNoSalesAndOnlySupplierSayWhatTheyAre(): void
    {
        $x = $this->xpath($this->page('?tab=best&show=all'));

        $this->assertSame("No sales in the window – can't compare", $this->clean($x->query('//tr[@data-item-id="P-NOSALES"]/td[contains(@class,"bs-msg")]')->item(0)->textContent));
        $this->assertSame('Only supplier', $this->clean($x->query('//tr[@data-item-id="P-C"]/td[contains(@class,"bs-msg")]')->item(0)->textContent));
        $this->assertSame(0, $x->query('//tr[@data-item-id="P-C"]//*[contains(@class,"bs-warn")]')->length, 'Class C: no warning.');
        $this->assertSame(1, $x->query('//tr[@data-item-id="P-ONLY"]//*[contains(@class,"bs-warn")]')->length, 'Class A with one supplier: the warning.');
    }

    // ------------------------------------------------------------ the detail: the Pepper Spray example

    public function testPepperSprayDetailShowsTheHandCheckedNumbers(): void
    {
        $html = $this->page('?tab=best&item=P-PEP');
        $x    = $this->xpath($html);
        $rows = [];
        foreach ($x->query('//table[@id="bestCompare"]//tr[contains(@class,"bs-offer")]') as $tr) {
            $rows[$tr->getAttribute('data-supplier')] = array_map(fn ($c) => $this->clean($c->textContent), iterator_to_array($x->query('./td', $tr)));
        }

        $this->assertSame(['SUP-001', 'SMP-001'], array_keys($rows), 'The main supplier first; the archived supplier\'s offer is not listed.');
        // Supplier | Unit price | Lead time | Minimum | Order qty (Q) | Safety stock (SS) | Yearly cost | Tags
        $this->assertSame(['Tactical Gear Inc Main supplier', '₱132.00', '12 days', '—', '29', '22', '₱9,669.59', 'Cheapest'], $rows['SUP-001']);
        $this->assertSame(['Pasig Tactical Supply Depot Sample', '₱139.00', '5 days', '—', '29', '14', '₱9,535.75', "Recommended Fastest can't be ordered from"], $rows['SMP-001']);

        $facts = $this->clean($x->query('//div[contains(@class,"bs-facts")]')->item(0)->textContent);
        foreach (['Class A', 'Yearly demand (D) 50', 'Demand spread (σ) 3.696', 'Z-score 1.645', 'EOQ 29', 'Ordering cost (S) ₱500.00', 'Storage cost per piece a year (H) ₱60.48', 'System minimum 20'] as $fact) {
            $this->assertStringContainsString($fact, $facts);
        }
        $saving = $this->clean($x->query('//*[@id="bsSaving"]')->item(0)->textContent);
        $this->assertStringContainsString('Pasig Tactical Supply Depot would cost ₱133.84 a year less than the main supplier.', $saving);
        $this->assertStringContainsString('It is a sample supplier, so you cannot order from it.', $saving);
        $this->assertSame('Only one real supplier for a fast-moving item (other offers shown are sample data).', $this->clean($x->query('//*[@id="bsSingleSource"]')->item(0)->textContent));
        $this->assertStringContainsString('Comparison only', $html);
    }

    public function testASupplierMinimumShowsAndMovesQ(): void
    {
        $x  = $this->xpath($this->page('?tab=best&item=P-NC'));
        $tr = $x->query('//tr[@data-supplier="SMP-002"]')->item(0);
        $td = array_map(fn ($c) => $this->clean($c->textContent), iterator_to_array($x->query('./td', $tr)));

        $this->assertSame(['Caloocan Embroidery Hub Sample', '₱61.00', '3 days', '100', '100', '22', '₱10,954.56', "Recommended Cheapest Fastest can't be ordered from"], $td);
    }

    public function testNoSalesDetailHasNoCostsAndOnlySupplierDetailHasNoTags(): void
    {
        $nosales = $this->xpath($this->page('?tab=best&item=P-NOSALES'));
        $this->assertNotNull($nosales->query('//*[@id="bsNoSales"]')->item(0));
        $this->assertSame("No sales in the window – can't compare", $this->clean($nosales->query('//*[@id="bsNoSales"]')->item(0)->textContent));
        foreach ($nosales->query('//tr[contains(@class,"bs-offer")]') as $tr) {
            $this->assertSame('—', $this->clean($nosales->query('./td[7]', $tr)->item(0)->textContent), 'No yearly cost without sales.');
        }

        $only = $this->xpath($this->page('?tab=best&item=P-ONLY'));
        $this->assertNotNull($only->query('//*[@id="bsOnlySupplier"]')->item(0));
        $this->assertSame('Only one supplier for a fast-moving item.', $this->clean($only->query('//*[@id="bsSingleSource"]')->item(0)->textContent));
        $this->assertSame(0, $only->query('//*[contains(@class,"bs-tag")]')->length);
    }

    public function testAClassAItemWithTwoRealSuppliersHasNoWarning(): void
    {
        $x = $this->xpath($this->page('?tab=best&item=P-TWO'));

        $this->assertNull($x->query('//*[@id="bsSingleSource"]')->item(0));
        $this->assertSame('The main supplier is already the lowest yearly cost.', $this->clean($x->query('//*[@id="bsSaving"]')->item(0)->textContent));
    }

    public function testAnUnknownItemFallsBackToTheList(): void
    {
        $this->assertNotNull($this->xpath($this->page('?tab=best&item=NOPE'))->query('//table[@id="bestList"]')->item(0));
    }

    public function testAMainSupplierWithNoPriceIsShownAsNoPriceSet(): void
    {
        $this->db->table('products')->where('item_id', 'P-PEP')->update(['unit_cost' => 0]);
        $x = $this->xpath($this->page('?tab=best&item=P-PEP'));

        $main = $x->query('//tr[@data-supplier="SUP-001"]')->item(0);
        $this->assertSame('No price set', $this->clean($x->query('./td[2]', $main)->item(0)->textContent));
        $this->assertSame(0, $x->query('.//*[contains(@class,"bs-tag")]', $main)->length, 'Left out of the tags.');
        $this->assertNull($x->query('//*[@id="bsSaving"]')->item(0), 'No saving against a main supplier with no price.');
    }

    // ------------------------------------------------------------ the CSV, no PDF

    public function testTheCsvHasOneLinePerItemAndSupplier(): void
    {
        $result = $this->asOwner()->get('admin/procurement-report/csv?tab=best');

        $result->assertOK();
        $this->assertStringContainsString('text/csv', $result->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('best-supplier-', $result->response()->getHeaderLine('Content-Disposition'));
        $lines = array_map('str_getcsv', array_values(array_filter(explode("\n", ltrim((string) $result->response()->getBody(), "\xEF\xBB\xBF")), static fn ($l) => trim($l) !== '')));

        $this->assertSame(['Item ID', 'Item', 'Size', 'Class', 'Yearly Demand', 'Supplier', 'Role', 'Sample', 'Unit Price', 'Lead Time (days)', 'Minimum Order', 'Order Qty (Q)', 'Safety Stock (SS)', 'Yearly Cost', 'Tags', 'Note'], $lines[0]);
        // The default list is the 4 items with other suppliers: P-PEP 2, P-NC 3, P-TWO 2, P-NOSALES 2 = 9 lines, then the Basis note.
        $this->assertCount(1 + 9 + 1, $lines);
        $pep = array_values(array_filter($lines, static fn ($l) => ($l[0] ?? '') === 'P-PEP'));
        $this->assertSame(['Tactical Gear Inc', 'Main supplier', '', '132.00', '12', '', '29', '22', '9669.59', 'Cheapest'], array_slice($pep[0], 5, 10));
        $this->assertSame(['Pasig Tactical Supply Depot', 'Other supplier', 'Sample', '139.00', '5', '', '29', '14', '9535.75', 'Recommended, Fastest'], array_slice($pep[1], 5, 10));
        $this->assertSame('Basis', end($lines)[0]);

        $all = $this->asOwner()->get('admin/procurement-report/csv?tab=best&show=all');
        $this->assertStringContainsString('Whistle', (string) $all->response()->getBody(), 'show=all adds the single-supplier items.');
    }

    public function testThereIsNoPdfForThisTab(): void
    {
        $html = $this->page();
        $this->assertStringNotContainsString('id="exportPdf"', $html);
        $this->assertStringContainsString('id="exportCsv"', $html);

        $this->asOwner()->get('admin/procurement-report/pdf?tab=best')->assertRedirectTo(site_url('/admin/procurement-report?tab=best'));
    }

    // ------------------------------------------------------------ read-only, main supplier still drives the DSS

    public function testOpeningTheTabItsDetailAndTheCsvChangesNothing(): void
    {
        $before = $this->snapshot();

        $this->page();
        $this->page('?tab=best&show=all&per_page=50');
        $this->page('?tab=best&item=P-PEP');
        $this->page('?tab=best&item=P-NOSALES');
        $this->asOwner()->get('admin/procurement-report/csv?tab=best&show=all');

        $this->assertSame($before, $this->snapshot(), 'No row anywhere was written.');
        $this->assertSame('SUP-001', $this->db->table('products')->where('item_id', 'P-PEP')->get()->getRow()->supplier_id, 'The main supplier is unchanged.');
    }

    public function testThereIsNoWritePathForTheMainSupplier(): void
    {
        // No POST route exists for this page at all (CodeIgniter throws "can't find a route"), so nothing can be sent to change a supplier.
        try {
            $this->asOwner()->post('admin/procurement-report', ['tab' => 'best', 'item' => 'P-PEP', 'supplier_id' => 'SMP-001']);
            $this->fail('A POST to the report must not be routed.');
        } catch (\CodeIgniter\Exceptions\PageNotFoundException $e) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('SUP-001', $this->db->table('products')->where('item_id', 'P-PEP')->get()->getRow()->supplier_id);
    }

    public function testTheOtherTabsAreUnchanged(): void
    {
        foreach (['?tab=suppliers', '?tab=items', '?tab=comparison'] as $query) {
            $this->asOwner()->get('admin/procurement-report' . $query)->assertOK();
        }
        $this->assertStringNotContainsString('bs-table', $this->page('?tab=items'));
        $this->assertStringNotContainsString('bestList', $this->page('?tab=suppliers'));
    }

    public function testOnlyTheOwnerCanOpenIt(): void
    {
        $this->asStaff()->get('admin/procurement-report?tab=best')->assertRedirect();
        $this->asStaff()->get('admin/procurement-report/csv?tab=best')->assertRedirect();
    }

    // ------------------------------------------------------------ the loader agrees with the rest of the app

    public function testTheLoadersOffersMatchSupplierPriceModelOffersForItem(): void
    {
        $sizes = ProcurementReport::rows(ProcurementReport::filters([]));
        $by    = array_column($sizes, null, 'item_id');
        $db    = db_connect();
        $suppliers = array_column($db->table('suppliers')->get()->getResultArray(), null, 'supplier_id');
        $prices    = [];
        foreach ($db->table('supplier_prices')->get()->getResultArray() as $p) {
            $prices[$p['item_id']][] = $p;
        }

        foreach (['P-PEP', 'P-NC', 'P-TWO', 'P-ONLY'] as $id) {
            $mine  = BestSupplier::offers($by[$id], $suppliers, $prices[$id] ?? []);
            $model = array_values(array_filter((new SupplierPriceModel())->offersForItem($id), static fn ($o) => $o['supplier_id'] !== 'SUP-009')); // the loader leaves out archived suppliers
            $shape = static fn (array $o): array => [$o['supplier_id'], $o['is_main'], $o['is_sample'], $o['unit_price'], $o['lead_time_days'], $o['min_order_qty']];

            $this->assertSame(array_map($shape, $model), array_map($shape, $mine), $id);
        }
    }

    public function testTheMainSuppliersNumbersMatchTheDssAndTheItemsTab(): void
    {
        $sizes  = ProcurementReport::rows(ProcurementReport::filters([]));
        $result = BestSupplier::forItems(array_values(array_filter($sizes, static fn ($r) => $r['item_id'] === 'P-PEP')))['P-PEP'];
        $main   = array_column($result['rows'], null, 'supplier_id')['SUP-001'];
        $items  = array_column($sizes, null, 'item_id')['P-PEP'];

        $this->assertSame(DssFormulas::safetyStock(1.645, 3.696, 12), $main['ss']);
        $this->assertSame($items['safety_stock'], $main['ss'], 'Same Safety Stock as the Items tab.');
        $this->assertSame($items['order_qty'], $main['q'], 'Same Order Qty as the Items tab.');
    }
}
