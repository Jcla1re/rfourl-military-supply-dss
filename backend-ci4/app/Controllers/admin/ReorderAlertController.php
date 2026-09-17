<?php


namespace App\Controllers\Admin;

use App\Controllers\BaseController;

class ReorderAlertController extends BaseController
{
    public function index()
    {
        $data = [
            'title' => 'Reorder Alerts',
            'active' => 'reorder_alerts',
            'alerts' => [],
            'lastUpdated' => '5 min ago',
        ];

        return view('admin/reorder_alerts', $data);
    }

    public function report()
    {
        $data = [
            'title' => 'Procurement Report',
            'active' => 'reorder_alerts',
        ];

        return view('admin/procurement_report', $data);
    }

    public function resolveAll()
    {
        session()->setFlashdata('success', 'All reorder alerts resolved.');
        return redirect()->to('/admin/reorder-alerts');
    }

    public function createOrder()
    {
        $data = $this->request->getPost();

        // save order logic here if needed
        session()->setFlashdata('success', 'Stock order created.');
        return redirect()->to('/admin/reorder-alerts');
    }
}