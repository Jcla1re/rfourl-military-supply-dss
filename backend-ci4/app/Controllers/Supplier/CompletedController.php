<?php

namespace App\Controllers\Supplier;

use App\Controllers\BaseController;
use App\Models\SoItemModel;
use App\Models\StockOrderModel;

class CompletedController extends BaseController
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
        $range      = $this->request->getGet('range') ?: 'month';

        $orders = $this->stockOrderModel->forSupplier($supplierId, ['Delivered']);

        $cutoff = match ($range) {
            '3months' => date('Y-m-d', strtotime('-3 months')),
            'all'     => null,
            default   => date('Y-m-01'),
        };

        if ($cutoff) {
            $orders = array_values(array_filter($orders, fn ($o) => $o['actual_delivery_date'] >= $cutoff));
        }

        foreach ($orders as &$o) {
            $lines             = $this->soItemModel->forOrder($o['so_id']);
            $o['lines']        = $lines;
            $o['total_units']  = array_sum(array_column($lines, 'order_quantity'));
            $expected          = $o['expected_delivery_date'] ?? $o['actual_delivery_date'];
            $diffDays          = (strtotime($o['actual_delivery_date']) - strtotime($expected)) / 86400;
            $o['on_time']      = $diffDays <= 0;
            $o['days_late']    = max(0, (int) round($diffDays));
        }
        unset($o);

        usort($orders, fn ($a, $b) => strcmp($b['actual_delivery_date'], $a['actual_delivery_date']));

        // Cancelled orders (by RfourL, or declined by this supplier) are listed apart from deliveries,
        // so they never count in the delivery stats.
        $cancelled = $this->stockOrderModel->forSupplier($supplierId, ['Cancelled']);
        $cancelled = array_values(array_filter($cancelled, fn ($o) => ! $cutoff || substr($o['cancelled_at'] ?? $o['order_date'], 0, 10) >= $cutoff));
        foreach ($cancelled as &$c) {
            $lines            = $this->soItemModel->forOrder($c['so_id']);
            $c['lines']       = $lines;
            $c['total_units'] = array_sum(array_column($lines, 'order_quantity'));
            $c['note']        = StockOrderModel::supplierCancelNote($c);
        }
        unset($c);

        $onTimeCount = count(array_filter($orders, fn ($o) => $o['on_time']));

        $data = [
            'title'         => 'Completed',
            'subtitle'      => 'Delivered this month',
            'active'        => 'completed',
            'orders'        => $orders,
            'cancelledOrders' => $cancelled,
            'range'         => $range,
            'completedCount'=> count($orders),
            'itemsDelivered'=> array_sum(array_column($orders, 'total_units')),
            'onTimeRate'    => $orders ? round(($onTimeCount / count($orders)) * 100) : 0,
            'onTimeCount'   => $onTimeCount,
        ];

        return view('supplier/completed', $data);
    }
}
