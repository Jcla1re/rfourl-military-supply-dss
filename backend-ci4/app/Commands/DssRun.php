<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Computes EOQ, safety stock, reorder point, ABC classification and
 * demand-variability clusters for every active product, then raises
 * reorder alerts where current_stock has fallen to/below the computed
 * reorder point.
 *
 * Demand is measured over a `demand_lookback_days`-day window ending on
 * the latest sale_date in the data (not today's server date), since this
 * runs against a historical/synthetic dataset rather than a live feed.
 */
class DssRun extends BaseCommand
{
    protected $group       = 'DSS';
    protected $name        = 'dss:run';
    protected $description = 'Runs the EOQ / safety stock / reorder point / ABC / clustering computation engine.';

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
        $orderingCost   = (float) $dssParams['ordering_cost'];
        $holdingCost    = (float) $dssParams['holding_cost_per_unit'];
        $zScore         = (float) $dssParams['z_score'];
        $lookbackDays   = (int) $dssParams['demand_lookback_days'];

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

        // --- Load full-history revenue per item (for ABC) ---
        $revRows = $db->table('sales_item si')
            ->select('si.item_id, SUM(si.quantity_sold * si.selling_price) AS revenue')
            ->groupBy('si.item_id')
            ->get()->getResultArray();
        $revenueByItem = [];
        foreach ($revRows as $r) {
            $revenueByItem[$r['item_id']] = (float) $r['revenue'];
        }
        arsort($revenueByItem);
        $totalRevenue = array_sum($revenueByItem);

        $abcByItem = [];
        $cumulative = 0.0;
        foreach ($revenueByItem as $itemId => $rev) {
            $cumulative += $rev;
            $pct = $totalRevenue > 0 ? $cumulative / $totalRevenue : 1;
            $abcByItem[$itemId] = $pct <= 0.80 ? 'A' : ($pct <= 0.95 ? 'B' : 'C');
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

        $computationRows = [];
        $alertRows       = [];
        $clusterRows     = [];
        $ropByItem       = [];
        $demandStats     = [];

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

            $totalQty      = array_sum($series);
            $avgDaily      = $totalQty / $lookbackDays;
            $variance      = 0.0;
            foreach ($series as $v) {
                $variance += ($v - $avgDaily) ** 2;
            }
            $stdDev        = sqrt($variance / $lookbackDays);
            $annualDemand  = (int) round($avgDaily * 365);
            $leadTime      = $leadByItem[$itemId] ?? 7;

            $safetyStock   = (int) ceil(max(0, $zScore * $stdDev * sqrt($leadTime)));
            $reorderPoint  = (int) ceil(($avgDaily * $leadTime) + $safetyStock);
            $eoq           = ($annualDemand > 0 && $holdingCost > 0)
                ? (int) round(sqrt((2 * $annualDemand * $orderingCost) / $holdingCost))
                : 0;

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

            $ropByItem[$itemId] = $reorderPoint;
            $demandStats[$itemId] = ['avg' => $avgDaily, 'std' => $stdDev];

            if ((int) $p['current_stock'] <= $reorderPoint) {
                $alertRows[$itemId] = [
                    'item_id'          => $itemId,
                    'trigger_reason'   => $reorderPoint > 0
                        ? "Stock ({$p['current_stock']}) at/below reorder point ({$reorderPoint})"
                        : 'No recent demand signal — review manually',
                    'recommended_eoq'  => $eoq,
                    'stock_at_trigger' => (int) $p['current_stock'],
                    'status'           => 'Open',
                ];
            }
        }

        // --- Clustering: K-Means via the Python ml-service (unit_cost, avg_daily_demand, demand_std_dev, lead_time_days) ---
        $unitCostByItem = array_column($products, 'unit_cost', 'item_id');
        $clusterItems = [];
        foreach ($demandStats as $itemId => $stat) {
            $clusterItems[] = [
                'item_id'          => $itemId,
                'unit_cost'        => (float) ($unitCostByItem[$itemId] ?? 0),
                'avg_daily_demand' => $stat['avg'],
                'demand_std_dev'   => $stat['std'],
                'lead_time_days'   => $leadByItem[$itemId] ?? 7,
            ];
        }
        $clusterRows = $this->fetchClusters($clusterItems);
        if ($clusterRows === null) {
            return;
        }

        // --- Persist ---
        $db->transStart();

        foreach (array_chunk($computationRows, 200) as $chunk) {
            $db->table('pdss_computation')->insertBatch($chunk);
        }

        foreach ($abcByItem as $itemId => $grade) {
            $db->table('products')->where('item_id', $itemId)->update(['abc_category' => $grade]);
        }
        foreach ($ropByItem as $itemId => $rop) {
            $db->table('products')->where('item_id', $itemId)->update(['manual_rop_warning' => $rop]);
        }

        $db->table('cluster_segments')->truncate();
        foreach (array_chunk($clusterRows, 200) as $chunk) {
            $db->table('cluster_segments')->insertBatch($chunk);
        }

        $existingOpenItems = $db->table('reorder_alert')->select('item_id')->where('status', 'Open')->get()->getResultArray();
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

        $db->transComplete();

        if ($db->transStatus() === false) {
            CLI::error('DSS run failed — transaction rolled back.');
            return;
        }

        CLI::write('DSS engine run complete.', 'green');
        CLI::write('Computations written: ' . count($computationRows));
        CLI::write('ABC — A: ' . count(array_filter($abcByItem, static fn ($g) => $g === 'A'))
            . ', B: ' . count(array_filter($abcByItem, static fn ($g) => $g === 'B'))
            . ', C: ' . count(array_filter($abcByItem, static fn ($g) => $g === 'C')));
        CLI::write('Clusters written: ' . count($clusterRows));
        CLI::write('New reorder alerts raised: ' . count($newAlerts) . ' (skipped ' . (count($alertRows) - count($newAlerts)) . ' already-open)');
    }

    /**
     * Calls the Python K-Means microservice's /cluster endpoint and maps
     * its response into cluster_segments rows. Returns null (after
     * printing an error) if the service is unreachable or rejects the
     * request, so the caller can abort the whole run rather than persist
     * partial results with no clustering.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>|null
     */
    private function fetchClusters(array $items): ?array
    {
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
            ];
        }
        return $rows;
    }
}
