<?php
// app/Controllers/Staff/ReorderAlertController.php

namespace App\Controllers\Staff;

use App\Controllers\BaseController;
use App\Libraries\ReorderBoard;
use App\Models\ProductModel;
use App\Models\ReorderAlertModel;
use App\Models\ReorderNoticeModel;

/**
 * Staff Reorder Alerts: the Owner's board (App\Libraries\ReorderBoard, same sizes and
 * numbers) with Staff's one action, Notify Owner. Staff can't order, dismiss or restore.
 */
class ReorderAlertController extends BaseController
{
    use NotifiesOwner;

    public function index()
    {
        $data = ReorderBoard::build($this->request->getGet() ?? [], session()->get('previous_login') ?: null);

        $data += [
            'title'   => 'Reorder Alerts',
            'active'  => 'reorder_alerts',
            'role'    => 'staff',
            // Sizes already notified (any staff account or page): "Owner notified", no second Notify Owner.
            'notices' => (new ReorderNoticeModel())->openFor(ReorderBoard::itemIds($data)),
            'success' => session()->getFlashdata('success'),
            'error'   => session()->getFlashdata('error'),
        ];

        return view('staff/reorder_alerts', $data);
    }

    public function notifyAdmin(string $alertId)
    {
        // The alert row has no name: the notice is about its size's product row.
        $alert   = (new ReorderAlertModel())->find((int) $alertId);
        $product = $alert ? (new ProductModel())->find($alert['item_id']) : null;
        if (! $product) {
            return redirect()->to('/staff/reorder-alerts');
        }

        return $this->notifyOwnerAbout($product, redirect()->to('/staff/reorder-alerts'));
    }
}
