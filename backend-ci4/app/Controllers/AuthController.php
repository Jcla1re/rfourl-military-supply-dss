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

    // Shows the login form
    public function login()
    {
        return view('auth/login');
    }

    // Handles the login form submission
    public function attemptLogin()
    {
        $username = $this->request->getPost('username');
        $password = $this->request->getPost('password');

        $user = $this->userModel->findByUsername($username);

        if (!$user) {
            return redirect()->back()->with('error', 'Incorrect username, please try again');
        }

        if (!$user['is_active']) {
            return redirect()->back()->with('error', 'This account has been deactivated');
        }

        if (!password_verify($password, $user['password_hash'])) {
            return redirect()->back()->with('error', 'Incorrect password, please try again');
        }

        // Success — store session data
        $session = session();
        $session->set([
            'user_id'      => $user['user_id'],
            'username'     => $user['username'],
            'full_name'    => $user['full_name'],
            'role'         => $user['role'],
            'supplier_id'  => $user['supplier_id'] ?? null,
            'isLoggedIn'   => true,
        ]);

        // Update last_login
        $this->userModel->update($user['user_id'], ['last_login' => date('Y-m-d H:i:s')]);

        // Redirect by role
        switch ($user['role']) {
            case 'Admin':
                return redirect()->to('/admin/dashboard');
            case 'Staff':
                return redirect()->to('/staff/dashboard');
            case 'Supplier':
                return redirect()->to('/supplier/dashboard');
            default:
                return redirect()->to('/login')->with('error', 'Unknown role');
        }
    }

    public function logout()
    {
        session()->destroy();
        return redirect()->to('/login');
    }
}