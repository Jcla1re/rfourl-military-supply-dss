<?php

namespace App\Controllers\Supplier;

use App\Controllers\BaseController;
use App\Libraries\SupplierScorecard;
use App\Models\SoItemModel;
use App\Models\StockOrderModel;
use App\Models\SupplierModel;

class DashboardController extends BaseController
{
    protected $stockOrderModel;
    protected $soItemModel;

    public function __construct()
    {
        $this->stockOrderModel = new StockOrderModel();
        $this->soItemModel     = new SoItemModel();
    }

    public function index()
    {
        $supplierId = session()->get('supplier_id');
        $orders     = $this->stockOrderModel->forSupplier($supplierId);
        $stated     = (int) ((new SupplierModel())->find($supplierId)['lead_time_days'] ?? 0);

        foreach ($orders as &$o) {
            $o['lines']       = $this->soItemModel->forOrder($o['so_id']);
            $o['total_units'] = array_sum(array_column($o['lines'], 'order_quantity'));
        }
        unset($o);

        $pending   = array_values(array_filter($orders, fn ($o) => $o['status'] === 'Order Confirmed'));
        $inTransit = array_values(array_filter($orders, fn ($o) => $o['status'] === 'Shipped'));
        $preparing = array_values(array_filter($orders, fn ($o) => $o['status'] === 'Preparing'));

        $deliveredThisMonth = array_values(array_filter($orders, function ($o) {
            return $o['status'] === 'Delivered'
                && ! empty($o['actual_delivery_date'])
                && date('Y-m', strtotime($o['actual_delivery_date'])) === date('Y-m');
        }));
        // On time is the one judgement the scorecard uses too (App\Libraries\SupplierScorecard::judge()).
        $onTimeThisMonth = count(array_filter($deliveredThisMonth, fn ($o) => SupplierScorecard::judge($o, $stated)['on_time'] === true));

        $totalUnitsThisMonth = array_sum(array_map(fn ($o) => $o['total_units'], $deliveredThisMonth));

        // Fewer than 5 received orders: no percentage, "Not enough orders yet" (the same rule as the Procurement Report scorecard).
        $rate         = SupplierScorecard::portalRate(array_values(array_filter($orders, fn ($o) => $o['status'] === 'Delivered')), $stated);

        $today = date('Y-m-d');
        $dueTodayOrSoon = array_values(array_filter($orders, function ($o) use ($today) {
            return in_array($o['status'], ['Order Confirmed', 'Preparing', 'Shipped'], true)
                && ! empty($o['expected_delivery_date'])
                && $o['expected_delivery_date'] >= $today
                && strtotime($o['expected_delivery_date']) <= strtotime('+2 days', strtotime($today));
        }));
        usort($dueTodayOrSoon, fn ($a, $b) => strcmp($a['expected_delivery_date'], $b['expected_delivery_date']));

        $schedule = array_values(array_filter($orders, fn ($o) => in_array($o['status'], ['Order Confirmed', 'Preparing', 'Shipped'], true)));
        usort($schedule, fn ($a, $b) => strcmp($a['expected_delivery_date'] ?? '9999', $b['expected_delivery_date'] ?? '9999'));

        $data = [
            'title'           => 'Dashboard',
            'active'          => 'dashboard',
            'alerts'          => array_slice($dueTodayOrSoon, 0, 3),
            'pendingOrders'   => $pending,
            'newOrdersCount'  => count($pending),
            'inTransitCount'  => count($inTransit),
            'completedMonth'  => count($deliveredThisMonth),
            'onTimeMonth'     => $onTimeThisMonth,
            'totalUnits'      => $totalUnitsThisMonth,
            'schedule'        => array_slice($schedule, 0, 5),
            'onTimeRate'      => $rate['pct'],
            'onTimeMessage'   => $rate['message'],
            'deliveredTotal'  => $rate['received'],
            'onTimeCount'     => $rate['on_time'],
            'leadTimeDays'    => (new SupplierModel())->find($supplierId)['lead_time_days'] ?? null,
            'today'           => $today,
        ];

        return view('supplier/dashboard', $data);
    }
}
