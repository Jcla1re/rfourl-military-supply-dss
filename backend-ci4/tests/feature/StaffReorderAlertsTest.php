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

    public function testNotifyOwnerIsTheStaffActionOnToOrderAndDismissed(): void
    {
        $html = $this->staffPage();

        $notify = '<button type="submit" class="btn btn-success btn-sm notify-btn" aria-label="Notify Owner: Patrol Cap">Notify Owner</button>';
        $this->assertStringContainsString('action="' . site_url('staff/reorder-alerts/notify/' . $this->orderAlert) . '"', $html);
        $this->assertStringContainsString('action="' . site_url('staff/reorder-alerts/notify/' . $this->dismissedAlert) . '"', $html, 'Dismissed is view-only, but Notify Owner still works there.');
        $this->assertStringContainsString($notify, $html);
        $this->assertSame(4, substr_count($html, '>Notify Owner</button>'), 'One per size on To order (3) and Dismissed (1); none on Ordered.');
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
