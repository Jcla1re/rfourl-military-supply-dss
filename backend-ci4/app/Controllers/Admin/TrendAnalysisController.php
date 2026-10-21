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
        $this->salesItemModel = new SalesItemModel();
        $this->productModel = new ProductModel();
        $this->pdssComputationModel = new PdssComputationModel();
    }

    private function buildData(): array
    {
        $year = (int) ($this->request->getGet('year') ?? date('Y'));
        $itemId = $this->request->getGet('item') ?: null;

        $monthTotals = $this->monthlyTotals($year, $itemId);
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

        // Active products plus anything that has sales history, so older items stay searchable.
        $products = $this->productModel
            ->where('is_active', 1)
            ->orWhere('item_id IN (SELECT DISTINCT item_id FROM sales_item)', null, false)
            ->orderBy('item_name', 'ASC')->findAll();

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
                    'item_id' => $row['item_id'],
                    'item_name' => $row['item_name'] ?? $row['item_id'],
                    'avg_daily_demand' => $c['avg_daily_demand'],
                    'safety_stock' => $c['safety_stock'],
                ];
            }
        }

        // ABC Classification panel: classic Pareto analysis (% contribution
        // to total sales value) for the selected year, ranked highest-revenue
        // first, split into A (top 80%), B (next 15%), C (remaining 5%).
        $revenueRows = $this->salesItemModel->revenueByItem($year);
        $totalRevenue = array_sum(array_column($revenueRows, 'revenue'));

        $abcClassTotals = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0];
        $abcItems = [];
        $cumulative = 0.0;
        foreach ($revenueRows as $i => $row) {
            $revenue = (float) $row['revenue'];
            $pct = $totalRevenue > 0 ? ($revenue / $totalRevenue) * 100 : 0;
            $cumulative += $revenue;
            $cumPct = $totalRevenue > 0 ? $cumulative / $totalRevenue : 1;
            $class = $cumPct <= 0.80 ? 'A' : ($cumPct <= 0.95 ? 'B' : 'C');

            $abcClassTotals[$class] += $pct;
            $name = $row['item_name'] ?? $row['item_id'];
            $size = trim((string) ($row['size'] ?? ''));
            $abcItems[] = [
                'rank' => $i + 1,
                'item_id' => $row['item_id'],
                'item_name' => $name,
                // Size variants are separate products, so show the size to tell them apart.
                'label' => $size !== '' ? "{$name} ({$size})" : $name,
                'code' => ($row['product_code'] ?? '') !== '' ? $row['product_code'] : $row['item_id'],
                'pct' => $pct,
                'class' => $class,
            ];
        }

        // Two rows can still read the same (no size on the product): those get their item code shown.
        $labelCounts = array_count_values(array_column($abcItems, 'label'));
        foreach ($abcItems as &$abcItem) {
            $abcItem['show_code'] = $labelCounts[$abcItem['label']] > 1;
        }
        unset($abcItem);

        // The PDF export keeps its short top-N list; the on-screen card lists every item ($abcItems).
        $abcTopItems = array_slice($abcItems, 0, $topN);

        // Per-class lists for the A / B / C tabs. Every item is listed; each keeps its
        // overall rank (the first B item shows its position among all items, not 1).
        $abcClassCounts = ['A' => 0, 'B' => 0, 'C' => 0];
        $abcByClass = ['A' => [], 'B' => [], 'C' => []];
        foreach ($abcItems as $item) {
            $abcClassCounts[$item['class']]++;
            $abcByClass[$item['class']][] = $item;
        }

        // Demand Variability: show each item's ABC class, highest daily demand first.
        $classByItem = array_column($abcItems, 'class', 'item_id');
        foreach ($variability as &$v) {
            $v['class'] = $classByItem[$v['item_id']] ?? null;
        }
        unset($v);
        usort($variability, fn($a, $b) => (float) $b['avg_daily_demand'] <=> (float) $a['avg_daily_demand']);

        // On-screen Demand variability card: EVERY item sold in the selected year that has a PDSS
        // computation (the PDF keeps the shorter $variability list above). Highest daily demand
        // first; ties go to the larger safety stock, then the name.
        $variabilityAll = [];
        foreach ($abcItems as $it) {
            $c = $computations[$it['item_id']] ?? null;
            if ($c === null) {
                continue;
            }
            $variabilityAll[] = [
                'label' => $it['label'],
                'code' => $it['code'],
                'show_code' => $it['show_code'],
                'avg_daily_demand' => (float) $c['avg_daily_demand'],
                'safety_stock' => (float) $c['safety_stock'],
                'class' => $it['class'],
            ];
        }
        usort($variabilityAll, fn($a, $b) => [$b['avg_daily_demand'], $b['safety_stock'], strtolower($a['label'])]
            <=> [$a['avg_daily_demand'], $a['safety_stock'], strtolower($b['label'])]);
        foreach ($variabilityAll as $i => &$v) {
            $v['rank'] = $i + 1;
        }
        unset($v);

        // ---- Trend cards + chart presentation (derived from the data above) ----
        $thisYear = (int) date('Y');
        // The current year's line stops at the current month: future months
        // are left empty (null), not plotted as 0.
        $monthsSoFar = $year < $thisYear ? 12 : ($year === $thisYear ? (int) date('n') : 0);
        $annualSoFar = [];
        foreach ($monthTotals as $m => $total) {
            $annualSoFar[] = $m <= $monthsSoFar ? $total : null;
        }

        // "Items sold in <year>": Jan 1 to today (or the whole year for past
        // years), compared with the same span a year earlier.
        $periodEnd = $year === $thisYear ? date('Y-m-d') : "{$year}-12-31";
        $prevEnd = (new \DateTime($periodEnd))->modify('-1 year')->format('Y-m-d');
        $unitsYtd = $this->unitsBetween("{$year}-01-01", $periodEnd, $itemId);
        $unitsPrev = $this->unitsBetween(($year - 1) . '-01-01', $prevEnd, $itemId);

        $bestMonth = null;
        for ($m = 1; $m <= $monthsSoFar; $m++) {
            if ($monthTotals[$m] > 0 && ($bestMonth === null || $monthTotals[$m] > $bestMonth['units'])) {
                $bestMonth = ['name' => date('F', mktime(0, 0, 0, $m, 1)), 'units' => $monthTotals[$m]];
            }
        }
        $topItem = $this->salesItemModel->topSellers(1, $year)[0] ?? null;

        $weekStart = (new \DateTime($weekAsOf))->modify('-6 days');

        $topSellers = $this->salesItemModel->topSellers(5);
        $maxUnits = $topSellers ? max(array_column($topSellers, 'units_sold')) : 1;

        $data = [
            'title' => 'Trend Analysis',
            'active' => 'trend_analysis',
            'years' => $this->yearsWithSales(),
            'selectedYear' => $year,
            'products' => $products,
            'selectedItem' => $itemId,
            'annualLabels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            'annualData' => $annualSoFar,
            'monthsSoFar' => $monthsSoFar,
            'annualDataPrev' => array_values($monthTotalsPrev),
            'annualPeak' => max($monthTotals),
            'weekLabels' => $thisWeek['labels'],
            'weekData' => $thisWeek['data'],
            'weekDataPrev' => $lastWeek['data'],
            'weekAsOf' => $weekAsOf,
            'weekRangeLabel' => self::dateRangeLabel($weekStart, new \DateTime($weekAsOf)),
            'unitsYtd' => $unitsYtd,
            'unitsPrev' => $unitsPrev,
            'changePct' => $unitsPrev > 0 ? (($unitsYtd - $unitsPrev) / $unitsPrev) * 100 : null,
            'periodLabel' => self::dateRangeLabel(new \DateTime("{$year}-01-01"), new \DateTime($periodEnd)),
            'bestMonth' => $bestMonth,
            'topItem' => $topItem,
            'topSellers' => $topSellers,
            'maxUnits' => $maxUnits ?: 1,
            'variability' => $variability,
            'variabilityAll' => $variabilityAll,
            'abcClassTotals' => $abcClassTotals,
            'abcTopItems' => $abcTopItems,
            'abcItems' => $abcItems,
            'abcByClass' => $abcByClass,
            'abcClassCounts' => $abcClassCounts,
            'totalStock' => array_sum(array_column(array_filter($products, fn($p) => (int) $p['is_active'] === 1), 'current_stock')),
            'salesToday' => $this->salesTransactionModel->totalForToday(),
        ];

        return $data;
    }

    public function index()
    {
        return view('admin/trend_analysis', $this->buildData());
    }

    public function pdf()
    {
        $data = $this->buildData();

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->loadHtml(view('admin/trend_analysis_pdf', $data));
        $dompdf->render();

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="trend-analysis-' . $data['selectedYear'] . '.pdf"')
            ->setBody($dompdf->output());
    }

    /** Every year that has sales (newest first), always including the current year. */
    private function yearsWithSales(): array
    {
        $rows = db_connect()->query('SELECT DISTINCT YEAR(sale_date) AS y FROM sales_transaction ORDER BY y DESC')->getResultArray();
        $years = array_map('intval', array_column($rows, 'y'));
        $years[] = (int) date('Y');
        $years = array_values(array_unique($years));
        rsort($years);

        return $years;
    }

    /**
     * Total units sold between two dates (inclusive), optionally for one item.
     */
    private function unitsBetween(string $start, string $end, ?string $itemId): int
    {
        return (int) array_sum(array_column($this->salesItemModel->dailyQuantities($start, $end, $itemId), 'qty'));
    }

    /**
     * Readable date range in the app's format: "Sep 24 – 30, 2026",
     * "Sep 28 – Oct 4, 2026" or "Dec 29, 2025 – Jan 4, 2026".
     */
    private static function dateRangeLabel(\DateTime $from, \DateTime $to): string
    {
        if ($from->format('Y') !== $to->format('Y')) {
            return $from->format('M j, Y') . ' – ' . $to->format('M j, Y');
        }
        if ($from->format('m') !== $to->format('m')) {
            return $from->format('M j') . ' – ' . $to->format('M j, Y');
        }
        if ($from->format('j') === $to->format('j')) {
            return $to->format('M j, Y');
        }

        return $from->format('M j') . ' – ' . $to->format('j, Y');
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
        $end = new \DateTime($endDate);
        $start = (clone $end)->modify('-6 days');

        $rows = $this->salesItemModel->dailyQuantities($start->format('Y-m-d'), $end->format('Y-m-d'), $itemId);
        $byDate = [];
        foreach ($rows as $r) {
            $byDate[$r['d']] = (int) $r['qty'];
        }

        $labels = [];
        $data = [];
        $cursor = clone $start;
        while ($cursor <= $end) {
            $labels[] = $cursor->format('D');
            $data[] = $byDate[$cursor->format('Y-m-d')] ?? 0;
            $cursor->modify('+1 day');
        }

        return ['labels' => $labels, 'data' => $data];
    }
}
