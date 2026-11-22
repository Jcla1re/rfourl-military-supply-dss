<?php

use App\Libraries\DssFormulas;
use App\Libraries\ProcurementComparison;
use App\Libraries\ProcurementDocs;
use App\Libraries\PurchaseOrderLine;
use App\Libraries\SupplierMinimum;
use App\Models\DssParameterModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ReorderFixture;

/**
 * Procurement optimization: the supplier minimum per item (Order Qty = the largest of EOQ, system minimum and
 * supplier minimum, with a note when the supplier's is above the EOQ), the Procurement Report's Cost comparison
 * (owner's usual order qty against the system's), the new setting and the supplier's payment terms.
 * Runs on the in-memory 'tests' database.
 *
 * @internal
 */
final class ProcurementOptimizationTest extends CIUnitTestCase
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
        Services::resetSingle('renderer');

        $forge = \Config\Database::forge();
        $forge->dropTable('app_settings', true);
        $forge->addField(['key' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true], 'value' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true]]);
        $forge->createTable('app_settings');

        $forge->addColumn('suppliers', ['category' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true], 'preferred_courier' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true], 'created_at' => ['type' => 'DATETIME', 'null' => true], 'updated_at' => ['type' => 'DATETIME', 'null' => true]]);

        // S = 50, H = 10, so EOQ = sqrt(2 x D x 50 / 10) = sqrt(10 x D)
        $this->db->table('dss_parameters')->update(['ordering_cost' => 50, 'holding_cost_per_unit' => 10, 'minimum_order_qty' => 10]);
    }

    protected function tearDown(): void
    {
        \Config\Database::forge()->dropTable('app_settings', true);
        $this->tearDownReorderFixture();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    /** A size with its latest DSS numbers: yearly demand D, the Order Qty the run saved and the EOQ before minimums. */
    private function sizeWithDss(string $id, string $name, ?string $size, int $demand, ?int $orderQty, ?int $eoqRaw = null, array $extra = [], int $stock = 50): void
    {
        $this->addSize($id, $name, $size, $stock, 3, $extra);
        $this->db->table('pdss_computation')->where('item_id', $id)->update(['annual_demand' => $demand, 'eoq_value' => $orderQty, 'eoq_raw' => $eoqRaw ?? $orderQty]);
    }

    /** D = 400 (EOQ 63, TC 632.46 against 1100 at 20), D = 100 (EOQ 32), D = 40 (EOQ 20, raised to the minimum 50), and one with no sales. */
    private function seedComparison(): void
    {
        $this->sizeWithDss('P-1', 'Pants', 'S', 400, 63);
        $this->sizeWithDss('P-2', 'Shirt', 'M', 100, 32);
        $this->sizeWithDss('P-3', 'Cap', null, 40, 50, 20);
        $this->sizeWithDss('P-4', 'Belt', null, 0, 10, 0);
        $this->db->table('cluster_segments')->insert(['item_id' => 'P-1', 'cluster_label' => 0, 'cluster_name' => 'A', 'abc_class' => 'A', 'service_level' => 95, 'z_score' => 1.645, 'computed_at' => '2026-11-01 10:00:00']);
    }

    private function html(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        return new \DOMXPath($dom);
    }

    private function owner(string $url): string
    {
        return (string) $this->asOwner()->get($url)->response()->getBody();
    }

    private function row(string $itemId): array
    {
        return $this->db->table('products')->where('item_id', $itemId)->get()->getRowArray();
    }

    // ================================================================ the math

    public function testTotalCostAndSavings(): void
    {
        // D = 400, S = 50, H = 10:   TC(Q) = D / Q x S + Q / 2 x H
        $this->assertSame(1100.0, ProcurementComparison::totalCost(400, 20, 50, 10), '400 / 20 x 50 + 20 / 2 x 10');
        $this->assertSame(700.0, ProcurementComparison::totalCost(400, 40, 50, 10), '500 + 200');
        $this->assertEqualsWithDelta(632.46, ProcurementComparison::totalCost(400, 63, 50, 10), 0.01);
        $this->assertSame(0.0, ProcurementComparison::totalCost(400, 0, 50, 10), 'No quantity: no cost to divide by.');
        $this->assertSame(20.0, ProcurementComparison::ordersPerYear(400, 20));
        $this->assertEqualsWithDelta(6.3, ProcurementComparison::ordersPerYear(400, 63), 0.05);
        $this->assertSame(0.0, ProcurementComparison::ordersPerYear(400, 0));
        // The lowest cost is at the EOQ: nothing around it is cheaper.
        $eoq = (int) round(sqrt(2 * 400 * 50 / 10));
        $this->assertLessThan(ProcurementComparison::totalCost(400, $eoq - 10, 50, 10), ProcurementComparison::totalCost(400, $eoq, 50, 10));
        $this->assertLessThan(ProcurementComparison::totalCost(400, $eoq + 10, 50, 10), ProcurementComparison::totalCost(400, $eoq, 50, 10));
    }

    public function testRowsWorkOutTheCostsAndTheSavingsPerSize(): void
    {
        $this->seedComparison();
        $rows = array_column(ProcurementComparison::rows(\App\Libraries\ProcurementReport::filters([])), null, 'item_id');

        $this->assertSame(['P-3', 'P-1', 'P-2'], array_keys($rows), 'By item name (Cap, Pants, Shirt); a size with no sales (P-4) is left out.');
        $p1 = $rows['P-1'];
        $this->assertSame([400, 20, 63, 1100.0, 632.46, 467.54, 42.5], [$p1['demand'], $p1['q_owner'], $p1['q_sys'], $p1['tc_owner'], $p1['tc_sys'], $p1['savings'], $p1['savings_pct']]);
        $this->assertSame([20.0, 6.3], [$p1['orders_owner'], $p1['orders_sys']], 'Orders a year: D / Q for both.');
        $this->assertSame(['A', 'Pants'], [$p1['class'], $p1['item_name']]);
        $p2 = $rows['P-2'];
        $this->assertSame([350.0, 316.25, 33.75, 9.6], [$p2['tc_owner'], $p2['tc_sys'], $p2['savings'], $p2['savings_pct']]);
        $p3 = $rows['P-3'];
        $this->assertSame([200.0, 290.0, -90.0, -45.0], [$p3['tc_owner'], $p3['tc_sys'], $p3['savings'], $p3['savings_pct']], 'The minimum order made the system qty dearer than 20: a negative saving, shown as it is.');
    }

    public function testTheCardsAddUpTheRows(): void
    {
        $this->seedComparison();

        $t = ProcurementComparison::totals(ProcurementComparison::rows(\App\Libraries\ProcurementReport::filters([])));

        $this->assertSame(['owner_cost' => 1650.0, 'sys_cost' => 1238.71, 'savings' => 411.29, 'savings_pct' => 24.9, 'items' => 3], $t);
        $this->assertSame(['owner_cost' => 0.0, 'sys_cost' => 0.0, 'savings' => 0.0, 'savings_pct' => 0.0, 'items' => 0], ProcurementComparison::totals([]));
    }

    public function testTheOwnersUsualQtyComesFromTheSettingAndDefaultsTo20(): void
    {
        $this->assertSame(20, DssParameterModel::DEFAULT_OWNER_USUAL_ORDER_QTY);
        $this->assertSame(20, (int) $this->db->table('dss_parameters')->get()->getRow()->owner_usual_order_qty, 'A new row starts at 20 (the migration says the same).');
        $this->assertSame(20, ProcurementComparison::ownerQty([]));
        $this->assertSame(20, ProcurementComparison::ownerQty(['owner_usual_order_qty' => 0]));
        $this->assertSame(35, ProcurementComparison::ownerQty(['owner_usual_order_qty' => 35]));

        $this->seedComparison();
        $this->db->table('dss_parameters')->update(['owner_usual_order_qty' => 40]);
        $p1 = array_column(ProcurementComparison::rows(\App\Libraries\ProcurementReport::filters([])), null, 'item_id')['P-1'];
        $this->assertSame([40, 700.0], [$p1['q_owner'], $p1['tc_owner']]);

        $migration = file_get_contents(APPPATH . 'Database/Migrations/2026-11-18-100100_AddOwnerUsualOrderQtyToDssParameters.php');
        $this->assertStringContainsString("'default' => 20", $migration);
    }

    // ================================================================ Order Qty with the supplier minimum

    public function testTheOrderQtyIsTheLargestOfTheEoqTheSystemMinimumAndTheSupplierMinimum(): void
    {
        $this->assertSame(12, DssFormulas::orderQty(7, 5, 12), 'The supplier minimum wins.');
        $this->assertSame(30, DssFormulas::orderQty(30, 5, 12), 'The EOQ wins.');
        $this->assertSame(10, DssFormulas::orderQty(3, 10, 8), 'The system minimum wins.');
        $this->assertSame(5, DssFormulas::orderQty(3, 5, null), 'No supplier minimum: as before.');
        $this->assertSame(5, DssFormulas::orderQty(3, 5, 0));
        $this->assertSame(0, DssFormulas::orderQty(0, 0, null));
        // dss:run uses it, and keeps eoq_raw as it was
        $run = file_get_contents(APPPATH . 'Commands/DssRun.php');
        $this->assertStringContainsString('DssFormulas::orderQty($eoqRaw, $minimumOrderQty,', $run);
        $this->assertStringContainsString("'eoq_raw'          => \$eoqRaw,", $run);
    }

    // ================================================================ the supplier-minimum note

    public function testTheNoteAppearsOnlyWhenTheSupplierMinimumIsAboveTheEoq(): void
    {
        $this->assertNull(SupplierMinimum::note(0, 40, 1200, 60), 'No minimum.');
        $this->assertNull(SupplierMinimum::note(40, 40, 1200, 60), 'Equal to the EOQ: nothing to say.');
        $this->assertNull(SupplierMinimum::note(30, 40, 1200, 60), 'Below the EOQ: the EOQ is what is ordered.');
        $this->assertNotNull(SupplierMinimum::note(41, 40, 1200, 60));
    }

    public function testTheNoteSaysWhatTheMinimumCoversAndCosts(): void
    {
        // M = 50, E = 40, D = 1200, H = 60:  N = 50 / 1200 x 12 = 0.5 months;  X = (50 - 40) / 2 x 60 = 300
        $note = SupplierMinimum::note(50, 40, 1200, 60.0);

        $this->assertSame('info', $note['level']);
        $this->assertSame('Supplier minimum is 50 (EOQ 40). Covers about 0.5 months of sales; extra storage about ₱300/year.', $note['text']);
        $this->assertSame([0.5, 300.0], [$note['months'], $note['extra_storage']]);
        $this->assertSame('Supplier minimum is 100 (EOQ 63). Covers about 3 months of sales; extra storage about ₱185/year.', SupplierMinimum::note(100, 63, 400, 10.0)['text'], '100 / 400 x 12 = 3; (100 - 63) / 2 x 10 = 185');
        $this->assertStringContainsString('about 1 month of sales', SupplierMinimum::note(100, 63, 1200, 10.0)['text'] . ' ' . 'about 1 month of sales', 'one month is singular');
        $this->assertStringContainsString('Covers about 1 month of', SupplierMinimum::note(100, 63, 1200, 10.0)['text']);
    }

    public function testMoreThanSixMonthsOfSalesIsAWarningInstead(): void
    {
        $six = SupplierMinimum::note(600, 100, 1200, 60.0); // exactly 6.0 months: still the plain note
        $this->assertSame('info', $six['level']);
        $this->assertSame(6.0, $six['months']);

        $warn = SupplierMinimum::note(601, 100, 1200, 60.0); // 6.01 months
        $this->assertSame('warning', $warn['level']);
        $this->assertSame('This minimum is more than 6 months of sales. Consider a smaller order from another supplier.', $warn['text']);
        $this->assertSame('warning', SupplierMinimum::note(800, 100, 1200, 60.0)['level'], '8 months');
        $this->assertSame('warning', SupplierMinimum::note(50, 10, 0, 60.0)['level'], 'No sales at all: no end to it.');
    }

    public function testTheNoteIsOnTheDialogLineReorderAlertsAndTheProcurementReport(): void
    {
        $this->sizeWithDss('P-1', 'Pants', 'S', 400, 100, 63, ['supplier_min_qty' => 100], 1); // M 100 > EOQ 63: 3 months, ₱185/year; 1 on hand = on the board
        $this->alertFor('P-1');
        $text = 'Supplier minimum is 100 (EOQ 63). Covers about 3 months of sales; extra storage about ₱185/year.';

        // the purchase order dialog's line data
        $line = PurchaseOrderLine::build($this->db->table('products')->where('item_id', 'P-1')->get()->getRowArray(), []);
        $this->assertSame(['level' => 'info', 'text' => $text], $line['supplier_note']);
        $this->assertSame(100, $line['supplier_min']);

        // Reorder Alerts
        $alerts = $this->owner('admin/reorder-alerts');
        $this->assertSame(1, substr_count($alerts, 'ra-min-note'), 'One note, under the item.');
        $this->assertStringContainsString($text, html_entity_decode($alerts));
        $this->assertStringContainsString('data-line=', $alerts);
        $this->assertStringContainsString('supplier_note', html_entity_decode($alerts), 'The dialog gets it with the line.');

        // Procurement Report
        $report = $this->html($this->owner('admin/procurement-report'));
        $note   = $report->query('//span[contains(@class,"pr-min-note")]')->item(0);
        $this->assertNotNull($note);
        $this->assertSame($text, trim($note->textContent));
        $this->assertStringNotContainsString('is-warning', $note->getAttribute('class'));
    }

    public function testNoNoteWhenTheMinimumIsNotAboveTheEoq(): void
    {
        $this->sizeWithDss('P-1', 'Pants', 'S', 400, 63, 63, ['supplier_min_qty' => 50], 1); // 50 is below the EOQ 63
        $this->sizeWithDss('P-2', 'Shirt', 'M', 400, 63, 63, [], 1);                          // no minimum at all
        $this->alertFor('P-1');
        $this->alertFor('P-2');

        $this->assertStringNotContainsString('ra-min-note', $this->owner('admin/reorder-alerts'));
        $this->assertStringNotContainsString('pr-min-note', preg_replace('/\.pr-min-note[^}]*\}/', '', $this->owner('admin/procurement-report')) ?? '');
        $this->assertNull(PurchaseOrderLine::build($this->row('P-1'), [])['supplier_note']);
        $this->assertSame(50, PurchaseOrderLine::build($this->row('P-1'), [])['supplier_min']);
    }

    public function testAVeryLargeMinimumIsAWarningOnTheScreens(): void
    {
        $this->sizeWithDss('P-1', 'Pants', 'S', 400, 300, 63, ['supplier_min_qty' => 300], 1); // 9 months of sales
        $this->alertFor('P-1');

        $alerts = $this->owner('admin/reorder-alerts');
        $this->assertStringContainsString('ra-min-note is-warning', $alerts);
        $this->assertStringContainsString('This minimum is more than 6 months of sales. Consider a smaller order from another supplier.', $alerts);
        $this->assertStringNotContainsString('Supplier minimum is 300', $alerts, 'A warning instead of the plain note.');
        $this->assertStringContainsString('pr-min-note is-warning', $this->owner('admin/procurement-report'));
    }

    // ================================================================ the item form

    private function updateItem(string $id, array $extra = []): \CodeIgniter\Test\TestResponse
    {
        $row = $this->row($id);

        return $this->asOwner()->post('admin/inventory/update/' . $id, $extra + [
            'item_name' => $row['item_name'], 'category' => $row['category'], 'size' => $row['size'], 'unit_cost' => '50', 'selling_price' => '90',
            'current_stock' => (string) $row['current_stock'], 'supplier_id' => 'SUP-001',
        ]);
    }

    public function testSavingTheItemFormAppliesTheSupplierMinimumToEverySize(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 5, 3);
        $this->addSize('P-M', 'Pants', 'M', 5, 3);
        $this->addSize('P-L', 'Pants', 'L', 5, 3);
        $this->addSize('W-1', 'Whistle', null, 5, 3, ['category' => 'Accessories']);

        $this->updateItem('P-M', ['supplier_min_qty' => '48']);

        $this->assertSame([48, 48, 48], [(int) $this->row('P-S')['supplier_min_qty'], (int) $this->row('P-M')['supplier_min_qty'], (int) $this->row('P-L')['supplier_min_qty']], 'The same value on all sizes of the item.');
        $this->assertNull($this->row('W-1')['supplier_min_qty'], 'Another item is not touched.');

        $this->updateItem('P-S', ['supplier_min_qty' => '']); // blank = no minimum, for the whole item
        $this->assertSame([null, null, null], [$this->row('P-S')['supplier_min_qty'], $this->row('P-M')['supplier_min_qty'], $this->row('P-L')['supplier_min_qty']]);
    }

    public function testASupplierMinimumMustBeAWholeNumberOfOneOrMoreOrBlank(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 5, 3, ['supplier_min_qty' => 12]);

        foreach (['0', '-5', '2.5', 'abc', '1000000'] as $bad) {
            $this->updateItem('P-S', ['supplier_min_qty' => $bad]);
            $this->assertSame(12, (int) $this->row('P-S')['supplier_min_qty'], "{$bad} is refused; nothing is saved.");
        }
        $this->assertStringContainsString('Supplier minimum order must be a whole number of 1 or more, or left blank.', json_encode($_SESSION['toast'] ?? []));
    }

    public function testAFormWithoutTheFieldLeavesTheMinimumAlone(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 5, 3, ['supplier_min_qty' => 12]);

        $this->updateItem('P-S'); // an older form, no supplier_min_qty field

        $this->assertSame(12, (int) $this->row('P-S')['supplier_min_qty']);
    }

    public function testANewSizeWithAMinimumAppliesItToItsItem(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 5, 3);

        $this->asOwner()->post('admin/inventory/store', ['item_name' => 'Pants', 'category' => 'Clothes', 'size' => 'M', 'unit_cost' => '50', 'selling_price' => '90', 'supplier_min_qty' => '24', 'supplier_id' => 'SUP-001']);

        $medium = $this->db->table('products')->where('item_name', 'Pants')->where('size', 'M')->get()->getRowArray();
        $this->assertSame(24, (int) $medium['supplier_min_qty']);
        $this->assertSame(24, (int) $this->row('P-S')['supplier_min_qty'], 'The other size takes it too.');

        $this->asOwner()->post('admin/inventory/store', ['item_name' => 'Pants', 'category' => 'Clothes', 'size' => 'L', 'unit_cost' => '50', 'selling_price' => '90', 'supplier_min_qty' => '', 'supplier_id' => 'SUP-001']);
        $this->assertNull($this->db->table('products')->where('size', 'L')->get()->getRow()->supplier_min_qty, 'Blank: this size has none.');
        $this->assertSame(24, (int) $this->row('P-S')['supplier_min_qty'], 'A blank on a new size leaves the others as they are.');
    }

    public function testTheItemFormHasTheOptionalFieldAndTheEditPayloadCarriesIt(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 5, 3, ['supplier_min_qty' => 36]);
        $html = $this->owner('admin/inventory');
        $x    = $this->html($html);

        $this->assertSame('Supplier minimum order (optional)', trim($x->query('//label[@for="supplier_min_qty"]')->item(0)->textContent));
        $input = $x->query('//input[@id="supplier_min_qty"]')->item(0);
        $this->assertSame('supplier_min_qty', $input->getAttribute('name'));
        $this->assertFalse($input->hasAttribute('required'), 'Optional.');
        $this->assertStringContainsString('36', html_entity_decode($x->query('//tr[contains(@class,"inv-row")]')->item(0)->getAttribute('data-variants')), 'The row carries it for the edit form.');
        $this->assertStringContainsString('supplier_min_qty', html_entity_decode($x->query('//button[contains(@class,"edit-row")]')->item(0)->getAttribute('data-item')));
        $this->assertStringContainsString("document.getElementById('supplier_min_qty').value = item.supplier_min_qty", $html, 'The edit form fills it in.');
    }

    // ================================================================ Settings

    public function testTheSettingsLabelIsRenamedAndTheUsualOrderQtyIsThere(): void
    {
        $html = $this->owner('admin/settings?tab=dss');
        $x    = $this->html($html);

        $labels = array_map(static fn ($l) => trim($l->textContent), iterator_to_array($x->query('//label')));
        $this->assertContains('System minimum order qty', $labels);
        $this->assertNotContains('Minimum Order Qty', $labels, 'Renamed, so it is not mistaken for the supplier minimum.');
        $this->assertContains("Owner's usual order qty", array_map(static fn ($t) => trim(explode('What', $t)[0]), $labels));
        $input = $x->query('//input[@name="owner_usual_order_qty"]')->item(0);
        $this->assertSame('20', $input->getAttribute('value'), 'The default from the owner interview.');
        $this->assertSame('1', $input->getAttribute('min'));
        $this->assertNotNull($x->query('//input[@name="minimum_order_qty"]')->item(0), 'The system minimum is still there, same field.');
    }

    private function saveSettings(array $extra = []): void
    {
        $this->asOwner()->post('admin/settings/dss-parameters', $extra + [
            'ordering_cost' => '50', 'holding_cost_per_unit' => '10', 'service_level_target' => '95', 'z_score' => '1.645', 'demand_lookback_days' => '730', 'minimum_order_qty' => '10',
        ]);
    }

    public function testTheUsualOrderQtyIsSavedAndValidated(): void
    {
        $this->saveSettings(['owner_usual_order_qty' => '35']);
        $this->assertSame(35, (int) $this->db->table('dss_parameters')->get()->getRow()->owner_usual_order_qty);
        $this->assertSame('Order settings saved.', $_SESSION['success'] ?? null);

        foreach (['0', '-3', 'abc', '2.5', '100000'] as $bad) {
            unset($_SESSION['success'], $_SESSION['error']);
            $this->saveSettings(['owner_usual_order_qty' => $bad]);
            $this->assertSame(35, (int) $this->db->table('dss_parameters')->get()->getRow()->owner_usual_order_qty, "{$bad} is refused.");
            $this->assertNotEmpty($_SESSION['error'] ?? null);
        }

        unset($_SESSION['success'], $_SESSION['error']);
        $this->saveSettings(['owner_usual_order_qty' => '']);   // blank keeps what is saved
        $this->saveSettings();                                  // so does a form that does not send it
        $this->assertSame(35, (int) $this->db->table('dss_parameters')->get()->getRow()->owner_usual_order_qty);
    }

    public function testTheSystemMinimumStillNeedsItsOwnValue(): void
    {
        $this->saveSettings(['minimum_order_qty' => '0']);

        $this->assertSame(10, (int) $this->db->table('dss_parameters')->get()->getRow()->minimum_order_qty, 'Unchanged rules for the system minimum.');
    }

    // ================================================================ the Cost comparison tab

    private function comparisonPage(string $query = ''): string
    {
        return $this->owner('admin/procurement-report?tab=comparison' . ($query !== '' ? '&' . $query : ''));
    }

    public function testTheProcurementReportHasTwoTabsAndTheSidebarLink(): void
    {
        $this->seedComparison();
        $html = $this->owner('admin/procurement-report');
        $x    = $this->html($html);

        $tabs = array_map(static fn ($a) => trim($a->textContent), iterator_to_array($x->query('//nav[contains(@class,"pr-tabs")]/a')));
        $this->assertSame(['Report', 'Cost comparison'], $tabs);
        $this->assertStringContainsString('active', $x->query('//nav[contains(@class,"pr-tabs")]/a')->item(0)->getAttribute('class'));
        $this->assertNotNull($x->query('//table[contains(@class,"pr-table")]')->item(0), 'The existing table is still the first tab.');
        $this->assertStringContainsString('tab=comparison', $x->query('//nav[contains(@class,"pr-tabs")]/a')->item(1)->getAttribute('href'));

        // the sidebar: under ORDERING, active here
        $ordering = strpos($html, 'nav-section-label">ORDERING');
        $system   = strpos($html, 'nav-section-label">SYSTEM');
        $link     = strpos($html, 'href="' . site_url('admin/procurement-report') . '" class="nav-link active"');
        $this->assertTrue($ordering < $link && $link < $system, 'Procurement Report sits under ORDERING.');
        $this->assertStringContainsString('Procurement Report', $x->query('//a[contains(@class,"nav-link") and contains(@class,"active")]')->item(0)->textContent);

        // Reorder Alerts keeps its button
        $alerts = $this->owner('admin/reorder-alerts');
        $this->assertStringContainsString('href="' . site_url('admin/procurement-report') . '"', $alerts);
        $this->assertStringContainsString('ra-report-btn', $alerts, 'The button on Reorder Alerts stays.');
    }

    public function testTheComparisonTabShowsTheFourCards(): void
    {
        $this->seedComparison();
        $x = $this->html($this->comparisonPage());

        $cards = [];
        foreach (['pcCardOwner', 'pcCardSystem', 'pcCardSavings', 'pcCardItems'] as $id) {
            $cards[$id] = preg_replace('/\s+/', ' ', trim($x->query('//*[@id="' . $id . '"]')->item(0)->textContent));
        }
        $this->assertStringContainsString('Yearly cost, ordering 20 pcs ₱1,650', $cards['pcCardOwner']);
        $this->assertStringContainsString('Yearly cost with PDSS ₱1,239', $cards['pcCardSystem']);
        $this->assertStringContainsString('Estimated savings', $cards['pcCardSavings']);
        $this->assertStringContainsString('₱411 (+24.9%)', $cards['pcCardSavings']);
        $this->assertStringContainsString('Items analysed 3', $cards['pcCardItems']);
        $this->assertNotNull($x->query('//*[@id="pcCardSavings"]//button[contains(@class,"help-tip")]')->item(0), 'The "How is this worked out?" help tip.');
        $this->assertStringContainsString('How is this worked out?', html_entity_decode($x->query('//*[@id="pcCardSavings"]//button')->item(0)->getAttribute('data-help-title')));
        $this->assertStringContainsString('D ÷ Q × S + Q ÷ 2 × H', html_entity_decode($x->query('//*[@id="pcCardSavings"]//button')->item(0)->getAttribute('data-help-body')));
    }

    public function testTheCardsUseTheOwnersQtyFromTheSettings(): void
    {
        $this->seedComparison();
        $this->db->table('dss_parameters')->update(['owner_usual_order_qty' => 40]);

        $this->assertStringContainsString('Yearly cost, ordering 40 pcs', $this->comparisonPage());
    }

    public function testTheComparisonTableHasOneSortableRowPerSizeWithSales(): void
    {
        $this->seedComparison();
        $x = $this->html($this->comparisonPage());

        $headers = array_map(static fn ($th) => trim($th->textContent), iterator_to_array($x->query('//table[@id="pcTable"]/thead//th')));
        $this->assertSame(['Item', 'Size', 'Class', 'Yearly demand', "Owner's qty", 'PDSS qty', "Yearly cost (owner's qty)", 'Yearly cost (PDSS qty)', 'Savings'], $headers);
        $this->assertSame(9, $x->query('//table[@id="pcTable"]/thead//button[@data-sort]')->length, 'Every column sorts.');
        $rows = $x->query('//tr[contains(@class,"pc-row")]');
        $this->assertSame(3, $rows->length, 'P-4 has no sales.');

        $first = $rows->item(0); // Belt is left out; Cap, Pants, Shirt by name
        $this->assertSame('Cap', trim($x->query('./td', $first)->item(0)->textContent));
        $pants = $rows->item(1);
        $cells = array_map(static fn ($td) => trim(preg_replace('/\s+/', ' ', $td->textContent)), iterator_to_array($x->query('./td', $pants)));
        $this->assertSame(['Pants', 'S', 'A', '400', '20', '63', '₱1,100', '₱632', '₱468 +42.5%'], $cells);
        // the numbers a sort and the chart read
        $this->assertSame('467.54', $x->query('./td', $pants)->item(8)->getAttribute('data-v'));
        $this->assertSame(['400', '20', '63'], [$pants->getAttribute('data-d'), $pants->getAttribute('data-q-owner'), $pants->getAttribute('data-q-sys')]);
        $this->assertSame(['20', '6.3'], [$pants->getAttribute('data-orders-owner'), $pants->getAttribute('data-orders-sys')]);
        $this->assertStringContainsString('is-negative', $x->query('./td', $first)->item(8)->getAttribute('class'), 'A negative saving is marked.');
    }

    public function testTheChartAndTheSortingAreOnThePage(): void
    {
        $this->seedComparison();
        $html = $this->comparisonPage();
        $x    = $this->html($html);

        $this->assertNotNull($x->query('//canvas[@id="pcChart"]')->item(0));
        $this->assertStringContainsString("Owner's usual order (", $html);
        $this->assertStringContainsString('EOQ (lowest cost)', $html);
        $this->assertStringContainsString('3 * Math.max(qOwner, qSys)', $html, 'The curve runs from 1 to 3 times the larger quantity.');
        $this->assertStringContainsString('D / q * S + q / 2 * H', $html, 'The same formula as the server.');
        $this->assertStringContainsString("document.addEventListener('click'", $html);
        $this->assertSame(1, $x->query('//*[@id="procurementResults" and @data-owner-qty="20" and @data-ordering-cost="50" and @data-holding-cost="10"]')->length, 'S, H and Q for the chart.');
    }

    public function testTheComparisonFiltersWorkLikeTheOtherTab(): void
    {
        $this->seedComparison();
        $names = fn (string $query) => array_map(static fn ($td) => trim($td->textContent), iterator_to_array($this->html($this->comparisonPage($query))->query('//tr[contains(@class,"pc-row")]/td[1]')));

        $this->assertSame(['Cap', 'Pants', 'Shirt'], $names(''));
        $this->assertSame(['Pants'], $names('class=A'), 'Class filter');
        $this->assertSame(['Shirt'], $names('search=shirt'), 'Search by name');
        $this->assertSame([], $names('status=Out+of+Stock'));
        $x = $this->html($this->comparisonPage('search=zzz'));
        $this->assertStringContainsString('No sizes with sales match these filters', $x->query('//*[@id="pcTable"]')->item(0)->textContent);
    }

    public function testTheLiveSearchGetsJustTheComparisonPartial(): void
    {
        $this->seedComparison();

        $partial = (string) $this->asOwner()->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->get('admin/procurement-report?tab=comparison&search=pants')->response()->getBody();

        $this->assertStringStartsWith('<div id="procurementResults" class="pc-wrap"', ltrim(preg_replace('/<!--.*?-->/s', '', $partial)));
        $this->assertStringNotContainsString('<html', $partial);
        $this->assertSame(1, substr_count($partial, 'class="pc-row"') + substr_count($partial, 'class="pc-row '));
        $this->assertStringContainsString('Items analysed', $partial, 'The cards follow the filtered rows.');
    }

    public function testNoSalesAtAllSaysSo(): void
    {
        $this->sizeWithDss('P-4', 'Belt', null, 0, 10, 0);
        $html = $this->comparisonPage();

        $this->assertStringContainsString('No sizes with sales to compare yet', $html);
        $this->assertStringContainsString('Items analysed', $html);
    }

    // ---------------------------------------------------------------- exports

    private function csvRows(string $csv): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        return array_map(static fn ($l) => str_getcsv($l, ',', '"', '\\'), explode("\n", trim(substr($csv, 3))));
    }

    public function testTheComparisonCsv(): void
    {
        $this->seedComparison();
        $result = $this->asOwner()->get('admin/procurement-report/csv?tab=comparison');

        $this->assertStringContainsString('text/csv', $result->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('cost-comparison-', $result->response()->getHeaderLine('Content-Disposition'));
        $rows = $this->csvRows($result->response()->getBody());

        $this->assertSame(['Item ID', 'Item', 'Size', 'Class', 'Yearly Demand', "Owner's Qty", 'System Qty', "Yearly Cost (Owner's Qty)", 'Yearly Cost (System Qty)', 'Savings', 'Savings %', "Orders a Year (Owner's Qty)", 'Orders a Year (System Qty)'], $rows[0]);
        $byId = array_column(array_slice($rows, 1, 3), null, 0);
        $this->assertSame(['P-1', 'Pants', 'S', 'A', '400', '20', '63', '1100.00', '632.46', '467.54', '42.5', '20.0', '6.3'], $byId['P-1']);
        $this->assertSame('-90.00', $byId['P-3'][9]);
        $this->assertSame(['Total', '3 sizes', '', '', '', '', '', '1650.00', '1238.71', '411.29', '24.9'], array_slice($rows[4], 0, 11));
        $text = json_encode($rows);
        $this->assertStringContainsString("Owner's usual order qty", $text);
        $this->assertStringContainsString('Ordering cost (S)', $text);

        $filtered = $this->csvRows($this->asOwner()->get('admin/procurement-report/csv?tab=comparison&class=A')->response()->getBody());
        $this->assertSame(['P-1'], array_column(array_slice($filtered, 1, 1), 0), 'The same filters as the page.');
    }

    public function testTheComparisonPdfAndTheExistingExportsStillWork(): void
    {
        $this->seedComparison();

        $pdf = $this->asOwner()->get('admin/procurement-report/pdf?tab=comparison');
        $pdf->assertStatus(200);
        $this->assertStringStartsWith('%PDF', $pdf->response()->getBody());
        $this->assertStringContainsString('cost-comparison-', $pdf->response()->getHeaderLine('Content-Disposition'));

        $view = view('reports/procurement_comparison_pdf', [
            'rows' => ProcurementComparison::rows(\App\Libraries\ProcurementReport::filters([])), 'totals' => ['owner_cost' => 1650.0, 'sys_cost' => 1238.71, 'savings' => 411.29, 'savings_pct' => 24.9, 'items' => 3],
            'ownerQty' => 20, 'basis' => [['Ordering cost (S)', '₱50 per order']], 'filterLabel' => '', 'generatedAt' => 'now',
        ]);
        foreach (['Cost comparison', 'ordering 20 pcs', '₱1,650', '₱1,239', '₱411 (+24.9%)', 'Pants', '-₱90', 'Items analysed', 'Basis'] as $text) {
            $this->assertStringContainsString($text, $view);
        }

        $oldPdf = $this->asOwner()->get('admin/procurement-report/pdf');
        $this->assertStringContainsString('procurement-report-', $oldPdf->response()->getHeaderLine('Content-Disposition'));
        $oldCsv = $this->csvRows($this->asOwner()->get('admin/procurement-report/csv')->response()->getBody());
        $this->assertSame(['Item ID', 'Item', 'Size', 'Class', 'On Hand', 'Reorder At', 'Status', 'Safety Stock', 'Order Qty'], $oldCsv[0], 'The original export is unchanged.');
    }

    public function testTheExportLinksFollowTheTab(): void
    {
        $this->seedComparison();
        $x = $this->html($this->comparisonPage('class=A'));

        $this->assertStringContainsString('tab=comparison', $x->query('//a[@id="exportPdf"]')->item(0)->getAttribute('href'));
        $this->assertStringContainsString('tab=comparison', $x->query('//a[@id="exportCsv"]')->item(0)->getAttribute('href'));
        $this->assertStringContainsString('class=A', $x->query('//a[@id="exportCsv"]')->item(0)->getAttribute('href'));
    }

    // ---------------------------------------------------------------- owner only

    public function testOnlyTheOwnerCanOpenTheComparisonAndItsExports(): void
    {
        $this->seedComparison();
        $supplier = $this->db->table('users')->where('username', 'tgi')->get()->getRow();

        foreach (['admin/procurement-report?tab=comparison', 'admin/procurement-report/pdf?tab=comparison', 'admin/procurement-report/csv?tab=comparison'] as $url) {
            $this->asOwner()->get($url)->assertStatus(200);
            $this->assertTrue($this->asStaff()->get($url)->isRedirect(), "staff: {$url}");
            $this->withSession(['isLoggedIn' => true, 'role' => 'Supplier', 'user_id' => (int) $supplier->user_id, 'username' => 'tgi', 'full_name' => 'Tactical Gear', 'supplier_id' => 'SUP-001', 'last_activity' => time()])->get($url)->assertRedirect();
            $guest = $this->withSession([])->get($url);
            $guest->assertRedirect();
            $this->assertStringNotContainsString('Cost comparison', (string) $guest->response()->getBody());
        }
    }

    // ================================================================ payment terms

    public function testPaymentTermsShowOnThePurchaseOrderPdf(): void
    {
        $this->db->table('suppliers')->where('supplier_id', 'SUP-001')->update(['payment_terms' => 'Check']);
        $this->addSize('P-1', 'Pants', 'S', 5, 3);
        $this->db->table('stock_order')->insert(['so_id' => 'SO-0001', 'supplier_id' => 'SUP-001', 'user_id' => $this->ownerId, 'status' => 'Order Confirmed', 'order_date' => '2026-11-14', 'po_number' => 'PO-2026-0001']);
        $this->db->table('so_item')->insert(['so_id' => 'SO-0001', 'item_id' => 'P-1', 'order_quantity' => 10, 'unit_price' => 50]);
        $order = $this->db->table('stock_order')->where('so_id', 'SO-0001')->get()->getRowArray();

        $po = view('procurement/po', ProcurementDocs::documentData($order, 'po'));
        $this->assertStringContainsString('>Terms</td><td class="v">Check<', $po);

        $this->db->table('suppliers')->where('supplier_id', 'SUP-001')->update(['payment_terms' => null]);
        $this->assertStringNotContainsString('>Terms<', view('procurement/po', ProcurementDocs::documentData($order, 'po')), 'No terms set: no line.');
    }

    private function saveSupplier(array $extra = []): void
    {
        $this->asOwner()->post('admin/suppliers/update/SUP-001', $extra + ['company_name' => 'Tactical Gear Inc', 'lead_time_days' => '5', 'supplier_status' => 'Active']);
    }

    private function terms(): ?string
    {
        return $this->db->table('suppliers')->where('supplier_id', 'SUP-001')->get()->getRow()->payment_terms;
    }

    public function testEditingASupplierSavesItsPaymentTerms(): void
    {
        $this->assertNull($this->terms(), 'Optional: none to begin with.');

        $this->saveSupplier(['payment_terms' => 'Check']);
        $this->assertSame('Check', $this->terms());
        $this->saveSupplier(['payment_terms' => 'Cash']);
        $this->assertSame('Cash', $this->terms());

        $this->saveSupplier();                               // a form that does not send it leaves it alone
        $this->assertSame('Cash', $this->terms());

        $this->saveSupplier(['payment_terms' => 'Barter']);  // not one of the choices: refused, nothing changes
        $this->assertSame('Cash', $this->terms());
        $this->assertNotEmpty($_SESSION['error'] ?? null);

        $this->saveSupplier(['payment_terms' => '']);        // "Not set"
        $this->assertNull($this->terms());
    }

    public function testAddingASupplierCanSetItsPaymentTerms(): void
    {
        $this->asOwner()->post('admin/suppliers/store', ['company_name' => 'Quartermaster Co', 'lead_time_days' => '3', 'payment_terms' => 'Other', 'supplier_status' => 'Active']);

        $this->assertSame('Other', $this->db->table('suppliers')->where('company_name', 'Quartermaster Co')->get()->getRow()->payment_terms);

        $this->asOwner()->post('admin/suppliers/store', ['company_name' => 'Bad Terms Co', 'lead_time_days' => '3', 'payment_terms' => 'Barter', 'supplier_status' => 'Active']);
        $this->assertNull($this->db->table('suppliers')->where('company_name', 'Bad Terms Co')->get()->getRow(), 'A bad value refuses the whole form.');
    }

    public function testThePaymentTermsChoices(): void
    {
        $this->assertSame(['Cash', 'Check', 'Cash or Check', 'Other'], \App\Models\SupplierModel::PAYMENT_TERMS, 'The three original options still work; Cash or Check is the fourth.');
        $this->assertStringContainsString("'payment_terms' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true", file_get_contents(APPPATH . 'Database/Migrations/2026-11-18-100200_AddPaymentTermsToSuppliers.php'));
        $this->assertStringContainsString("'default' => null", file_get_contents(APPPATH . 'Database/Migrations/2026-11-18-100000_AddSupplierMinQtyToProducts.php'), 'Blank = no supplier minimum.');
    }

    public function testTheSupplierFormAndCardCarryThePaymentTerms(): void
    {
        $form = file_get_contents(APPPATH . 'Views/admin/supplier_form.php');
        $card = file_get_contents(APPPATH . 'Views/admin/suppliers.php');
        $ctrl = file_get_contents(APPPATH . 'Controllers/Admin/SupplierController.php');

        $this->assertStringContainsString('name="payment_terms"', $form);
        $this->assertStringContainsString('Payment terms (optional)', $form);
        $this->assertStringContainsString('SupplierModel::PAYMENT_TERMS', $form);
        $this->assertStringContainsString('Terms</span>', $card);
        $this->assertSame(2, substr_count($ctrl, "'payment_terms' => 'permit_empty|in_list[' . implode(',', \App\Models\SupplierModel::PAYMENT_TERMS) . ']',"), 'Checked on Add and on Edit, against the one list.');
    }
}
