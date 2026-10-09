<?php

namespace App\Controllers\Supplier;

use App\Controllers\BaseController;
use App\Libraries\PasswordPolicy;
use App\Models\SecurityLogModel;
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
            'loginAccount' => $this->userModel->find((int) session()->get('user_id')),
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
            'error'    => session()->getFlashdata('error'),
        ];

        return view('supplier/profile', $data);
    }

    public function update()
    {
        $supplierId = session()->get('supplier_id');

        if (! $this->validate(['contact_email' => 'permit_empty|valid_email'])) {
            return redirect()->to('/supplier/profile')->with('error', implode(' ', $this->validator->getErrors()));
        }

        $this->supplierModel->update($supplierId, [
            'contact_person'    => $this->request->getPost('contact_person') ?: null,
            'contact_email'     => $this->request->getPost('contact_email') ?: null,
            'contact_number'    => $this->request->getPost('contact_number') ?: null,
            'preferred_courier' => $this->request->getPost('preferred_courier') ?: null,
        ]);

        return redirect()->to('/supplier/profile')->with('success', 'Profile updated.');
    }

    /**
     * The Admin creates the portal account, but from then on the supplier owns
     * its login email and password. Both changes re-check the current password.
     */
    public function updateLoginEmail()
    {
        $user = $this->userModel->find((int) session()->get('user_id'));

        if (! $user || ! password_verify((string) $this->request->getPost('current_password'), $user['password_hash'])) {
            SecurityLogModel::log('email_change_failed', null, 'wrong current password');
            return redirect()->to('/supplier/profile')->with('error', 'Current password is incorrect.');
        }

        if (! $this->validate([
            'login_email' => "required|valid_email|is_unique[users.email,user_id,{$user['user_id']}]",
        ])) {
            return redirect()->to('/supplier/profile')->with('error', implode(' ', $this->validator->getErrors()));
        }

        $this->userModel->update($user['user_id'], ['email' => trim((string) $this->request->getPost('login_email'))]);

        SecurityLogModel::log('email_changed', $user, 'supplier login email');

        return redirect()->to('/supplier/profile')->with('success', 'Login email updated.');
    }

    public function changePassword()
    {
        $user = $this->userModel->find((int) session()->get('user_id'));

        if (! $user || ! password_verify((string) $this->request->getPost('current_password'), $user['password_hash'])) {
            SecurityLogModel::log('password_change_failed', null, 'wrong current password');
            return redirect()->to('/supplier/profile')->with('error', 'Current password is incorrect.');
        }

        $newPass = (string) $this->request->getPost('new_password');

        if ($error = PasswordPolicy::check($newPass)) {
            return redirect()->to('/supplier/profile')->with('error', $error);
        }
        if ($newPass !== (string) $this->request->getPost('confirm_password')) {
            return redirect()->to('/supplier/profile')->with('error', 'Passwords do not match.');
        }

        $this->userModel->update($user['user_id'], ['password_hash' => password_hash($newPass, PASSWORD_DEFAULT)]);

        SecurityLogModel::log('password_changed', $user, 'supplier changed own password');

        return redirect()->to('/supplier/profile')->with('success', 'Password updated.');
    }
}
