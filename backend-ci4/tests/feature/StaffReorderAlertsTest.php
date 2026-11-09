<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ReorderFixture;

/**
 * Staff Reorder Alerts: the Owner's board (App\Libraries\ReorderBoard,
 * partials/_reorder_board.php) with Notify Owner as Staff's only action. Same numbers as
 * the Owner page; no Order, Dismiss, Restore, View order or Ordering Report; the Owner's
 * routes stay closed to Staff. Runs on the in-memory 'tests' database.
 *
 * @internal
 */
final class StaffReorderAlertsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ReorderFixture;

    protected $migrate = false;
    protected $refresh = false;

    private const PREVIOUS_LOGIN = '2026-11-01 12:00:00';

    private int $orderAlert;
    private int $dismissedAlert;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReorderFixture();
        Services::resetSingle('renderer');

        // To order: two sizes of one item (one out of stock, raised after the previous login) and a one-size item.
        $this->addSize('P-S', 'Tactical Pants', 'S', 0, 3);
        $this->addSize('P-M', 'Tactical Pants', 'M', 2, 3);
        $this->addSize('W-1', 'Whistle', null, 1, 4, ['supplier_id' => 'SUP-002']);
        $this->orderAlert = $this->alertFor('P-S', 'Active', ['alert_timestamp' => '2026-11-05 09:00:00']);
        $this->alertFor('P-M');
        $this->alertFor('W-1');
        // Ordered: one size on an open order.
        $this->addSize('B-9', 'Combat Boots', '9', 1, 2, ['category' => 'Shoes']);
        $this->openOrder('SO-0001', ['B-9'], date('Y-m-d', strtotime('+3 days')));
        $this->alertFor('B-9', 'Ordered', ['so_id' => 'SO-0001']);
        // Dismissed by the Owner.
        $this->addSize('C-1', 'Patrol Cap', null, 1, 2, ['category' => 'Accessories']);
        $this->dismissedAlert = $this->alertFor('C-1', 'Dismissed', ['hidden_until_recovered' => 1]);
    }

    protected function tearDown(): void
    {
        $this->tearDownReorderFixture();
        parent::tearDown();
    }

    private function staffPage(string $query = ''): string
    {
        Services::resetSingle('renderer');

        return (string) $this->withSession(['isLoggedIn' => true, 'role' => 'Staff', 'user_id' => $this->staffId, 'username' => 'staff1', 'full_name' => 'Ana Cruz', 'previous_login' => self::PREVIOUS_LOGIN, 'last_activity' => time()])
            ->get('staff/reorder-alerts' . $query)->getBody();
    }

    private function ownerPage(): string
    {
        Services::resetSingle('renderer');

        return (string) $this->asOwner(['previous_login' => self::PREVIOUS_LOGIN])->get('admin/reorder-alerts')->getBody();
    }

    /** Headline, tab and chip numbers as the page shows them. */
    private function numbers(string $html): array
    {
        $this->assertSame(1, preg_match('#Reorder Now: <span class="text-nowrap">([^<]+)</span> &middot; <span class="text-nowrap">([^<]+)</span>#', $html, $head));
        preg_match_all('/data-tab-count="(\w+)">(\d+)</', $html, $tabs, PREG_SET_ORDER);
        preg_match_all('/data-chip-count="(\w+)">(\d+)</', $html, $chips, PREG_SET_ORDER);

        return [
            'headline' => [$head[1], $head[2]],
            'tabs'     => array_column($tabs, 2, 1),
            'chips'    => array_column($chips, 2, 1),
        ];
    }

    public function testStaffAndOwnerShowTheSameNumbers(): void
    {
        $staff = $this->numbers($this->staffPage());
        $owner = $this->numbers($this->ownerPage());

        $this->assertSame($owner, $staff);
        $this->assertSame(['headline' => ['4 items', '5 sizes'], 'tabs' => ['order' => '3', 'ordered' => '1', 'dismissed' => '1'], 'chips' => ['out' => '1', 'new' => '1']], $staff);
    }

    public function testStaffSeesTheOwnersLayoutButNoneOfTheOwnersActions(): void
    {
        $html = $this->staffPage();

        $this->assertMatchesRegularExpression('/placeholder="Search by item, ID or supplier(…|&hellip;)"/', $html);
        foreach (['<div class="page-wrap ra-page" id="raBoard"', 'All categories', 'All suppliers',
                  'New since Nov 1, 12:00 PM', '/assets/css/reorder-alerts.css', '/assets/js/reorder-alerts.js', 'Reorder levels'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        foreach (['open-order-modal', 'data-order-all', 'reorder-alerts/dismiss', 'reorder-alerts/restore', '>Restore</button>', 'View order',
                  'Ordering Report', 'procurement-report', 'admin/orders', 'id="poModal"', 'purchase-order-dialog.js'] as $owner) {
            $this->assertStringNotContainsString($owner, $html, "Staff must not see: {$owner}");
        }
        // The old Staff page's parts are gone.
        foreach (['At 0 units', 'Received This Week', 'need action', 'What do these numbers mean?', 'How this was worked out', 'dismissed (hidden)', 'ra-stat-box'] as $old) {
            $this->assertStringNotContainsString($old, $html, "Removed: {$old}");
        }
        // Tabs link to the Staff page.
        $this->assertStringContainsString('href="' . site_url('staff/reorder-alerts') . '?tab=dismissed"', $html);
    }

    public function testNotifyOwnerIsTheStaffActionOnToOrderOnly(): void
    {
        $html = $this->staffPage();

        $notify = '<button type="submit" class="btn btn-success btn-sm notify-btn" aria-label="Notify Owner: Whistle">Notify Owner</button>';
        $this->assertStringContainsString('action="' . site_url('staff/reorder-alerts/notify/' . $this->orderAlert) . '"', $html);
        $this->assertStringContainsString($notify, $html);
        $this->assertSame(3, substr_count($html, '>Notify Owner</button>'), 'One per size on To order (3); none on Ordered or Dismissed.');
    }

    public function testTheDismissedTabIsViewOnlyForStaff(): void
    {
        $html = $this->staffPage('?tab=dismissed');
        $this->assertSame(1, preg_match('#<section class="ra-panel" data-panel="dismissed".*?</section>#s', $html, $panel));

        $this->assertStringContainsString('Patrol Cap', $panel[0]);
        $this->assertMatchesRegularExpression("/Dismissed alerts stay here until the item(&#039;|')s stock is above Reorder at again\\./", $panel[0]);
        $this->assertStringNotContainsString('Notify Owner', $panel[0]);
        $this->assertStringNotContainsString('Owner notified', $panel[0]);
        $this->assertStringNotContainsString('staff/reorder-alerts/notify/', $panel[0]);
        $this->assertStringContainsString('<span class="ra-c-action">Action</span>', $panel[0]);
        $this->assertMatchesRegularExpression('#<div class="ra-c-action ra-actions">\s*<span class="notify-dismissed">Dismissed by owner</span>\s*</div>#', $panel[0], 'Said the same way as on Staff Inventory.');
    }

    public function testTheOrderedTabShowsTheOrdersStatusToStaff(): void
    {
        $this->db->table('stock_order')->update(['status' => 'Preparing'], ['so_id' => 'SO-0001']);

        $staff = $this->staffPage('?tab=ordered');
        $this->assertSame(1, preg_match('#<section class="ra-panel" data-panel="ordered".*?</section>#s', $staff, $panel));
        $this->assertStringContainsString('<span class="ra-c-action">Status</span>', $panel[0]);
        $this->assertStringContainsString('<span class="ra-order-status">Preparing</span>', $panel[0], 'The real status, read-only.');
        $this->assertStringNotContainsString('<a ', $panel[0]);

        // The Owner's card is unchanged: View order, no status pill.
        $owner = $this->ownerPage();
        $this->assertStringContainsString('aria-label="View order SO-0001">View order</a>', $owner);
        $this->assertStringNotContainsString('ra-order-status', $owner);
    }

    public function testStaffInventoryShowsDismissedByOwnerInsteadOfNotifyOwner(): void
    {
        $html = (string) $this->withSession(['isLoggedIn' => true, 'role' => 'Staff', 'user_id' => $this->staffId, 'username' => 'staff1', 'full_name' => 'Ana Cruz', 'last_activity' => time()])
            ->get('staff/inventory?search=Patrol')->getBody();
        $this->assertSame(1, preg_match('#<td class="inv-notify-cell col-actions">.*?</td>#s', $html, $cell), 'The Patrol Cap row.');

        $this->assertStringContainsString('<span class="notify-dismissed" style="">Dismissed by owner</span>', $cell[0]);
        $this->assertMatchesRegularExpression('#class="notify-form" style="display:none;"#', $cell[0], 'No Notify Owner.');
        $this->assertMatchesRegularExpression('/(&quot;|")dismissed(&quot;|"):true/', $html, 'The size picker knows it too.');

        // A size still waiting to be ordered keeps its Notify Owner.
        $whistle = (string) $this->withSession(['isLoggedIn' => true, 'role' => 'Staff', 'user_id' => $this->staffId, 'username' => 'staff1', 'full_name' => 'Ana Cruz', 'last_activity' => time()])
            ->get('staff/inventory?search=Whistle')->getBody();
        $this->assertStringContainsString('<span class="notify-dismissed" style="display:none;">Dismissed by owner</span>', $whistle);
        $this->assertMatchesRegularExpression('#class="notify-form" style="">#', $whistle);
    }

    public function testEveryNotifyRouteRefusesADismissedSize(): void
    {
        $session = ['isLoggedIn' => true, 'role' => 'Staff', 'user_id' => $this->staffId, 'username' => 'staff1', 'full_name' => 'Ana Cruz', 'last_activity' => time()];

        foreach ([
            'reorder-alerts' => fn () => $this->withSession($session)->post('staff/reorder-alerts/notify/' . $this->dismissedAlert),
            'inventory'      => fn () => $this->withSession($session)->post('staff/inventory/notify/C-1'),
            'dashboard'      => fn () => $this->withSession($session)->post('staff/dashboard/notify', ['item_id' => 'C-1']),
        ] as $page => $press) {
            $press();
            $this->assertSame(['info', 'No notice sent', 'The owner dismissed this alert, so no notice was sent.'], [$_SESSION['toast']['type'] ?? null, $_SESSION['toast']['title'] ?? null, $_SESSION['toast']['message'] ?? null], $page);
        }
        $this->assertSame(0, $this->db->table('reorder_notices')->countAllResults(), 'No notice created.');
        $this->assertSame(0, $this->db->table('notifications')->where('type', 'Reorder Point Notice')->countAllResults());
    }

    public function testNotifyOwnerTurnsIntoOwnerNotifiedAndOnlyNotifiesOnce(): void
    {
        $session = ['isLoggedIn' => true, 'role' => 'Staff', 'user_id' => $this->staffId, 'username' => 'staff1', 'full_name' => 'Ana Cruz', 'last_activity' => time()];

        $response = $this->withSession($session)->post('staff/reorder-alerts/notify/' . $this->orderAlert);
        $this->assertSame(site_url('staff/reorder-alerts'), $response->getRedirectUrl());
        $this->assertNotEmpty($_SESSION['success'] ?? null, 'The existing success message.');
        // The notice the Owner gets (ReorderNoticeModel::notifyOwner); loading the page can add other Owner notifications.
        $adminNotes = fn () => $this->db->table('notifications')->where('recipient_role', 'Admin')->where('type', 'Reorder Point Notice')->like('title', 'Tactical Pants')->countAllResults();
        $this->assertSame(1, $adminNotes());

        $html = $this->staffPage();
        $this->assertMatchesRegularExpression('#<button type="button" class="btn btn-success btn-sm notify-btn" disabled>Owner notified</button>\s*<span class="ra-notified-by">Notified by Ana Cruz#', $html);
        $this->assertStringNotContainsString('staff/reorder-alerts/notify/' . $this->orderAlert . '"', $html);

        // A second notify for the same size sends nothing.
        $this->withSession($session)->post('staff/reorder-alerts/notify/' . $this->orderAlert);
        $this->assertSame(1, $adminNotes());
        $this->assertSame(1, $this->db->table('reorder_notices')->where('item_id', 'P-S')->countAllResults());
    }

    public function testTheOrderedTabHasTheOwnersCardsWithoutViewOrder(): void
    {
        $html = $this->staffPage('?tab=ordered');

        $this->assertMatchesRegularExpression('#<span class="ra-name">Order SO-0001</span>#', $html);
        $this->assertStringNotContainsString('View order', $html);
        $this->assertStringContainsString('<section class="ra-panel" data-panel="ordered" role="tabpanel">', $html, 'Opens on Ordered.');
    }

    public function testStaffStillCantUseTheOwnersRoutes(): void
    {
        $session = ['isLoggedIn' => true, 'role' => 'Staff', 'user_id' => $this->staffId, 'username' => 'staff1', 'full_name' => 'Ana Cruz', 'last_activity' => time()];

        $this->withSession($session)->post('admin/reorder-alerts/dismiss/' . $this->orderAlert);
        $this->withSession($session)->post('admin/reorder-alerts/restore/' . $this->dismissedAlert);

        $status = fn (int $id) => $this->db->table('reorder_alert')->where('alert_id', $id)->get()->getRow()->status;
        $this->assertSame('Active', $status($this->orderAlert));
        $this->assertSame('Dismissed', $status($this->dismissedAlert));
        $this->assertSame(1, $this->db->table('stock_order')->countAllResults(), 'No order created.');
    }
}
