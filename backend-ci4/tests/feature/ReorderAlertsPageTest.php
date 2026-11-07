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
        preg_match_all('/data-ra-row data-tab="' . $tab . '".*?data-item-id="([^"]+)"|data-ra-row data-tab="' . $tab . '"[^>]*>.*?<span class="sub">[^<]*?(P-[\w-]+)<\/span>/s', $panel[0] ?? '', $m);

        return array_values(array_filter(array_map(static fn ($a, $b) => $a ?: $b, $m[1], $m[2])));
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
        $this->assertSame(['P-S', 'P-1', 'P-M'], $this->cardsIn($html, 'order'), 'Out of stock first, then lowest compared to Reorder at.');
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
}
