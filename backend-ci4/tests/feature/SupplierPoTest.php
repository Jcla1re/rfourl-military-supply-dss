<?php

use App\Libraries\AccountingReports;
use App\Libraries\OnceToken;
use App\Libraries\ProcurementDocs;
use App\Libraries\SupplierPo;
use App\Models\StockOrderModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Config\Factories;
use Config\Services;
use Tests\Support\ReorderFixture;

/**
 * "Create PO for low items" (Procurement Report > Suppliers): the review of one supplier's items at Reorder
 * Now (and, unticked, at Low Stock), and the ONE purchase order with several lines created from it. Every
 * line is checked again on the server (main supplier only, not already on an open order, whole quantity of
 * 1 or more, the item's own unit cost), the whole order is refused when anything changed, a double submit
 * saves once, and the documents / supplier portal / receiving / journal handle the several lines.
 * In-memory 'tests' database.
 *
 * @internal
 */
final class SupplierPoTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ReorderFixture;

    protected $migrate = false;
    protected $refresh = false;

    private const EXTRA_TABLES = ['inventory_log', 'form_submissions'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReorderFixture();
        Services::resetSingle('renderer');

        $forge = \Config\Database::forge();
        foreach (self::EXTRA_TABLES as $t) {
            $forge->dropTable($t, true);
        }
        $id  = ['type' => 'INTEGER', 'auto_increment' => true];
        $int = ['type' => 'INTEGER', 'null' => true];
        $str = ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true];
        $forge->addField(['log_id' => $id, 'item_id' => $str, 'user_id' => $int, 'log_type' => $str, 'quantity_changed' => $int, 'stock_after_change' => $int, 'reference_so_id' => $str, 'notes' => $str, 'timestamp' => ['type' => 'DATETIME', 'null' => true]]);
        $forge->addKey('log_id', true);
        $forge->createTable('inventory_log');
        $forge->addField(['id' => $id, 'submission_key' => ['type' => 'CHAR', 'constraint' => 32], 'action' => $str, 'user_id' => $int, 'result_type' => $str, 'result_id' => $str, 'created_at' => ['type' => 'DATETIME']]);
        $forge->addKey('id', true);
        $forge->addUniqueKey('submission_key');
        $forge->createTable('form_submissions');

        $this->db->table('suppliers')->insertBatch([
            ['supplier_id' => 'SUP-003', 'company_name' => 'Empty Supply Co', 'lead_time_days' => 9, 'is_sample' => 0, 'is_active' => 1],
            ['supplier_id' => 'SUP-004', 'company_name' => 'Archived Co', 'lead_time_days' => 5, 'is_sample' => 0, 'is_active' => 0],
            ['supplier_id' => 'SMP-001', 'company_name' => 'Sample Stitch Works', 'lead_time_days' => 3, 'is_sample' => 1, 'is_active' => 1],
        ]);
        $this->db->table('suppliers')->where('supplier_id', 'SUP-001')->update(['lead_time_days' => 5, 'is_active' => 1, 'is_sample' => 0]);
        $this->db->table('suppliers')->where('supplier_id', 'SUP-002')->update(['lead_time_days' => 7, 'is_active' => 1, 'is_sample' => 0]);

        // SUP-001: two at Reorder Now, one Low Stock, one fine, one at Reorder Now but already on an open order, one archived.
        $this->addSize('P-S', 'Pants', 'S', 1, 3);
        $this->addSize('P-M', 'Pants', 'M', 2, 3);
        $this->addSize('P-L', 'Pants', 'L', 4, 3);      // Low Stock
        $this->addSize('P-OK', 'Pants', 'XL', 50, 3);   // In Stock
        $this->addSize('P-BUSY', 'Belt', null, 1, 3);   // Reorder Now, but on order
        $this->addSize('P-ARCH', 'Old Cap', null, 1, 3, ['is_active' => 0]);
        // Another supplier's item at Reorder Now.
        $this->addSize('Q-1', 'Patch', null, 1, 3, ['supplier_id' => 'SUP-002']);
        $this->openOrder('SO-0005', ['P-BUSY'], null);
    }

    protected function tearDown(): void
    {
        $forge = \Config\Database::forge();
        foreach (self::EXTRA_TABLES as $t) {
            $forge->dropTable($t, true);
        }
        $this->tearDownReorderFixture();
        Services::resetSingle('security');
        parent::tearDown();
    }

    // ------------------------------------------------------------ helpers

    private function key(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        return new \DOMXPath($dom);
    }

    private function review(string $supplier = 'SUP-001'): string
    {
        return (string) $this->asOwner()->get('admin/procurement-report/po/' . $supplier)->getBody();
    }

    /** POSTs the review's form. $qty defaults to 12 for every ticked item. */
    private function send(array $pick, ?array $qty = null, array $extra = [], string $supplier = 'SUP-001', ?string $key = null)
    {
        $qty ??= array_fill_keys($pick, '12');

        return $this->asOwner()->post('admin/procurement-report/po/' . $supplier, [
            'pick' => $pick, 'qty' => $qty, OnceToken::FIELD => $key ?? $this->key(),
        ] + $extra);
    }

    private function rows(string $table, array $where = []): int
    {
        return $this->db->table($table)->where($where)->countAllResults();
    }

    /** The new order (SO-0006 is the next after the fixture's one) with its lines, or null. */
    private function newOrder(): ?array
    {
        $o = $this->db->table('stock_order')->where('so_id !=', 'SO-0005')->get()->getRowArray();

        return $o ? $o + ['lines' => $this->db->table('so_item')->where('so_id', $o['so_id'])->orderBy('so_item_id')->get()->getResultArray()] : null;
    }

    private function errorsOf(string $html): string
    {
        $node = $this->xpath($html)->query('//*[@id="spoErrors"]')->item(0);

        return $node ? preg_replace('/\s+/', ' ', $node->textContent) : '';
    }

    // ------------------------------------------------------------ the review

    public function testTheReviewListsReorderNowTicked_LowStockUnticked_AndOpenOrdersDisabled(): void
    {
        $raw = html_entity_decode($this->review()); // the app writes ₱ as &#8369;
        $x   = $this->xpath($raw);

        $now = [];
        foreach ($x->query('//table[@id="spoNowTable"]//tr[contains(@class,"spo-row")]') as $tr) {
            $now[$tr->getAttribute('data-item-id')] = $tr;
        }
        $this->assertEqualsCanonicalizing(['P-S', 'P-M', 'P-BUSY'], array_keys($now), 'Reorder Now only, this supplier only, no archived item.');

        foreach (['P-S', 'P-M'] as $id) {
            $box = $x->query('.//input[contains(@class,"spo-pick")]', $now[$id])->item(0);
            $this->assertTrue($box->hasAttribute('checked'), "{$id} is ticked.");
            $this->assertFalse($box->hasAttribute('disabled'));
            $this->assertSame('12', $x->query('.//input[contains(@class,"spo-qty")]', $now[$id])->item(0)->getAttribute('value'), 'The Items tab Order Qty.');
        }

        $busy = $now['P-BUSY'];
        $this->assertTrue($x->query('.//input[contains(@class,"spo-pick")]', $busy)->item(0)->hasAttribute('disabled'));
        $this->assertFalse($x->query('.//input[contains(@class,"spo-pick")]', $busy)->item(0)->hasAttribute('checked'));
        $this->assertStringContainsString('Already on order (SO-0005)', $busy->textContent);
        $this->assertSame(0, $x->query('.//input[contains(@class,"spo-qty")]', $busy)->length, 'Nothing to type for a line that cannot be ordered.');

        $low = $x->query('//table[@id="spoLowTable"]//tr[contains(@class,"spo-row")]');
        $this->assertSame(1, $low->length);
        $this->assertSame('P-L', $low->item(0)->getAttribute('data-item-id'));
        $this->assertFalse($x->query('.//input[contains(@class,"spo-pick")]', $low->item(0))->item(0)->hasAttribute('checked'), 'Low Stock starts unticked.');
        $this->assertStringContainsString('Also running low (optional)', $raw);
        $this->assertStringContainsString('Adding these now saves a separate order later (₱500.00 ordering cost per order).', $raw);
        $this->assertStringContainsString('1 order instead of', file_get_contents(FCPATH . 'assets/js/supplier-po-review.js'));
    }

    public function testTheUnitCostIsShownButNotAnEditableField(): void
    {
        $html = $this->review();

        $this->assertStringContainsString('₱50.00', html_entity_decode($html));
        $this->assertSame(0, $this->xpath($html)->query('//input[starts-with(@name,"unit_price") or starts-with(@name,"price")]')->length, 'Only the quantity is posted.');
    }

    public function testASupplierMinimumIsAWarningOnTheLineNotABlock(): void
    {
        $this->db->table('products')->where('item_id', 'P-M')->update(['supplier_min_qty' => 30]);
        $x = $this->xpath($this->review());

        $warn = $x->query('//tr[@data-item-id="P-M"]//*[contains(@class,"spo-warn")]')->item(0);
        $this->assertFalse($warn->hasAttribute('hidden'), 'The suggested quantity is under the supplier minimum of 30: the warning shows.');
        $this->assertStringContainsString('Below the supplier', $warn->textContent);

        $this->send(['P-M'], ['P-M' => '5'])->assertRedirect();
        $this->assertSame(5, (int) $this->newOrder()['lines'][0]['order_quantity'], 'Warn only: the order is still created.');
    }

    // ------------------------------------------------------------ creating one order

    public function testOneOrderWithSeveralLinesIsCreatedAtTheItemsOwnUnitCost(): void
    {
        $result = $this->send(['P-S', 'P-M'], ['P-S' => '12', 'P-M' => '20'], ['unit_price' => ['P-S' => '1', 'P-M' => '1']]);

        $result->assertRedirectTo(site_url('/admin/procurement-report?tab=suppliers&supplier=SUP-001'));
        $order = $this->newOrder();
        $this->assertNotNull($order);
        $this->assertSame('SUP-001', $order['supplier_id']);
        $this->assertSame('Order Confirmed', $order['status']);
        $this->assertMatchesRegularExpression('/^PO-\d{4}-\d{4}$/', $order['po_number']);
        $this->assertSame(1, $this->rows('stock_order', ['so_id !=' => 'SO-0005']), 'ONE order.');
        $this->assertSame(['P-S' => 12, 'P-M' => 20], array_column($order['lines'], 'order_quantity', 'item_id'));
        $this->assertSame([50.0, 50.0], array_map('floatval', array_column($order['lines'], 'unit_price')), 'The posted price is ignored: the item\'s own cost.');
        $this->assertSame('Purchase order created', $_SESSION['toast']['title'] ?? null);
    }

    public function testALowStockLineIsAcceptedWhenTheOwnerTicksIt(): void
    {
        $this->send(['P-S', 'P-L'])->assertRedirect();

        $this->assertEqualsCanonicalizing(['P-S', 'P-L'], array_column($this->newOrder()['lines'], 'item_id'));
    }

    public function testOnlyTickedLinesAreOrdered(): void
    {
        $this->send(['P-S'], ['P-S' => '12', 'P-M' => '99'])->assertRedirect();

        $this->assertSame(['P-S'], array_column($this->newOrder()['lines'], 'item_id'), 'P-M had a typed quantity but was not ticked.');
    }

    public function testNothingTickedIsRefused(): void
    {
        $result = $this->send([]);

        $result->assertStatus(422);
        $this->assertStringContainsString('Tick at least 1 item', $this->errorsOf((string) $result->response()->getBody()));
        $this->assertNull($this->newOrder());
    }

    public function testAnOrderedItemsAlertsAreOrderedAndStaffNoticesAreCleared(): void
    {
        $alert = $this->alertFor('P-S');
        $this->db->table('reorder_notices')->insert(['item_id' => 'P-M', 'created_at' => '2026-11-01 10:00:00']);

        $this->send(['P-S', 'P-M'])->assertRedirect();

        $row = $this->db->table('reorder_alert')->where('alert_id', $alert)->get()->getRowArray();
        $this->assertSame('Ordered', $row['status']);
        $this->assertSame($this->newOrder()['so_id'], $row['so_id']);
        $this->assertNotNull($this->db->table('reorder_notices')->where('item_id', 'P-M')->get()->getRowArray()['cleared_at'], 'Staff "Owner notified" is answered by the order.');
    }

    public function testTheSupplierIsToldOnceAboutTheOrder(): void
    {
        $this->send(['P-S', 'P-M'])->assertRedirect();

        $po  = $this->newOrder()['po_number'];
        $row = $this->db->table('notifications')->where('title', "New order {$po}")->get()->getResultArray();
        $this->assertCount(1, $row);
        $this->assertSame('Supplier', $row[0]['recipient_role']);
    }

    public function testTheOrderIsUrgentWhenALineHasNothingOnHand(): void
    {
        $this->db->table('products')->where('item_id', 'P-S')->update(['current_stock' => 0]);
        $this->send(['P-S', 'P-M'])->assertRedirect();

        $this->assertSame('Urgent', $this->newOrder()['priority']);
    }

    public function testExpectedDeliveryStartsAtTodayPlusTheLeadTimeAndMustBeARealFutureDate(): void
    {
        $x = $this->xpath($this->review());
        $this->assertSame(date('Y-m-d', strtotime('+5 days')), $x->query('//*[@id="spoExpected"]')->item(0)->getAttribute('value'));

        foreach (['2020-01-01', 'next week', '2026-02-30'] as $bad) {
            $this->send(['P-S'], null, ['expected_delivery_date' => $bad])->assertStatus(422);
        }
        $this->assertNull($this->newOrder());

        $good = date('Y-m-d', strtotime('+9 days'));
        $this->send(['P-S'], null, ['expected_delivery_date' => $good])->assertRedirect();
        $this->assertStringStartsWith($good, (string) $this->newOrder()['expected_delivery_date']);
    }

    // ------------------------------------------------------------ the server never trusts the browser

    public function testAnItemThatGotAnOpenOrderSinceTheReviewRefusesTheWholeOrderAndSaysWhich(): void
    {
        $this->review(); // the owner looks at it...
        $this->openOrder('SO-0009', ['P-M'], null); // ...and meanwhile someone orders P-M.

        $result = $this->send(['P-S', 'P-M']);

        $result->assertStatus(422);
        $html = (string) $result->response()->getBody();
        $this->assertStringContainsString('Pants · Size M is already on order (SO-0009).', $this->errorsOf($html));
        $this->assertStringContainsString('Nothing was ordered', $this->errorsOf($html));
        $this->assertSame(0, $this->rows('stock_order', ['so_id' => 'SO-0010']), 'Not even the good line (P-S) was ordered.');
        $this->assertSame(0, $this->rows('so_item', ['item_id' => 'P-S']));
        $this->assertStringContainsString('Already on order (SO-0009)', $html, 'The review is shown again, up to date.');
        $this->assertSame(0, $this->rows('form_submissions'), 'The one-time key is free for the corrected form.');
    }

    public function testAnItemAlreadyOnAnOpenOrderCannotBePosted(): void
    {
        $result = $this->send(['P-S', 'P-BUSY']);

        $result->assertStatus(422);
        $this->assertStringContainsString('Belt is already on order (SO-0005).', $this->errorsOf((string) $result->response()->getBody()));
        $this->assertNull($this->newOrder());
    }

    public function testADeliveredOrderDoesNotBlockANewOne(): void
    {
        $this->db->table('stock_order')->where('so_id', 'SO-0005')->update(['status' => 'Delivered']);

        $this->send(['P-BUSY'])->assertRedirect();

        $this->assertSame(['P-BUSY'], array_column($this->newOrder()['lines'], 'item_id'));
    }

    public function testAnItemFromAnotherSupplierIsRefused(): void
    {
        $result = $this->send(['P-S', 'Q-1']);

        $result->assertStatus(422);
        $this->assertStringContainsString("Patch isn't from this supplier.", $this->errorsOf((string) $result->response()->getBody()));
        $this->assertNull($this->newOrder());
    }

    public function testAnItemThatIsNotLowAnyMoreArchivedOrUnknownIsRefused(): void
    {
        foreach ([['P-OK', 'Pants · Size XL is not low on stock any more (now In Stock).'], ['P-ARCH', 'Old Cap is archived.'], ['NOPE', 'no longer exists']] as [$id, $message]) {
            $result = $this->send(['P-S', $id]);
            $result->assertStatus(422);
            $this->assertStringContainsString($message, $this->errorsOf((string) $result->response()->getBody()), $id);
        }
        $this->assertNull($this->newOrder());
    }

    /** @dataProvider badQuantities */
    public function testAQuantityMustBeAWholeNumberOfOneOrMore(string $typed): void
    {
        $result = $this->send(['P-S', 'P-M'], ['P-S' => '12', 'P-M' => $typed]);

        $result->assertStatus(422);
        $this->assertStringContainsString('Enter a whole quantity of 1 or more for Pants · Size M.', $this->errorsOf((string) $result->response()->getBody()));
        $this->assertNull($this->newOrder());
    }

    public static function badQuantities(): array
    {
        return [['0'], ['-3'], ['1.5'], ['abc'], [''], [' '], ['1e3'], ['99999999999']];
    }

    public function testAMissingOrNonArrayQuantityMapIsRefusedWithoutAnError(): void
    {
        $this->asOwner()->post('admin/procurement-report/po/SUP-001', ['pick' => ['P-S'], 'qty' => 'oops', OnceToken::FIELD => $this->key()])->assertStatus(422);
        $this->asOwner()->post('admin/procurement-report/po/SUP-001', ['pick' => 'P-S', OnceToken::FIELD => $this->key()])->assertStatus(422);

        $this->assertNull($this->newOrder());
    }

    public function testAnItemWithNoUnitCostIsRefused(): void
    {
        $this->db->table('products')->where('item_id', 'P-S')->update(['unit_cost' => 0]);

        $result = $this->send(['P-S']);

        $result->assertStatus(422);
        $this->assertStringContainsString('has no unit cost', $this->errorsOf((string) $result->response()->getBody()));
    }

    // ------------------------------------------------------------ who can order from whom

    /** @dataProvider unavailableSuppliers */
    public function testSampleArchivedAndUnknownSuppliersCannotReceiveAnOrder(string $supplier): void
    {
        $this->asOwner()->get('admin/procurement-report/po/' . $supplier)->assertRedirectTo(site_url('/admin/procurement-report?tab=suppliers'));
        $this->send(['P-S'], null, [], $supplier)->assertRedirectTo(site_url('/admin/procurement-report?tab=suppliers'));

        $this->assertSame(0, $this->rows('stock_order', ['so_id !=' => 'SO-0005']));
        $this->assertSame('error', $_SESSION['toast']['type'] ?? null, 'The owner is told why.');
    }

    public static function unavailableSuppliers(): array
    {
        return [['SMP-001'], ['SUP-004'], ['SUP-999']];
    }

    public function testASampleSupplierSaysWhy(): void
    {
        $this->send(['P-S'], null, [], 'SMP-001');

        $this->assertStringContainsString('only for price comparison', (string) ($_SESSION['toast']['message'] ?? ''));
    }

    public function testOnlyTheOwnerCanOpenOrCreate(): void
    {
        $get  = $this->asStaff()->get('admin/procurement-report/po/SUP-001');
        $post = $this->asStaff()->post('admin/procurement-report/po/SUP-001', ['pick' => ['P-S'], 'qty' => ['P-S' => '12'], OnceToken::FIELD => $this->key()]);

        $get->assertRedirect();
        $post->assertRedirect();
        $this->assertNull($this->newOrder());
    }

    public function testAnExpiredCsrfTokenIsRefusedAndNothingIsSaved(): void
    {
        $filters = config('Filters');
        array_unshift($filters->globals['before'], 'csrf');
        $security           = config('Security');
        $security->redirect = false;
        Factories::injectMock('config', 'Security', $security);
        Services::resetSingle('security');

        $result = $this->asOwner()->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->post('admin/procurement-report/po/SUP-001', ['csrf_test_name' => 'expired', 'pick' => ['P-S'], 'qty' => ['P-S' => '12'], OnceToken::FIELD => $this->key()]);

        $result->assertStatus(419);
        $this->assertNull($this->newOrder());
        $this->assertSame(0, $this->rows('form_submissions'));
    }

    // ------------------------------------------------------------ double submit

    public function testTheSameFormSentTwiceMakesOneOrder(): void
    {
        $key = $this->key();

        $this->send(['P-S', 'P-M'], null, [], 'SUP-001', $key)->assertRedirect();
        $this->send(['P-S', 'P-M'], null, [], 'SUP-001', $key)->assertRedirect();

        $this->assertSame(1, $this->rows('stock_order', ['so_id !=' => 'SO-0005']));
        $this->assertSame(2, $this->rows('so_item', ['so_id' => $this->newOrder()['so_id']]));
        $this->assertSame('Already saved.', $_SESSION['toast']['title'] ?? null);
        $this->assertSame(1, $this->rows('notifications', ['recipient_role' => 'Supplier']), 'The supplier is told once.');
    }

    public function testAnOrderCreateRacedByAnotherInTheSameMomentCannotDuplicateAnItem(): void
    {
        // SupplierPo::create() checks again inside its transaction: the second of two orders for the same item is refused.
        $dss    = (new \App\Models\DssParameterModel())->current();
        $first  = SupplierPo::check('SUP-001', ['P-S'], ['P-S' => '12'], $dss);
        $this->assertSame([], $first['errors']);

        $a = SupplierPo::create('SUP-001', $first['lines'], null, $this->key(), $this->ownerId, $dss);
        $b = SupplierPo::create('SUP-001', $first['lines'], null, $this->key(), $this->ownerId, $dss);

        $this->assertSame('created', $a['status']);
        $this->assertSame('refused', $b['status']);
        $this->assertStringContainsString('already on order', $b['errors'][0]);
        $this->assertSame(1, $this->rows('so_item', ['item_id' => 'P-S']));
    }

    // ------------------------------------------------------------ the button on the Suppliers tab

    public function testTheSuppliersTabShowsTheButtonForSuppliersWithLowItemsOnly(): void
    {
        $x = $this->xpath((string) $this->asOwner()->get('admin/procurement-report?tab=suppliers')->getBody());

        $link = $x->query('//tr[@data-supplier="SUP-001"]//a[contains(@class,"sp-po-btn")]')->item(0);
        $this->assertNotNull($link);
        $this->assertStringEndsWith('/admin/procurement-report/po/SUP-001', $link->getAttribute('href'));
        $this->assertSame('Create PO for low items 2', preg_replace('/\s+/', ' ', trim($link->textContent)), '2 sizes at Reorder Now that are not on an order.');

        $this->assertNotNull($x->query('//tr[@data-supplier="SUP-002"]//a[contains(@class,"sp-po-btn")]')->item(0));
        $none = $x->query('//tr[@data-supplier="SUP-003"]//*[contains(@class,"sp-po-btn")]')->item(0);
        $this->assertNotNull($none);
        $this->assertSame('span', $none->nodeName, 'Nothing low: a disabled button, not a link.');
        $this->assertSame(0, $x->query('//tr[@data-supplier="SMP-001"]//*[contains(@class,"sp-po-btn")]')->length, 'A sample supplier never gets one.');
    }

    public function testTheSupplierDetailHasTheButtonToo(): void
    {
        $x = $this->xpath((string) $this->asOwner()->get('admin/procurement-report?tab=suppliers&supplier=SUP-001')->getBody());

        $this->assertNotNull($x->query('//div[contains(@class,"sp-detail-actions")]//a[contains(@class,"sp-po-btn")]')->item(0));
        $this->assertNotNull($x->query('//*[@id="spNeedsToggle"]')->item(0), 'The Needs action toggle is still there.');
    }

    public function testTheItemsTabStaysReadOnly(): void
    {
        $html = (string) $this->asOwner()->get('admin/procurement-report?tab=items')->getBody();

        $this->assertStringNotContainsString('procurement-report/po/', $html, 'No Create PO link on the Items tab.');
        $this->assertStringNotContainsString('poModal', $html);
    }

    public function testTheHelpGuideMentionsIt(): void
    {
        $this->asOwner()->get('admin/help')->assertSee('Create PO for low items');
    }

    // ------------------------------------------------------------ the several lines through the rest of the flow

    public function testTheDocumentsListEveryLineAndOldSingleItemOrdersStillRender(): void
    {
        $this->send(['P-S', 'P-M'], ['P-S' => '12', 'P-M' => '20'])->assertRedirect();
        $new = (new StockOrderModel())->findWithDetails($this->newOrder()['so_id']);

        $po = ProcurementDocs::documentData($new, 'po');
        $this->assertNotNull($po);
        $this->assertSame([12, 20], array_column($po['lines'], 'ordered'));
        $this->assertSame(1600.0, (float) $po['total'], '12 + 20 pieces at ₱50.');

        $this->db->table('stock_order')->where('so_id', 'SO-0005')->update(['po_number' => 'PO-2020-0001']);
        $old = ProcurementDocs::documentData((new StockOrderModel())->findWithDetails('SO-0005'), 'po');
        $this->assertSame([12], array_column($old['lines'], 'ordered'), 'The single-item order is unchanged.');

        $page = $this->asOwner()->get('admin/orders/' . $new['so_id']);
        $page->assertOK();
        $page->assertSee('Pants');
        $old = $this->asOwner()->get('admin/orders/SO-0005');
        $old->assertOK();
        $old->assertSee('Belt');
    }

    public function testTheSupplierSeesEveryLineAndShipsEachWithItsOwnQuantity(): void
    {
        $this->send(['P-S', 'P-M'], ['P-S' => '12', 'P-M' => '20'])->assertRedirect();
        $so = $this->newOrder();

        $supplier = ['isLoggedIn' => true, 'role' => 'Supplier', 'user_id' => (int) $this->db->table('users')->where('username', 'tgi')->get()->getRow()->user_id, 'supplier_id' => 'SUP-001', 'username' => 'tgi', 'full_name' => 'Tactical Gear', 'last_activity' => time()];
        $portal = $this->withSession($supplier)->get('supplier/new-orders');
        $portal->assertOK();
        $portal->assertSee('Pants');

        $this->db->table('stock_order')->where('so_id', $so['so_id'])->update(['status' => 'Preparing']);
        $ship = [];
        foreach ($so['lines'] as $l) {
            $ship[$l['so_item_id']] = $l['item_id'] === 'P-S' ? '10' : '20';
        }
        $this->withSession($supplier)->post('supplier/deliveries/ship/' . $so['so_id'], ['shipped_qty' => $ship, 'tracking_no' => 'TRK1'])->assertRedirect();

        $this->assertSame(['P-S' => 10, 'P-M' => 20], array_column($this->newOrder()['lines'], 'shipped_quantity', 'item_id'));
    }

    public function testReceivingAddsStockAndAnInventoryLogEntryForEachLineOnce(): void
    {
        $this->send(['P-S', 'P-M'], ['P-S' => '12', 'P-M' => '20'])->assertRedirect();
        $so = $this->newOrder();
        $this->db->table('stock_order')->where('so_id', $so['so_id'])->update(['status' => 'Shipped']);
        $this->db->table('so_item')->where('so_id', $so['so_id'])->where('item_id', 'P-S')->update(['shipped_quantity' => 10]);
        $this->db->table('so_item')->where('so_id', $so['so_id'])->where('item_id', 'P-M')->update(['shipped_quantity' => 20]);

        $key = $this->key();
        $this->asStaff()->post('staff/log-transaction/restock-confirm/' . $so['so_id'], [OnceToken::FIELD => $key]);
        $this->asStaff()->post('staff/log-transaction/restock-confirm/' . $so['so_id'], [OnceToken::FIELD => $this->key()]); // a second, different form

        $stock = static fn (CIUnitTestCase $t, string $id) => (int) \Config\Database::connect()->table('products')->where('item_id', $id)->get()->getRow()->current_stock;
        $this->assertSame(11, $stock($this, 'P-S'), '1 on hand + 10 shipped.');
        $this->assertSame(22, $stock($this, 'P-M'), '2 on hand + 20 shipped.');
        $log = $this->db->table('inventory_log')->where('log_type', 'RESTOCK')->orderBy('log_id')->get()->getResultArray();
        $this->assertSame(['P-S' => 10, 'P-M' => 20], array_column($log, 'quantity_changed', 'item_id'), 'One RESTOCK entry per line.');
        $this->assertSame([$so['so_id'], $so['so_id']], array_column($log, 'reference_so_id'));
        $this->assertSame('Delivered', $this->db->table('stock_order')->where('so_id', $so['so_id'])->get()->getRow()->status);
    }

    public function testThePurchasesJournalAddsEveryLineOfTheReceivedOrder(): void
    {
        $this->send(['P-S', 'P-M'], ['P-S' => '12', 'P-M' => '20'])->assertRedirect();
        $so = $this->newOrder();
        $this->db->table('stock_order')->where('so_id', $so['so_id'])->update(['status' => 'Delivered', 'received_at' => date('Y-m-d H:i:s')]);
        $this->db->table('so_item')->where('so_id', $so['so_id'])->where('item_id', 'P-S')->update(['shipped_quantity' => 10]);

        $journal = AccountingReports::purchasesJournal(date('Y-m-d'), date('Y-m-d'));
        $row     = array_values(array_filter($journal['rows'], static fn ($r) => $r['so_id'] === $so['so_id']))[0];

        $this->assertSame(2, $row['items']);
        $this->assertEquals(10 * 50 + 20 * 50, $row['amount'] ?? 0, 'P-S as shipped (10) plus P-M as ordered (20), at ₱50.');
    }

    public function testCountsAreNotAffectedByAnotherSuppliersOrOnOrderItems(): void
    {
        $sizes  = \App\Libraries\ProcurementReport::rows(\App\Libraries\ProcurementReport::filters([]));
        $counts = SupplierPo::countsBySupplier($sizes);

        $this->assertSame(['now' => 2, 'low' => 1, 'on_order' => 1], $counts['SUP-001']);
        $this->assertSame(['now' => 1, 'low' => 0, 'on_order' => 0], $counts['SUP-002']);
        $this->assertArrayNotHasKey('SUP-003', $counts);
    }

    // ------------------------------------------------------------ page layout (page check fixes)

    public function testTheReviewHasOneBackLinkInTheHeaderAndTheStandardPageContainer(): void
    {
        $html = $this->review();
        $x    = $this->xpath($html);

        $this->assertSame(1, substr_count($html, 'Back to supplier'), 'Only the green header has it.');
        $this->assertSame(1, $x->query('//*[contains(@class,"btn-topbar")][contains(.,"Back to supplier")]')->length);
        $this->assertSame(0, $x->query('//*[contains(@class,"page-wrap")]//a[contains(@class,"lt-back")]')->length, 'No second link in the content.');
        $this->assertGreaterThan(0, $x->query('//div[contains(@class,"page-wrap")]//h2[contains(.,"Create PO for low items")]')->length, 'Same padded container as the Procurement Report.');
        $this->assertGreaterThan(0, $x->query('//div[contains(@class,"page-wrap")]//form[@id="spoForm"]')->length);
    }

    public function testTheButtonExplainsItsCountInATooltip(): void
    {
        $x    = $this->xpath((string) $this->asOwner()->get('admin/procurement-report?tab=suppliers')->getBody());
        $link = $x->query('//tr[@data-supplier="SUP-001"]//a[contains(@class,"sp-po-btn")]')->item(0);

        $this->assertSame('Items at or below the reorder point that are not already on order. Low Stock items can be added on the next page.', $link->getAttribute('title'));

        $detail = $this->xpath((string) $this->asOwner()->get('admin/procurement-report?tab=suppliers&supplier=SUP-001')->getBody());
        $this->assertStringStartsWith('Items at or below the reorder point', $detail->query('//div[contains(@class,"sp-detail-actions")]//a[contains(@class,"sp-po-btn")]')->item(0)->getAttribute('title'));
    }

    public function testCostComparisonHeadersWrapInsteadOfBeingCutOff(): void
    {
        $html = (string) $this->asOwner()->get('admin/procurement-report?tab=comparison')->getBody();

        $this->assertMatchesRegularExpression('/\.pc-table thead th \{[^}]*white-space: normal/', $html, 'Headers may wrap onto two lines.');
        $this->assertDoesNotMatchRegularExpression('/\.pc-table thead th \{[^}]*white-space: nowrap/', $html);
        $this->assertMatchesRegularExpression('/\.pc-sort \{[^}]*display: block/', $html);
    }
}
