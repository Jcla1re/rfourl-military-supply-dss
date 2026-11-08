<?php

use App\Libraries\PurchaseOrderLine;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ReorderFixture;

/**
 * Phase 4b: the one shared "New purchase order" dialog (partials/_purchase_order_dialog.php)
 * on Inventory and Reorder Alerts: same markup on both pages, no Priority field, each Order
 * button's line data, the unit cost going on the order line only, sizes without a supplier,
 * the return to Inventory, and the Ordered state matching on both pages. Runs on the
 * in-memory 'tests' database; supplier email goes to the fake mailer (see ReorderFixture).
 *
 * @internal
 */
final class PurchaseOrderDialogTest extends CIUnitTestCase
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

    private function page(string $url): string
    {
        return (string) $this->asOwner()->get($url)->getBody();
    }

    /** The dialog and its line template, with the CSRF token value taken out. */
    private function dialogOf(string $html): string
    {
        $start = strpos($html, '<div class="inv-modal po-modal" id="poModal" data-po-dialog>');
        $this->assertNotFalse($start, 'The page has the shared dialog.');
        $end = strpos($html, '</template>', $start) + strlen('</template>');

        return preg_replace('/value="[0-9a-f]{32}"/', 'value="TOKEN"', substr($html, $start, $end - $start));
    }

    /** item_id => decoded data-line, for every Order button on the page. */
    private function linesOn(string $html): array
    {
        preg_match_all('/data-line=(["\'])(.*?)\1/', $html, $m);

        return array_column(array_map(static fn ($a) => json_decode(html_entity_decode($a, ENT_QUOTES), true), $m[2]), null, 'item_id');
    }

    /** What the dialog posts. */
    private function placeOrder(string $returnTo, array $lines, array $extra = [])
    {
        return $this->asOwner()->post('admin/orders/store', $extra + [
            'supplier_id'            => 'SUP-001',
            'item_id'                => array_keys($lines),
            'quantity'               => array_column($lines, 0),
            'unit_price'             => array_column($lines, 1),
            'expected_delivery_date' => '2026-12-01',
            'return_to'              => $returnTo,
        ]);
    }

    // ------------------------------------------------------------ one dialog on both pages

    public function testInventoryAndReorderAlertsRenderTheSameDialog(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3);
        $this->alertFor('P-1');

        $inventory = $this->page('admin/inventory');
        $alerts    = $this->page('admin/reorder-alerts');

        $this->assertSame($this->dialogOf($inventory), $this->dialogOf($alerts));
        $dialog = $this->dialogOf($inventory);
        foreach (['<h2 id="poTitle">New purchase order</h2>', 'Restock an item from its supplier.', '<template id="poLineTemplate">', 'Order details', 'Create order', "The item's own supplier."] as $text) {
            $this->assertStringContainsString($text, $dialog);
        }
        foreach ([$inventory, $alerts] as $html) {
            $this->assertStringNotContainsString('name="priority"', $html, 'No Priority field.');
            $this->assertStringNotContainsString('New Stock Order', $html, 'The old dialog is gone.');
            $this->assertStringNotContainsString('id="orderModal"', $html);
            $this->assertStringContainsString('/assets/js/purchase-order-dialog.js', $html);
            $this->assertStringContainsString('/assets/css/item-modal.css', $html, 'Same dialog styles as Add item.');
        }
        $this->assertMatchesRegularExpression('/class="inv-action-btn open-order-modal"[^>]*>\s*Order\s*<\/button>/s', $inventory, 'Inventory keeps the label "Order".');
    }

    public function testBothPagesGiveTheSizeTheSameLine(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 1, 3, ['category' => 'Clothes', 'unit_cost' => 125.5]);
        $this->addSize('P-M', 'Pants', 'M', 9, 3); // In Stock
        $this->alertFor('P-S');

        $inventory = $this->linesOn($this->page('admin/inventory'));
        $alerts    = $this->linesOn($this->page('admin/reorder-alerts'));

        $expected = ['item_id' => 'P-S', 'name' => 'Pants · Size S', 'size' => 'S', 'category' => 'Clothes', 'status' => 'Reorder Now', 'status_class' => 'red', 'on_hand' => 1, 'rop' => 3, 'safety' => 1, 'eoq' => 12, 'cost' => 125.5, 'supplier_id' => 'SUP-001', 'supplier' => 'Tactical Gear Inc', 'lead' => 5];
        $this->assertSame($expected, $inventory['P-S'], 'Inventory: the size the Order button orders.');
        $this->assertSame($expected, $alerts['P-S']);
    }

    public function testASizeWithoutASupplierLetsTheOwnerChooseOne(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3, ['supplier_id' => null]);
        $this->alertFor('P-1');
        $html = $this->page('admin/reorder-alerts');

        $this->assertSame(['', ''], [$this->linesOn($html)['P-1']['supplier_id'], $this->linesOn($html)['P-1']['supplier']]);
        $this->assertMatchesRegularExpression('/<select name="supplier_id" id="poSupplierPick"[^>]*hidden disabled>/', $html, 'Shown by the dialog for a size without a supplier.');
        $this->assertMatchesRegularExpression('/<option value="SUP-002" data-lead="7">Quiapo Patches Co<\/option>/', $html);

        // No supplier chosen: refused. One chosen: the order goes to it.
        $this->placeOrder('/admin/reorder-alerts', ['P-1' => [5, 50]], ['supplier_id' => '']);
        $this->assertSame(['error', 'Please select a supplier.'], [$_SESSION['toast']['type'], $_SESSION['toast']['message']]);
        $this->assertSame(0, $this->db->table('stock_order')->countAllResults());

        $this->placeOrder('/admin/reorder-alerts', ['P-1' => [5, 50]], ['supplier_id' => 'SUP-002']);
        $this->assertSame('SUP-002', $this->db->table('stock_order')->get()->getRow()->supplier_id);
    }

    // ------------------------------------------------------------ what the order saves

    public function testTheTypedUnitCostGoesOnTheOrderLineOnly(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3, ['unit_cost' => 50]);

        $this->placeOrder('/admin/inventory', ['P-1' => [10, 77.25]]);

        $this->assertSame(77.25, (float) $this->db->table('so_item')->get()->getRow()->unit_price);
        $this->assertSame(50.0, (float) $this->db->table('products')->where('item_id', 'P-1')->get()->getRow()->unit_cost, "The item's saved cost doesn't change.");
    }

    public function testDialogOrdersNeedACostAboveZeroAndAQuantity(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 1, 3);
        $this->addSize('P-M', 'Pants', 'M', 1, 3);

        $this->placeOrder('/admin/inventory', ['P-S' => [5, 0]], ['return_query' => '?category=Clothes']);
        $this->assertSame("Enter a unit cost above ₱0 for every size.", $_SESSION['toast']['message']);

        $response = $this->placeOrder('/admin/reorder-alerts', ['P-S' => [5, 50], 'P-M' => [0, 50]]);
        $this->assertSame('Enter a quantity of 1 or more for every size.', $_SESSION['toast']['message']);
        $this->assertSame(site_url('admin/reorder-alerts'), $response->getRedirectUrl());

        $this->assertSame(0, $this->db->table('stock_order')->countAllResults());
    }

    public function testDialogOrdersSaveTheDefaultPriority(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3);

        $this->placeOrder('/admin/inventory', ['P-1' => [5, 50]], ['priority' => 'Urgent']);

        $this->assertSame('Order', $this->db->table('stock_order')->get()->getRow()->priority);
    }

    // ------------------------------------------------------------ automatic priority

    public function testAnOrderWithAnOutOfStockSizeIsUrgent(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 0, 3); // nothing on hand
        $this->addSize('P-M', 'Pants', 'M', 2, 3);

        $this->placeOrder('/admin/reorder-alerts', ['P-S' => [12, 50], 'P-M' => [8, 50]], ['priority' => 'Planned']);

        $this->assertSame('Urgent', $this->db->table('stock_order')->get()->getRow()->priority, 'From the stock now; the posted value is ignored.');
    }

    public function testAnOrderWithoutAnOutOfStockSizeIsNormalWhateverIsPosted(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 1, 3);
        $this->addSize('P-M', 'Pants', 'M', 2, 3);

        $this->placeOrder('/admin/inventory', ['P-S' => [12, 50], 'P-M' => [8, 50]], ['priority' => 'Urgent']);

        $this->assertSame('Order', $this->db->table('stock_order')->get()->getRow()->priority);
    }

    public function testTheStockAtOrderTimeDecidesNotThePage(): void
    {
        // The page was opened while the size had 0 on hand; a sale return brought it back before ordering.
        $this->addSize('P-1', 'Whistle', null, 0, 3);
        $line = $this->linesOn($this->page('admin/inventory'))['P-1'];
        $this->db->table('products')->where('item_id', 'P-1')->update(['current_stock' => 2]);

        $this->placeOrder('/admin/inventory', ['P-1' => [5, 50]]);

        $this->assertSame([0, 'Order'], [$line['on_hand'], $this->db->table('stock_order')->get()->getRow()->priority]);
        $this->assertSame('Order', \App\Controllers\Admin\StockOrderController::dialogPriority([['current_stock' => 3], ['current_stock' => 1]]));
        $this->assertSame('Urgent', \App\Controllers\Admin\StockOrderController::dialogPriority([['current_stock' => 3], ['current_stock' => 0]]));
    }

    public function testTheOrdersPageKeepsItsManualPriority(): void
    {
        $this->addSize('P-1', 'Whistle', null, 0, 3); // out of stock, but the Orders page form decides
        $this->addSize('P-2', 'Cap', null, 5, 3);

        $this->asOwner()->post('admin/orders/store', ['supplier_id' => 'SUP-001', 'item_id' => ['P-1'], 'quantity' => [5], 'unit_price' => [50], 'priority' => 'Planned', 'return_to' => '/admin/orders']);
        $this->asOwner()->post('admin/orders/store', ['supplier_id' => 'SUP-001', 'item_id' => ['P-2'], 'quantity' => [5], 'unit_price' => [50], 'priority' => 'Urgent', 'return_to' => '/admin/orders']);

        $this->assertSame(['Planned', 'Urgent'], array_column($this->db->table('stock_order')->orderBy('so_id')->get()->getResultArray(), 'priority'));
    }

    public function testTheSupplierSeesTheUrgentBadge(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 0, 3);
        $this->addSize('P-M', 'Pants', 'M', 2, 3);
        $this->placeOrder('/admin/reorder-alerts', ['P-S' => [12, 50]]); // urgent
        $this->placeOrder('/admin/reorder-alerts', ['P-M' => [8, 50]]);  // normal
        [$urgent, $normal] = array_column($this->db->table('stock_order')->orderBy('so_id')->get()->getResultArray(), 'so_id');
        $supplier = $this->db->table('users')->where('username', 'tgi')->get()->getRow();

        $html = (string) $this->withSession(['isLoggedIn' => true, 'role' => 'Supplier', 'user_id' => (int) $supplier->user_id, 'username' => 'tgi', 'full_name' => 'Tactical Gear', 'supplier_id' => 'SUP-001', 'last_activity' => time()])
            ->get('supplier/new-orders')->getBody();

        $this->assertMatchesRegularExpression('/<span class="badge-pill urgent">Urgent<\/span>\s*<span class="fw-bold">#' . $urgent . '<\/span>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<span class="badge-pill urgent">Urgent<\/span>\s*<span class="fw-bold">#' . $normal . '<\/span>/', $html);
        $this->assertStringContainsString('#' . $normal, $html);
    }

    public function testTheDialogHasTheUrgentNoteHiddenUntilItApplies(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3);
        $this->alertFor('P-1');

        $this->assertStringContainsString('<p class="im-help po-urgent-note" id="poUrgentNote" hidden>This order will be marked Urgent for the supplier because an item is out of stock.</p>', $this->dialogOf($this->page('admin/reorder-alerts')));
    }

    // ------------------------------------------------------------ Ordered on both pages

    public function testOrderingFromInventoryStaysOnInventoryAndShowsOrderedOnBothPages(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 1, 3);
        $id = $this->alertFor('P-S');

        $response = $this->placeOrder('/admin/inventory', ['P-S' => [12, 50]], ['return_query' => '?category=Clothes&search=pan&evil=1']);

        $this->assertSame(site_url('admin/inventory?category=Clothes&search=pan'), $response->getRedirectUrl(), 'Back to the same Inventory view.');
        $soId  = $this->db->table('stock_order')->get()->getRow()->so_id;
        $toast = $_SESSION['toast'];
        $this->assertSame(['success', 'Order created', $soId, 'was sent to Tactical Gear Inc.'], [$toast['type'], $toast['title'], $toast['name'], $toast['message']]);
        $this->assertSame(['label' => 'View order', 'url' => site_url('admin/orders/' . $soId)], $toast['action']);
        $this->assertSame(['Ordered', $soId], [$this->db->table('reorder_alert')->where('alert_id', $id)->get()->getRow()->status, $soId]);

        $this->assertOrderedOnBothPages('P-S', $soId);
    }

    public function testOrderingFromReorderAlertsShowsOrderedOnInventory(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 1, 3);
        $id = $this->alertFor('P-S');

        $this->placeOrder('/admin/reorder-alerts', ['P-S' => [12, 50]], ['alert_id' => $id]);

        $this->assertOrderedOnBothPages('P-S', $this->db->table('stock_order')->get()->getRow()->so_id);
    }

    private function assertOrderedOnBothPages(string $itemId, string $soId): void
    {
        $inventory = $this->page('admin/inventory');
        $this->assertStringContainsString('<span class="ordered-tag" title="On order: ' . $soId . '">Ordered</span>', $inventory);
        $this->assertStringContainsString('href="' . site_url('admin/orders/' . $soId) . '">View order</a>', $inventory);
        $this->assertStringNotContainsString('class="inv-action-btn open-order-modal"', $inventory, 'No second Order button.');

        $alerts = $this->page('admin/reorder-alerts');
        preg_match('/<section class="ra-panel" data-panel="ordered".*?<\/section>/s', $alerts, $panel);
        $this->assertStringContainsString('data-item-id="' . $itemId . '"', $panel[0]);
        $this->assertStringContainsString('Order ' . $soId, $panel[0]);
        $this->assertStringContainsString('To order (<span data-tab-count="order">0</span>)', $alerts);
    }

    public function testTheLineBuilderReadsTheReorderAlertsRowsToo(): void
    {
        // Reorder Alerts rows carry the alert's status string in 'status' and the DSS row in 'computation'.
        $line = PurchaseOrderLine::build(
            ['item_id' => 'X', 'item_name' => 'Cap', 'size' => '', 'category' => 'Accessories', 'current_stock' => 0, 'manual_rop_warning' => 4, 'unit_cost' => 20, 'supplier_id' => 'SUP-002', 'status' => 'Active', 'recommended_eoq' => 15, 'computation' => ['safety_stock' => 2, 'lead_time_days' => 3]],
            ['SUP-002' => ['supplier_id' => 'SUP-002', 'company_name' => 'Quiapo Patches Co', 'lead_time_days' => 7]]
        );

        $this->assertSame(['Cap', '', 'Reorder Now', 15, 2, 3, 'Quiapo Patches Co'], [$line['name'], $line['size'], $line['status'], $line['eoq'], $line['safety'], $line['lead'], $line['supplier']]);
    }
}
