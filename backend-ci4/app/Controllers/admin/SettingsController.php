<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\DssParameterModel;
use App\Models\UserModel;

class SettingsController extends BaseController
{
    protected $dssParameterModel;
    protected $userModel;

    public function __construct()
    {
        $this->dssParameterModel = new DssParameterModel();
        $this->userModel         = new UserModel();
    }

    public function index()
    {
        $tab = $this->request->getGet('tab') ?: 'profile';

        $data = [
            'title'         => 'Settings',
            'active'        => 'settings',
            'tab'           => $tab,
            'dss'           => $this->dssParameterModel->current(),
            'admin'         => $this->userModel->find(session()->get('user_id')),
            'staffAccounts' => $this->userModel->where('role', 'Staff')->findAll(),
            'success'       => session()->getFlashdata('success'),
            'error'         => session()->getFlashdata('error'),
        ];

        return view('admin/settings', $data);
    }

    public function updateDssParameters()
    {
        $rules = [
            'ordering_cost'         => 'required|numeric|greater_than_equal_to[0]',
            'holding_cost_per_unit' => 'required|numeric|greater_than_equal_to[0]',
            'service_level_target'  => 'required|numeric|greater_than[0]|less_than_equal_to[100]',
            'z_score'               => 'required|numeric',
            'demand_lookback_days'  => 'required|integer|greater_than[0]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->to('/admin/settings?tab=dss')->with('error', implode(' ', $this->validator->getErrors()));
        }

        $payload = [
            'ordering_cost'         => (float) $this->request->getPost('ordering_cost'),
            'holding_cost_per_unit' => (float) $this->request->getPost('holding_cost_per_unit'),
            'service_level_target'  => (float) $this->request->getPost('service_level_target'),
            'z_score'               => (float) $this->request->getPost('z_score'),
            'demand_lookback_days'  => (int) $this->request->getPost('demand_lookback_days'),
            'updated_by'            => session()->get('user_id'),
        ];

        $current = $this->dssParameterModel->current();

        if (! empty($current['id'])) {
            $this->dssParameterModel->update($current['id'], $payload);
        } else {
            $this->dssParameterModel->insert($payload);
        }

        return redirect()->to('/admin/settings?tab=dss')->with('success', 'Probabilistic model parameters updated.');
    }

    public function updateAccount()
    {
        $userId = session()->get('user_id');
        $user   = $this->userModel->find($userId);

        if (! $user) {
            return redirect()->to('/admin/settings')->with('error', 'Account not found.');
        }

        $update = [
            'full_name' => $this->request->getPost('full_name'),
            'email'     => $this->request->getPost('email') ?: null,
        ];

        $this->userModel->update($userId, $update);
        session()->set('full_name', $update['full_name']);

        return redirect()->to('/admin/settings?tab=profile')->with('success', 'Profile updated.');
    }

    public function changeOwnerPassword()
    {
        return $this->changePasswordFor((int) session()->get('user_id'), 'owner');
    }

    public function changeStaffPassword()
    {
        $staff = $this->userModel->where('role', 'Staff')->first();

        if (! $staff) {
            return redirect()->to('/admin/settings?tab=security')->with('error', 'No staff account found.');
        }

        return $this->changePasswordFor((int) $staff['user_id'], 'staff');
    }

    private function changePasswordFor(int $userId, string $label)
    {
        $newPass = $this->request->getPost('new_password');
        $confirm = $this->request->getPost('confirm_password');

        if (empty($newPass) || strlen($newPass) < 8) {
            return redirect()->to('/admin/settings?tab=security')->with('error', 'Password must be at least 8 characters.');
        }
        if ($newPass !== $confirm) {
            return redirect()->to('/admin/settings?tab=security')->with('error', 'Passwords do not match.');
        }

        $this->userModel->update($userId, ['password_hash' => password_hash($newPass, PASSWORD_DEFAULT)]);

        return redirect()->to('/admin/settings?tab=security')->with('success', ucfirst($label) . ' password updated.');
    }

    public function addStaffAccount()
    {
        $rules = [
            'full_name' => 'required',
            'username'  => 'required|is_unique[users.username]',
            'password'  => 'required|min_length[8]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->to('/admin/settings?tab=accounts')->with('error', implode(' ', $this->validator->getErrors()));
        }

        $confirm = $this->request->getPost('confirm_password');
        if ($this->request->getPost('password') !== $confirm) {
            return redirect()->to('/admin/settings?tab=accounts')->with('error', 'Passwords do not match.');
        }

        $this->userModel->insert([
            'username'      => $this->request->getPost('username'),
            'full_name'     => $this->request->getPost('full_name'),
            'email'         => $this->request->getPost('contact_email') ?: null,
            'password_hash' => password_hash($this->request->getPost('password'), PASSWORD_DEFAULT),
            'role'          => 'Staff',
            'is_active'     => 1,
        ]);

        return redirect()->to('/admin/settings?tab=accounts')->with('success', 'Staff account created.');
    }

    public function deactivateStaff($userId)
    {
        $user = $this->userModel->find((int) $userId);

        if (! $user || $user['role'] !== 'Staff') {
            return redirect()->to('/admin/settings?tab=accounts')->with('error', 'Staff account not found.');
        }

        $this->userModel->update((int) $userId, ['is_active' => $user['is_active'] ? 0 : 1]);

        return redirect()->to('/admin/settings?tab=accounts')->with('success', $user['is_active'] ? 'Staff account deactivated.' : 'Staff account reactivated.');
    }
}
