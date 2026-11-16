<?php

use App\Database\Migrations\AddRopSourceToProducts;
use App\Database\Migrations\WidenDemandAndAddEoqRawToPdssComputation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ReorderFixture;

// Migration files carry a date prefix, so they aren't autoloaded.
require_once APPPATH . 'Database/Migrations/2026-11-16-100000_WidenDemandAndAddEoqRawToPdssComputation.php';
require_once APPPATH . 'Database/Migrations/2026-11-16-100100_AddRopSourceToProducts.php';

/**
 * Who set an item's Reorder At (products.rop_source) and the DSS columns added with it:
 * Inventory Add / Edit marks a typed value 'manual'; the migration backfills old rows; dss:run
 * saves eoq_raw and writes its reorder point through DssFormulas::reorderPointUpdate() (the rule
 * itself is unit tested in DssFormulasTest). dss:run needs the K-Means service, so its wiring is
 * checked in the source, like the other dss:run tests. In-memory 'tests' database.
 *
 * @internal
 */
final class RopSourceTest extends CIUnitTestCase
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

    private function product(string $id): array
    {
        return $this->db->table('products')->where('item_id', $id)->get()->getRowArray();
    }

    private function addItem(array $post): void
    {
        $this->asOwner()->post('admin/inventory/store', $post + ['item_name' => 'Field Cap', 'category' => 'Accessories', 'unit_cost' => 50, 'selling_price' => 90, 'current_stock' => 0]);
    }

    // ------------------------------------------------------------ Inventory Add / Edit

    public function testAddItemWithATypedReorderAtIsManual(): void
    {
        $this->addItem(['manual_rop_warning' => 6]);
        $row = $this->db->table('products')->where('item_name', 'Field Cap')->get()->getRowArray();

        $this->assertSame(6, (int) $row['manual_rop_warning']);
        $this->assertSame('manual', $row['rop_source']);
    }

    public function testAddItemWithoutAReorderAtIsDss(): void
    {
        $this->addItem(['manual_rop_warning' => '']);
        $row = $this->db->table('products')->where('item_name', 'Field Cap')->get()->getRowArray();

        $this->assertSame(0, (int) $row['manual_rop_warning']);
        $this->assertSame('dss', $row['rop_source']);
    }

    private function edit(string $id, $rop): void
    {
        $p = $this->product($id);
        $this->asOwner()->post('admin/inventory/update/' . $id, [
            'item_name' => $p['item_name'], 'category' => $p['category'], 'size' => $p['size'] ?? '',
            'unit_cost' => 50, 'selling_price' => 90, 'current_stock' => $p['current_stock'], 'manual_rop_warning' => $rop,
        ]);
    }

    public function testEditTypingANewReorderAtMarksItManual(): void
    {
        $this->addSize('P-1', 'Pants', 'M', 9, 0, ['rop_source' => 'dss']); // latest run gave 0: field editable
        $this->edit('P-1', 4);

        $this->assertSame(4, (int) $this->product('P-1')['manual_rop_warning']);
        $this->assertSame('manual', $this->product('P-1')['rop_source']);
    }

    public function testEditKeepingTheSameValueKeepsWhoSetIt(): void
    {
        $this->addSize('P-1', 'Pants', 'M', 9, 0, ['manual_rop_warning' => 2, 'rop_source' => 'dss']);
        $this->edit('P-1', 2);

        $this->assertSame('dss', $this->product('P-1')['rop_source']);
    }

    public function testEditIsIgnoredOnceTheDssCalculatedOne(): void
    {
        $this->addSize('P-1', 'Pants', 'M', 9, 3, ['rop_source' => 'dss']); // latest run gave 3: "Auto"
        $this->edit('P-1', 8);

        $this->assertSame(3, (int) $this->product('P-1')['manual_rop_warning']);
        $this->assertSame('dss', $this->product('P-1')['rop_source']);
    }

    // ------------------------------------------------------------ migrations

    public function testRopSourceBackfill(): void
    {
        $this->addSize('ZERO', 'Zero', null, 5, 0);
        $this->addSize('NONE', 'None', null, 5, null);
        $this->addSize('OLD', 'Old run', null, 5, 0);  // latest run 0, an earlier run gave 2
        $this->db->table('products')->where('item_id', 'OLD')->update(['manual_rop_warning' => 2]);
        $this->db->table('pdss_computation')->insert(['item_id' => 'OLD', 'computed_at' => '2026-10-01 10:00:00', 'reorder_point' => 2, 'eoq_value' => 10]);
        $this->addSize('TYPED', 'Typed', null, 5, 0);  // no run ever gave 7
        $this->db->table('products')->where('item_id', 'TYPED')->update(['manual_rop_warning' => 7]);
        $this->addSize('SET', 'Already set', null, 5, 0, ['rop_source' => 'manual']);

        (new AddRopSourceToProducts(\Config\Database::forge()))->up();

        $this->assertSame('dss', $this->product('ZERO')['rop_source']);
        $this->assertSame('dss', $this->product('NONE')['rop_source']);
        $this->assertSame('dss', $this->product('OLD')['rop_source'], 'A value a DSS run produced is the DSS\'s.');
        $this->assertSame('manual', $this->product('TYPED')['rop_source'], 'A value no run produced was typed.');
        $this->assertSame('manual', $this->product('SET')['rop_source'], 'Filled rows are left alone.');

        (new AddRopSourceToProducts(\Config\Database::forge()))->up(); // safe to run again
        $this->assertSame('manual', $this->product('TYPED')['rop_source']);
    }

    public function testRopSourceMigrationAddsAndRemovesTheColumn(): void
    {
        $migration = new AddRopSourceToProducts(\Config\Database::forge());
        $migration->down();
        $this->db->resetDataCache();
        $this->assertFalse($this->db->fieldExists('rop_source', 'products'));
        $migration->up();
        $this->db->resetDataCache();
        $this->assertTrue($this->db->fieldExists('rop_source', 'products'));
    }

    public function testEoqRawMigrationAddsAndRemovesTheColumn(): void
    {
        $migration = new WidenDemandAndAddEoqRawToPdssComputation(\Config\Database::forge());
        $migration->down();
        $this->db->resetDataCache();
        $this->assertFalse($this->db->fieldExists('eoq_raw', 'pdss_computation'));
        $migration->up();
        $migration->up(); // safe to run again
        $this->db->resetDataCache();
        $this->assertTrue($this->db->fieldExists('eoq_raw', 'pdss_computation'));
    }

    public function testDemandColumnsAreWidenedToFourDecimals(): void
    {
        // MySQL only (the SQLite test tables use REAL), so the statement itself is checked.
        $source = file_get_contents(APPPATH . 'Database/Migrations/2026-11-16-100000_WidenDemandAndAddEoqRawToPdssComputation.php');
        $this->assertStringContainsString("private const DEMAND_COLUMNS = ['avg_daily_demand', 'demand_std_dev'];", $source);
        $this->assertStringContainsString('MODIFY {$column} DECIMAL(10,4) NULL', $source);
        // dss:run already rounds both to 4 places, so 0.004 a day reaches the column as 0.004.
        $this->assertMatchesRegularExpression("/'avg_daily_demand' => round\\(\\\$avgDaily, 4\\)/", file_get_contents(APPPATH . 'Commands/DssRun.php'));
    }

    // ------------------------------------------------------------ dss:run wiring

    public function testDssRunSavesEoqRawAndUsesTheReorderPointRule(): void
    {
        $source = file_get_contents(APPPATH . 'Commands/DssRun.php');

        $this->assertMatchesRegularExpression("/'eoq_raw'\s*=> \\\$eoqRaw/", $source, 'The EOQ before the minimum is saved.');
        $this->assertMatchesRegularExpression("/'eoq_value'\s*=> \\\$eoq,/", $source);
        $this->assertStringContainsString('DssFormulas::reorderPointUpdate(', $source);
        $this->assertStringContainsString("\$p['rop_source'] ?? null", $source);
        $this->assertStringContainsString("->update(\$ropUpdate)", $source, 'Writes the value and its source together.');
        $this->assertStringNotContainsString('if ($reorderPoint > 0) {', $source, 'The old "above 0 only" rule is gone.');
    }
}
