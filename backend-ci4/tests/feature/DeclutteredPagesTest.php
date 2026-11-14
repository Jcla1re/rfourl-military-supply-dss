<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ReorderFixture;

/**
 * The decluttered Owner and Staff pages (view-only changes): the Owner dashboard keeps the banner, three
 * stat cards and Weekly Sales Trend; Items by Category and Top Sellers moved to Trend Analysis > Products;
 * Trend Analysis has ?tab= tabs; Inventory shows six columns with the rest in an expandable row; the
 * Ordering Report has one DSS line with a details toggle and Z only in the class tooltip; Staff dashboard
 * lists show three rows and a "View all" link. Runs on the in-memory 'tests' database.
 *
 * @internal
 */
final class DeclutteredPagesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ReorderFixture;

    protected $migrate = false;
    protected $refresh = false;

    private string $originalPrefix = '';

    protected function setUp(): void
    {
        parent::setUp();
        // The dashboards and Trend Analysis run raw SQL ("FROM sales_transaction") with no table prefix.
        $this->originalPrefix = $this->db->DBPrefix;
        $this->db->setPrefix('');
        $this->setUpReorderFixture();
        Services::resetSingle('renderer');

        $forge = \Config\Database::forge();
        foreach (['sales_transaction', 'sales_item', 'inventory_log', 'app_settings'] as $t) {
            $forge->dropTable($t, true);
        }
        $str  = ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true];
        $real = ['type' => 'REAL', 'default' => 0];
        $int  = ['type' => 'INTEGER', 'null' => true];
        $when = ['type' => 'DATETIME', 'null' => true];
        $make = static function (string $name, array $fields, string $key) use ($forge) {
            $forge->addField($fields);
            $forge->addKey($key, true);
            $forge->createTable($name);
        };
        $make('sales_transaction', ['sales_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'receipt_no' => $str, 'or_number' => $str, 'user_id' => $int, 'sale_date' => $when, 'payment_method' => $str, 'cash_amount' => $real, 'gcash_amount' => $real, 'gcash_reference_no' => $str, 'subtotal' => $real, 'discount' => $real, 'total_amount' => $real], 'sales_id');
        $make('sales_item', ['sales_item_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'sales_id' => $int, 'item_id' => $str, 'quantity_sold' => $int, 'selling_price' => $real, 'is_bulk' => ['type' => 'INTEGER', 'default' => 0]], 'sales_item_id');
        $make('inventory_log', ['log_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'item_id' => $str, 'user_id' => $int, 'log_type' => $str, 'quantity_changed' => $int, 'stock_after_change' => $int, 'reference_so_id' => $str, 'notes' => $str, 'timestamp' => $when], 'log_id');
        $make('app_settings', ['key' => $str, 'value' => $str], 'key');
        // MySQL's DATE_FORMAT / YEAR, which the dashboards and Trend Analysis use, for SQLite.
        $this->db->connID->createFunction('DATE_FORMAT', static fn ($date, $format) => $format === '%Y-%m' ? substr((string) $date, 0, 7) : (string) $date);
        $this->db->connID->createFunction('YEAR', static fn ($date) => (int) substr((string) $date, 0, 4));
        $this->db->connID->createFunction('MONTH', static fn ($date) => (int) substr((string) $date, 5, 2));

        $this->addSize('P-S', 'Pants', 'S', 1, 3, ['selling_price' => 350, 'product_code' => '0111', 'supplier_id' => 'SUP-001']);
        $this->addSize('P-M', 'Pants', 'M', 9, 3, ['selling_price' => 350, 'product_code' => '0111']);
        $this->addSize('W-1', 'Whistle', null, 20, 3, ['category' => 'Accessories', 'selling_price' => 90]);
        $this->alertFor('P-S');
    }

    protected function tearDown(): void
    {
        $forge = \Config\Database::forge();
        foreach (['sales_transaction', 'sales_item', 'inventory_log', 'app_settings'] as $t) {
            $forge->dropTable($t, true);
        }
        $this->tearDownReorderFixture();
        $this->db->setPrefix($this->originalPrefix);
        parent::tearDown();
    }

    private function html(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        return new \DOMXPath($dom);
    }

    private function owner(string $url): string
    {
        return (string) $this->asOwner()->get($url)->response()->getBody();
    }

    private function staff(string $url): string
    {
        return (string) $this->asStaff()->get($url)->response()->getBody();
    }

    /** A completed sale of one Whistle, $minutesAgo minutes ago. */
    private function sale(int $n, int $minutesAgo = 5): void
    {
        $this->db->table('sales_transaction')->insert(['receipt_no' => (string) (1000 + $n), 'user_id' => $this->staffId, 'sale_date' => date('Y-m-d H:i:s', strtotime("-{$minutesAgo} minutes")), 'payment_method' => 'Cash', 'cash_amount' => 90, 'subtotal' => 90, 'total_amount' => 90]);
        $this->db->table('sales_item')->insert(['sales_id' => (int) $this->db->insertID(), 'item_id' => 'W-1', 'quantity_sold' => 1, 'selling_price' => 90]);
    }

    // ---------------------------------------------------------------- Owner dashboard

    /**
     * The dashboard view with the controller's data. (The controller's weekly-recalculation check uses MySQL's
     * NOW() - INTERVAL, which the SQLite test database can't run, so the view is rendered with its data directly.)
     */
    private function dashboard(): string
    {
        $_SESSION = ['isLoggedIn' => true, 'role' => 'Admin', 'user_id' => $this->ownerId, 'username' => 'owner1', 'full_name' => 'Owner One', 'last_activity' => time()];

        return view('admin/dashboard', [
            'title' => 'Dashboard', 'pageTitle' => 'Dashboard', 'active' => 'dashboard', 'totalStock' => 30,
            'reorderSummary' => ['needs' => 1, 'on_order' => 0], 'salesToday' => 180, 'salesYesterday' => 90,
            'weekLabels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], 'weekData' => [0, 0, 0, 0, 0, 90, 90], 'lastWeekData' => [0, 0, 0, 0, 0, 0, 0],
            'stockTrendPct' => '0%', 'salesTrendPct' => '100%',
            'seasonLabels' => ['Clothes'], 'seasonData' => [2], 'topProducts' => [['item_name' => 'Whistle', 'units_sold' => 4]],
        ]);
    }

    public function testTheOwnerDashboardKeepsTheBannerThreeStatCardsAndWeeklySalesTrend(): void
    {
        $html = $this->dashboard();
        $x    = $this->html($html);

        $this->assertStringContainsString('1 item needs reordering', $html, 'The alert banner.');
        $this->assertSame(3, $x->query('//*[contains(@class,"dash-stat")]')->length, 'Three stat cards.');
        $this->assertSame(['Total Stock on hand', 'Sales Today', 'Sales Yesterday'], array_map(static fn ($n) => trim($n->textContent), iterator_to_array($x->query('//*[contains(@class,"dash-stat")]//*[contains(@class,"label")]'))));
        $this->assertStringContainsString('Weekly Sales Trend', $html);
        $this->assertNotNull($x->query('//canvas[@id="weeklySalesChart"]')->item(0));
        $this->assertSame(1, $x->query('//canvas')->length, 'Only the weekly chart is left on the page.');
    }

    public function testItemsByCategoryAndTopSellersAreNoLongerOnTheOwnerDashboard(): void
    {
        $html = $this->dashboard(); // given the data the cards used to show, it still does not draw them

        foreach (['Items by Category', 'Top Sellers', 'seasonChart', 'top-product', 'Whistle'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, "{$gone} moved to Trend Analysis > Products");
        }
    }

    // ---------------------------------------------------------------- Trend Analysis tabs

    /** @return array<string, array> data-panel => ['hidden' => bool, 'text' => string] */
    private function panels(string $html): array
    {
        $x   = $this->html($html);
        $out = [];
        foreach ($x->query('//section[contains(@class,"ta-panel")]') as $section) {
            $out[$section->getAttribute('data-panel')] = ['hidden' => $section->hasAttribute('hidden'), 'text' => preg_replace('/\s+/', ' ', $section->textContent)];
        }

        return $out;
    }

    public function testTrendAnalysisHasFourTabsAndOpensOnOverview(): void
    {
        $html = $this->owner('admin/trend-analysis');
        $x    = $this->html($html);

        $tabs = [];
        foreach ($x->query('//nav[contains(@class,"ta-tabs")]/a') as $a) {
            $tabs[trim($a->textContent)] = $a->getAttribute('href');
        }
        $this->assertSame(['Overview', 'Products', 'ABC classification', 'Daily demand'], array_keys($tabs));
        foreach (['overview', 'products', 'abc', 'daily'] as $key) {
            $this->assertStringContainsString('tab=' . $key, implode(' ', $tabs));
        }
        $panels = $this->panels($html);
        $this->assertSame(['overview', 'products', 'abc', 'daily'], array_keys($panels));
        $this->assertSame(['overview' => false, 'products' => true, 'abc' => true, 'daily' => true], array_map(static fn ($p) => $p['hidden'], $panels));
        $this->assertStringContainsString('Items sold in', $panels['overview']['text'], 'The four cards.');
        $this->assertSame(4, $x->query('//section[@data-panel="overview"]//*[contains(@class,"ta-card")]')->length);
        $this->assertNotNull($x->query('//section[@data-panel="overview"]//canvas[@id="trendChart"]')->item(0), 'The monthly chart.');
        $this->assertSame('overview', $x->query('//nav[contains(@class,"ta-tabs")]/a[contains(@class,"active")]')->item(0)->getAttribute('href') ? 'overview' : '');
    }

    public function testTheTabIsInTheUrlAndSelectsThePanel(): void
    {
        foreach (['products', 'abc', 'daily'] as $tab) {
            $html   = $this->owner('admin/trend-analysis?tab=' . $tab);
            $panels = $this->panels($html);

            foreach ($panels as $name => $panel) {
                $this->assertSame($name !== $tab, $panel['hidden'], "{$tab}: panel {$name}");
            }
            $x = $this->html($html);
            $this->assertStringContainsString('tab=' . $tab, $x->query('//nav[contains(@class,"ta-tabs")]/a[contains(@class,"active")]')->item(0)->getAttribute('href'));
            $this->assertSame($tab, $x->query('//form[@id="taFilters"]/input[@name="tab"]')->item(0)->getAttribute('value'), 'Changing the year or item keeps the tab.');
        }
        $this->assertFalse($this->panels($this->owner('admin/trend-analysis?tab=nonsense'))['overview']['hidden'], 'An unknown tab opens Overview.');
    }

    public function testTheTabLinksKeepTheYearAndItem(): void
    {
        $html = $this->owner('admin/trend-analysis?tab=abc&year=' . date('Y') . '&item=P-S');
        $x    = $this->html($html);

        $href = $x->query('//nav[contains(@class,"ta-tabs")]/a')->item(1)->getAttribute('href');
        $this->assertStringContainsString('tab=products', $href);
        $this->assertStringContainsString('year=' . date('Y'), $href);
        $this->assertStringContainsString('item=P-S', $href);
    }

    public function testTheProductsTabHasProductPerformanceItemsByCategoryAndTopSellers(): void
    {
        $this->sale(1);
        $this->sale(2);
        $panel = $this->panels($this->owner('admin/trend-analysis?tab=products'))['products'];

        $this->assertFalse($panel['hidden']);
        foreach (['Product performance', 'Items by category', 'Top sellers (all time)', 'Whistle'] as $text) {
            $this->assertStringContainsString($text, $panel['text']);
        }
        $html = $this->owner('admin/trend-analysis?tab=products');
        $this->assertNotNull($this->html($html)->query('//section[@data-panel="products"]//canvas[@id="seasonChart"]')->item(0));
        // Items per category: Pants (2 sizes) counts once, plus the Whistle.
        $this->assertStringContainsString('"Clothes","Shoes"', $html, 'Category chart labels are the Inventory categories.');
    }

    public function testTheAbcAndDailyDemandCardsLiveInTheirOwnTabs(): void
    {
        $panels = $this->panels($this->owner('admin/trend-analysis'));

        $this->assertStringContainsString('ABC classification', $panels['abc']['text']);
        $this->assertStringNotContainsString('Daily demand', $panels['abc']['text']);
        $this->assertStringContainsString('Daily demand', $panels['daily']['text']);
        $this->assertStringNotContainsString('ABC classification (K-Means)', $panels['daily']['text']);
        $this->assertStringNotContainsString('Product performance', $panels['overview']['text'], 'Product performance moved to the Products tab.');
    }

    // ---------------------------------------------------------------- Inventory

    /** @return list<string> the header cells' text */
    private function headers(\DOMXPath $x): array
    {
        return array_map(static fn ($th) => trim(preg_replace('/\s+/', ' ', explode('Info', $th->textContent)[0])), iterator_to_array($x->query('//table[contains(@class,"inv-table")]/thead//th')));
    }

    public function testOwnerInventoryShowsSixColumnsAndAChevron(): void
    {
        $html = $this->owner('admin/inventory');
        $x    = $this->html($html);

        $headers = $this->headers($x);
        $this->assertCount(7, $headers, 'Chevron + six columns.');
        $this->assertSame('Details', $headers[0]);
        $this->assertSame('Item', $headers[1]);
        $this->assertSame('Size', $headers[2]);
        $this->assertSame('On Hand', $headers[3]);
        $this->assertStringStartsWith('Reorder', $headers[4]);
        $this->assertStringStartsWith('Status', $headers[5]);
        $this->assertSame('Actions', $headers[6]);
        foreach (['Item ID', 'Category', 'Order Qty', 'Stock Level', 'Last Updated'] as $moved) {
            $this->assertNotContains($moved, $headers);
        }

        $row = $x->query('//tr[contains(@class,"inv-row")]')->item(0);
        $this->assertSame(7, $x->query('./td', $row)->length);
        $this->assertSame('false', $x->query('.//button[contains(@class,"inv-expand")]', $row)->item(0)->getAttribute('aria-expanded'));
        $this->assertNotNull($x->query('.//button[contains(@class,"open-order-modal")]', $row)->item(0), 'Order is still on the row.');
        $this->assertNotNull($x->query('.//button[contains(@class,"edit-row")]', $row)->item(0), 'Edit is still on the row.');
    }

    public function testOwnerInventoryDetailRowHoldsTheMovedColumns(): void
    {
        $x      = $this->html($this->owner('admin/inventory'));
        $row    = $x->query('//tr[contains(@class,"inv-row")]')->item(0);
        $detail = $x->query('//tr[contains(@class,"inv-detail")]')->item(0);

        $this->assertTrue($detail->hasAttribute('hidden'), 'Closed until the chevron is used.');
        $this->assertSame($x->query('.//button[contains(@class,"inv-expand")]', $row)->item(0)->getAttribute('aria-controls'), $detail->getAttribute('id'));
        $labels = array_map(static fn ($dt) => trim(preg_replace('/\s+/', ' ', explode('Info', $dt->textContent)[0])), iterator_to_array($x->query('.//dt', $detail)));
        $this->assertSame(['Item ID', 'Category', 'Order Qty', 'Stock level', 'Last updated'], array_map(static fn ($l) => $l, $labels));
        $this->assertNotNull($x->query('.//*[contains(@class,"inv-bar-cell")]//*[contains(@class,"stock-bar")]', $detail)->item(0), 'The stock-level bar.');
        $this->assertSame('P-S', trim($x->query('.//*[contains(@class,"inv-item-id")]', $detail)->item(0)->textContent));
        $this->assertStringContainsString('Clothes', $detail->textContent);
        $this->assertNull($x->query('.//td[contains(@class,"inv-item-id")]', $row)->item(0), 'Item ID is not in the visible row.');
        $this->assertStringNotContainsString('Clothes', $row->textContent);
    }

    public function testStaffInventoryShowsTheSameSixColumnsAndADetailRow(): void
    {
        $x = $this->html($this->staff('staff/inventory'));

        $headers = $this->headers($x);
        $this->assertCount(7, $headers);
        $this->assertSame(['Details', 'Item', 'Size', 'On Hand'], array_slice($headers, 0, 4));
        $this->assertSame('Actions', $headers[6]);
        $row    = $x->query('//tr[contains(@class,"inv-row")]')->item(0);
        $detail = $x->query('//tr[contains(@class,"inv-detail")]')->item(0);
        $this->assertSame(7, $x->query('./td', $row)->length);
        $this->assertTrue($detail->hasAttribute('hidden'));
        $labels = array_map(static fn ($dt) => trim($dt->textContent), iterator_to_array($x->query('.//dt', $detail)));
        $this->assertSame(['Item ID', 'Category', 'Stock level', 'Last updated'], $labels);
        $this->assertNotNull($x->query('.//*[contains(@class,"notify-form")]', $row)->item(0), 'Notify Owner stays on the row.');
    }

    public function testInventoryShowsTenRowsPerPageByDefault(): void
    {
        for ($i = 1; $i <= 14; $i++) {
            $this->addSize('I-' . $i, 'Item ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), null, 50, 3);
        }

        foreach (['admin' => $this->owner('admin/inventory'), 'staff' => $this->staff('staff/inventory')] as $role => $html) {
            $this->assertSame(10, $this->html($html)->query('//tr[contains(@class,"inv-row")]')->length, "{$role}: 10 rows per page");
        }
    }

    // ---------------------------------------------------------------- Ordering Report

    public function testTheOrderingReportDssCardIsOneLineWithADetailsToggle(): void
    {
        $this->db->table('dss_parameters')->update(['ordering_cost' => 500, 'holding_cost_per_unit' => 60.48, 'service_level_target' => 95, 'z_score' => 1.645, 'demand_lookback_days' => 730]);
        $html = $this->owner('admin/procurement-report');
        $x    = $this->html($html);

        $this->assertSame('Cost/order ₱500 · Storage ₱60.48 · 95% · 730 days', trim($x->query('//*[contains(@class,"pr-summary")]')->item(0)->textContent));
        $toggle = $x->query('//button[@id="prToggle"]')->item(0);
        $this->assertSame('false', $toggle->getAttribute('aria-expanded'));
        $this->assertStringContainsString('details', $toggle->textContent);
        $details = $x->query('//*[@id="prDetails"]')->item(0);
        $this->assertTrue($details->hasAttribute('hidden'), 'The four numbers wait behind the toggle.');
        foreach (['Cost per order', 'Storage cost per item per year', 'Default availability target', 'Sales history used'] as $label) {
            $this->assertStringContainsString($label, $details->textContent);
        }
        $this->assertStringNotContainsString('Z =', $details->textContent, 'Z is only in the class tooltip.');
    }

    public function testZIsOnlyInTheClassTooltip(): void
    {
        $this->db->table('pdss_computation')->where('item_id', 'P-S')->update(['service_level_z' => 1.645]);
        $this->db->table('cluster_segments')->insert(['item_id' => 'P-S', 'cluster_label' => 0, 'cluster_name' => 'A', 'abc_class' => 'A', 'service_level' => 95, 'z_score' => 1.645, 'computed_at' => '2026-11-01 10:00:00']);
        $html = $this->owner('admin/procurement-report');
        $x    = $this->html($html);

        $badge = $x->query('//table//span[contains(@class,"abc-badge")]')->item(0);
        $this->assertNotNull($badge, 'The class badge.');
        $this->assertStringContainsString('Z = 1.645', $badge->getAttribute('title'));
        $this->assertStringNotContainsString('Z=', $x->query('//table')->item(0)->textContent, 'No Z under the badge.');
    }

    public function testTheOrderingReportBackButtonIsTheHeadersOnlyButton(): void
    {
        $x = $this->html($this->owner('admin/procurement-report'));

        $this->assertStringContainsString('btn-topbar', $x->query('//a[contains(@href,"admin/reorder-alerts") and contains(@class,"btn")]')->item(0)->getAttribute('class'));
    }

    // ---------------------------------------------------------------- Staff dashboard

    public function testStaffDashboardRecentTransactionsShowsThreeRowsAndViewAll(): void
    {
        foreach ([1, 2, 3, 4, 5] as $n) {
            $this->sale($n, $n * 10);
        }
        $x = $this->html($this->staff('staff/dashboard'));

        $this->assertSame(3, $x->query('//*[contains(@class,"recent-row")]')->length, 'Three of the five.');
        $link = $x->query('//h6[normalize-space()="Recent Transactions"]/following-sibling::a[contains(@class,"view-all")]')->item(0);
        $this->assertNotNull($link, 'A "View all" link next to the heading.');
        $this->assertSame('View all', trim($link->textContent));
        $this->assertStringContainsString('staff/sales?tab=receipts', $link->getAttribute('href'));
    }

    public function testStaffDashboardLowStockShowsThreeRowsAndViewAll(): void
    {
        foreach (['L-1', 'L-2', 'L-3', 'L-4', 'L-5'] as $id) {
            $this->addSize($id, 'Low ' . $id, null, 0, 5);
        }
        $x = $this->html($this->staff('staff/dashboard'));

        $this->assertSame(3, $x->query('//*[contains(@class,"lowstock-row")]')->length);
        $link = $x->query('//h6[normalize-space()="Low Stock Items"]/following-sibling::a[contains(@class,"view-all")]')->item(0);
        $this->assertNotNull($link);
        $this->assertStringContainsString('staff/reorder-alerts', $link->getAttribute('href'));
    }

    public function testStaffDashboardKeepsItsOtherCardsAndQuickActions(): void
    {
        $html = $this->staff('staff/dashboard');

        foreach (['Items Sold Today', 'Recent Transactions', 'Low Stock Items', 'Quick Actions', 'Log New Transaction', 'POS &amp; Sales', 'Check Inventory', 'Reorder Alerts'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }
}
