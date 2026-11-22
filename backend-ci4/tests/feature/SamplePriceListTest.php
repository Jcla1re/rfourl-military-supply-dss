<?php

use App\Commands\SampleSuppliers as SampleSuppliersCommand;
use App\Libraries\ProcurementDocs;
use App\Libraries\SampleSuppliers;
use App\Models\SupplierModel;
use App\Models\SupplierPriceModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Services;
use Tests\Support\ReorderFixture;

/**
 * Supplier price list and sample supplier data (Task A1): the offers method (main supplier first), the
 * sample-suppliers command (load is idempotent, remove deletes only sample data, unmatched names stop the
 * load), sample suppliers kept out of the main-supplier dropdown and the purchase order dialog, the badge and
 * summary cards, is_sample never changeable from a form, the "Cash or Check" payment term, the supplier ID
 * generator, and pdss_computation left alone. Runs on the in-memory 'tests' database.
 *
 * @internal
 */
final class SamplePriceListTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ReorderFixture;
    use StreamFilterTrait;

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
    }

    protected function tearDown(): void
    {
        \Config\Database::forge()->dropTable('app_settings', true);
        $this->tearDownReorderFixture();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    /** The ten items of the command's price list, each from its real main supplier; "Patch" in two sizes. */
    private function seedCatalog(): void
    {
        $items = [
            ['A-1', '1ID Security Patch', null, 36], ['A-2', 'Name Cloth', null, 72], ['A-3', 'Logo', null, 84], ['A-4', 'Patch', 'S', 150], ['A-5', 'Patch', 'M', 150],
            ['B-1', 'KALASAG Pin', null, 48], ['B-2', 'PAPURI MEDAL', null, 168], ['B-3', 'Badge', null, 150],
            ['C-1', 'Handcuffs', null, 330], ['C-2', 'Pepper Spray', null, 132], ['C-3', 'Cloth', null, 72],
        ];
        foreach ($items as [$id, $name, $size, $cost]) {
            $this->addSize($id, $name, $size, 50, 3, ['unit_cost' => $cost, 'supplier_id' => 'SUP-001']);
        }
    }

    /** A small config of the same shape as the command's arrays. */
    private function config(): array
    {
        return [
            [
                'SMP-001' => ['name' => 'Sample One', 'category' => 'Patches', 'lead_time_days' => 7, 'min_order_qty' => null, 'payment_terms' => 'Cash', 'contact_person' => 'Sample Contact', 'contact_email' => 'a@example.invalid', 'contact_number' => '0000'],
                'SMP-002' => ['name' => 'Sample Two', 'category' => 'Patches', 'lead_time_days' => 3, 'min_order_qty' => 100, 'payment_terms' => 'Check', 'contact_person' => 'Sample Contact', 'contact_email' => 'b@example.invalid', 'contact_number' => '0000'],
            ],
            [
                'SMP-001' => ['factor' => 0.92, 'items' => ['1ID Security Patch', 'Patch']],
                'SMP-002' => ['factor' => 0.85, 'items' => ['patch']],
            ],
        ];
    }

    private function rows(string $table, array $where = []): int
    {
        return $this->db->table($table)->where($where)->countAllResults();
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

    // ================================================================ the offers method

    public function testTheOffersComeMainSupplierFirstThenThePriceList(): void
    {
        $this->db->table('suppliers')->insertBatch([
            ['supplier_id' => 'SMP-001', 'company_name' => 'Sample One', 'lead_time_days' => 3, 'is_sample' => 1, 'payment_terms' => null],
            ['supplier_id' => 'SUP-003', 'company_name' => 'Real Three', 'lead_time_days' => 9, 'is_sample' => 0, 'payment_terms' => 'Cash or Check'],
        ]);
        $this->addSize('P-1', 'Patch', null, 50, 3, ['unit_cost' => 50, 'supplier_id' => 'SUP-001', 'supplier_min_qty' => 20]);
        $this->db->table('supplier_prices')->insertBatch([
            ['supplier_id' => 'SUP-003', 'item_id' => 'P-1', 'unit_price' => 45, 'lead_time_days' => null, 'min_order_qty' => null, 'is_sample' => 0],
            ['supplier_id' => 'SMP-001', 'item_id' => 'P-1', 'unit_price' => 40, 'lead_time_days' => 4, 'min_order_qty' => 100, 'is_sample' => 1],
            ['supplier_id' => 'SUP-001', 'item_id' => 'P-1', 'unit_price' => 1, 'lead_time_days' => null, 'min_order_qty' => null, 'is_sample' => 0], // the main supplier is never an extra offer
        ]);

        $offers = (new SupplierPriceModel())->offersForItem('P-1');

        $this->assertSame(['SUP-001', 'SMP-001', 'SUP-003'], array_column($offers, 'supplier_id'), 'Main first, then the others cheapest first.');
        [$main, $sample, $real] = $offers;
        $this->assertSame([true, false, 50.0, 7, 'supplier', 20], [$main['is_main'], $main['is_sample'], $main['unit_price'], $main['lead_time_days'], $main['lead_time_from'], $main['min_order_qty']], "The main offer is products.unit_cost + the supplier's lead time + products.supplier_min_qty.");
        $this->assertSame([false, true, 40.0, 4, 'offer', 100], [$sample['is_main'], $sample['is_sample'], $sample['unit_price'], $sample['lead_time_days'], $sample['lead_time_from'], $sample['min_order_qty']], "The offer's own lead time wins.");
        $this->assertSame([45.0, 9, 'supplier', null, 'Cash or Check'], [$real['unit_price'], $real['lead_time_days'], $real['lead_time_from'], $real['min_order_qty'], $real['payment_terms']], "NULL lead time = the supplier's; NULL minimum = none.");
    }

    public function testAnItemWithoutAMainSupplierHasOnlyPriceListOffersAndAnUnknownItemNone(): void
    {
        $this->db->table('suppliers')->insert(['supplier_id' => 'SUP-003', 'company_name' => 'Real Three', 'lead_time_days' => 9]);
        $this->addSize('P-1', 'Patch', null, 50, 3, ['unit_cost' => 50, 'supplier_id' => null]);
        $this->db->table('supplier_prices')->insert(['supplier_id' => 'SUP-003', 'item_id' => 'P-1', 'unit_price' => 45, 'is_sample' => 0]);

        $offers = (new SupplierPriceModel())->offersForItem('P-1');

        $this->assertSame(['SUP-003'], array_column($offers, 'supplier_id'));
        $this->assertFalse($offers[0]['is_main']);
        $this->assertSame([], (new SupplierPriceModel())->offersForItem('NOPE'));
    }

    public function testAnItemWithNoPriceListIsJustItsMainOffer(): void
    {
        $this->addSize('P-1', 'Patch', null, 50, 3, ['unit_cost' => 50, 'supplier_id' => 'SUP-001']);

        $offers = (new SupplierPriceModel())->offersForItem('P-1');

        $this->assertCount(1, $offers);
        $this->assertTrue($offers[0]['is_main']);
        $this->assertNull($offers[0]['min_order_qty']);
    }

    // ================================================================ load

    public function testLoadCreatesTheSampleSuppliersAndTheirPriceRowsForEverySize(): void
    {
        $this->seedCatalog();
        [$suppliers, $priceList] = $this->config();

        $result = SampleSuppliers::load($suppliers, $priceList);

        $this->assertTrue($result['ok'], implode(' ', $result['errors']));
        $this->assertSame([2, 0], [$result['suppliers_created'], $result['suppliers_existing']]);
        // SMP-001: 1ID Security Patch + Patch S + Patch M; SMP-002: Patch S + M ("patch" in lower case matches too)
        $this->assertCount(5, $result['rows']);
        $this->assertSame(5, $this->rows('supplier_prices'));
        $this->assertSame(5, $this->rows('supplier_prices', ['is_sample' => 1]), 'Every row is marked Sample.');

        $one = $this->db->table('suppliers')->where('supplier_id', 'SMP-001')->get()->getRowArray();
        $this->assertSame([1, 1, 7, 'Cash', 'Sample One'], [(int) $one['is_sample'], (int) $one['is_active'], (int) $one['lead_time_days'], $one['payment_terms'], $one['company_name']]);
        $this->assertStringContainsString('example.invalid', $one['contact_email'], 'Contact details are clearly fake.');

        // price = round(unit cost x factor), whole pesos: 36 x 0.92 = 33.12 -> 33; 150 x 0.92 = 138; 150 x 0.85 = 127.5 -> 128
        $price = fn (string $supplier, string $item) => (float) $this->db->table('supplier_prices')->where(['supplier_id' => $supplier, 'item_id' => $item])->get()->getRow()->unit_price;
        $this->assertSame(33.0, $price('SMP-001', 'A-1'));
        $this->assertSame(138.0, $price('SMP-001', 'A-4'));
        $this->assertSame(128.0, $price('SMP-002', 'A-4'));
        $this->assertSame(128.0, $price('SMP-002', 'A-5'), 'The other size too.');
        // lead time NULL = the supplier's own; the supplier's per-item minimum goes on its rows
        $row = $this->db->table('supplier_prices')->where(['supplier_id' => 'SMP-002', 'item_id' => 'A-4'])->get()->getRowArray();
        $this->assertNull($row['lead_time_days']);
        $this->assertSame(100, (int) $row['min_order_qty']);
        $this->assertNull($this->db->table('supplier_prices')->where(['supplier_id' => 'SMP-001', 'item_id' => 'A-1'])->get()->getRow()->min_order_qty, 'No minimum for SMP-001.');
    }

    public function testLoadReportsTheMainCostAndTheSamplePriceOfEachRow(): void
    {
        $this->seedCatalog();
        [$suppliers, $priceList] = $this->config();

        $rows = SampleSuppliers::load($suppliers, $priceList)['rows'];

        $this->assertSame(['supplier' => 'Sample One', 'item' => '1ID Security Patch', 'size' => '', 'main_cost' => 36.0, 'price' => 33, 'status' => 'new'], $rows[0]);
        $this->assertSame(['S', 'M'], [$rows[1]['size'], $rows[2]['size']]);
    }

    public function testLoadIsIdempotent(): void
    {
        $this->seedCatalog();
        [$suppliers, $priceList] = $this->config();

        SampleSuppliers::load($suppliers, $priceList);
        $before = [$this->rows('suppliers'), $this->rows('supplier_prices'), $this->db->table('supplier_prices')->get()->getResultArray()];
        $again  = SampleSuppliers::load($suppliers, $priceList);

        $this->assertTrue($again['ok']);
        $this->assertSame([0, 2], [$again['suppliers_created'], $again['suppliers_existing']], 'Nothing new.');
        $this->assertSame(['updated'], array_values(array_unique(array_column($again['rows'], 'status'))));
        $this->assertSame($before[0], $this->rows('suppliers'));
        $this->assertSame($before[1], $this->rows('supplier_prices'), 'No duplicate rows (supplier + item is unique).');
        $this->assertSame(array_column($before[2], 'unit_price', 'id'), array_column($this->db->table('supplier_prices')->get()->getResultArray(), 'unit_price', 'id'));
    }

    public function testRunningLoadAgainAppliesAnAdjustedFactor(): void
    {
        $this->seedCatalog();
        [$suppliers, $priceList] = $this->config();
        SampleSuppliers::load($suppliers, $priceList);

        $priceList['SMP-001']['factor'] = 0.5;
        SampleSuppliers::load($suppliers, $priceList);

        $this->assertSame(18.0, (float) $this->db->table('supplier_prices')->where(['supplier_id' => 'SMP-001', 'item_id' => 'A-1'])->get()->getRow()->unit_price, '36 x 0.5');
        $this->assertSame(5, $this->rows('supplier_prices'));
    }

    public function testLoadNeverOverwritesARealPriceOrARealSupplier(): void
    {
        $this->seedCatalog();
        [$suppliers, $priceList] = $this->config();
        $this->db->table('suppliers')->insert(['supplier_id' => 'SMP-001', 'company_name' => 'Somebody Real', 'lead_time_days' => 5, 'is_sample' => 0]);

        $clash = SampleSuppliers::load($suppliers, $priceList);
        $this->assertFalse($clash['ok']);
        $this->assertStringContainsString('already belongs to a real supplier', $clash['errors'][0]);
        $this->assertSame('Somebody Real', $this->db->table('suppliers')->where('supplier_id', 'SMP-001')->get()->getRow()->company_name);
        $this->assertSame(0, $this->rows('supplier_prices'));

        $this->db->table('suppliers')->where('supplier_id', 'SMP-001')->delete();
        $this->db->table('suppliers')->insert(['supplier_id' => 'SMP-001', 'company_name' => 'Sample One', 'lead_time_days' => 7, 'is_sample' => 1]);
        $this->db->table('supplier_prices')->insert(['supplier_id' => 'SMP-001', 'item_id' => 'A-1', 'unit_price' => 99, 'is_sample' => 0]); // a price somebody entered
        $rows = SampleSuppliers::load($suppliers, $priceList)['rows'];

        $this->assertSame(99.0, (float) $this->db->table('supplier_prices')->where(['supplier_id' => 'SMP-001', 'item_id' => 'A-1'])->get()->getRow()->unit_price, 'Kept.');
        $this->assertSame('kept (real price)', $rows[0]['status']);
    }

    // ---------------------------------------------------------------- unmatched names stop the load

    public function testAnUnmatchedItemNameStopsTheWholeLoadAndListsTheNames(): void
    {
        $this->seedCatalog();
        [$suppliers, $priceList] = $this->config();
        $priceList['SMP-002']['items'] = ['Patch', 'MEDALYA PAPURI', 'Totally Unknown Thing'];

        $result = SampleSuppliers::load($suppliers, $priceList);

        $this->assertFalse($result['ok']);
        $this->assertSame(['MEDALYA PAPURI', 'Totally Unknown Thing'], $result['unmatched'], "The names that match nothing are listed, and nothing is guessed (no 'Papuri Medal').");
        $this->assertStringContainsString('MEDALYA PAPURI, Totally Unknown Thing', $result['errors'][0]);
        $this->assertSame(0, $this->rows('suppliers', ['is_sample' => 1]), 'Not even the suppliers were created.');
        $this->assertSame(0, $this->rows('supplier_prices'), 'Nor the names that did match.');
        $this->assertSame([], $result['rows']);
    }

    public function testAnArchivedItemDoesNotCount(): void
    {
        $this->seedCatalog();
        $this->db->table('products')->where('item_name', 'Logo')->update(['is_active' => 0]);

        $result = SampleSuppliers::load([
            'SMP-001' => $this->config()[0]['SMP-001'],
        ], ['SMP-001' => ['factor' => 0.9, 'items' => ['Logo']]]);

        $this->assertFalse($result['ok']);
        $this->assertSame(['Logo'], $result['unmatched']);
    }

    public function testAMissingMigrationIsSaidClearly(): void
    {
        \Config\Database::forge()->dropTable('supplier_prices', true);
        $this->db->resetDataCache();
        [$suppliers, $priceList] = $this->config();

        $result = SampleSuppliers::load($suppliers, $priceList);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('php spark migrate', $result['errors'][0]);
    }

    // ================================================================ remove

    public function testRemoveDeletesOnlySampleDataAndNothingElse(): void
    {
        $this->seedCatalog();
        $this->db->table('suppliers')->insert(['supplier_id' => 'SUP-003', 'company_name' => 'Real Three', 'lead_time_days' => 9]);
        [$suppliers, $priceList] = $this->config();
        SampleSuppliers::load($suppliers, $priceList);
        $this->db->table('supplier_prices')->insert(['supplier_id' => 'SUP-003', 'item_id' => 'A-1', 'unit_price' => 30, 'is_sample' => 0]); // a real price-list row
        $this->db->table('stock_order')->insert(['so_id' => 'SO-0001', 'supplier_id' => 'SUP-001', 'status' => 'Delivered', 'order_date' => '2026-11-01']);
        $keep = [
            'suppliers' => $this->db->table('suppliers')->where('is_sample', 0)->get()->getResultArray(),
            'products'  => $this->db->table('products')->get()->getResultArray(),
            'users'     => $this->db->table('users')->get()->getResultArray(),
            'orders'    => $this->db->table('stock_order')->get()->getResultArray(),
            'real_price' => $this->db->table('supplier_prices')->where('is_sample', 0)->get()->getResultArray(),
        ];

        $result = SampleSuppliers::remove();

        $this->assertTrue($result['ok']);
        $this->assertSame([5, 2], [$result['prices'], $result['suppliers']]);
        $this->assertSame(0, $this->rows('suppliers', ['is_sample' => 1]));
        $this->assertSame(0, $this->rows('supplier_prices', ['is_sample' => 1]));
        $this->assertSame($keep['suppliers'], $this->db->table('suppliers')->where('is_sample', 0)->get()->getResultArray(), 'Real suppliers untouched.');
        $this->assertSame($keep['products'], $this->db->table('products')->get()->getResultArray());
        $this->assertSame($keep['users'], $this->db->table('users')->get()->getResultArray());
        $this->assertSame($keep['orders'], $this->db->table('stock_order')->get()->getResultArray());
        $this->assertSame($keep['real_price'], $this->db->table('supplier_prices')->where('is_sample', 0)->get()->getResultArray(), 'A real price-list row stays.');

        $again = SampleSuppliers::remove();
        $this->assertSame([true, 0, 0], [$again['ok'], $again['prices'], $again['suppliers']], 'Nothing left to remove: fine.');
    }

    public function testRemoveRefusesIfAnythingRealPointsAtASampleSupplier(): void
    {
        $this->seedCatalog();
        [$suppliers, $priceList] = $this->config();
        SampleSuppliers::load($suppliers, $priceList);
        $this->db->table('users')->insert(['username' => 'oops', 'full_name' => 'Oops', 'role' => 'Supplier', 'supplier_id' => 'SMP-001', 'password_hash' => 'x']);

        $result = SampleSuppliers::remove();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('a portal user', $result['errors'][0]);
        $this->assertSame(2, $this->rows('suppliers', ['is_sample' => 1]), 'Nothing was deleted.');
        $this->assertSame(5, $this->rows('supplier_prices'));
    }

    // ================================================================ the command

    public function testTheCommandsDataIsTheAgreedList(): void
    {
        $s = SampleSuppliersCommand::SUPPLIERS;
        $this->assertSame(['SMP-001', 'SMP-002', 'SMP-003', 'SMP-004'], array_keys($s));
        $this->assertSame([
            ['Tondo Stitch & Patch Works', 'Patches', 7, null, 'Cash'],
            ['Caloocan Embroidery Hub', 'Patches', 3, 100, 'Check'],
            ['Bulacan Brass & Pin Makers', 'Metal', 10, null, 'Cash'],
            ['Pasig Tactical Supply Depot', 'Equipment', 5, null, 'Cash or Check'],
        ], array_map(static fn ($x) => [$x['name'], $x['category'], $x['lead_time_days'], $x['min_order_qty'], $x['payment_terms']], array_values($s)));
        foreach ($s as $x) {
            $this->assertStringContainsString('example.invalid', $x['contact_email']);
            $this->assertContains($x['payment_terms'], SupplierModel::PAYMENT_TERMS, 'A value the form accepts.');
        }
        $p = SampleSuppliersCommand::PRICE_LIST;
        $this->assertSame([0.92, 0.85, 0.90, 1.05], array_column($p, 'factor'));
        $this->assertSame(['1ID Security Patch', 'Name Cloth', 'Logo', 'Patch'], $p['SMP-001']['items']);
        $this->assertSame($p['SMP-001']['items'], $p['SMP-002']['items']);
        $this->assertSame(['KALASAG Pin', 'Papuri Medal', 'Badge'], $p['SMP-003']['items']);
        $this->assertSame(['Handcuffs', 'Pepper Spray', 'Cloth'], $p['SMP-004']['items']);
    }

    public function testTheCommandLoadsPrintsTheTableAndRemoves(): void
    {
        $this->seedCatalog();

        command('sample-suppliers load');
        $out = $this->getStreamFilterBuffer();

        $this->assertStringContainsString('Sample suppliers: 4 created, 0 already there', $out);
        $this->assertStringContainsString('Price-list rows: 16 new', $out, '5 + 5 + 3 + 3 (Patch has two sizes).');
        foreach (['Supplier', 'Item', 'Size', 'Main cost', 'Sample price', 'Tondo Stitch & Patch Works', '₱36', '₱33', 'Pasig Tactical Supply Depot', '₱347'] as $text) {
            $this->assertStringContainsString($text, $out);
        }
        $this->assertSame(4, $this->rows('suppliers', ['is_sample' => 1]));
        $this->assertSame(16, $this->rows('supplier_prices'));
        $this->assertSame(0, $this->rows('users', ['role' => 'Supplier']) - 1, 'No portal user for any sample supplier (only the fixture\'s own).');
        $this->assertSame(0, $this->db->table('users')->whereIn('supplier_id', ['SMP-001', 'SMP-002', 'SMP-003', 'SMP-004'])->countAllResults());

        $this->resetStreamFilterBuffer();
        command('sample-suppliers load');
        $this->assertStringContainsString('0 created, 4 already there', $this->getStreamFilterBuffer());
        $this->assertSame(16, $this->rows('supplier_prices'), 'Safe to run again.');

        $this->resetStreamFilterBuffer();
        command('sample-suppliers remove');
        $this->assertStringContainsString('Removed 16 sample price-list row(s) and 4 sample supplier(s)', $this->getStreamFilterBuffer());
        $this->assertSame([0, 0], [$this->rows('suppliers', ['is_sample' => 1]), $this->rows('supplier_prices')]);
    }

    public function testTheCommandStopsAndListsUnmatchedNames(): void
    {
        $this->seedCatalog();
        $this->db->table('products')->where('item_name', 'PAPURI MEDAL')->update(['item_name' => 'MEDALYA PAPURI']); // the real data's other spelling

        command('sample-suppliers load');
        $out = $this->getStreamFilterBuffer();

        $this->assertStringContainsString('Papuri Medal', $out, 'The name that matched nothing is listed.');
        $this->assertStringContainsString('nothing was loaded', $out);
        $this->assertSame(0, $this->rows('suppliers', ['is_sample' => 1]));
        $this->assertSame(0, $this->rows('supplier_prices'));
    }

    public function testTheCommandNeedsAnAction(): void
    {
        command('sample-suppliers');

        $this->assertStringContainsString('php spark sample-suppliers load', $this->getStreamFilterBuffer());
        $this->assertSame(0, $this->rows('suppliers', ['is_sample' => 1]));
    }

    // ================================================================ pdss_computation and real data stay as they are

    public function testLoadAndRemoveLeaveTheDssResultsAndTheMainSuppliersAlone(): void
    {
        $this->seedCatalog();
        $snapshot = fn () => [
            $this->db->table('pdss_computation')->orderBy('computation_id')->get()->getResultArray(),
            $this->db->table('products')->orderBy('item_id')->get()->getResultArray(),
        ];
        $before = $snapshot();

        command('sample-suppliers load');
        $this->assertSame($before, $snapshot(), 'After load: pdss_computation (lead time, safety stock, ROP, EOQ) and every item\'s main supplier are identical.');
        $leadTimes = $this->db->query('SELECT p.item_id, s.lead_time_days FROM db_products p LEFT JOIN db_suppliers s ON s.supplier_id = p.supplier_id ORDER BY p.item_id')->getResultArray();
        $this->assertSame(['SUP-001'], array_values(array_unique(array_column($this->db->table('products')->get()->getResultArray(), 'supplier_id'))));
        $this->assertSame([7], array_values(array_unique(array_column($leadTimes, 'lead_time_days'))), "dss:run's lead-time lookup still sees only the main supplier.");

        command('sample-suppliers remove');
        $this->assertSame($before, $snapshot(), 'After remove too.');
    }

    // ================================================================ never a main supplier, never in the order dialog

    private function loadSample(): void
    {
        $this->seedCatalog();
        [$suppliers, $priceList] = $this->config();
        SampleSuppliers::load($suppliers, $priceList);
    }

    /** @return list<string> the supplier IDs offered by a <select> */
    private function options(string $html, string $selectId): array
    {
        $x = $this->html($html);

        return array_values(array_filter(array_map(static fn ($o) => $o->getAttribute('value'), iterator_to_array($x->query('//select[@id="' . $selectId . '"]/option')))));
    }

    public function testSampleSuppliersAreNotOfferedAsAnItemsMainSupplier(): void
    {
        $this->loadSample();

        $options = $this->options($this->owner('admin/inventory'), 'supplier_id');

        $this->assertContains('SUP-001', $options);
        $this->assertSame([], array_values(array_filter($options, static fn ($id) => str_starts_with($id, 'SMP-'))));
    }

    public function testSampleSuppliersAreNotInThePurchaseOrderDialog(): void
    {
        $this->loadSample();

        $orders = $this->options($this->owner('admin/orders'), 'poSupplierChoose');

        $this->assertContains('SUP-001', $orders, 'The Orders page dialog lists real suppliers.');
        $this->assertSame([], array_values(array_filter($orders, static fn ($id) => str_starts_with($id, 'SMP-'))));
        // and the dialog's data on the other pages carries no sample supplier either
        $this->assertStringNotContainsString('SMP-0', $this->owner('admin/reorder-alerts') . $this->owner('admin/inventory'));
    }

    public function testTheServerRefusesASampleSupplierAsMainSupplier(): void
    {
        $this->loadSample();
        $body = ['item_name' => 'New Thing', 'category' => 'Patches', 'unit_cost' => '50', 'selling_price' => '90', 'supplier_id' => 'SMP-001'];

        $this->asOwner()->post('admin/inventory/store', $body);
        $this->assertSame(0, $this->rows('products', ['item_name' => 'New Thing']), 'Not added.');
        $this->assertStringContainsString('Sample suppliers are only for price comparison', json_encode($_SESSION['toast'] ?? []));

        $before = $this->db->table('products')->where('item_id', 'A-1')->get()->getRow()->supplier_id;
        $this->asOwner()->post('admin/inventory/update/A-1', ['item_name' => '1ID Security Patch', 'category' => 'Clothes', 'unit_cost' => '36', 'selling_price' => '90', 'supplier_id' => 'SMP-002']);
        $this->assertSame($before, $this->db->table('products')->where('item_id', 'A-1')->get()->getRow()->supplier_id, 'The main supplier did not change.');

        // a real supplier still works
        $this->asOwner()->post('admin/inventory/store', ['supplier_id' => 'SUP-001'] + $body);
        $this->assertSame(1, $this->rows('products', ['item_name' => 'New Thing']));
    }

    public function testTheServerRefusesAnOrderToASampleSupplier(): void
    {
        $this->loadSample();

        $this->asOwner()->post('admin/orders/store', ['supplier_id' => 'SMP-001', 'item_id' => ['A-4'], 'quantity' => ['10'], 'unit_price' => ['100'], 'return_to' => '/admin/orders', 'priority' => 'Order']);

        $this->assertSame(0, $this->rows('stock_order'), 'No order, no purchase.');
        $this->assertStringContainsString('Sample suppliers are only for price comparison', json_encode($_SESSION['toast'] ?? []));
    }

    public function testASampleSupplierNeverGetsAPortalAccountFromTheForms(): void
    {
        $this->loadSample();

        $this->assertSame(0, $this->db->table('users')->whereIn('supplier_id', ['SMP-001', 'SMP-002'])->countAllResults());
        // the Edit form has no portal fields (the portal login is only created when a supplier is added)
        $form = file_get_contents(APPPATH . 'Controllers/Admin/SupplierController.php');
        $update = substr($form, strpos($form, 'public function update('), strpos($form, 'public function delete(') - strpos($form, 'public function update('));
        $this->assertStringNotContainsString('userModel->insert', $update);
    }

    // ================================================================ the Suppliers page

    private function supplierCards(string $url = 'admin/suppliers'): array
    {
        $x = $this->html($this->owner($url));
        $cards = [];
        foreach ($x->query('//div[@class="sup-stat"]') as $card) {
            $cards[trim($x->query('./span', $card)->item(0)->textContent)] = trim($x->query('./strong', $card)->item(0)->textContent);
        }

        return $cards;
    }

    public function testTheSuppliersPageShowsASampleBadge(): void
    {
        $this->loadSample();
        $x = $this->html($this->owner('admin/suppliers'));

        $names = [];
        foreach ($x->query('//div[contains(concat(" ", normalize-space(@class), " "), " sup-card ")]') as $card) {
            $names[trim($x->query('.//div[@class="name"]/text()', $card)->item(0)->textContent)] = $x->query('.//span[contains(@class,"sup-sample")]', $card)->length;
        }

        $this->assertSame(1, $names['Sample One']);
        $this->assertSame(1, $names['Sample Two']);
        $this->assertSame(0, $names['Tactical Gear Inc'], 'A real supplier has no badge.');
        $this->assertSame('Sample', trim($x->query('//span[contains(@class,"sup-sample")]')->item(0)->textContent));
    }

    public function testTheBadgeAlsoShowsWhenTheSampleSupplierIsArchived(): void
    {
        $this->loadSample();
        $this->asOwner()->post('admin/suppliers/delete/SMP-001');
        $this->assertSame(0, (int) $this->db->table('suppliers')->where('supplier_id', 'SMP-001')->get()->getRow()->is_active, 'Archiving is allowed.');

        $archived = $this->owner('admin/suppliers?status=archived');

        $this->assertStringContainsString('Sample One', $archived);
        $this->assertStringContainsString('sup-sample', preg_replace('/\.sup-sample[^}]*\}/', '', $archived));
    }

    public function testTheSummaryCardsLeaveSampleSuppliersOut(): void
    {
        $before = $this->supplierCards();
        $this->loadSample();

        $this->assertSame($before, $this->supplierCards(), 'Total, average delivery time, active and pending: the same with or without samples.');
        $this->assertSame($before, $this->supplierCards('admin/suppliers?status=archived'));
        $this->assertSame(2, $this->rows('suppliers', ['is_sample' => 1]));
    }

    public function testASampleSuppliersEditFormSaysSoAndKeepsTheOtherFieldsEditable(): void
    {
        $this->loadSample();
        $html = $this->owner('admin/suppliers/edit/SMP-001');
        $x    = $this->html($html);

        $this->assertStringContainsString('Sample supplier.', $x->query('//div[contains(@class,"sf-sample-note")]')->item(0)->textContent);
        $this->assertNotNull($x->query('//input[@name="company_name"]')->item(0));
        $this->assertNotNull($x->query('//select[@name="payment_terms"]')->item(0));
        $this->assertNull($x->query('//*[@name="is_sample"]')->item(0), 'No field can change it.');
        $real = $this->html($this->owner('admin/suppliers/edit/SUP-001'));
        $this->assertNull($real->query('//div[contains(@class,"sf-sample-note")]')->item(0));
    }

    public function testASampleSupplierCanBeEditedButCanNeverBecomeReal(): void
    {
        $this->loadSample();

        $this->asOwner()->post('admin/suppliers/update/SMP-001', ['company_name' => 'Sample One (renamed)', 'lead_time_days' => '9', 'payment_terms' => 'Check', 'is_sample' => '0', 'supplier_status' => 'Active']);

        $row = $this->db->table('suppliers')->where('supplier_id', 'SMP-001')->get()->getRowArray();
        $this->assertSame(['Sample One (renamed)', 9, 'Check'], [$row['company_name'], (int) $row['lead_time_days'], $row['payment_terms']], 'Details and terms can be edited.');
        $this->assertSame(1, (int) $row['is_sample'], 'is_sample = 0 in the form changes nothing.');
        $this->assertNotContains('is_sample', (new SupplierModel())->allowedFields, 'The model cannot mass-assign it either.');
    }

    public function testARealSupplierCanNeverBecomeASampleOrBeCreatedAsOne(): void
    {
        $this->asOwner()->post('admin/suppliers/update/SUP-001', ['company_name' => 'Tactical Gear Inc', 'lead_time_days' => '5', 'is_sample' => '1', 'supplier_status' => 'Active']);
        $this->assertSame(0, (int) $this->db->table('suppliers')->where('supplier_id', 'SUP-001')->get()->getRow()->is_sample);

        $this->asOwner()->post('admin/suppliers/store', ['company_name' => 'Brand New Co', 'lead_time_days' => '3', 'is_sample' => '1', 'supplier_status' => 'Active']);
        $new = $this->db->table('suppliers')->where('company_name', 'Brand New Co')->get()->getRowArray();
        $this->assertNotNull($new);
        $this->assertSame(0, (int) $new['is_sample']);
        $this->assertStringStartsWith('SUP-', $new['supplier_id']);
    }

    // ================================================================ "Cash or Check"

    public function testCashOrCheckIsAFourthPaymentTermAndTheOldOnesStillWork(): void
    {
        $this->assertSame(['Cash', 'Check', 'Cash or Check', 'Other'], SupplierModel::PAYMENT_TERMS);
        $save = function (string $terms): ?string {
            $this->asOwner()->post('admin/suppliers/update/SUP-001', ['company_name' => 'Tactical Gear Inc', 'lead_time_days' => '5', 'payment_terms' => $terms, 'supplier_status' => 'Active']);

            return $this->db->table('suppliers')->where('supplier_id', 'SUP-001')->get()->getRow()->payment_terms;
        };

        foreach (['Cash', 'Check', 'Other', 'Cash or Check'] as $terms) {
            $this->assertSame($terms, $save($terms), "{$terms} is accepted.");
        }
        $this->assertSame('Cash or Check', $save('Barter'), 'Not a choice: refused, unchanged.');

        $this->asOwner()->post('admin/suppliers/store', ['company_name' => 'Both Ways Co', 'lead_time_days' => '3', 'payment_terms' => 'Cash or Check', 'supplier_status' => 'Active']);
        $this->assertSame('Cash or Check', $this->db->table('suppliers')->where('company_name', 'Both Ways Co')->get()->getRow()->payment_terms);
    }

    public function testCashOrCheckIsInTheFormTheCardAndThePurchaseOrderPdf(): void
    {
        $this->db->table('suppliers')->where('supplier_id', 'SUP-001')->update(['payment_terms' => 'Cash or Check']);

        $form = $this->html($this->owner('admin/suppliers/edit/SUP-001'));
        $options = array_map(static fn ($o) => trim($o->textContent), iterator_to_array($form->query('//select[@name="payment_terms"]/option')));
        $this->assertSame(['Not set', 'Cash', 'Check', 'Cash or Check', 'Other'], $options);
        $this->assertSame('Cash or Check', trim($form->query('//select[@name="payment_terms"]/option[@selected]')->item(0)->textContent));

        $cards = $this->html($this->owner('admin/suppliers'));
        $terms = $cards->query('//div[contains(concat(" ", normalize-space(@class), " "), " sup-card ")][.//div[@class="name"][contains(., "Tactical Gear Inc")]]//div[contains(@class,"sup-terms")]');
        $this->assertStringContainsString('Cash or Check', $terms->item(0)->textContent, 'The supplier card shows its terms.');

        $this->addSize('P-1', 'Patch', null, 5, 3);
        $this->db->table('stock_order')->insert(['so_id' => 'SO-0001', 'supplier_id' => 'SUP-001', 'user_id' => $this->ownerId, 'status' => 'Order Confirmed', 'order_date' => '2026-11-14', 'po_number' => 'PO-2026-0001']);
        $this->db->table('so_item')->insert(['so_id' => 'SO-0001', 'item_id' => 'P-1', 'order_quantity' => 10, 'unit_price' => 50]);
        $order = $this->db->table('stock_order')->where('so_id', 'SO-0001')->get()->getRowArray();
        $this->assertStringContainsString('>Terms</td><td class="v">Cash or Check<', view('procurement/po', ProcurementDocs::documentData($order, 'po')));
    }

    // ================================================================ the supplier ID generator

    private function ids(array $ids): void
    {
        $this->db->table('suppliers')->truncate();
        foreach ($ids as $id) {
            $this->db->table('suppliers')->insert(['supplier_id' => $id, 'company_name' => $id, 'lead_time_days' => 5, 'is_sample' => str_starts_with($id, 'SMP-') ? 1 : 0]);
        }
    }

    public function testTheNextRealSupplierIdIsTheNextFreeSupNumberEvenWithSamplesAndGaps(): void
    {
        $this->ids(['SUP-001', 'SUP-002', 'SUP-005', 'SMP-001', 'SMP-002', 'SMP-003', 'SMP-004']);

        $this->assertSame('SUP-006', (new SupplierModel())->generateNextId(), 'The highest SUP number is 5, not the row count (7).');
    }

    public function testTheGeneratorIgnoresRowCountsAndOddIds(): void
    {
        $this->ids([]);
        $this->assertSame('SUP-001', (new SupplierModel())->generateNextId(), 'The first supplier.');

        $this->ids(['SUP-001', 'SUP-002', 'SUP-003']);
        $this->assertSame('SUP-004', (new SupplierModel())->generateNextId(), 'No gaps: as before.');

        $this->ids(['SUP-010', 'SUP-X', 'SUP-', 'SMP-009', 'DEMO-777']);
        $this->assertSame('SUP-011', (new SupplierModel())->generateNextId(), 'Only SUP-### numbers count.');
    }

    public function testAddingASupplierAfterLoadingAndRemovingSamplesNeverReusesAnId(): void
    {
        $this->ids(['SUP-001', 'SUP-002']);
        $this->db->table('suppliers')->insertBatch(array_map(static fn ($n) => ['supplier_id' => 'SMP-00' . $n, 'company_name' => 'S' . $n, 'lead_time_days' => 3, 'is_sample' => 1], [1, 2, 3, 4]));
        $model = new SupplierModel();

        $first = $model->generateNextId();                  // SUP-003 although 6 rows exist
        $this->db->table('suppliers')->insert(['supplier_id' => $first, 'company_name' => 'Real Three', 'lead_time_days' => 5]);
        $this->db->table('suppliers')->where('is_sample', 1)->delete();   // remove the samples
        $second = $model->generateNextId();

        $this->assertSame(['SUP-003', 'SUP-004'], [$first, $second], 'Existing IDs never change and none is handed out twice.');
        $this->assertSame(['SUP-001', 'SUP-002', 'SUP-003'], array_column($this->db->table('suppliers')->orderBy('supplier_id')->get()->getResultArray(), 'supplier_id'));
    }
}
