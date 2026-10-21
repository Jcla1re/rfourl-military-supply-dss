<?php

use App\Models\ClusterSegmentModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The ABC class everywhere is the K-Means class that dss:run saved (cluster_segments), never a
 * Pareto class worked out from sales. The Trend Analysis ABC card and the Daily demand badges
 * show the same class, and it is the class whose Z-score the saved safety stock was computed with.
 *
 * Runs on the in-memory 'tests' database (MySQL's YEAR()/MONTH() are registered for SQLite).
 *
 * @internal
 */
final class KMeansClassTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    private string $originalPrefix = '';

    private const Z = ['A' => 1.645, 'B' => 1.282, 'C' => 1.036];

    protected function setUp(): void
    {
        parent::setUp();

        // Raw SQL in the controller ("FROM sales_transaction") has no table prefix, so run this test without one.
        $this->originalPrefix = $this->db->DBPrefix;
        $this->db->setPrefix('');
        $this->db->connID->createFunction('YEAR', static fn ($d) => $d === null ? null : (int) substr((string) $d, 0, 4), 1);
        $this->db->connID->createFunction('MONTH', static fn ($d) => $d === null ? null : (int) substr((string) $d, 5, 2), 1);

        $forge  = \Config\Database::forge();
        $tables = ['users', 'notifications', 'products', 'sales_transaction', 'sales_item', 'pdss_computation', 'cluster_segments'];
        foreach ($tables as $t) {
            $forge->dropTable($t, true);
        }
        $make = function (string $name, array $fields, string $key) use ($forge) {
            $forge->addField($fields);
            $forge->addKey($key, true);
            $forge->createTable($name);
        };
        $make('users', ['user_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'username' => ['type' => 'VARCHAR', 'constraint' => 50], 'full_name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'role' => ['type' => 'VARCHAR', 'constraint' => 20], 'is_active' => ['type' => 'INTEGER', 'default' => 1], 'password_hash' => ['type' => 'VARCHAR', 'constraint' => 255],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true], 'supplier_id' => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true]], 'user_id');
        $make('notifications', ['notification_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'recipient_role' => ['type' => 'VARCHAR', 'constraint' => 20], 'recipient_id' => ['type' => 'INTEGER', 'null' => true],
            'is_read' => ['type' => 'INTEGER', 'default' => 0]], 'notification_id');
        $make('products', ['item_id' => ['type' => 'VARCHAR', 'constraint' => 15], 'item_name' => ['type' => 'VARCHAR', 'constraint' => 100], 'category' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'size' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true], 'product_code' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true], 'unit_cost' => ['type' => 'REAL', 'default' => 0],
            'is_active' => ['type' => 'INTEGER', 'default' => 1]], 'item_id');
        $make('sales_transaction', ['sales_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'sale_date' => ['type' => 'DATETIME'], 'total_amount' => ['type' => 'REAL', 'default' => 0]], 'sales_id');
        $make('sales_item', ['sales_item_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'sales_id' => ['type' => 'INTEGER'], 'item_id' => ['type' => 'VARCHAR', 'constraint' => 15],
            'quantity_sold' => ['type' => 'INTEGER'], 'selling_price' => ['type' => 'REAL']], 'sales_item_id');
        $make('pdss_computation', ['computation_id' => ['type' => 'INTEGER', 'auto_increment' => true], 'item_id' => ['type' => 'VARCHAR', 'constraint' => 15], 'computed_at' => ['type' => 'DATETIME', 'null' => true],
            'annual_demand' => ['type' => 'REAL', 'default' => 0], 'avg_daily_demand' => ['type' => 'REAL'], 'demand_std_dev' => ['type' => 'REAL', 'default' => 0], 'lead_time_days' => ['type' => 'INTEGER', 'default' => 7],
            'service_level_z' => ['type' => 'REAL'], 'safety_stock' => ['type' => 'REAL'], 'reorder_point' => ['type' => 'REAL', 'default' => 0], 'eoq_value' => ['type' => 'REAL', 'default' => 0],
            'triggered_by' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true]], 'computation_id');
        $make('cluster_segments', ['item_id' => ['type' => 'VARCHAR', 'constraint' => 15], 'cluster_label' => ['type' => 'INTEGER', 'default' => 0], 'cluster_name' => ['type' => 'VARCHAR', 'constraint' => 30],
            'abc_class' => ['type' => 'VARCHAR', 'constraint' => 1, 'null' => true], 'service_level' => ['type' => 'REAL', 'null' => true], 'z_score' => ['type' => 'REAL', 'null' => true]], 'item_id');

        $this->db->table('users')->insert(['username' => 'owner1', 'full_name' => 'Owner', 'role' => 'Admin', 'is_active' => 1, 'password_hash' => 'x']);

        // Sales: P-BIG sells by far the most (a sales-value Pareto would call it class A),
        // but the K-Means run put it in class C. P-NONE has sales but was never classified.
        $year = date('Y');
        $items = [
            'P-BIG'  => ['Big Seller', 100000, 'C', 0.30],
            'P-MID'  => ['Middle Item', 5000, 'B', 0.20],
            'P-SMALL' => ['Small Item', 1000, 'A', 0.10],
            'P-NONE' => ['Never Classified', 800, null, 0.05],
        ];
        $this->db->table('sales_transaction')->insert(['sale_date' => "{$year}-03-10 10:00:00"]);
        $saleId = (int) $this->db->insertID();
        foreach ($items as $id => [$name, $value, $class, $demand]) {
            $this->db->table('products')->insert(['item_id' => $id, 'item_name' => $name, 'unit_cost' => 100]);
            $this->db->table('sales_item')->insert(['sales_id' => $saleId, 'item_id' => $id, 'quantity_sold' => 1, 'selling_price' => $value]);
            if ($class !== null) {
                // What one dss:run saves: the class, its service level and Z-score in cluster_segments, and
                // a computation whose safety stock was worked out with that same Z-score.
                $this->db->table('cluster_segments')->insert(['item_id' => $id, 'cluster_name' => 'x', 'abc_class' => $class, 'service_level' => ['A' => 95, 'B' => 90, 'C' => 85][$class], 'z_score' => self::Z[$class]]);
                $this->db->table('pdss_computation')->insert(['item_id' => $id, 'avg_daily_demand' => $demand, 'service_level_z' => self::Z[$class], 'safety_stock' => 4]);
            } else {
                $this->db->table('pdss_computation')->insert(['item_id' => $id, 'avg_daily_demand' => $demand, 'service_level_z' => 1.645, 'safety_stock' => 2]);
            }
        }
    }

    protected function tearDown(): void
    {
        foreach (['users', 'notifications', 'products', 'sales_transaction', 'sales_item', 'pdss_computation', 'cluster_segments'] as $t) {
            \Config\Database::forge()->dropTable($t, true);
        }
        $this->db->setPrefix($this->originalPrefix);
        parent::tearDown();
    }

    private function page(): DOMXPath
    {
        $html = (string) $this->withSession(['isLoggedIn' => true, 'role' => 'Admin', 'user_id' => 1, 'username' => 'owner1', 'last_activity' => time()])
            ->get('admin/trend-analysis')->getBody();
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        return new DOMXPath($dom);
    }

    /** @return array<string, string> label => class letter ('—' when unclassified) */
    private function abcCardClasses(DOMXPath $x): array
    {
        $out = [];
        foreach ($x->query('//div[@data-tab-panel="all"]//div[contains(@class,"abc-row")]') as $row) {
            $name  = trim($x->query('.//span[contains(@class,"abc-name")]', $row)->item(0)->textContent);
            $badge = $x->query('.//span[contains(@class,"abc-cls")]', $row)->item(0)->textContent;
            $out[$name] = trim($badge);
        }

        return $out;
    }

    /** @return array<string, string> label => class letter ('—' when unclassified) */
    private function dailyDemandClasses(DOMXPath $x): array
    {
        $out = [];
        foreach ($x->query('//table[contains(@class,"var-table")]//tbody/tr') as $row) {
            $cells = $x->query('./td', $row);
            $out[trim($cells->item(1)->textContent)] = trim($cells->item(4)->textContent);
        }

        return $out;
    }

    public function testTheClassIsTheKMeansClassNotAParetoClass(): void
    {
        $classes = $this->abcCardClasses($this->page());

        // The biggest seller is class C because K-Means said so, not A because of its sales value.
        $this->assertSame('C', $classes['Big Seller']);
        $this->assertSame('B', $classes['Middle Item']);
        $this->assertSame('A', $classes['Small Item']);
        $this->assertSame('—', $classes['Never Classified']);
    }

    public function testAbcCardDailyDemandAndTheSavedRunAgreeForEveryItem(): void
    {
        $x      = $this->page();
        $card   = $this->abcCardClasses($x);
        $demand = $this->dailyDemandClasses($x);
        $byName = ['Big Seller' => 'P-BIG', 'Middle Item' => 'P-MID', 'Small Item' => 'P-SMALL'];
        $saved  = (new ClusterSegmentModel())->classByItem();

        foreach ($byName as $label => $itemId) {
            $this->assertSame($saved[$itemId], $card[$label], "ABC card class for {$label}");
            $this->assertSame($saved[$itemId], $demand[$label], "Daily demand badge for {$label}");

            // The Z-score the run used for the item's safety stock is the one its class stands for.
            $z = (float) $this->db->table('pdss_computation')->where('item_id', $itemId)->get()->getRow()->service_level_z;
            $this->assertEqualsWithDelta(self::Z[$saved[$itemId]], $z, 0.0001, "Z-score behind {$label}'s safety stock");
        }
        $this->assertSame('—', $demand['Never Classified']);
    }

    public function testTabCountsAreCountsOfKMeansClassesAndTheBarCountsItems(): void
    {
        $x    = $this->page();
        $tabs = [];
        foreach ($x->query('//button[contains(@class,"abc-tab")]') as $btn) {
            $tabs[] = preg_replace('/\s+/', ' ', trim($btn->textContent));
        }
        $this->assertSame(['All', 'A (1)', 'B (1)', 'C (1)'], $tabs);

        $body = $x->document->saveHTML();
        $this->assertStringContainsString('ABC classification (K-Means)', $body);
        $this->assertStringContainsString('Grouped by cost, demand, demand variability and lead time.', $body);
        $this->assertStringContainsString('Number of items in each class', $body);
        $this->assertStringContainsString('Highest priority, 95% service level', $body);
        $this->assertStringContainsString('Medium priority, 90% service level', $body);
        $this->assertStringContainsString('Lowest priority, 85% service level', $body);
        $this->assertStringContainsString('Sales share', $body);
        $this->assertStringContainsString('Run the DSS engine to classify this item.', $body);
        $this->assertStringNotContainsString('% of sales value', $body, 'K-Means classes are not an 80/15/5 split of sales value');
    }

    public function testEachTabListsItsClassBySalesOrder(): void
    {
        $x = $this->page();
        foreach (['A' => ['Small Item'], 'B' => ['Middle Item'], 'C' => ['Big Seller']] as $class => $names) {
            $found = [];
            foreach ($x->query('//div[@data-tab-panel="' . $class . '"]//span[contains(@class,"abc-name")]') as $n) {
                $found[] = trim($n->textContent);
            }
            $this->assertSame($names, $found, "class {$class} tab");
        }
    }

    public function testHelperReturnsOnlyRealClasses(): void
    {
        $this->db->table('cluster_segments')->insert(['item_id' => 'P-JUNK', 'cluster_name' => 'x', 'abc_class' => null, 'service_level' => null, 'z_score' => null]);

        $map = (new ClusterSegmentModel())->classByItem();

        $this->assertSame(['P-BIG' => 'C', 'P-MID' => 'B', 'P-SMALL' => 'A'], $map);
    }
}
