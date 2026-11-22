<?php

use App\Database\Migrations\AddIsSampleToSuppliers;
use App\Database\Migrations\CreateSupplierPrices;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The two migrations behind the supplier price list: suppliers.is_sample and the supplier_prices table.
 * Run for real (up and down) against the in-memory 'tests' database, with minimal suppliers and products tables.
 *
 * @internal
 */
final class SupplierPricesMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    protected function setUp(): void
    {
        parent::setUp();
        // Migration files have dated names, so they are not autoloaded.
        require_once APPPATH . 'Database/Migrations/2026-11-22-100000_AddIsSampleToSuppliers.php';
        require_once APPPATH . 'Database/Migrations/2026-11-22-100100_CreateSupplierPrices.php';
        $forge = \Config\Database::forge();
        foreach (['supplier_prices', 'products', 'suppliers'] as $t) {
            $forge->dropTable($t, true);
        }
        $forge->addField(['supplier_id' => ['type' => 'VARCHAR', 'constraint' => 10], 'company_name' => ['type' => 'VARCHAR', 'constraint' => 100], 'lead_time_days' => ['type' => 'INTEGER', 'default' => 7]]);
        $forge->addKey('supplier_id', true);
        $forge->createTable('suppliers');
        $forge->addField(['item_id' => ['type' => 'VARCHAR', 'constraint' => 15], 'item_name' => ['type' => 'VARCHAR', 'constraint' => 100]]);
        $forge->addKey('item_id', true);
        $forge->createTable('products');
        $this->db->table('suppliers')->insert(['supplier_id' => 'SUP-001', 'company_name' => 'Real Co']);
        $this->db->table('products')->insert(['item_id' => 'P-1', 'item_name' => 'Patch']);
    }

    protected function tearDown(): void
    {
        $forge = \Config\Database::forge();
        foreach (['supplier_prices', 'products', 'suppliers'] as $t) {
            $forge->dropTable($t, true);
        }
        parent::tearDown();
    }

    public function testIsSampleIsAddedToSuppliersAndDefaultsToReal(): void
    {
        $this->assertFalse($this->db->fieldExists('is_sample', 'suppliers'));

        (new AddIsSampleToSuppliers())->up();
        $this->db->resetDataCache(); // field names are cached per table

        $this->assertTrue($this->db->fieldExists('is_sample', 'suppliers'));
        $this->assertSame(0, (int) $this->db->table('suppliers')->where('supplier_id', 'SUP-001')->get()->getRow()->is_sample, 'An existing supplier stays real.');
        $this->db->table('suppliers')->insert(['supplier_id' => 'SUP-002', 'company_name' => 'Another']);
        $this->assertSame(0, (int) $this->db->table('suppliers')->where('supplier_id', 'SUP-002')->get()->getRow()->is_sample, 'NOT NULL DEFAULT 0.');

        (new AddIsSampleToSuppliers())->down();
        $this->db->resetDataCache();
        $this->assertFalse($this->db->fieldExists('is_sample', 'suppliers'));
        $this->assertSame(2, $this->db->table('suppliers')->countAllResults(), 'Going back keeps the suppliers.');
    }

    public function testSupplierPricesHasTheColumnsAndTheUniqueKey(): void
    {
        (new AddIsSampleToSuppliers())->up();
        $this->assertFalse($this->db->tableExists('supplier_prices'));

        (new CreateSupplierPrices())->up();
        $this->db->resetDataCache();

        $this->assertTrue($this->db->tableExists('supplier_prices'));
        $this->assertSame(['id', 'supplier_id', 'item_id', 'unit_price', 'lead_time_days', 'min_order_qty', 'is_sample', 'created_at', 'updated_at'], $this->db->getFieldNames('supplier_prices'));

        // lead_time_days and min_order_qty are optional (NULL = the supplier's lead time / no minimum); is_sample defaults to 0
        $this->db->table('supplier_prices')->insert(['supplier_id' => 'SUP-001', 'item_id' => 'P-1', 'unit_price' => 33.5]);
        $row = $this->db->table('supplier_prices')->get()->getRowArray();
        $this->assertNull($row['lead_time_days']);
        $this->assertNull($row['min_order_qty']);
        $this->assertSame(0, (int) $row['is_sample']);
        $this->assertEqualsWithDelta(33.5, (float) $row['unit_price'], 0.001);

        // one offer per supplier and item
        $this->expectException(\Throwable::class);
        $this->db->table('supplier_prices')->insert(['supplier_id' => 'SUP-001', 'item_id' => 'P-1', 'unit_price' => 30]);
    }

    public function testTheTableCanBeDroppedAgain(): void
    {
        (new AddIsSampleToSuppliers())->up();
        (new CreateSupplierPrices())->up();

        (new CreateSupplierPrices())->down();
        $this->db->resetDataCache();

        $this->assertFalse($this->db->tableExists('supplier_prices'));
    }

    public function testTheMigrationsOnlyAddAndKeepTheCreateStatementsOneWay(): void
    {
        $dir = APPPATH . 'Database/Migrations/';
        $sample = file_get_contents($dir . '2026-11-22-100000_AddIsSampleToSuppliers.php');
        $prices = file_get_contents($dir . '2026-11-22-100100_CreateSupplierPrices.php');

        $this->assertStringContainsString("'type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0", $sample);
        foreach (["'unit_price'     => ['type' => 'DECIMAL', 'constraint' => '10,2']", "'lead_time_days' => ['type' => 'INT', 'constraint' => 11, 'null' => true", "'min_order_qty'  => ['type' => 'INT', 'constraint' => 11, 'null' => true", "addUniqueKey(['supplier_id', 'item_id']", "addForeignKey('supplier_id', 'suppliers', 'supplier_id'", "addForeignKey('item_id', 'products', 'item_id'"] as $needle) {
            $this->assertStringContainsString($needle, $prices);
        }
        // the new migrations never rewrite what exists: no dropping of anything but their own additions
        $this->assertSame(0, substr_count($sample . $prices, 'dropTable(\'suppliers\''));
        $this->assertSame(0, substr_count($sample . $prices, 'modifyColumn'));
    }
}
