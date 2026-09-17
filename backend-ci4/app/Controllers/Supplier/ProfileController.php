<?php

namespace App\Controllers\Supplier;

use App\Controllers\BaseController;
use App\Models\SoItemModel;
use App\Models\StockOrderModel;
use App\Models\SupplierModel;
use App\Models\UserModel;

class ProfileController extends BaseController
{
    protected $supplierModel;
    protected $stockOrderModel;
    protected $soItemModel;
    protected $userModel;

    public function __construct()
    {
        $this->supplierModel   = new SupplierModel();
        $this->stockOrderModel = new StockOrderModel();
        $this->soItemModel     = new SoItemModel();
        $this->userModel       = new UserModel();
    }

    public function index()
    {
        $supplierId = session()->get('supplier_id');
        $supplier   = $this->supplierModel->find($supplierId);
        $orders     = $this->stockOrderModel->forSupplier($supplierId);

        foreach ($orders as &$o) {
            $lines            = $this->soItemModel->forOrder($o['so_id']);
            $o['total_units'] = array_sum(array_column($lines, 'order_quantity'));
        }
        unset($o);

        $delivered     = array_values(array_filter($orders, fn ($o) => $o['status'] === 'Delivered'));
        $onTime        = count(array_filter($delivered, fn ($o) => strtotime($o['actual_delivery_date']) <= strtotime($o['expected_delivery_date'] ?? $o['actual_delivery_date'])));
        $completable   = array_values(array_filter($orders, fn ($o) => in_array($o['status'], ['Delivered', 'Cancelled'], true)));

        $monthDelivered = array_values(array_filter($delivered, fn ($o) => date('Y-m', strtotime($o['actual_delivery_date'])) === date('Y-m')));
        $monthOrders    = array_values(array_filter($orders, fn ($o) => date('Y-m', strtotime($o['order_date'])) === date('Y-m')));

        $data = [
            'title'    => 'My Profile',
            'subtitle' => 'Manage your account and preferences',
            'active'   => 'profile',
            'supplier' => $supplier,
            'stats'    => [
                'on_time_rate'     => $delivered ? round(($onTime / count($delivered)) * 100) : 0,
                'orders_completed' => count($monthDelivered) . ' / ' . count($monthOrders),
                'items_month'      => array_sum(array_column($monthDelivered, 'total_units')),
                'total_fulfilled'  => count($delivered) . ' / ' . count($completable),
                'items_total'      => array_sum(array_column($delivered, 'total_units')),
            ],
            'success'  => session()->getFlashdata('success'),
        ];

        return view('supplier/profile', $data);
    }

    public function update()
    {
        $supplierId = session()->get('supplier_id');

        $this->supplierModel->update($supplierId, [
            'contact_person'    => $this->request->getPost('contact_person') ?: null,
            'contact_email'     => $this->request->getPost('contact_email') ?: null,
            'contact_number'    => $this->request->getPost('contact_number') ?: null,
            'preferred_courier' => $this->request->getPost('preferred_courier') ?: null,
        ]);

        return redirect()->to('/supplier/profile')->with('success', 'Profile updated.');
    }
}
