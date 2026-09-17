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
        $itemId = $this->request->getGet('item');

        $weekly = $this->salesTransactionModel->weeklyTotals();

        $db = db_connect();
        $builder = $db->table('sales_item si')
            ->select("MONTH(st.sale_date) as m, SUM(si.quantity_sold) as total")
            ->join('sales_transaction st', 'st.sales_id = si.sales_id')
            ->where('YEAR(st.sale_date)', $year)
            ->groupBy('m');
        if ($itemId) {
            $builder->where('si.item_id', $itemId);
        }
        $monthly = $builder->get()->getResultArray();
        $monthTotals = array_fill(1, 12, 0);
        foreach ($monthly as $row) {
            $monthTotals[(int) $row['m']] = (int) $row['total'];
        }

        $products = $this->productModel->where('is_active', 1)->orderBy('item_name', 'ASC')->findAll();

        $computations = [];
        foreach ($this->pdssComputationModel->latestPerItem() as $c) {
            $computations[$c['item_id']] = $c;
        }
        $variability = [];
        foreach ($products as $p) {
            if (isset($computations[$p['item_id']])) {
                $variability[] = [
                    'item_name'        => $p['item_name'],
                    'avg_daily_demand' => $computations[$p['item_id']]['avg_daily_demand'],
                    'safety_stock'     => $computations[$p['item_id']]['safety_stock'],
                ];
            }
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
            'annualPeak'     => max($monthTotals),
            'weekLabels'     => $weekly['labels'],
            'weekData'       => $weekly['data'],
            'topSellers'     => $topSellers,
            'maxUnits'       => $maxUnits ?: 1,
            'variability'    => $variability,
            'totalStock'     => array_sum(array_column($products, 'current_stock')),
            'salesToday'     => $this->salesTransactionModel->totalForToday(),
        ];

        return view('admin/trend_analysis', $data);
    }
}
