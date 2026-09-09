<?php
// app/Controllers/AuthController.php

namespace App\Controllers;

use App\Models\UserModel;

class AuthController extends BaseController
{
    protected $userModel;

    public function __construct()
    {
        $this->userModel = new UserModel();
    }

    public function landing()
    {
        return view('auth/landing');
    }

    // --- ADMIN ---
    public function showAdminLogin()
    {
        return view('auth/login_admin');
    }

    public function attemptAdminLogin()
    {
        $username = $this->request->getPost('username');
        $password = $this->request->getPost('password');

        $user = $this->userModel->findByUsername($username);

        if (!$user || $user['role'] !== 'Admin') {
            return redirect()->back()->with('error', 'Incorrect password. Please try again');
        }
        if (!$user['is_active']) {
            return redirect()->back()->with('error', 'This account has been deactivated');
        }
        if (!password_verify($password, $user['password_hash'])) {
            return redirect()->back()->with('error', 'Incorrect password. Please try again');
        }

        $this->logUserIn($user);
        return redirect()->to('/admin/dashboard');
    }

    // --- STAFF (password only, per your Figma design) ---
    public function showStaffLogin()
    {
        return view('auth/login_staff');
    }

    public function attemptStaffLogin()
    {
        $password = $this->request->getPost('password');

        // Design simplification: looks up the single active Staff-role account.
        // Flag to your panel if you expect multiple concurrent staff accounts.
        $user = $this->userModel->where('role', 'Staff')
                                 ->where('is_active', 1)
                                 ->first();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return redirect()->back()->with('error', 'Incorrect password. Please try again');
        }

        $this->logUserIn($user);
        return redirect()->to('/staff/dashboard');
    }

    // --- SUPPLIER ---
    public function showSupplierLogin()
    {
        return view('auth/login_supplier');
    }

    public function attemptSupplierLogin()
    {
        $username = $this->request->getPost('username');
        $password = $this->request->getPost('password');

        $user = $this->userModel->findByUsername($username);

        if (!$user || $user['role'] !== 'Supplier') {
            return redirect()->back()->with('error', 'Incorrect password. Please try again');
        }
        if (!password_verify($password, $user['password_hash'])) {
            return redirect()->back()->with('error', 'Incorrect password. Please try again');
        }

        $this->logUserIn($user);
        return redirect()->to('/supplier/dashboard');
    }

    private function logUserIn(array $user): void
    {
        session()->set([
            'user_id'     => $user['user_id'],
            'username'    => $user['username'],
            'full_name'   => $user['full_name'],
            'role'        => $user['role'],
            'supplier_id' => $user['supplier_id'] ?? null,
            'isLoggedIn'  => true,
        ]);
        $this->userModel->update($user['user_id'], ['last_login' => date('Y-m-d H:i:s')]);
    }

    public function logout()
    {
        session()->destroy();
        return redirect()->to('/');
    }
}