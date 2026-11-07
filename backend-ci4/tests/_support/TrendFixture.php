<?php

namespace Tests\Support;

/**
 * A small but realistic store for the Trend Analysis tests: two years of sales (this year stops a
 * few months ago, so there is a run of empty months), 14 products in the three K-Means classes, an
 * unclassified one, and a DSS run. Runs on the in-memory 'tests' database, with MySQL's YEAR() and
 * MONTH() registered for SQLite. Use inside a test class that has DatabaseTestTrait.
 */
trait TrendFixture
{
    private string $trendOriginalPrefix = '';

    /** item_id => [name, size, class|null, avg daily demand, safety stock, units per sale] */
    private const TREND_ITEMS = [
        'P-01' => ['Patrol Shirt', 'M', 'A', 4.20, 18, 6],
        'P-02' => ['Combat Boots', '9', 'A', 3.10, 14, 4],
        'P-03' => ['Tactical Pants', 'L', 'A', 2.80, 12, 4],
        'P-04' => ['Name Tape', null, 'B', 1.60, 8, 5],
        'P-05' => ['Rank Insignia', null, 'B', 1.40, 7, 4],
        'P-06' => ['Belt Buckle', null, 'B', 1.10, 6, 3],
        'P-07' => ['Reflector Vest', 'XL', 'B', 0.90, 5, 3],
        'P-08' => ['Cap Device', null, 'B', 0.80, 5, 2],
        'P-09' => ['Whistle', null, 'C', 0.40, 3, 2],
        'P-10' => ['Lanyard', null, 'C', 0.35, 3, 2],
        'P-11' => ['Pin Set', null, 'C', 0.30, 2, 2],
        'P-12' => ['Patch Large', null, 'C', 0.25, 2, 1],
        'P-13' => ['Handcuff Case', null, 'C', 0.20, 2, 1],
        'P-14' => ['Never Classified', null, null, 0.10, 1, 1],
    ];

    protected function seedTrendFixture(): void
    {
        $this->trendOriginalPrefix = $this->db->DBPrefix;
        $this->db->setPrefix('');
        $this->db->connID->createFunction('YEAR', static fn ($d) => $d === null ? null : (int) substr((string) $d, 0, 4), 1);
        $this->db->connID->createFunction('MONTH', static fn ($d) => $d === null ? null : (int) substr((string) $d, 5, 2), 1);

        $forge = \Config\Database::forge();
        foreach (['users', 'notifications', 'products', 'sales_transaction', 'sales_item', 'pdss_computation', 'cluster_segments'] as $t) {
            $forge->dropTable($t, true);
        }
        $make = static function (string $name, array $fields, string $key) use ($forge) {
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

        $z     = ['A' => 1.645, 'B' => 1.282, 'C' => 1.036];
        $price = [];
        $n     = 0;
        foreach (self::TREND_ITEMS as $id => [$name, $size, $class, $demand, $safety]) {
            $price[$id] = 100 + $n * 40;
            $n++;
            $this->db->table('products')->insert(['item_id' => $id, 'item_name' => $name, 'size' => $size, 'unit_cost' => $price[$id] / 2]);
            if ($class !== null) {
                $this->db->table('cluster_segments')->insert(['item_id' => $id, 'cluster_name' => 'x', 'abc_class' => $class, 'service_level' => ['A' => 95, 'B' => 90, 'C' => 85][$class], 'z_score' => $z[$class]]);
            }
            $this->db->table('pdss_computation')->insert([
                'item_id' => $id, 'computed_at' => date('Y-m-d H:i:s', strtotime('-2 days')), 'avg_daily_demand' => $demand,
                'service_level_z' => $class ? $z[$class] : 1.645, 'safety_stock' => $safety,
            ]);
        }

        // Last year: every month. This year: January up to a few months ago, then nothing.
        $thisYear   = (int) date('Y');
        $lastMonth  = max(1, (int) date('n') - 3);
        $seasonal   = [1, 1, 2, 2, 3, 2, 1, 1, 2, 3, 4, 5];
        $sell = function (string $date, array $lines) use ($price): void {
            $this->db->table('sales_transaction')->insert(['sale_date' => $date . ' 10:00:00']);
            $saleId = (int) $this->db->insertID();
            foreach ($lines as $id => $qty) {
                $this->db->table('sales_item')->insert(['sales_id' => $saleId, 'item_id' => $id, 'quantity_sold' => $qty, 'selling_price' => $price[$id]]);
            }
        };
        foreach ([$thisYear - 1 => 12, $thisYear => $lastMonth] as $year => $months) {
            for ($m = 1; $m <= $months; $m++) {
                foreach ([3, 12, 21] as $day) {
                    $lines = [];
                    foreach (self::TREND_ITEMS as $id => [, , , , , $perSale]) {
                        if ($id === 'P-14' && $m > 2) {
                            continue;
                        }
                        $lines[$id] = max(1, (int) round($perSale * $seasonal[$m - 1] * ($year === $thisYear ? 0.8 : 1)));
                    }
                    $sell(sprintf('%d-%02d-%02d', $year, $m, $day), $lines);
                }
                // Busier at the end, so the weekly bars are not flat.
                if ($year === $thisYear && $m >= $months - 2) {
                    for ($day = 1; $day <= 21; $day += 2) {
                        $sell(sprintf('%d-%02d-%02d', $year, $m, $day), ['P-01' => 2 + ($day % 4), 'P-04' => 1 + ($day % 3)]);
                    }
                }
            }
        }
    }

    protected function dropTrendFixture(): void
    {
        foreach (['users', 'notifications', 'products', 'sales_transaction', 'sales_item', 'pdss_computation', 'cluster_segments'] as $t) {
            \Config\Database::forge()->dropTable($t, true);
        }
        $this->db->setPrefix($this->trendOriginalPrefix);
    }
}
