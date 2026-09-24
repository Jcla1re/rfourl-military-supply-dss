<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\PdssComputationModel;
use App\Models\ProductModel;
use App\Models\SalesItemModel;
use App\Models\SalesTransactionModel;

class TrendAnalysisController extends BaseController
{
    protected $salesTransactionModel;
    protected $salesItemModel;
    protected $productModel;
    protected $pdssComputationModel;

    public function __construct()
    {
        $this->salesTransactionModel = new SalesTransactionModel();
        $this->salesItemModel        = new SalesItemModel();
        $this->productModel          = new ProductModel();
        $this->pdssComputationModel  = new PdssComputationModel();
    }

    public function index()
    {
        $year   = (int) ($this->request->getGet('year') ?? date('Y'));
        $itemId = $this->request->getGet('item') ?: null;

        $monthTotals     = $this->monthlyTotals($year, $itemId);
        $monthTotalsPrev = $this->monthlyTotals($year - 1, $itemId);

        // Weekly chart: anchored to the latest actual sale date within the
        // selected year/item (not the server's real-world "today") — this
        // is historical/seeded data, so "today" often has no relationship
        // to whichever year or item is selected.
        $weekAsOf = $this->salesItemModel->maxSaleDateInYear($year, $itemId);
        $weekAsOf = $weekAsOf ? substr($weekAsOf, 0, 10) : "{$year}-12-31";

        $thisWeek = $this->weekSeries($weekAsOf, $itemId);
        $lastWeekEnd = (new \DateTime($weekAsOf))->modify('-7 days')->format('Y-m-d');
        $lastWeek = $this->weekSeries($lastWeekEnd, $itemId);

        $products = $this->productModel->where('is_active', 1)->orderBy('item_name', 'ASC')->findAll();

        $computations = [];
        foreach ($this->pdssComputationModel->latestPerItem() as $c) {
            $computations[$c['item_id']] = $c;
        }

        // Demand Variability panel: only the best-selling items for the
        // selected year (not every product ever computed), so the table
        // stays readable and relevant to the period being viewed.
        $topN = 8;
        $bestSellersThisYear = $this->salesItemModel->topSellers($topN, $year);
        $variability = [];
        foreach ($bestSellersThisYear as $row) {
            if (isset($computations[$row['item_id']])) {
                $c = $computations[$row['item_id']];
                $variability[] = [
                    'item_name'        => $row['item_name'] ?? $row['item_id'],
                    'avg_daily_demand' => $c['avg_daily_demand'],
                    'safety_stock'     => $c['safety_stock'],
                ];
            }
        }

        // ABC Classification panel: classic Pareto analysis (% contribution
        // to total sales value) for the selected year, ranked highest-revenue
        // first, split into A (top 80%), B (next 15%), C (remaining 5%).
        $revenueRows  = $this->salesItemModel->revenueByItem($year);
        $totalRevenue = array_sum(array_column($revenueRows, 'revenue'));

        $abcClassTotals = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0];
        $abcItems       = [];
        $cumulative     = 0.0;
        foreach ($revenueRows as $row) {
            $revenue = (float) $row['revenue'];
            $pct     = $totalRevenue > 0 ? ($revenue / $totalRevenue) * 100 : 0;
            $cumulative += $revenue;
            $cumPct  = $totalRevenue > 0 ? $cumulative / $totalRevenue : 1;
            $class   = $cumPct <= 0.80 ? 'A' : ($cumPct <= 0.95 ? 'B' : 'C');

            $abcClassTotals[$class] += $pct;
            $abcItems[] = [
                'item_name' => $row['item_name'] ?? $row['item_id'],
                'pct'       => $pct,
                'class'     => $class,
            ];
        }
        $abcTopItems = array_slice($abcItems, 0, $topN);

        // Per-class breakdown so the "A / B / C" filter tabs can show every
        // item in that class (not just whichever land in the mixed top-N
        // list above — Class A items dominate that list by definition,
        // since they're the highest individual % contributors).
        $abcClassCounts = ['A' => 0, 'B' => 0, 'C' => 0];
        $abcByClass     = ['A' => [], 'B' => [], 'C' => []];
        foreach ($abcItems as $item) {
            $abcClassCounts[$item['class']]++;
            $abcByClass[$item['class']][] = $item;
        }
        $maxPerClass = 15;
        foreach ($abcByClass as $cls => $list) {
            $abcByClass[$cls] = array_slice($list, 0, $maxPerClass);
        }

        $topSellers = $this->salesItemModel->topSellers(5);
        $maxUnits   = $topSellers ? max(array_column($topSellers, 'units_sold')) : 1;

        $data = [
            'title'          => 'Trend Analysis',
            'active'         => 'trend_analysis',
            'years'          => range((int) date('Y'), (int) date('Y') - 3),
            'selectedYear'   => $year,
            'products'       => $products,
            'selectedItem'   => $itemId,
            'annualLabels'   => ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
            'annualData'     => array_values($monthTotals),
            'annualDataPrev' => array_values($monthTotalsPrev),
            'annualPeak'     => max($monthTotals),
            'weekLabels'     => $thisWeek['labels'],
            'weekData'       => $thisWeek['data'],
            'weekDataPrev'   => $lastWeek['data'],
            'weekAsOf'       => $weekAsOf,
            'topSellers'     => $topSellers,
            'maxUnits'       => $maxUnits ?: 1,
            'variability'    => $variability,
            'abcClassTotals' => $abcClassTotals,
            'abcTopItems'    => $abcTopItems,
            'abcByClass'     => $abcByClass,
            'abcClassCounts' => $abcClassCounts,
            'totalStock'     => array_sum(array_column($products, 'current_stock')),
            'salesToday'     => $this->salesTransactionModel->totalForToday(),
        ];

        return view('admin/trend_analysis', $data);
    }

    /**
     * Units sold per month (index 1-12) for one year, optionally scoped to
     * a single item — powers the Annual Demand chart's "This Year" /
     * "Last Year" datasets.
     */
    private function monthlyTotals(int $year, ?string $itemId): array
    {
        $builder = db_connect()->table('sales_item si')
            ->select("MONTH(st.sale_date) as m, SUM(si.quantity_sold) as total")
            ->join('sales_transaction st', 'st.sales_id = si.sales_id')
            ->where('YEAR(st.sale_date)', $year)
            ->groupBy('m');

        if ($itemId) {
            $builder->where('si.item_id', $itemId);
        }

        $totals = array_fill(1, 12, 0);
        foreach ($builder->get()->getResultArray() as $row) {
            $totals[(int) $row['m']] = (int) $row['total'];
        }

        return $totals;
    }

    /**
     * 7-day units-sold series ending on $endDate, optionally scoped to one
     * item — powers the Weekly Sales Trend chart's "This Week" / "Last
     * Week" datasets.
     *
     * @return array{labels: string[], data: int[]}
     */
    private function weekSeries(string $endDate, ?string $itemId): array
    {
        $end   = new \DateTime($endDate);
        $start = (clone $end)->modify('-6 days');

        $rows = $this->salesItemModel->dailyQuantities($start->format('Y-m-d'), $end->format('Y-m-d'), $itemId);
        $byDate = [];
        foreach ($rows as $r) {
            $byDate[$r['d']] = (int) $r['qty'];
        }

        $labels = [];
        $data   = [];
        $cursor = clone $start;
        while ($cursor <= $end) {
            $labels[] = $cursor->format('D');
            $data[]   = $byDate[$cursor->format('Y-m-d')] ?? 0;
            $cursor->modify('+1 day');
        }

        return ['labels' => $labels, 'data' => $data];
    }
}
