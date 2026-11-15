<?php

use App\Libraries\DuplicateSaleVoid;
use App\Models\ProductModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Services;
use Tests\Support\ReorderFixture;
use Tests\Support\VoidSalesFixture;

/**
 * php spark sales:void-duplicate (App\Libraries\DuplicateSaleVoid): it refuses anything that is not
 * an exact double-click duplicate, the dry run changes nothing, --apply voids the duplicate and gives
 * its stock back exactly (relative), writes the SALE_VOID and security logs, syncs the reorder
 * notices, rolls back completely on a failure, and a second run changes nothing.
 * Runs on the in-memory 'tests' database.
 *
 * @internal
 */
final class VoidDuplicateSaleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ReorderFixture;
    use StreamFilterTrait;
    use VoidSalesFixture;

    protected $migrate = false;
    protected $refresh = false;

    private const WHEN = '2026-11-14 16:25:11';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVoidSales();
        Services::resetSingle('renderer');
    }

    protected function tearDown(): void
    {
        $this->tearDownVoidSales();
        parent::tearDown();
    }

    private function spark(string $args): string
    {
        $this->resetStreamFilterBuffer();
        command('sales:void-duplicate ' . $args);

        return $this->getStreamFilterBuffer();
    }

    private function failed(array $check): array
    {
        return array_column(array_filter($check['checks'], static fn ($c) => ! $c['ok']), 'label');
    }

    // ---------------------------------------------------------------- dry run

    public function testTheDryRunShowsBothSalesAndThePlanAndChangesNothing(): void
    {
        [$orig, $dup] = $this->doubleClick('1737', '1738', self::WHEN);
        $before       = $this->snapshot();

        $out = $this->spark("{$dup} {$orig} --by {$this->ownerId}");

        $this->assertSame($before, $this->snapshot(), 'The dry run changed nothing.');
        $this->assertStringContainsString('Original', $out);
        $this->assertStringContainsString('Duplicate', $out);
        $this->assertStringContainsString('P-1 x1 @ 250.00', $out);
        $this->assertStringNotContainsString('FAIL', $out);
        $this->assertStringContainsString('P-1 Patrol Shirt', $out);
        $this->assertStringContainsString('3 -> 4 (+1)', $out, 'Stock before and after (5 - 2 sales = 3).');
        $this->assertStringContainsString('"Duplicate sale #1738 voided, duplicate of #1737"', $out);
        $this->assertStringContainsString('DRY RUN: nothing was changed', $out);
    }

    public function testTheCommandNeedsBothIds(): void
    {
        $this->assertStringContainsString('Usage:', $this->spark('738'));
    }

    // ---------------------------------------------------------------- apply

    public function testApplyVoidsTheDuplicateAndGivesItsStockBack(): void
    {
        [$orig, $dup] = $this->doubleClick('1737', '1738', self::WHEN);
        $this->assertSame(3, $this->stock('P-1'));

        $out = $this->spark("{$dup} {$orig} --by {$this->ownerId} --apply");
        $this->assertStringContainsString('APPLIED', $out);

        $this->assertSame(4, $this->stock('P-1'), 'Exactly the one unit the duplicate took.');
        $this->assertSame(9, $this->stock('P-2'), 'Other items untouched.');

        $sale = $this->db->table('sales_transaction')->where('sales_id', $dup)->get()->getRowArray();
        $this->assertNotNull($sale['voided_at']);
        $this->assertSame($this->ownerId, (int) $sale['voided_by']);
        $this->assertSame($orig, (int) $sale['duplicate_of_sales_id']);
        $this->assertSame(DuplicateSaleVoid::DEFAULT_REASON, $sale['void_reason']);
        $this->assertSame(2, $this->db->table('sales_transaction')->countAllResults(), 'The duplicate is kept, not deleted.');
        $this->assertSame(1, $this->db->table('sales_item')->where('sales_id', $dup)->countAllResults(), 'Its line is kept.');
        $this->assertNull($this->db->table('sales_transaction')->where('sales_id', $orig)->get()->getRow()->voided_at, 'The original is untouched.');

        $logs = $this->db->table('inventory_log')->where('log_type', 'SALE_VOID')->get()->getResultArray();
        $this->assertCount(1, $logs);
        $this->assertSame(['P-1', 1, 4, 'Duplicate sale #1738 voided, duplicate of #1737', $this->ownerId], [$logs[0]['item_id'], (int) $logs[0]['quantity_changed'], (int) $logs[0]['stock_after_change'], $logs[0]['notes'], (int) $logs[0]['user_id']]);
        $this->assertSame(2, $this->db->table('inventory_log')->where('log_type', 'SALE')->countAllResults(), 'The original SALE entries stay.');

        $audit = $this->db->table('security_log')->get()->getResultArray();
        $this->assertCount(1, $audit);
        $this->assertSame([DuplicateSaleVoid::EVENT, $this->ownerId, 'owner1', 'Admin'], [$audit[0]['event'], (int) $audit[0]['user_id'], $audit[0]['username'], $audit[0]['role']]);
        foreach (['#1738', 'sales_id ' . $dup, '#1737', 'sales_id ' . $orig, 'P-1 +1', DuplicateSaleVoid::DEFAULT_REASON] as $part) {
            $this->assertStringContainsString($part, $audit[0]['detail']);
        }
        $this->assertNotEmpty($audit[0]['created_at']);
    }

    public function testACustomReasonIsSaved(): void
    {
        [$orig, $dup] = $this->doubleClick('1737', '1738', self::WHEN);
        $this->spark("{$dup} {$orig} --by {$this->ownerId} --apply --reason \"Double click on Nov 14 test\"");

        $this->assertSame('Double click on Nov 14 test', $this->db->table('sales_transaction')->where('sales_id', $dup)->get()->getRow()->void_reason);
        $this->assertStringContainsString('reason: Double click on Nov 14 test', $this->db->table('security_log')->get()->getRow()->detail);
    }

    public function testTheStockIsGivenBackRelativeToWhatIsThereNow(): void
    {
        [$orig, $dup] = $this->doubleClick('1737', '1738', self::WHEN);
        $this->db->table('products')->where('item_id', 'P-1')->update(['current_stock' => 10]); // a restock since the dry run

        DuplicateSaleVoid::apply($dup, $orig, $this->ownerId);

        $this->assertSame(11, $this->stock('P-1'), 'Stock now + 1, never a number worked out earlier.');
    }

    public function testEveryLineIsGivenBackIncludingTwoLinesOfTheSameItem(): void
    {
        [$orig, $dup] = $this->doubleClick('1737', '1738', self::WHEN, [['P-1', 1, 250.0], ['P-2', 2, 120.0], ['P-1', 1, 250.0, 1]]);
        $this->assertSame([1, 5], [$this->stock('P-1'), $this->stock('P-2')]);

        $result = DuplicateSaleVoid::apply($dup, $orig, $this->ownerId);

        $this->assertSame(DuplicateSaleVoid::VOIDED, $result['status']);
        $this->assertSame([3, 7], [$this->stock('P-1'), $this->stock('P-2')]);
        $this->assertSame(3, $this->db->table('inventory_log')->where('log_type', 'SALE_VOID')->countAllResults(), 'One entry per line.');
    }

    public function testRunningItTwiceChangesNothingTheSecondTime(): void
    {
        [$orig, $dup] = $this->doubleClick('1737', '1738', self::WHEN);
        $this->spark("{$dup} {$orig} --by {$this->ownerId} --apply");
        $after = $this->snapshot();

        $out = $this->spark("{$dup} {$orig} --by {$this->ownerId} --apply");

        $this->assertStringContainsString('Already done', $out);
        $this->assertSame($after, $this->snapshot(), 'The second run changed nothing.');
        $this->assertSame(DuplicateSaleVoid::ALREADY, DuplicateSaleVoid::apply($dup, $orig, $this->ownerId)['status']);
        $this->assertSame($after, $this->snapshot());
        $this->assertSame(4, $this->stock('P-1'));
    }

    public function testAFailurePartWayRollsEverythingBack(): void
    {
        [$orig, $dup] = $this->doubleClick('1737', '1738', self::WHEN);
        $tables = ['sales_transaction', 'sales_item', 'products', 'inventory_log', 'reorder_alert', 'reorder_notices'];
        $before = $this->snapshot($tables);
        \Config\Database::forge()->dropTable('security_log', true); // the last write fails

        $result = DuplicateSaleVoid::apply($dup, $orig, $this->ownerId);

        $this->assertSame(DuplicateSaleVoid::FAILED, $result['status']);
        $this->assertStringContainsString('Nothing was changed', $result['message']);
        $this->assertSame($before, $this->snapshot($tables), 'Sale, stock and logs exactly as before.');

        // A later run on the same connection is not affected by that failure.
        $forge = \Config\Database::forge();
        $forge->addField(['log_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'user_id' => ['type' => 'INTEGER', 'null' => true], 'username' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true], 'role' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true], 'event' => ['type' => 'VARCHAR', 'constraint' => 50], 'detail' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true], 'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true], 'created_at' => ['type' => 'DATETIME', 'null' => true]]);
        $forge->addKey('log_id', true);
        $forge->createTable('security_log');
        $this->assertSame(DuplicateSaleVoid::VOIDED, DuplicateSaleVoid::apply($dup, $orig, $this->ownerId)['status']);
    }

    public function testItRollsEverythingBackWhenTheLogTypeIsNotSavedAsSaleVoid(): void
    {
        [$orig, $dup] = $this->doubleClick('1737', '1738', self::WHEN);
        // What a non-strict MySQL does with a value its ENUM lacks: saves '' without an error.
        $this->db->query("CREATE TRIGGER blank_sale_void AFTER INSERT ON inventory_log WHEN NEW.log_type = 'SALE_VOID' BEGIN UPDATE inventory_log SET log_type = '' WHERE log_id = NEW.log_id; END");
        $tables = ['sales_transaction', 'sales_item', 'products', 'inventory_log', 'security_log', 'reorder_alert', 'reorder_notices'];
        $before = $this->snapshot($tables);

        $result = DuplicateSaleVoid::apply($dup, $orig, $this->ownerId);

        $this->assertSame(DuplicateSaleVoid::FAILED, $result['status']);
        $this->assertStringContainsString("saved with log_type '' instead of SALE_VOID", $result['message']);
        $this->assertSame($before, $this->snapshot($tables), 'The sale is not voided, no stock given back, no log rows kept.');
        $this->db->query('DROP TRIGGER blank_sale_void');
    }

    public function testTheEnumValuesAreReadFromTheColumnType(): void
    {
        $this->assertSame(['MANUAL_ADJUSTMENT', 'RESTOCK', 'SALE', 'RETURN', 'DAMAGED_LOST'], DuplicateSaleVoid::enumValues("enum('MANUAL_ADJUSTMENT','RESTOCK','SALE','RETURN','DAMAGED_LOST')"));
        $this->assertContains('SALE_VOID', DuplicateSaleVoid::enumValues("ENUM('SALE','SALE_VOID')"));
        $this->assertSame(["it's", 'B'], DuplicateSaleVoid::enumValues("enum('it''s','B')"));
        $this->assertSame([], DuplicateSaleVoid::enumValues('varchar(20)'));
    }

    public function testTheLogTypeCheckIsPartOfEveryRun(): void
    {
        // SQLite keeps any text, so the check passes here; on MySQL it reads the ENUM (shown on localhost).
        [$orig, $dup] = $this->doubleClick('1737', '1738', self::WHEN);

        $this->assertContains('inventory_log.log_type accepts SALE_VOID (migration AddVoidToSalesTransaction run)', array_column(DuplicateSaleVoid::check($dup, $orig, $this->ownerId)['checks'], 'label'));
        $this->assertNull(DuplicateSaleVoid::logTypeValues());
    }

    public function testTheReorderStatusAndNoticeFollowTheStockGivenBack(): void
    {
        [$orig, $dup] = $this->doubleClick('1737', '1738', self::WHEN); // 5 - 2 = 3: at the reorder point 3
        $this->db->table('reorder_notices')->insert(['item_id' => 'P-1', 'notified_by' => $this->staffId, 'created_at' => self::WHEN]);
        $products = new ProductModel();
        $this->assertSame('Reorder Now', $products->getStatus($products->find('P-1'))['label']);

        $this->assertSame(DuplicateSaleVoid::VOIDED, DuplicateSaleVoid::apply($dup, $orig, $this->ownerId)['status']); // 3 + 1 = 4: above it

        $this->assertNotSame('Reorder Now', $products->getStatus($products->find('P-1'))['label']);
        $notice = $this->db->table('reorder_notices')->get()->getRow();
        $this->assertNotNull($notice->cleared_at, 'Cleared, as after any stock change (ReorderNoticeModel::clearResolved).');
        $this->assertSame('stock_ok', $notice->cleared_reason);
    }

    // ---------------------------------------------------------------- refusals

    /** @return array<string, array{0: Closure, 1: string}> */
    public static function mismatches(): array
    {
        $sale = static fn (self $t, int $id, array $set) => $t->db->table('sales_transaction')->where('sales_id', $id)->update($set);
        $line = static fn (self $t, int $id, array $set) => $t->db->table('sales_item')->where('sales_id', $id)->update($set);

        return [
            'a different user'          => [static fn (self $t, int $o, int $d) => $sale($t, $d, ['user_id' => $t->staffId]), 'Same user'],
            'a different item or size'  => [static fn (self $t, int $o, int $d) => $line($t, $d, ['item_id' => 'P-2']), 'Same items, sizes, quantities and prices (line by line)'],
            'a different quantity'      => [static fn (self $t, int $o, int $d) => $line($t, $d, ['quantity_sold' => 2]), 'Same items, sizes, quantities and prices (line by line)'],
            'a different price'         => [static fn (self $t, int $o, int $d) => $line($t, $d, ['selling_price' => 240]), 'Same items, sizes, quantities and prices (line by line)'],
            'a bulk line'               => [static fn (self $t, int $o, int $d) => $line($t, $d, ['is_bulk' => 1]), 'Same items, sizes, quantities and prices (line by line)'],
            'an extra line'             => [static fn (self $t, int $o, int $d) => $t->db->table('sales_item')->insert(['sales_id' => $d, 'item_id' => 'P-2', 'quantity_sold' => 1, 'selling_price' => 120]), 'Same items, sizes, quantities and prices (line by line)'],
            'a missing line'            => [static fn (self $t, int $o, int $d) => $t->db->table('sales_item')->insert(['sales_id' => $o, 'item_id' => 'P-2', 'quantity_sold' => 1, 'selling_price' => 120]), 'Same items, sizes, quantities and prices (line by line)'],
            'a different payment'       => [static fn (self $t, int $o, int $d) => $sale($t, $d, ['payment_method' => 'GCash']), 'Same payment method'],
            'a different cash amount'   => [static fn (self $t, int $o, int $d) => $sale($t, $d, ['cash_amount' => 300]), 'Same cash amount'],
            'a different GCash amount'  => [static fn (self $t, int $o, int $d) => $sale($t, $d, ['gcash_amount' => 50]), 'Same GCash amount'],
            'a different GCash ref'     => [static fn (self $t, int $o, int $d) => $sale($t, $d, ['gcash_reference_no' => 'REF-9']), 'Same GCash reference'],
            'a different subtotal'      => [static fn (self $t, int $o, int $d) => $sale($t, $d, ['subtotal' => 260]), 'Same subtotal'],
            'a different discount'      => [static fn (self $t, int $o, int $d) => $sale($t, $d, ['discount' => 10]), 'Same discount'],
            'a different total'         => [static fn (self $t, int $o, int $d) => $sale($t, $d, ['total_amount' => 240]), 'Same total'],
            'a different OR number'     => [static fn (self $t, int $o, int $d) => $sale($t, $d, ['or_number' => 'A-1']), 'Same OR number'],
            'saved 11 seconds later'    => [static fn (self $t, int $o, int $d) => $sale($t, $d, ['sale_date' => '2026-11-14 16:25:22']), 'Saved after the original, within 10 seconds'],
            'saved before the original' => [static fn (self $t, int $o, int $d) => $sale($t, $d, ['sale_date' => '2026-11-14 16:25:10']), 'Saved after the original, within 10 seconds'],
            'the original voided'       => [static fn (self $t, int $o, int $d) => $sale($t, $o, ['voided_at' => '2026-11-15 09:00:00']), 'Original still valid (not voided, has items)'],
            'a return on the duplicate' => [static fn (self $t, int $o, int $d) => $t->db->table('inventory_log')->insert(['item_id' => 'P-1', 'user_id' => $t->staffId, 'log_type' => 'RETURN', 'quantity_changed' => 1, 'stock_after_change' => 4, 'notes' => 'Condition: Good. Reason: Wrong size. Refund action: Refund Issued. Ref:  1738 ']), 'No customer return logged against the duplicate'],
        ];
    }

    /** @dataProvider mismatches */
    public function testItRefusesAndChangesNothingWhen(Closure $change, string $failingCheck): void
    {
        [$orig, $dup] = $this->doubleClick('1737', '1738', self::WHEN);
        $change($this, $orig, $dup);
        $before = $this->snapshot();

        $result = DuplicateSaleVoid::apply($dup, $orig, $this->ownerId);

        $this->assertSame(DuplicateSaleVoid::REFUSED, $result['status']);
        $this->assertContains($failingCheck, $this->failed($result['check']));
        $this->assertSame($before, $this->snapshot(), 'Nothing changed.');

        $out = $this->spark("{$dup} {$orig} --by {$this->ownerId} --apply");
        $this->assertStringContainsString('REFUSED', $out);
        $this->assertStringContainsString('[FAIL] ' . $failingCheck, $out);
        $this->assertSame($before, $this->snapshot(), 'Nothing changed by the command either.');
    }

    public function testTenSecondsApartIsStillADuplicate(): void
    {
        [$orig, $dup] = $this->doubleClick('1737', '1738', self::WHEN);
        $this->db->table('sales_transaction')->where('sales_id', $dup)->update(['sale_date' => '2026-11-14 16:25:21']);

        $this->assertTrue(DuplicateSaleVoid::check($dup, $orig, $this->ownerId)['ok']);
    }

    public function testAReturnOnTheOriginalDoesNotBlockIt(): void
    {
        [$orig, $dup] = $this->doubleClick('1737', '1738', self::WHEN);
        $this->db->table('inventory_log')->insert(['item_id' => 'P-1', 'log_type' => 'RETURN', 'quantity_changed' => 1, 'stock_after_change' => 4, 'notes' => 'Condition: Good. Ref: 1737']);

        $this->assertTrue(DuplicateSaleVoid::check($dup, $orig, $this->ownerId)['ok']);
    }

    public function testItRefusesTheIdsTheWrongWayRound(): void
    {
        [$orig, $dup] = $this->doubleClick('1737', '1738', self::WHEN);
        $before = $this->snapshot();

        $result = DuplicateSaleVoid::apply($orig, $dup, $this->ownerId);

        $this->assertSame(DuplicateSaleVoid::REFUSED, $result['status']);
        $this->assertContains('Two different sales, the duplicate saved later', $this->failed($result['check']));
        $this->assertSame($before, $this->snapshot());
    }

    public function testItRefusesASaleThatDoesNotExist(): void
    {
        [$orig] = $this->doubleClick('1737', '1738', self::WHEN);
        $before = $this->snapshot();

        $out = $this->spark("999 {$orig} --by {$this->ownerId} --apply");

        $this->assertStringContainsString('[FAIL] Duplicate sale exists - no sale with sales_id 999', $out);
        $this->assertSame($before, $this->snapshot());
    }

    /** @return array<string, array{0: string}> */
    public static function notAnOwner(): array
    {
        return ['no --by' => [''], 'a staff account' => ['staff'], 'an inactive owner' => ['inactive'], 'an unknown user' => ['999']];
    }

    /** @dataProvider notAnOwner */
    public function testItMustBeRunByAnActiveOwner(string $who): void
    {
        [$orig, $dup] = $this->doubleClick('1737', '1738', self::WHEN);
        if ($who === 'inactive') {
            $this->db->table('users')->where('user_id', $this->ownerId)->update(['is_active' => 0]);
        }
        $by     = ['' => '', 'staff' => " --by {$this->staffId}", 'inactive' => " --by {$this->ownerId}", '999' => ' --by 999'][$who];
        $before = $this->snapshot();

        $out = $this->spark("{$dup} {$orig}{$by} --apply");

        $this->assertStringContainsString('[FAIL] Run by an active Owner account (--by)', $out);
        $this->assertStringContainsString('REFUSED', $out);
        $this->assertSame($before, $this->snapshot());
    }

    public function testTheByOptionAlsoWorksWithAnEqualsSign(): void
    {
        [$orig, $dup] = $this->doubleClick('1737', '1738', self::WHEN);

        $this->assertStringContainsString('DRY RUN', $this->spark("{$dup} {$orig} --by={$this->ownerId}"));
    }
}
