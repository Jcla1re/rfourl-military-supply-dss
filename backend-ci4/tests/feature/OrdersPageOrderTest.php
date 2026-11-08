<?php

use App\Controllers\Admin\StockOrderController;
use App\Models\StockOrderModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ReorderFixture;

/**
 * The Orders page: "Add New Order" and "Reorder" open the shared purchase order dialog in
 * "empty start" mode (supplier first, then its items, a Priority control, no tracking number),
 * store() checks those orders, the one set of priority labels, the Follow up button (text by
 * due date, once per order per day) and the supplier's Planned pill. Runs on the in-memory
 * 'tests' database; email goes to a recording fake mailer, nobody is emailed.
 *
 * @internal
 */
final class OrdersPageOrderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ReorderFixture;

    protected $migrate = false;
    protected $refresh = false;

    private object $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReorderFixture();

        $this->mailer = new class () {
            public array $sent = [];
            private array $current = [];

            public function __call(string $name, array $args)
            {
                if ($name === 'send') {
                    $this->sent[]  = $this->current;
                    $this->current = [];

                    return true;
                }
                $this->current[$name] = $args[0] ?? null;

                return $this;
            }
        };
        Services::injectMock('email', $this->mailer);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        $this->tearDownReorderFixture();
        parent::tearDown();
    }

    /** What the Orders page dialog posts. */
    private function placeOrder(array $lines, array $extra = [])
    {
        return $this->asOwner()->post('admin/orders/store', $extra + [
            'supplier_id'            => 'SUP-001',
            'item_id'                => array_keys($lines),
            'quantity'               => array_column($lines, 0),
            'unit_price'             => array_column($lines, 1),
            'priority'               => 'Order',
            'expected_delivery_date' => '2026-12-01',
            'return_to'              => '/admin/orders',
        ]);
    }

    private function orders(): array
    {
        return $this->db->table('stock_order')->orderBy('so_id')->get()->getResultArray();
    }

    private function assertRefused($response, string $why): void
    {
        $this->assertSame(site_url('admin/orders'), $response->getRedirectUrl());
        $this->assertSame(['error', "Couldn't create order", $why], [$_SESSION['toast']['type'], $_SESSION['toast']['title'], $_SESSION['toast']['message']]);
        $this->assertSame([], $this->orders(), 'Nothing is saved.');
    }

    private function catalogOn(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" id="orderCatalog">(.*?)</script>#s', $html, $m), 'The page has the item list.');

        return json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
    }

    // ------------------------------------------------------------ the page and its dialog

    public function testTheOrdersPageUsesTheSharedDialogInEmptyStart(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 0, 3);
        $this->addSize('P-M', 'Pants', 'M', 9, 3);
        $this->addSize('C-1', 'Patch', null, 4, 2, ['supplier_id' => 'SUP-002']);
        $this->addSize('OLD', 'Old Cap', null, 4, 2, ['is_active' => 0]);
        $this->addSize('NOS', 'Whistle', null, 4, 2, ['supplier_id' => null]);

        $html = (string) $this->asOwner()->get('admin/orders')->getBody();

        $this->assertStringContainsString('<div class="inv-modal po-modal" id="poModal" data-po-dialog>', $html);
        $this->assertStringContainsString("mode: 'empty', showPriority: true, returnTo: '/admin/orders'", $html);
        $this->assertStringContainsString('/assets/css/item-modal.css', $html);
        foreach (['id="itemModal"', 'id="itemPickerModal"', 'New Stock Order', 'name="tracking_no"', 'Tracking Number (optional)', 'productGroups'] as $old) {
            $this->assertStringNotContainsString($old, $html, "The old dialog is gone: {$old}");
        }
        $this->assertMatchesRegularExpression('/<option value="SUP-001" data-lead="7">Tactical Gear Inc &middot; 7-day lead time<\/option>/', $html);
        $this->assertStringContainsString("One order = one supplier. The item list below shows only this supplier's items.", $html);
        $this->assertMatchesRegularExpression('/data-priority="Urgent">Urgent<\/button>.*data-priority="Order">Normal<\/button>.*data-priority="Planned">Planned<\/button>/s', $html);

        // Grouped by supplier: active items only, each built like on Inventory.
        $catalog = $this->catalogOn($html);
        $this->assertSame(['SUP-001', 'SUP-002'], array_keys($catalog));
        $this->assertSame(['P-S', 'P-M'], array_column($catalog['SUP-001'], 'item_id'));
        $this->assertSame(['C-1'], array_column($catalog['SUP-002'], 'item_id'));
        $this->assertSame(['item_id' => 'P-S', 'name' => 'Pants · Size S', 'size' => 'S', 'category' => 'Clothes', 'status' => 'Reorder Now', 'status_class' => 'red', 'on_hand' => 0, 'rop' => 3, 'safety' => 1, 'eoq' => 12, 'cost' => 50, 'supplier_id' => 'SUP-001', 'supplier' => 'Tactical Gear Inc', 'lead' => 5], $catalog['SUP-001'][0]);
    }

    public function testInventoryAndReorderAlertsStillHaveNoPriorityField(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3);
        $this->alertFor('P-1');

        foreach (['admin/inventory', 'admin/reorder-alerts'] as $url) {
            $html = (string) $this->asOwner()->get($url)->getBody();
            $this->assertStringNotContainsString('name="priority"', $html, $url);
            $this->assertMatchesRegularExpression('/<div class="im-field po-priority-field" id="poPriorityField" hidden>/', $html, 'Hidden unless the Orders page asks for it.');
            $this->assertMatchesRegularExpression('/<section class="im-section" id="poChooseSection" hidden>/', $html);
        }
    }

    // ------------------------------------------------------------ store() from the Orders page

    public function testAValidOrderIsSavedWithTheChosenPriority(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 0, 3);
        $this->addSize('P-M', 'Pants', 'M', 9, 3);
        $this->alertFor('P-S');

        $response = $this->placeOrder(['P-S' => [12, 55.5], 'P-M' => [3, 50]], ['priority' => 'Planned']);

        $this->assertSame(site_url('admin/orders'), $response->getRedirectUrl());
        $toast = $_SESSION['toast'];
        $this->assertSame(['success', 'Order created', 'SO-0001', 'was sent to Tactical Gear Inc.'], [$toast['type'], $toast['title'], $toast['name'], $toast['message']]);
        $this->assertSame(['label' => 'View order', 'url' => site_url('admin/orders/SO-0001')], $toast['action']);

        $order = $this->orders()[0];
        $this->assertSame(['SUP-001', 'Planned', 'Order Confirmed', null], [$order['supplier_id'], $order['priority'], $order['status'], $order['tracking_no']], "The owner's choice is kept, even with an item out of stock.");
        $this->assertSame([['P-S', 12, 55.5], ['P-M', 3, 50.0]], array_map(static fn ($r) => [$r['item_id'], (int) $r['order_quantity'], (float) $r['unit_price']], $this->db->table('so_item')->orderBy('so_item_id')->get()->getResultArray()));
        $this->assertSame('Ordered', $this->db->table('reorder_alert')->get()->getRow()->status);
    }

    public function testASupplierIsRequired(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3);
        $this->assertRefused($this->placeOrder(['P-1' => [5, 50]], ['supplier_id' => '']), 'Please select a supplier.');
    }

    public function testAtLeastOneLineIsRequired(): void
    {
        $this->assertRefused($this->placeOrder([]), 'Add at least 1 item.');
    }

    public function testEveryQuantityMustBeOneOrMore(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3);
        $this->addSize('P-2', 'Cap', null, 1, 3);
        $this->assertRefused($this->placeOrder(['P-1' => [5, 50], 'P-2' => [0, 50]]), 'Enter a quantity of 1 or more for every item.');
    }

    public function testEveryUnitCostMustBeAboveZero(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3);
        $this->assertRefused($this->placeOrder(['P-1' => [5, 0]]), 'Enter a unit cost above ₱0 for every item.');
    }

    public function testAnItemFromAnotherSupplierIsRefused(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3);
        $this->addSize('C-1', 'Patch', null, 1, 3, ['supplier_id' => 'SUP-002']);
        $this->assertRefused($this->placeOrder(['P-1' => [5, 50], 'C-1' => [5, 50]]), "Patch isn't from this supplier.");
    }

    public function testAnArchivedItemIsRefused(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3, ['is_active' => 0]);
        $this->assertRefused($this->placeOrder(['P-1' => [5, 50]]), 'Whistle is archived. Remove it from the order.');
    }

    public function testAnInvalidPriorityFallsBackToNormal(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3);
        $this->placeOrder(['P-1' => [5, 50]], ['priority' => 'Critical']);
        $this->assertSame('Order', $this->orders()[0]['priority']);
    }

    public function testAPostedTrackingNumberIsNotSaved(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3);
        $this->addSize('P-2', 'Cap', null, 1, 3);
        $this->placeOrder(['P-1' => [5, 50]], ['tracking_no' => 'TRK-2026-0001']);
        // The older form without return_to, too.
        $this->asOwner()->post('admin/orders/store', ['supplier_id' => 'SUP-001', 'item_id' => ['P-2'], 'quantity' => [5], 'unit_price' => [50], 'tracking_no' => 'TRK-2026-0002']);

        $this->assertSame([null, null], array_column($this->orders(), 'tracking_no'));
    }

    public function testInventoryAndReorderAlertsStillIgnoreAPostedPriority(): void
    {
        $this->addSize('P-1', 'Whistle', null, 0, 3); // nothing on hand
        $this->addSize('P-2', 'Cap', null, 5, 3);

        $this->placeOrder(['P-1' => [5, 50]], ['return_to' => '/admin/inventory', 'priority' => 'Planned']);
        $this->placeOrder(['P-2' => [5, 50]], ['return_to' => '/admin/reorder-alerts', 'priority' => 'Urgent']);

        $this->assertSame(['Urgent', 'Order'], array_column($this->orders(), 'priority'));
    }

    // ------------------------------------------------------------ Reorder from a cancelled order

    public function testReorderOpensTheDialogWithTheOrderAndSaysWhatWasLeftOut(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 0, 3);
        $this->addSize('OLD', 'Old Cap', null, 4, 2);
        $this->addSize('MOV', 'Patch', null, 4, 2);
        $this->openOrder('SO-0001', ['P-S', 'OLD', 'MOV'], '2026-11-20', 'Cancelled');
        $this->db->table('stock_order')->update(['priority' => 'Planned'], ['so_id' => 'SO-0001']);
        $this->db->table('so_item')->update(['order_quantity' => 7, 'unit_price' => 61.25], ['item_id' => 'P-S']);
        $this->db->table('products')->update(['is_active' => 0], ['item_id' => 'OLD']);
        $this->db->table('products')->update(['supplier_id' => 'SUP-002'], ['item_id' => 'MOV']);

        $html = (string) $this->asOwner()->get('admin/orders?reorder=SO-0001')->getBody();

        $this->assertSame(1, preg_match('/const reorderPrefill = (\{.*?\});\r?\n/', $html, $m));
        $prefill = json_decode($m[1], true);
        $this->assertSame(['SO-0001', 'SUP-001', 'Planned'], [$prefill['from'], $prefill['supplier_id'], $prefill['priority']]);
        $this->assertSame([['P-S', 7, 61.25, 0, 12]], array_map(static fn ($l) => [$l['item_id'], $l['qty'], $l['price'], $l['on_hand'], $l['eoq']], $prefill['lines']), 'Original quantity and price, current numbers.');
        $this->assertSame('Left out of this reorder: Old Cap (archived), Patch (now from another supplier).', $prefill['warning']);
        $this->assertSame(1, $this->db->table('stock_order')->countAllResults(), 'Nothing is created.');
    }

    // ------------------------------------------------------------ one set of priority labels

    public function testPriorityLabel(): void
    {
        $this->assertSame(['Urgent', 'Normal', 'Planned', 'Normal', 'Normal'], array_map([StockOrderModel::class, 'priorityLabel'], ['Urgent', 'Order', 'Planned', null, '']));
    }

    public function testTheOrdersListAndTheOrderDetailSayNormal(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3);
        $this->openOrder('SO-0001', ['P-1'], '2026-12-01');
        $this->db->table('stock_order')->update(['priority' => 'Order'], ['so_id' => 'SO-0001']);

        $list = (string) $this->asOwner()->get('admin/orders')->getBody();
        $this->assertStringContainsString('<span class="priority-pill">Normal</span>', $list);
        $this->assertStringNotContainsString('Upcoming', $list);

        $detail = (string) $this->asOwner()->get('admin/orders/SO-0001')->getBody();
        $this->assertMatchesRegularExpression('/<span>Priority<\/span>\s*<strong>Normal<\/strong>/', $detail);
    }

    // ------------------------------------------------------------ Follow up

    private function followUp(string $soId)
    {
        return $this->asOwner()->post('admin/orders/flag-delayed/' . $soId);
    }

    private function supplierNotes(): array
    {
        return $this->db->table('notifications')->where('recipient_role', 'Supplier')->get()->getResultArray();
    }

    public function testFollowUpBeforeTheDueDateAsksForAnUpdate(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3);
        $due = date('Y-m-d', strtotime('+4 days'));
        $this->openOrder('SO-0001', ['P-1'], $due);

        $this->followUp('SO-0001');

        $text = 'RfourL is asking for an update on SO-0001 (due ' . date('M j', strtotime($due)) . ').';
        $note = $this->supplierNotes()[0];
        $this->assertSame(['Follow-up on SO-0001', $text], [$note['title'], $note['message']]);
        $this->assertSame([['setTo' => 'supplier@example.test', 'setSubject' => 'Follow-up on SO-0001', 'setMessage' => $text]], $this->mailer->sent, 'The same text by email.');
        $this->assertSame(['success', 'Follow-up sent to Tactical Gear Inc.'], [$_SESSION['toast']['type'], $_SESSION['toast']['title']]);
        $this->assertSame('Order Confirmed', $this->orders()[0]['status'], 'Nothing on the order changes.');
    }

    public function testFollowUpAfterTheDueDateSaysItHasNotArrived(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3);
        $due = date('Y-m-d', strtotime('-2 days'));
        $this->openOrder('SO-0001', ['P-1'], $due);

        $this->followUp('SO-0001');

        $text = 'SO-0001 was due ' . date('M j', strtotime($due)) . " and hasn't arrived. Please give an update.";
        $this->assertSame($text, $this->supplierNotes()[0]['message']);
        $this->assertSame($text, $this->mailer->sent[0]['setMessage'], 'The same text by email.');
    }

    public function testFollowUpTextOnTheDueDateItselfStillAsks(): void
    {
        $order = ['so_id' => 'SO-0007', 'expected_delivery_date' => '2026-11-09'];
        $this->assertSame('RfourL is asking for an update on SO-0007 (due Nov 9).', StockOrderController::followUpMessage($order, '2026-11-09'));
        $this->assertSame("SO-0007 was due Nov 9 and hasn't arrived. Please give an update.", StockOrderController::followUpMessage($order, '2026-11-10'));
        $this->assertSame('RfourL is asking for an update on SO-0007.', StockOrderController::followUpMessage(['so_id' => 'SO-0007'], '2026-11-10'));
    }

    public function testASecondFollowUpOnTheSameDaySendsNothing(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3);
        $this->openOrder('SO-0001', ['P-1'], date('Y-m-d', strtotime('-1 day')));
        // Yesterday's follow-up doesn't count.
        $this->db->table('notifications')->insert(['recipient_role' => 'Supplier', 'type' => 'Order Status', 'title' => 'Follow-up on SO-0001', 'created_at' => date('Y-m-d 23:59:00', strtotime('-1 day'))]);

        $this->followUp('SO-0001');
        $this->followUp('SO-0001');

        $this->assertCount(2, $this->supplierNotes(), "Yesterday's and today's: the second click today added nothing.");
        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame(['info', 'You already followed up on SO-0001 today.'], [$_SESSION['toast']['type'], $_SESSION['toast']['title']]);

        $html = (string) $this->asOwner()->get('admin/orders')->getBody();
        $this->assertMatchesRegularExpression('/<button type="submit" disabled aria-describedby="followed-SO-0001">Follow up<\/button>\s*<span class="ord-followed" id="followed-SO-0001">Followed up today<\/span>/', $html);
    }

    public function testTheCardSaysFollowUpAndShowsOverdueWhenLate(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3);
        $this->openOrder('SO-0001', ['P-1'], date('Y-m-d', strtotime('-1 day')));
        $this->openOrder('SO-0002', ['P-1'], date('Y-m-d', strtotime('+3 days')));

        $html = (string) $this->asOwner()->get('admin/orders')->getBody();

        $this->assertSame(2, substr_count($html, '>Follow up</button>'));
        $this->assertStringNotContainsString('>Delayed</button>', $html);
        $this->assertSame(1, substr_count($html, '<span class="priority-pill overdue">Overdue</span>'));
        $this->assertStringNotContainsString('Followed up today', $html);
    }

    // ------------------------------------------------------------ the supplier's Planned pill

    public function testTheSupplierSeesAPlannedPill(): void
    {
        $this->addSize('P-1', 'Whistle', null, 5, 3);
        foreach (['SO-0001' => 'Planned', 'SO-0002' => 'Order', 'SO-0003' => 'Urgent'] as $id => $priority) {
            $this->openOrder($id, ['P-1'], '2026-12-01');
            $this->db->table('stock_order')->update(['priority' => $priority], ['so_id' => $id]);
        }
        $supplier = $this->db->table('users')->where('username', 'tgi')->get()->getRow();
        $html = (string) $this->withSession(['isLoggedIn' => true, 'role' => 'Supplier', 'user_id' => (int) $supplier->user_id, 'username' => 'tgi', 'full_name' => 'Tactical Gear', 'supplier_id' => 'SUP-001', 'last_activity' => time()])
            ->get('supplier/new-orders')->getBody();

        $this->assertMatchesRegularExpression('/<span class="badge-pill planned">Planned<\/span>\s*<span class="fw-bold">#SO-0001<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<div class="no-card-head">\s*<span class="fw-bold">#SO-0002<\/span>/', $html, 'Normal: no pill.');
        $this->assertMatchesRegularExpression('/<span class="badge-pill urgent">Urgent<\/span>\s*<span class="fw-bold">#SO-0003<\/span>/', $html);
        $this->assertStringContainsString('<strong>Priority:</strong> Normal', $html);

        $dashboard = (string) $this->withSession(['isLoggedIn' => true, 'role' => 'Supplier', 'user_id' => (int) $supplier->user_id, 'username' => 'tgi', 'full_name' => 'Tactical Gear', 'supplier_id' => 'SUP-001', 'last_activity' => time()])
            ->get('supplier/dashboard')->getBody();
        $this->assertStringContainsString('Order &mdash; #SO-0001 <span class="prio-pill">Planned</span></div>', $dashboard);
        $this->assertStringContainsString('Order &mdash; #SO-0002</div>', $dashboard, 'Normal: no pill.');
    }
}
