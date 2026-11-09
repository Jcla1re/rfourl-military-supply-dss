<?php

use App\Libraries\NotificationIcon;
use App\Libraries\StaffPasswordAlert;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ReorderFixture;

/**
 * The Notifications page of the Owner, Staff and Supplier: one stylesheet and toolbar, the
 * shared icon rule (App\Libraries\NotificationIcon), the NEW pill on every kind of card, the
 * Owner's cards showing the time, and the toolbar's disabled states. Runs on the in-memory
 * 'tests' database.
 *
 * @internal
 */
final class NotificationsPageTest extends CIUnitTestCase
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
        // A fresh view renderer, as in a real request: the shared one keeps view data (such as
        // another Supplier page's 'subtitle') from tests that ran before.
        \Config\Services::resetSingle('renderer');
    }

    protected function tearDown(): void
    {
        $this->tearDownReorderFixture();
        parent::tearDown();
    }

    private function notify(string $role, array $fields): int
    {
        $this->db->table('notifications')->insert($fields + [
            'recipient_role' => $role, 'type' => 'Order Status', 'category' => 'order_status', 'title' => 'Note',
            'message' => 'Something happened.', 'is_read' => 0, 'created_at' => date('Y-m-d 14:05:00'),
        ]);

        return (int) $this->db->insertID();
    }

    private function asSupplier(): self
    {
        $u = $this->db->table('users')->where('username', 'tgi')->get()->getRow();

        return $this->withSession(['isLoggedIn' => true, 'role' => 'Supplier', 'user_id' => (int) $u->user_id, 'username' => 'tgi', 'full_name' => 'Tactical Gear', 'supplier_id' => 'SUP-001', 'last_activity' => time()]);
    }

    private function page(string $role): string
    {
        return (string) match ($role) {
            'Admin'    => $this->asOwner()->get('admin/notifications')->getBody(),
            'Staff'    => $this->asStaff()->get('staff/notifications')->getBody(),
            'Supplier' => $this->asSupplier()->get('supplier/notifications')->getBody(),
        };
    }

    // ------------------------------------------------------------ the shared icon rule

    public function testTheIconHelper(): void
    {
        $cases = [
            [['type' => 'Damaged Item', 'category' => 'staff_activity'], 'bi-exclamation', 'red'],
            [['type' => 'Lost stock', 'category' => 'order_status'], 'bi-exclamation', 'red'],
            [['type' => 'Access Request', 'category' => 'access_request'], 'bi-exclamation', 'amber'],
            [['type' => 'Security', 'category' => 'security'], 'bi-shield-exclamation', 'amber'],
            [['type' => 'Order Status', 'category' => 'order_status'], 'bi-clipboard-check', 'green'],
            [['type' => 'Sales Record', 'category' => 'sales_records'], 'bi-archive', 'green'],
            [['type' => 'Reorder Point Notice', 'category' => 'staff_activity'], 'bi-bell', 'green'],
            [['type' => 'Anything', 'category' => null], 'bi-bell', 'green'],
        ];
        foreach ($cases as [$n, $icon, $color]) {
            $this->assertSame(['icon' => $icon, 'color' => $color], NotificationIcon::for($n), json_encode($n));
        }
    }

    public function testStaffAndSupplierGetTheOwnersIcons(): void
    {
        $this->notify('Staff', ['title' => 'Order placed', 'link_url' => '/staff/reorder-alerts']);
        $this->notify('Supplier', ['title' => 'Damaged on arrival', 'type' => 'Damaged Item', 'category' => 'staff_activity']);

        $this->assertStringContainsString('<i class="bi bi-clipboard-check"></i>', $this->page('Staff'));
        $supplier = $this->page('Supplier');
        $this->assertStringContainsString('<div class="nt-card red is-unread">', $supplier);
        $this->assertStringContainsString('<i class="bi bi-exclamation"></i>', $supplier);
    }

    // ------------------------------------------------------------ the NEW pill on every card

    /** @dataProvider rolesWithLinkCards */
    public function testTheNewPillOnALinkCardIsTheStyledPill(string $role, string $base): void
    {
        $id = $this->notify($role, ['title' => 'Order SO-0002 was cancelled', 'link_url' => '/somewhere']);
        $html = $this->page($role);

        $this->assertMatchesRegularExpression(
            '#<form method="post" action="[^"]*' . $base . '/notifications/open/' . $id . '" class="nt-openform">.*?<button type="submit" class="nt-card green is-unread">.*?'
            . '<span class="nt-title">Order SO-0002 was cancelled<span class="new-pill">NEW</span></span>#s',
            $html
        );
        $this->assertStringContainsString('<link href="/assets/css/notifications.css" rel="stylesheet">', $html);
        $this->assertMatchesRegularExpression('/\.new-pill \{[^}]*display: inline-flex/', (string) file_get_contents(FCPATH . 'assets/css/notifications.css'), 'Styled on its own, not only inside one kind of card.');
        $this->assertMatchesRegularExpression('/\.nt-title \{[^}]*gap: 4px 8px/', (string) file_get_contents(FCPATH . 'assets/css/notifications.css'), 'Spaced from the title.');
    }

    public static function rolesWithLinkCards(): array
    {
        return ['Staff' => ['Staff', 'staff'], 'Supplier' => ['Supplier', 'supplier']];
    }

    public function testAReadCardHasNoNewPill(): void
    {
        $this->notify('Staff', ['title' => 'Old news', 'is_read' => 1, 'link_url' => '/x']);
        $html = $this->page('Staff');

        $this->assertStringContainsString('<button type="submit" class="nt-card green is-read">', $html);
        $this->assertStringNotContainsString('<span class="new-pill">NEW</span>', $html);
    }

    // ------------------------------------------------------------ the Owner's cards

    public function testTheOwnersCardsShowTheTime(): void
    {
        $this->notify('Admin', ['title' => 'Order SO-0001 shipped', 'type' => 'Reorder Point Notice']);
        $this->notify('Admin', ['title' => 'Staff note', 'category' => 'staff_activity', 'type' => 'Damaged Item']);
        $html = $this->page('Admin');
        $time = date('M j, Y', strtotime('today')) . ' 2:05 PM';

        $this->assertStringContainsString('<span class="nt-meta"><span>' . $time . '</span><span class="type-pill">Reorder At Notice</span></span>', $html);
        $this->assertStringContainsString('<span class="nt-meta"><span>' . $time . '</span><span class="type-pill">Damaged Item</span></span>', $html);
        $this->assertStringContainsString('<details class="nt-card nt-details red is-unread">', $html, 'Staff notices stay a <details>.');
    }

    public function testTheSecurityCardKeepsItsButtonsAndForms(): void
    {
        $id = $this->notify('Admin', ['title' => 'Password reset requested', 'category' => 'security', 'type' => StaffPasswordAlert::TYPE_FORGOT, 'action_user_id' => $this->staffId]);
        $html = $this->page('Admin');

        foreach (['accept', 'lock', 'mark-read'] as $action) {
            $this->assertStringContainsString("admin/notifications/{$action}/{$id}", $html);
        }
        $this->assertStringContainsString('<button type="submit" class="nt-btn is-green">Accept</button>', $html);
        $this->assertStringContainsString('<button type="submit" class="nt-btn is-red">Lock account</button>', $html);
        $this->assertStringContainsString('<button type="submit" class="nt-btn">Mark as Done</button>', $html);
        $this->assertMatchesRegularExpression('/data-confirm="They won(\'|&#039;)t be able to sign in until you reset their password from Manage Accounts."\s*data-confirm-title="Lock this account\?"\s*data-confirm-ok="Lock account"/', $html);
        $this->assertStringContainsString('<div class="nt-card amber is-unread">', $html);
        $this->assertStringContainsString('<i class="bi bi-shield-exclamation"></i>', $html);
    }

    // ------------------------------------------------------------ toolbar, empty state, header

    /** @dataProvider allRoles */
    public function testTheToolbarIsDisabledWhenThereIsNothingToDo(string $role, string $base): void
    {
        $empty = $this->page($role);
        $this->assertStringContainsString('<span class="nt-empty-icon" aria-hidden="true"><i class="bi bi-bell"></i></span>', $empty);
        $this->assertStringContainsString('No notifications yet.', $empty);
        $this->assertStringContainsString('<button type="submit" class="nt-bar-btn is-read" disabled><i class="bi bi-check2-all" aria-hidden="true"></i> Mark all as read</button>', $empty);
        $this->assertStringContainsString('<button type="submit" class="nt-bar-btn is-clear" disabled><i class="bi bi-trash3" aria-hidden="true"></i> Clear all</button>', $empty);
        $this->assertStringContainsString('action="' . site_url($base . '/notifications/clear-all') . '"', $empty);
        $this->assertStringContainsString('data-confirm="This permanently deletes all your notifications. This cannot be undone."', $empty);

        // All read: only Mark all as read stays disabled.
        $this->notify($role, ['is_read' => 1, 'category' => 'staff_activity']);
        $read = $this->page($role);
        $this->assertStringContainsString('class="nt-bar-btn is-read" disabled>', $read);
        $this->assertStringContainsString('class="nt-bar-btn is-clear">', $read);

        // Something unread: both work.
        $this->notify($role, ['category' => 'staff_activity']);
        $this->assertStringContainsString('class="nt-bar-btn is-read">', $this->page($role));
    }

    public static function allRoles(): array
    {
        return ['Owner' => ['Admin', 'admin'], 'Staff' => ['Staff', 'staff'], 'Supplier' => ['Supplier', 'supplier']];
    }

    public function testTheSupplierHeaderHasNoUnreadSubtitle(): void
    {
        $empty = $this->page('Supplier');
        $this->notify('Supplier', []);
        $this->notify('Supplier', []);
        $unread = $this->page('Supplier');

        foreach ([$empty, $unread] as $html) {
            $this->assertMatchesRegularExpression('/<h1>Notifications<\/h1>\s*<\/div>/', $html, 'Title only, like Owner and Staff.');
            $this->assertStringNotContainsString('text-white-50 small mt-1', $html);
            $this->assertStringNotContainsString(' unread</div>', $html);
        }
        $this->assertStringContainsString('Unread <span class="nt-count">2</span>', $unread, 'The count stays on the Unread chip.');
    }
}
