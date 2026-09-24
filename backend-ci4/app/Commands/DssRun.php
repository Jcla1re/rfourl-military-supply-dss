<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Computes EOQ, safety stock, reorder point, K-Means-driven ABC
 * classification, and per-class service-level Z-scores for every active
 * product, then raises reorder alerts where current_stock has fallen
 * to/below the computed reorder point.
 *
 * Demand is measured over a `demand_lookback_days`-day window ending on
 * the latest sale_date in the data (not today's server date), since this
 * runs against a historical/synthetic dataset rather than a live feed.
 *
 * Multi-criteria classification: K-Means (unit_cost, avg_daily_demand,
 * demand_std_dev, lead_time_days) groups items into 3 clusters via the
 * Python ml-service. The cluster ranked highest by demand becomes Class
 * A (95% service level), the middle B (90%), and the lowest C (85%) —
 * that per-item class and Z-score is what drives Safety Stock/ROP below,
 * not a single global Z-score. If the ml-service is unreachable, the
 * last cached classification in `cluster_segments` is reused instead of
 * aborting the whole run; only items with no cluster data at all (e.g.
 * before clustering has ever run) fall back to the admin-configured
 * default service level in `dss_parameters`.
 */
class DssRun extends BaseCommand
{
    protected $group       = 'DSS';
    protected $name        = 'dss:run';
    protected $description = 'Runs the EOQ / safety stock / reorder point / K-Means ABC classification engine.';

    /**
     * Base URL of the Python K-Means clustering microservice (ml-service/).
     * Override with the ML_SERVICE_URL env var if it runs elsewhere.
     */
    private string $mlServiceUrl;

    public function run(array $params)
    {
        $this->mlServiceUrl = env('ML_SERVICE_URL', 'http://127.0.0.1:5001');

        $db = db_connect();

        $dssParams = $db->table('dss_parameters')->get()->getRowArray();
        if (! $dssParams) {
            CLI::error('dss_parameters is empty — seed it first.');
            return;
        }
        $orderingCost     = (float) $dssParams['ordering_cost'];
        $holdingCost      = (float) $dssParams['holding_cost_per_unit'];
        $defaultZScore    = (float) $dssParams['z_score'];
        $lookbackDays     = (int) $dssParams['demand_lookback_days'];
        $minimumOrderQty  = (int) ($dssParams['minimum_order_qty'] ?? 5);

        $asOfRow = $db->table('sales_transaction')->select('MAX(sale_date) AS mx')->get()->getRowArray();
        if (empty($asOfRow['mx'])) {
            CLI::error('No sales_transaction data — cannot compute demand.');
            return;
        }
        $asOf = new \DateTime(substr($asOfRow['mx'], 0, 10));
        $windowStart = (clone $asOf)->modify('-' . ($lookbackDays - 1) . ' days');

        CLI::write("As-of date: {$asOf->format('Y-m-d')} (window: {$windowStart->format('Y-m-d')} to {$asOf->format('Y-m-d')}, {$lookbackDays} days)", 'yellow');

        // --- Load window sales, grouped per item per day ---
        $windowRows = $db->table('sales_item si')
            ->select('si.item_id, DATE(st.sale_date) AS d, SUM(si.quantity_sold) AS qty')
            ->join('sales_transaction st', 'st.sales_id = si.sales_id')
            ->where('DATE(st.sale_date) >=', $windowStart->format('Y-m-d'))
            ->where('DATE(st.sale_date) <=', $asOf->format('Y-m-d'))
            ->groupBy('si.item_id, d')
            ->get()->getResultArray();

        $dailyByItem = [];
        foreach ($windowRows as $r) {
            $dailyByItem[$r['item_id']][$r['d']] = (int) $r['qty'];
        }

        // --- Supplier lead times per item ---
        $leadRows = $db->table('products p')
            ->select('p.item_id, s.lead_time_days')
            ->join('suppliers s', 's.supplier_id = p.supplier_id', 'left')
            ->get()->getResultArray();
        $leadByItem = [];
        foreach ($leadRows as $r) {
            $leadByItem[$r['item_id']] = $r['lead_time_days'] !== null ? (int) $r['lead_time_days'] : 7;
        }

        $products = $db->table('products')->where('is_active', 1)->get()->getResultArray();

        // --- Pass 1: demand statistics per item (no Z-score dependency yet) ---
        $demandStats = [];
        foreach ($products as $p) {
            $itemId = $p['item_id'];
            $days   = $dailyByItem[$itemId] ?? [];

            $series = [];
            $cursor = clone $windowStart;
            while ($cursor <= $asOf) {
                $d = $cursor->format('Y-m-d');
                $series[] = $days[$d] ?? 0;
                $cursor->modify('+1 day');
            }

            $totalQty = array_sum($series);
            $avgDaily = $totalQty / $lookbackDays;
            $variance = 0.0;
            foreach ($series as $v) {
                $variance += ($v - $avgDaily) ** 2;
            }
            $stdDev = sqrt($variance / $lookbackDays);

            $demandStats[$itemId] = [
                'avg'    => $avgDaily,
                'std'    => $stdDev,
                'lead'   => $leadByItem[$itemId] ?? 7,
                'annual' => (int) round($avgDaily * 365),
            ];
        }

        // --- Multi-criteria K-Means classification (unit_cost, demand rate, ---
        // --- demand variability, lead time) -> per-item ABC class + Z-score ---
        $unitCostByItem = array_column($products, 'unit_cost', 'item_id');
        $clusterItems = [];
        foreach ($demandStats as $itemId => $stat) {
            $clusterItems[] = [
                'item_id'          => $itemId,
                'unit_cost'        => (float) ($unitCostByItem[$itemId] ?? 0),
                'avg_daily_demand' => $stat['avg'],
                'demand_std_dev'   => $stat['std'],
                'lead_time_days'   => $stat['lead'],
            ];
        }

        $liveClusters   = $this->fetchClusters($clusterItems);
        $classByItem    = [];
        $freshClusterRows = null;
        $usingCache     = false;

        if ($liveClusters !== null) {
            $freshClusterRows = $liveClusters;
            foreach ($liveClusters as $row) {
                $classByItem[$row['item_id']] = $row;
            }
            CLI::write('K-Means clustering service reachable — using live cluster assignment.', 'green');
        } else {
            $usingCache = true;
            $cached = $db->table('cluster_segments')->get()->getResultArray();
            foreach ($cached as $row) {
                $classByItem[$row['item_id']] = $row;
            }
            if ($cached) {
                CLI::write('Falling back to the last cached cluster assignment (' . count($cached) . ' items) — cluster_segments left untouched this run.', 'yellow');
            } else {
                CLI::write('No cached cluster data available either — every item will use the default service level from dss_parameters until the ML service is back up.', 'yellow');
            }
        }

        // --- Pass 2: EOQ / safety stock / reorder point using each item's own ---
        // --- class-based Z-score (falls back to the global default z_score) ---
        $computationRows = [];
        $alertRows       = [];
        $abcUpdates      = [];
        $classifiedCount = 0;
        $abcClassAgg     = [
            'A' => ['count' => 0, 'demand_sum' => 0.0],
            'B' => ['count' => 0, 'demand_sum' => 0.0],
            'C' => ['count' => 0, 'demand_sum' => 0.0],
        ];

        foreach ($products as $p) {
            $itemId = $p['item_id'];
            $stat   = $demandStats[$itemId];
            $cls    = $classByItem[$itemId] ?? null;

            $avgDaily     = $stat['avg'];
            $stdDev       = $stat['std'];
            $leadTime     = $stat['lead'];
            $annualDemand = $stat['annual'];

            $zScore = ($cls !== null && $cls['z_score'] !== null && $cls['z_score'] !== '')
                ? (float) $cls['z_score']
                : $defaultZScore;

            if ($cls !== null && ! empty($cls['abc_class'])) {
                $abcUpdates[$itemId] = $cls['abc_class'];
                $classifiedCount++;
                $abcClassAgg[$cls['abc_class']]['count']++;
                $abcClassAgg[$cls['abc_class']]['demand_sum'] += $avgDaily;
            }

            $safetyStock  = (int) ceil(max(0, $zScore * $stdDev * sqrt($leadTime)));
            $reorderPoint = (int) ceil(($avgDaily * $leadTime) + $safetyStock);
            $eoqRaw       = ($annualDemand > 0 && $holdingCost > 0)
                ? (int) round(sqrt((2 * $annualDemand * $orderingCost) / $holdingCost))
                : 0;
            // Floor at the supplier-side minimum order quantity — without
            // this, an item with zero recorded demand (out of stock the
            // whole lookback window, or newly added) computes EOQ = 0,
            // which recommends "order nothing" for an item that may well
            // need restocking right now.
            $eoq = max($eoqRaw, $minimumOrderQty);

            $computationRows[] = [
                'item_id'          => $itemId,
                'annual_demand'    => $annualDemand,
                'avg_daily_demand' => round($avgDaily, 4),
                'demand_std_dev'   => round($stdDev, 4),
                'lead_time_days'   => $leadTime,
                'service_level_z'  => $zScore,
                'safety_stock'     => $safetyStock,
                'reorder_point'    => $reorderPoint,
                'eoq_value'        => $eoq,
                'triggered_by'     => 'spark:dss:run',
            ];

            if ((int) $p['current_stock'] <= $reorderPoint) {
                $alertRows[$itemId] = [
                    'item_id'          => $itemId,
                    'trigger_reason'   => $reorderPoint > 0
                        ? "Stock ({$p['current_stock']}) at/below reorder point ({$reorderPoint})"
                        : 'No recent demand signal — review manually',
                    'recommended_eoq'  => $eoq,
                    'stock_at_trigger' => (int) $p['current_stock'],
                    'status'           => 'Active',
                ];
            }
        }

        // --- Persist ---
        $db->transStart();

        foreach (array_chunk($computationRows, 200) as $chunk) {
            $db->table('pdss_computation')->insertBatch($chunk);
        }

        foreach ($abcUpdates as $itemId => $grade) {
            $db->table('products')->where('item_id', $itemId)->update(['abc_category' => $grade]);
        }

        // Only rewrite the cluster cache when we actually got a fresh
        // response — if we're running on the cache fallback, truncating
        // it here would destroy the very data we just fell back to.
        if ($freshClusterRows !== null) {
            $db->table('cluster_segments')->truncate();
            foreach (array_chunk($freshClusterRows, 200) as $chunk) {
                $db->table('cluster_segments')->insertBatch($chunk);
            }
        }

        $existingOpenItems = $db->table('reorder_alert')->select('item_id')->whereIn('status', \App\Models\ReorderAlertModel::OPEN_STATUSES)->get()->getResultArray();
        $existingOpenItems = array_column($existingOpenItems, 'item_id');
        $newAlerts = [];
        foreach ($alertRows as $itemId => $row) {
            if (! in_array($itemId, $existingOpenItems, true)) {
                $newAlerts[] = $row;
            }
        }
        foreach (array_chunk($newAlerts, 200) as $chunk) {
            $db->table('reorder_alert')->insertBatch($chunk);
        }

        // --- BI reporting ledger (Data Dictionary Table 24) — persist a ---
        // --- summary of this run so historical trend data survives past ---
        // --- the "latest state" tables above. ---
        $snapshotUserId = (int) ($dssParams['updated_by'] ?? 1);
        $generatedAt    = date('Y-m-d H:i:s');
        $period         = $asOf->format('Y-m-d');

        $snapshotRows = [];
        foreach ($abcClassAgg as $class => $agg) {
            $snapshotRows[] = [
                'user_id'      => $snapshotUserId,
                'generated_at' => $generatedAt,
                'metric_type'  => 'abc_distribution',
                'period'       => $period,
                'metric_value' => $agg['count'],
                'raw_data'     => json_encode([
                    'class'            => $class,
                    'item_count'       => $agg['count'],
                    'avg_daily_demand' => $agg['count'] > 0 ? round($agg['demand_sum'] / $agg['count'], 4) : 0,
                    'source'           => $usingCache ? 'cached_cluster' : 'live_cluster',
                ]),
            ];
        }
        $snapshotRows[] = [
            'user_id'      => $snapshotUserId,
            'generated_at' => $generatedAt,
            'metric_type'  => 'reorder_alerts_raised',
            'period'       => $period,
            'metric_value' => count($newAlerts),
            'raw_data'     => json_encode([
                'new_alerts'          => count($newAlerts),
                'already_open_skipped' => count($alertRows) - count($newAlerts),
                'total_open_after_run' => count($existingOpenItems) + count($newAlerts),
            ]),
        ];
        $db->table('analytics_snapshot')->insertBatch($snapshotRows);

        $db->transComplete();

        if ($db->transStatus() === false) {
            CLI::error('DSS run failed — transaction rolled back.');
            return;
        }

        CLI::write('DSS engine run complete.', 'green');
        CLI::write('Computations written: ' . count($computationRows));
        CLI::write('Classified via K-Means (' . ($usingCache ? 'cached' : 'live') . '): ' . $classifiedCount . ' / ' . count($products)
            . ' (unclassified items used the default service level of ' . $defaultZScore . ')');
        CLI::write('Clusters ' . ($freshClusterRows !== null ? 'refreshed' : 'left as cached') . ': ' . count($classByItem));
        CLI::write('New reorder alerts raised: ' . count($newAlerts) . ' (skipped ' . (count($alertRows) - count($newAlerts)) . ' already-open)');
        CLI::write('Analytics snapshots written: ' . count($snapshotRows));
    }

    /**
     * Calls the Python K-Means microservice's /cluster endpoint and maps
     * its response into cluster_segments rows (including the abc_class /
     * service_level / z_score the service derives from each cluster's
     * demand rank). Returns null (after printing an error) if the
     * service is unreachable or rejects the request, so the caller can
     * fall back to the cached classification instead.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>|null
     */
    private function fetchClusters(array $items): ?array
    {
        if (count($items) < 3) {
            CLI::write('Fewer than 3 active products with demand data — skipping K-Means clustering this run.', 'yellow');
            return null;
        }

        $ch = curl_init($this->mlServiceUrl . '/cluster');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode(['items' => $items, 'n_clusters' => 3]),
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            CLI::error("Could not reach the K-Means clustering service at {$this->mlServiceUrl}: {$error}");
            CLI::error('Start it with: cd ml-service && python app.py (or set ML_SERVICE_URL).');
            return null;
        }

        $body = json_decode($response, true);
        if ($status !== 200 || ! isset($body['clusters'])) {
            $msg = $body['error'] ?? $response;
            CLI::error("Clustering service returned an error (HTTP {$status}): {$msg}");
            return null;
        }

        $rows = [];
        foreach ($body['clusters'] as $c) {
            $rows[] = [
                'item_id'       => $c['item_id'],
                'cluster_label' => (int) $c['cluster_label'],
                'cluster_name'  => $c['cluster_name'],
                'abc_class'     => $c['abc_class'] ?? null,
                'service_level' => isset($c['service_level']) ? $c['service_level'] * 100 : null,
                'z_score'       => $c['z_score'] ?? null,
            ];
        }
        return $rows;
    }
}
