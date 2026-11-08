<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ReorderFixture;

/**
 * The Owner's Reorder Alerts page structure (Phase 2): header, headline pill,
 * "Reorder levels calculated", filters, the three tabs (counts add up to the
 * headline's sizes), "Show only" chips, captions, empty states, and the removed
 * pieces. Filtering itself runs in the browser (assets/js/reorder-alerts.js).
 * Runs on the in-memory 'tests' database (see Tests\Support\ReorderFixture).
 *
 * @internal
 */
final class ReorderAlertsPageTest extends CIUnitTestCase
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

    private function page(string $query = '', array $session = []): string
    {
        return (string) $this->asOwner($session)->get('admin/reorder-alerts' . $query)->getBody();
    }

    /** @return array<string,int> tab => count shown on the tab */
    private function tabCounts(string $html): array
    {
        preg_match_all('/data-tab-count="(\w+)">(\d+)</', $html, $m);

        return array_map('intval', array_combine($m[1], $m[2]));
    }

    /** @return list<string> item IDs of the cards in one tab, in page order */
    private function cardsIn(string $html, string $tab): array
    {
        preg_match('/<section class="ra-panel" data-panel="' . $tab . '".*?<\/section>/s', $html, $panel);
        preg_match_all('/data-ra-row data-tab="' . $tab . '" data-item-id="([^"]+)"/', $panel[0] ?? '', $m);

        return $m[1];
    }

    // ------------------------------------------------------------ tabs and headline

    public function testTabCountsAddUpToTheHeadlineSizes(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3);                    // To order
        $this->alertFor('P-1');
        $this->addSize('P-S', 'Pants', 'S', 0, 3);                       // To order (one item, two sizes)
        $this->addSize('P-M', 'Pants', 'M', 2, 3);
        $this->alertFor('P-S');
        $this->alertFor('P-M', 'Acknowledged');
        $this->addSize('P-2', 'Belt', null, 1, 3);                       // Ordered: alert Ordered + open order
        $this->alertFor('P-2', 'Ordered', ['so_id' => 'SO-1']);
        $this->openOrder('SO-1', ['P-2'], '2026-12-30');
        $this->addSize('P-3', 'Cap', null, 1, 3);                        // Dismissed until recovery
        $this->alertFor('P-3', 'Dismissed', ['hidden_until_recovered' => 1]);
        $this->addSize('P-4', 'Boots', '9', 1, 3);                       // Dismissed but on an open order: Ordered wins
        $this->alertFor('P-4', 'Dismissed', ['hidden_until_recovered' => 1]);
        $this->openOrder('SO-2', ['P-4'], null);
        $this->addSize('P-5', 'Archived', null, 1, 3, ['is_active' => 0]); // archived: not counted
        $this->alertFor('P-5');
        $this->addSize('P-6', 'Recovered', null, 20, 3);                 // Ordered alert, stock back up: not Reorder Now
        $this->alertFor('P-6', 'Ordered', ['so_id' => 'SO-3']);
        $this->openOrder('SO-3', ['P-6'], '2026-12-30');

        $html = $this->page();

        $this->assertSame(['order' => 3, 'ordered' => 2, 'dismissed' => 1], $this->tabCounts($html));
        $this->assertStringContainsString('Reorder Now: 5 items &middot; 6 sizes', strip_tags($html));
        // One card per item, following its most urgent size: Pants (S is out of stock, then M), then Whistle.
        $this->assertSame(['P-S', 'P-M', 'P-1'], $this->cardsIn($html, 'order'), 'Out of stock first, then lowest compared to Reorder at.');
        $this->assertStringNotContainsString('Recovered', $html);
        $this->assertStringNotContainsString('>Archived<', $html);
    }

    public function testEmptyPage(): void
    {
        $html = $this->page();

        $this->assertSame(['order' => 0, 'ordered' => 0, 'dismissed' => 0], $this->tabCounts($html));
        $this->assertStringContainsString('Reorder Now: 0 items &middot; 0 sizes', strip_tags($html));
        $this->assertStringContainsString('All stocked up. Nothing is at Reorder Now.', $html);
        $this->assertStringContainsString('Nothing on order. Sizes move here when you order them.', $html);
        $this->assertStringContainsString('No dismissed alerts.', $html);
        $this->assertStringContainsString('No sizes match these filters.', $html);
    }

    public function testOldShowDismissedLinkOpensTheDismissedTab(): void
    {
        $this->assertStringContainsString('data-active-tab="dismissed"', $this->page('?dismissed=1'));
        $this->assertStringContainsString('data-active-tab="ordered"', $this->page('?tab=ordered'));
        $this->assertStringContainsString('data-active-tab="order"', $this->page('?tab=nonsense'));
    }

    // ------------------------------------------------------------ header, top row, removed pieces

    public function testHeaderHasOnlyTheTitleAndBellAndTheReportButtonMovedIntoThePage(): void
    {
        $html = $this->page();
        $topbar = substr($html, strpos($html, 'class="topbar'), strpos($html, 'class="main-scroll"') - strpos($html, 'class="topbar'));

        $this->assertStringNotContainsString('Ordering Report', $topbar);
        $this->assertStringContainsString('notification-button', $topbar);
        $this->assertMatchesRegularExpression('/<a href="[^"]*admin\/procurement-report" class="btn btn-outline-success ra-report-btn">Ordering Report<\/a>/', $html);
    }

    public function testReorderLevelsCalculatedDate(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3); // its DSS row was computed Nov 1, 2026
        $this->assertStringContainsString('Reorder levels calculated Nov 1, 2026', $this->page());
    }

    public function testRemovedPieces(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3);
        $this->alertFor('P-1');
        $html = $this->page();

        foreach (['Received This Week', 'icon-stat-card', 'Reading these numbers', 'need action +', 'page-panel', 'At 0 units', 'dismissed (hidden)'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, $gone);
        }
    }

    public function testFiltersCaptionsAndSearchText(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3, ['product_code' => 'WH-01', 'category' => 'Accessories', 'supplier_id' => 'SUP-002']);
        $this->alertFor('P-1');
        $html = $this->page('?q=whis&cat=Accessories&sup=SUP-002&only=out,bogus');

        $this->assertStringContainsString('placeholder="Search by item, ID or supplier&hellip;"', $html); // the page writes … as &hellip;
        $this->assertStringContainsString('value="whis"', $html);
        $this->assertMatchesRegularExpression('/<option value="Accessories" selected>Accessories<\/option>/', $html);
        $this->assertMatchesRegularExpression('/<option value="SUP-002" selected>Quiapo Patches Co<\/option>/', $html);
        $this->assertMatchesRegularExpression('/data-only="out" class="active" aria-pressed="true"/', $html);
        $this->assertStringContainsString('data-search="whistle p-1 wh-01 quiapo patches co"', $html);
        $this->assertStringContainsString('Sorted by stock left: out of stock first, then lowest compared to Reorder at. The black tick on each bar marks Reorder at.', $html);
        $this->assertStringContainsString('Late deliveries first, then by expected arrival. Items leave this tab when the delivery is received.', $html);
        $this->assertStringContainsString("Dismissed alerts stay here until the item's stock is above Reorder at again.", $html);
    }

    // ------------------------------------------------------------ Show only chips

    public function testOutOfStockAndNewSinceLastLoginChips(): void
    {
        $this->addSize('P-1', 'Old Zero', null, 0, 3);
        $this->alertFor('P-1', 'Active', ['alert_timestamp' => '2026-11-02 08:00:00']);
        $this->addSize('P-2', 'New Low', null, 1, 3);
        $this->alertFor('P-2', 'Active', ['alert_timestamp' => '2026-11-04 08:00:00']);
        $this->addSize('P-3', 'New Zero', null, 0, 3);
        $this->alertFor('P-3', 'Active', ['alert_timestamp' => '2026-11-05 08:00:00']);

        $html = $this->page('', ['previous_login' => '2026-11-03 09:00:00']);

        $this->assertMatchesRegularExpression('/Out of stock &middot; <span data-chip-count="out">2<\/span>/', $html);
        $this->assertMatchesRegularExpression('/New since Nov 3, 9:00 AM &middot; <span data-chip-count="new">2<\/span>/', $html);
        $this->assertMatchesRegularExpression('/data-search="old zero[^"]*"[^>]*data-new="0"/', $html);
        $this->assertMatchesRegularExpression('/data-search="new low[^"]*"[^>]*data-new="1"/', $html);

        $this->assertStringNotContainsString('New since', $this->page(), 'No previous login: no chip.');
    }

    public function testLoginKeepsThePreviousLoginForTheNewChip(): void
    {
        $this->db->table('users')->where('user_id', $this->ownerId)->update(['password_hash' => password_hash('Secret#123', PASSWORD_DEFAULT), 'last_login' => '2026-11-05 08:30:00']);

        $this->post('login/admin', ['username' => 'owner1', 'password' => 'Secret#123'])->assertRedirectTo('/admin/dashboard');

        $this->assertSame('2026-11-05 08:30:00', $_SESSION['previous_login'] ?? null);
        $this->assertNotSame('2026-11-05 08:30:00', $this->db->table('users')->where('user_id', $this->ownerId)->get()->getRow()->last_login);
    }

    // ------------------------------------------------------------ Ordered tab

    public function testOrderedTabListsLateFirstAndCountsLate(): void
    {
        $lateDate   = date('Y-m-d', strtotime('-3 days'));
        $futureDate = date('Y-m-d', strtotime('+5 days'));
        $this->addSize('P-1', 'Alpha On Time', null, 1, 3);
        $this->alertFor('P-1', 'Ordered', ['so_id' => 'SO-1']);
        $this->openOrder('SO-1', ['P-1'], $futureDate);
        $this->addSize('P-2', 'Zulu Late', null, 1, 3);
        $this->alertFor('P-2', 'Ordered', ['so_id' => 'SO-2']);
        $this->openOrder('SO-2', ['P-2'], $lateDate);
        $this->addSize('P-3', 'Bravo No Date', null, 1, 3);
        $this->openOrder('SO-3', ['P-3'], null); // ordered from the Orders page: no alert needed

        $html = $this->page('?tab=ordered');

        $this->assertSame(['order' => 0, 'ordered' => 3, 'dismissed' => 0], $this->tabCounts($html));
        $this->assertMatchesRegularExpression('/data-late-count> &middot; <span data-late-number>1<\/span> late/', $html);
        $this->assertStringContainsString('Late &middot; 3 days', $html);
        $zulu  = strpos($html, 'Zulu Late');
        $alpha = strpos($html, 'Alpha On Time');
        $bravo = strpos($html, 'Bravo No Date');
        $this->assertTrue($zulu < $alpha && $alpha < $bravo, 'Late first, then by expected arrival, no date last.');
        $this->assertStringContainsString('admin/orders/SO-2', $html);
    }

    public function testNoLateTextWhenNothingIsLate(): void
    {
        $this->addSize('P-1', 'Alpha', null, 1, 3);
        $this->openOrder('SO-1', ['P-1'], date('Y-m-d', strtotime('+2 days')));

        $this->assertMatchesRegularExpression('/<span class="ra-tab-late" data-late-count hidden>/', $this->page());
    }

    // ------------------------------------------------------------ Phase 3: item cards

    /** The markup of the one card (article) that holds this size. */
    private function cardOf(string $html, string $itemId): string
    {
        foreach (preg_split('/(?=<article class="ra-item")/', $html) as $chunk) {
            if (str_starts_with($chunk, '<article') && str_contains($chunk, 'data-item-id="' . $itemId . '"')) {
                return substr($chunk, 0, strpos($chunk, '</article>'));
            }
        }
        $this->fail("No card holds {$itemId}.");
    }

    public function testColumnHeaderRow(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3);
        $this->alertFor('P-1');
        $html = $this->page();

        preg_match('/<div class="ra-head ra-grid">(.*?)<\/div>/s', $html, $head);
        $this->assertSame(['Item', 'Size', 'On hand', 'Reorder at', 'Status', 'Action'], array_values(array_filter(array_map('trim', explode("\n", strip_tags($head[1]))))));
    }

    public function testSingleSizeCard(): void
    {
        $this->addSize('P-1', 'Whistle', 'L', 1, 3, ['category' => 'Accessories', 'supplier_id' => 'SUP-002']);
        $this->alertFor('P-1', 'Active', ['alert_timestamp' => '2026-11-05 08:00:00']);
        $card = $this->cardOf($this->page('', ['previous_login' => '2026-11-03 09:00:00']), 'P-1');

        $this->assertStringNotContainsString('data-multi', $card);
        $this->assertStringNotContainsString('ra-item-head', $card, 'One row, no card header.');
        $this->assertStringContainsString('<span class="ra-name">Whistle<span class="ra-new">NEW</span></span>', $card, 'NEW next to the name.');
        $this->assertStringContainsString('<span class="ra-sub">Accessories &middot; P-1</span>', $card);
        $this->assertStringContainsString('<span class="ra-sub">Quiapo Patches Co &middot; 5-day delivery</span>', $card);
        $this->assertStringContainsString('<div class="ra-c-size">L</div>', $card);
        $this->assertStringContainsString('<span class="ra-sub ra-inline-size">Size L</span>', $card, 'Under the name on narrow widths.');
        $this->assertStringContainsString('<span class="status-pill red">Reorder Now</span>', $card);
        $this->assertStringContainsString('<div class="ra-c-rop ra-num">3</div>', $card);
        $this->assertMatchesRegularExpression('/open-order-modal"[^>]*>Order 12<\/button>/s', $card);
        $this->assertStringNotContainsString('Order all sizes', $card);
    }

    public function testMultiSizeCardWithOrderAllSizes(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 0, 3, ['product_code' => '0111']);
        $this->addSize('P-M', 'Pants', 'M', 2, 3, ['product_code' => '0111']);
        $this->addSize('P-L', 'Pants', 'L', 9, 3, ['product_code' => '0111']); // In Stock: not on the page
        $this->alertFor('P-S');
        $this->alertFor('P-M', 'Active', ['alert_timestamp' => '2026-11-05 08:00:00']);
        $card = $this->cardOf($this->page('', ['previous_login' => '2026-11-03 09:00:00']), 'P-S');

        $this->assertStringContainsString('data-total="2" data-multi', $card);
        $this->assertStringContainsString('<span class="ra-name">Pants</span>', $card);
        $this->assertStringContainsString('<span class="ra-sub">Clothes &middot; 0111 &middot; Tactical Gear Inc &middot; 5-day delivery</span>', $card);
        $this->assertStringContainsString('<div class="ra-c-size" data-size-count>2 sizes</div>', $card);
        $this->assertMatchesRegularExpression('/<button type="button" class="btn btn-outline-success btn-sm" data-order-all>Order all sizes<\/button>/', $card);
        $this->assertStringNotContainsString('P-L', $card);
        // Each size once in the Size column (and once for narrow widths); NEW next to the size, not the name.
        $this->assertStringContainsString('<div class="ra-c-size">S</div>', $card);
        $this->assertStringContainsString('<div class="ra-c-size">M<span class="ra-new">NEW</span></div>', $card);
        $this->assertSame(1, substr_count($card, 'ra-new">NEW</span></div>'));
        $this->assertStringContainsString('<span class="status-pill ra-pill-out">Out of stock</span>', $card);
        $this->assertLessThan(strpos($card, 'data-item-id="P-M"'), strpos($card, 'data-item-id="P-S"'), 'Most urgent size first.');
    }

    public function testOneOfTwoSizesWhenTheOtherSizeIsOnAnotherTab(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 0, 3);
        $this->addSize('P-M', 'Pants', 'M', 2, 3);
        $this->alertFor('P-S');
        $this->alertFor('P-M', 'Dismissed', ['hidden_until_recovered' => 1]);
        $html = $this->page();

        $order = $this->cardOf($html, 'P-S');
        $this->assertStringContainsString('<div class="ra-c-size" data-size-count>1 of 2 sizes</div>', $order);
        $this->assertMatchesRegularExpression('/data-order-all hidden>Order all sizes/', $order, 'Only one size can be ordered.');
        $this->assertStringContainsString('<div class="ra-c-size" data-size-count>1 of 2 sizes</div>', $this->cardOf($html, 'P-M'));
    }

    public function testOrderAllSizesOnlyCountsTheSameSupplier(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 0, 3);
        $this->addSize('P-M', 'Pants', 'M', 2, 3, ['supplier_id' => 'SUP-002']);
        $this->alertFor('P-S');
        $this->alertFor('P-M');
        $card = $this->cardOf($this->page(), 'P-S');

        $this->assertMatchesRegularExpression('/data-order-all hidden>Order all sizes/', $card, 'Different suppliers: each size keeps its own Order button.');
        $this->assertSame(2, substr_count($card, 'open-order-modal'));
        $this->assertStringContainsString('Several suppliers', $card);
    }

    public function testSameNameInAnotherCategoryIsAnotherCard(): void
    {
        $this->addSize('P-1', 'Cap', 'S', 1, 3, ['category' => 'Accessories']);
        $this->addSize('P-2', 'Cap', 'M', 1, 3, ['category' => 'Clothes']);
        $this->alertFor('P-1');
        $this->alertFor('P-2');
        $html = $this->page();

        $this->assertSame(2, substr_count($html, '<article class="ra-item"'));
        $this->assertStringNotContainsString('data-multi', $html);
    }

    public function testOnHandBarAndReorderAtTick(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3); // Reorder at 3 + order qty 12 = 15
        $this->alertFor('P-1');
        $this->addSize('P-2', 'Cap', null, 2, 3);
        $this->alertFor('P-2', 'Dismissed', ['hidden_until_recovered' => 1]);
        $html = $this->page();

        $this->assertStringContainsString('<span class="ra-num">1</span><span class="ra-bar"><span class="stock-bar red"><span style="width: 7%"></span></span><span class="ra-tick" style="left: 20%" data-tick></span></span>', $this->cardOf($html, 'P-1'));
        $this->assertStringContainsString('<span class="stock-bar gray"><span style="width: 13%"></span></span>', $this->cardOf($html, 'P-2'), 'Gray for dismissed.');

        $bar = new \ReflectionMethod(\App\Controllers\Admin\ReorderAlertController::class, 'stockBar');
        $this->assertSame(['pct' => 0, 'tick' => null], $bar->invoke(null, ['current_stock' => 0, 'manual_rop_warning' => 0, 'recommended_eoq' => 12]), 'No tick when Reorder at is 0.');
        $this->assertSame(['pct' => 100, 'tick' => 50.0], $bar->invoke(null, ['current_stock' => 40, 'manual_rop_warning' => 10, 'recommended_eoq' => 10]), 'Capped at 100%.');
    }

    public function testActionButtonsAndTheOnlyTooltip(): void
    {
        $this->addSize('P-1', 'Whistle', 'L', 1, 3);
        $id = $this->alertFor('P-1');
        $this->addSize('P-2', 'Cap', null, 1, 3);
        $dismissedId = $this->alertFor('P-2', 'Dismissed', ['hidden_until_recovered' => 1]);
        $html = $this->page();

        $order = $this->cardOf($html, 'P-1');
        $this->assertStringContainsString('action="' . site_url('admin/reorder-alerts/dismiss/' . $id) . '" data-keep-view>', $order);
        $this->assertStringContainsString('<button type="submit" class="btn btn-outline-secondary btn-sm ra-icon-btn" data-tooltip="Dismiss until restocked" data-tooltip-pos="above" aria-label="Dismiss until restocked"><i class="bi bi-bell-slash" aria-hidden="true"></i></button>', $order);
        $this->assertStringContainsString('aria-label="Order 12: Whistle &middot; Size L"', $order);
        $this->assertStringNotContainsString('data-confirm', $html, 'No confirmation dialog.');

        $dismissed = $this->cardOf($html, 'P-2');
        $this->assertStringContainsString('action="' . site_url('admin/reorder-alerts/restore/' . $dismissedId) . '" data-keep-view>', $dismissed);
        $this->assertStringContainsString('>Restore</button>', $dismissed);
        $this->assertStringContainsString('<span class="status-pill gray">Dismissed</span>', $dismissed);
        $this->assertStringNotContainsString('open-order-modal', $dismissed);

        // No info-icon tooltips; the only title on the page's cards is the icon button's name.
        $this->assertStringNotContainsString('class="info-tip help-tip"', $html); // help_tip() / Glossary tips (the layout still loads help-tip.js)
        // The icon button is named by the shared tooltip (data-tooltip), not the browser's title.
        preg_match('/<div class="ra-items".*<\/section>/s', $html, $list);
        $this->assertSame(0, preg_match_all('/\stitle="/', $list[0]), 'No browser title tooltips.');
        $this->assertSame(['Dismiss until restocked'], array_values(array_unique(preg_match_all('/data-tooltip="([^"]*)"/', $list[0], $m) ? $m[1] : [])));
    }

    // ------------------------------------------------------------ Phase 3: Dismiss and Restore

    public function testDismissMovesTheSizeToDismissedWithAnUndoToast(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 1, 3);
        $id = $this->alertFor('P-S', 'Acknowledged');

        $response = $this->asOwner()->post('admin/reorder-alerts/dismiss/' . $id, ['return_query' => '?q=pan&cat=Clothes&only=out&evil=1']);

        $this->assertSame(site_url('admin/reorder-alerts?q=pan&cat=Clothes&only=out'), $response->getRedirectUrl(), 'Back to the same tab and filters.');
        $row = $this->db->table('reorder_alert')->where('alert_id', $id)->get()->getRowArray();
        $this->assertSame(['Dismissed', 1], [$row['status'], (int) $row['hidden_until_recovered']]);
        $toast = $_SESSION['toast'];
        $this->assertSame(['success', 'Alert dismissed', 'Pants · Size S', 'moved to Dismissed.', site_url('admin/reorder-alerts/restore/' . $id)], [$toast['type'], $toast['title'], $toast['name'], $toast['message'], $toast['undo']]);

        $html = $this->page();
        $this->assertSame([], $this->cardsIn($html, 'order'));
        $this->assertSame(['P-S'], $this->cardsIn($html, 'dismissed'));
    }

    public function testRestoreMovesTheSizeBackWithAnUndoToast(): void
    {
        $this->addSize('P-S', 'Pants', 'S', 1, 3);
        $id = $this->alertFor('P-S', 'Dismissed', ['hidden_until_recovered' => 1]);

        $response = $this->asOwner()->post('admin/reorder-alerts/restore/' . $id, ['return_query' => '?tab=dismissed']);

        $this->assertSame(site_url('admin/reorder-alerts?tab=dismissed'), $response->getRedirectUrl());
        $row = $this->db->table('reorder_alert')->where('alert_id', $id)->get()->getRowArray();
        $this->assertSame(['Active', 0], [$row['status'], (int) $row['hidden_until_recovered']]);
        $toast = $_SESSION['toast'];
        $this->assertSame(['Alert restored', 'Pants · Size S', 'is back in To order.', site_url('admin/reorder-alerts/dismiss/' . $id)], [$toast['title'], $toast['name'], $toast['message'], $toast['undo']]);
        $this->assertSame(['P-S'], $this->cardsIn($this->page(), 'order'));

        // Undo (the toast's Dismiss) works on the restored alert.
        $this->asOwner()->post('admin/reorder-alerts/dismiss/' . $id, ['return_query' => '?tab=dismissed']);
        $this->assertSame('Dismissed', $this->db->table('reorder_alert')->where('alert_id', $id)->get()->getRow()->status);
    }

    public function testRestoreOnlyWorksOnAnAlertDismissedUntilRecovery(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3);
        $active   = $this->alertFor('P-1');
        $this->addSize('P-2', 'Cap', null, 1, 3);
        $notHidden = $this->alertFor('P-2', 'Dismissed', ['hidden_until_recovered' => 0]); // closed by the sync, not by the Owner

        foreach ([$active => 'Active', $notHidden => 'Dismissed', 9999 => null] as $id => $status) {
            $response = $this->asOwner()->post('admin/reorder-alerts/restore/' . $id);
            $this->assertSame(site_url('admin/reorder-alerts'), $response->getRedirectUrl());
            $this->assertSame(['error', "Couldn't restore this alert"], [$_SESSION['toast']['type'], $_SESSION['toast']['title']]);
            if ($status !== null) {
                $this->assertSame($status, $this->db->table('reorder_alert')->where('alert_id', $id)->get()->getRow()->status);
            }
        }
    }

    public function testDismissRefusesAnAlertThatIsNotPending(): void
    {
        $this->addSize('P-1', 'Whistle', null, 1, 3);
        $id = $this->alertFor('P-1', 'Ordered', ['so_id' => 'SO-1']);

        $this->asOwner()->post('admin/reorder-alerts/dismiss/' . $id);

        $this->assertSame(['error', "Couldn't dismiss this alert"], [$_SESSION['toast']['type'], $_SESSION['toast']['title']]);
        $this->assertSame('Ordered', $this->db->table('reorder_alert')->where('alert_id', $id)->get()->getRow()->status);
    }
}
