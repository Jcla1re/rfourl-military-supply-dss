<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ReorderFixture;

/**
 * Reorder Alerts, Phase 4: ordering from the page (one size or "Order all sizes"
 * as one stock order with a line per size, same supplier only), the return to the
 * Ordered tab, the dismissed-alert fix in StockOrderController::store(), the order
 * dialog's data, and the Ordered tab's cards. Runs on the in-memory 'tests'
 * database; supplier email goes to the fake mailer (see ReorderFixture).
 *
 * @internal
 */
final class ReorderOrderingTest extends CIUnitTestCase
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

    /** What the dialog posts. */
    private function placeOrder(array $lines, array $extra = [])
    {
        return $this->asOwner()->post('admin/orders/store', $extra + [
            'supplier_id'            => 'SUP-001',
            'item_id'                => array_keys($lines),
            'quantity'               => array_values($lines),
            'unit_price'             => array_fill(0, count($lines), 50),
            'priority'               => 'Order',
            'expected_delivery_date' => '2026-12-01',
            'return_to'              => '/admin/reorder-alerts',
        ]);
    }

    private function alertRow(int $id): array
    {
        return $this->db->table('reorder_alert')->where('alert_id', $id)->get()->getRowArray();
    }

    // ------------------------------------------------------------ placing orders

    public function testOrderAllSizesCreatesOneOrderWithALinePerSize(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 0, 3);
        $this->addSize('P-M', 'Pants', 'M', 2, 3);
        $s = $this->alertFor('P-S');
        $m = $this->alertFor('P-M', 'Acknowledged');

        $response = $this->placeOrder(['P-S' => 12, 'P-M' => 8]);

        $this->assertSame(site_url('admin/reorder-alerts?tab=ordered'), $response->getRedirectUrl(), 'Back to the Ordered tab.');
        $orders = $this->db->table('stock_order')->get()->getResultArray();
        $this->assertCount(1, $orders);
        $soId = $orders[0]['so_id'];
        // Urgent: size S has nothing on hand (Phase 4b's automatic priority).
        $this->assertSame(['SUP-001', 'Urgent', '2026-12-01'], [$orders[0]['supplier_id'], $orders[0]['priority'], substr((string) $orders[0]['expected_delivery_date'], 0, 10)]);
        $lines = $this->db->table('so_item')->where('so_id', $soId)->orderBy('item_id')->get()->getResultArray();
        $this->assertSame([['P-M', 8], ['P-S', 12]], array_map(static fn ($l) => [$l['item_id'], (int) $l['order_quantity']], $lines));
        foreach ([$s, $m] as $id) {
            $this->assertSame(['Ordered', $soId], [$this->alertRow($id)['status'], $this->alertRow($id)['so_id']]);
        }

        $toast = $_SESSION['toast'];
        $this->assertSame(['success', 'Order created', $this->db->table('stock_order')->where('so_id', $soId)->get()->getRow()->po_number, 'was sent to Tactical Gear Inc (2 sizes).'], [$toast['type'], $toast['title'], $toast['name'], $toast['message']]);
        $this->assertSame(['label' => 'View order', 'url' => site_url('admin/orders/' . $soId)], $toast['action']);

        $html = (string) $this->asOwner()->get('admin/reorder-alerts?tab=ordered')->getBody();
        $this->assertSame(1, substr_count($html, 'class="ra-item ra-order-card"'), 'One card for the order.');
    }

    public function testSingleSizeOrderToast(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3);
        $id = $this->alertFor('P-1');

        $this->placeOrder(['P-1' => 12], ['alert_id' => $id]);

        $this->assertSame('was sent to Tactical Gear Inc.', $_SESSION['toast']['message']);
        $this->assertSame('Ordered', $this->alertRow($id)['status']);
    }

    public function testOnlyReorderAlertsIsAcceptedAsTheReturnPage(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3);
        $this->alertFor('P-1');

        $response = $this->placeOrder(['P-1' => 5], ['return_to' => 'https://evil.example/admin/reorder-alerts']);

        $this->assertSame(site_url('admin/orders'), $response->getRedirectUrl());
        $this->assertSame(1, $this->db->table('stock_order')->countAllResults(), 'The order is still placed.');
    }

    public function testASizeIsNeverOrderedFromAnotherSupplier(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 0, 3);
        $this->addSize('P-M', 'Pants', 'M', 2, 3, ['supplier_id' => 'SUP-002']);
        $s = $this->alertFor('P-S');
        $this->alertFor('P-M');

        $response = $this->placeOrder(['P-S' => 12, 'P-M' => 8]);

        $this->assertSame(site_url('admin/reorder-alerts'), $response->getRedirectUrl());
        $this->assertSame(['error', "Couldn't create order", 'Pants · Size M comes from another supplier.'], [$_SESSION['toast']['type'], $_SESSION['toast']['title'], $_SESSION['toast']['message']]);
        $this->assertSame(0, $this->db->table('stock_order')->countAllResults());
        $this->assertSame('Active', $this->alertRow($s)['status']);
    }

    public function testADismissedAlertPassedAsAlertIdStaysDismissed(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3);
        $id = $this->alertFor('P-1', 'Dismissed', ['hidden_until_recovered' => 1]);

        $this->placeOrder(['P-1' => 5], ['alert_id' => $id]);

        $this->assertSame(1, $this->db->table('stock_order')->countAllResults());
        $row = $this->alertRow($id);
        $this->assertSame(['Dismissed', null, 1], [$row['status'], $row['so_id'], (int) $row['hidden_until_recovered']]);
    }

    // ------------------------------------------------------------ the dialog

    public function testOrderButtonsCarryTheDialogData(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 0, 3, ['unit_cost' => 125.5]);
        $this->addSize('P-M', 'Pants', 'M', 2, 3);
        $this->alertFor('P-S');
        $this->alertFor('P-M');
        $html = (string) $this->asOwner()->get('admin/reorder-alerts')->getBody();

        preg_match_all('/data-line=(["\'])(.*?)\1/', $html, $m);
        $lines = array_column(array_map(static fn ($a) => json_decode(html_entity_decode($a, ENT_QUOTES), true), $m[2]), null, 'item_id');
        $this->assertSame(
            ['item_id' => 'P-S', 'name' => 'Pants · Size S', 'size' => 'S', 'category' => 'Clothes', 'status' => 'Reorder Now', 'status_class' => 'red', 'on_hand' => 0, 'rop' => 3, 'safety' => 1, 'eoq' => 12, 'cost' => 125.5, 'supplier_id' => 'SUP-001', 'supplier' => 'Tactical Gear Inc', 'lead' => 5],
            $lines['P-S']
        );
        $this->assertMatchesRegularExpression('/<button type="button" class="btn btn-outline-success btn-sm" data-order-all>Order all sizes<\/button>/', $html, 'Order all sizes is live.');

        // The shared purchase order dialog (Phase 4b): no Priority field, a fixed supplier
        // (the dropdown only for sizes without one), back to this page (set when it opens).
        $this->assertStringNotContainsString('name="priority"', $html);
        $this->assertMatchesRegularExpression('/<select name="supplier_id" id="poSupplierPick"[^>]*hidden disabled>/', $html);
        $this->assertStringContainsString('<input type="hidden" name="return_to" id="poReturnTo">', $html);
        $this->assertStringContainsString('<template id="poLineTemplate">', $html);
    }

    // ------------------------------------------------------------ Ordered tab

    public function testOrderedTabCards(): void
    {
        $late   = date('Y-m-d', strtotime('-4 days'));
        $future = date('Y-m-d', strtotime('+6 days'));
        $this->addSize('P-S', 'Pants', 'S', 0, 3);
        $this->addSize('P-M', 'Pants', 'M', 2, 3);
        $this->openOrder('SO-0007', ['P-S', 'P-M'], $future);           // "Order all sizes": one order, two lines
        $this->addSize('P-1', 'Whistle', null, 1, 3);
        $this->alertFor('P-1', 'Ordered', ['so_id' => 'SO-0003']);
        $this->openOrder('SO-0003', ['P-1'], $late);
        $this->db->table('so_item')->where(['so_id' => 'SO-0007', 'item_id' => 'P-M'])->update(['order_quantity' => 8]);

        $html = (string) $this->asOwner()->get('admin/reorder-alerts?tab=ordered')->getBody();
        preg_match('/<section class="ra-panel" data-panel="ordered".*?<\/section>/s', $html, $panel);
        $panel = $panel[0];

        preg_match('/<div class="ra-head ra-grid">(.*?)<\/div>/s', $panel, $head);
        $this->assertSame(['Item', 'Size', 'Qty ordered', 'Expected arrival', 'Action'], array_values(array_filter(array_map('trim', explode("\n", strip_tags($head[1]))))));

        $this->assertSame(2, substr_count($panel, 'class="ra-item ra-order-card"'));
        $this->assertLessThan(strpos($panel, 'Order SO-0007'), strpos($panel, 'Order SO-0003'), 'Late first.');
        $this->assertStringContainsString('<span class="status-pill red ra-late">Late &middot; 4 days</span>', $panel);
        $this->assertSame(2, substr_count($panel, 'Late &middot;'), 'In the column and under the order number for narrow widths.');
        $this->assertStringContainsString('Tactical Gear Inc &middot; ordered Nov 1, 2026', $panel);
        $this->assertStringContainsString('<span class="ra-expected">' . date('M j, Y', strtotime($future)) . '</span>', $panel);
        $this->assertStringContainsString('href="' . site_url('admin/orders/SO-0003') . '" aria-label="View order SO-0003">View order</a>', $panel);

        // The two sizes of SO-0007 in one card, with their own quantities.
        $card = substr($panel, strpos($panel, 'Order SO-0007'));
        $this->assertMatchesRegularExpression('/data-item-id="P-M".*?<div class="ra-c-size">M<\/div>\s*<div class="ra-c-qty ra-num">8<\/div>/s', $card);
        $this->assertMatchesRegularExpression('/data-item-id="P-S".*?<div class="ra-c-size">S<\/div>\s*<div class="ra-c-qty ra-num">12<\/div>/s', $card);
    }
}
