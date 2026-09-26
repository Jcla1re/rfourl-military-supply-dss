<?php
// app/Controllers/AuthController.php

namespace App\Controllers;

use App\Models\NotificationModel;
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

    // --- FORGOT PASSWORD (Admin & Supplier only — Staff shares one password reset from Settings) ---

    private const RESET_ROLES = ['admin' => 'Admin', 'supplier' => 'Supplier'];

    public function showForgotPassword($role)
    {
        if (! isset(self::RESET_ROLES[$role])) {
            return redirect()->to('/');
        }

        return view('auth/forgot_password', [
            'role'  => $role,
            'error' => session()->getFlashdata('error'),
        ]);
    }

    public function sendOtp($role)
    {
        if (! isset(self::RESET_ROLES[$role])) {
            return redirect()->to('/');
        }

        $email = trim((string) $this->request->getPost('email'));
        $user  = $this->userModel->where('role', self::RESET_ROLES[$role])
            ->where('email', $email)
            ->where('is_active', 1)
            ->first();

        // Always show the same message so we don't reveal whether an email is registered.
        $genericMessage = 'If that email is registered, a verification code has been sent to it.';

        if (! $user) {
            return redirect()->to("/login/{$role}/forgot")->with('error', $genericMessage);
        }

        $otp = (string) random_int(100000, 999999);

        $this->userModel->update($user['user_id'], [
            'reset_otp_hash'    => password_hash($otp, PASSWORD_DEFAULT),
            'reset_otp_expires' => date('Y-m-d H:i:s', strtotime('+10 minutes')),
        ]);

        $this->deliverOtpEmail($user, $otp);

        session()->set([
            'pwd_reset_user_id'  => $user['user_id'],
            'pwd_reset_role'     => $role,
            'pwd_reset_verified' => false,
        ]);

        return redirect()->to("/login/{$role}/otp");
    }

    public function showOtpVerify($role)
    {
        if (! $this->hasPendingReset($role)) {
            return redirect()->to("/login/{$role}/forgot");
        }

        $user = $this->userModel->find(session()->get('pwd_reset_user_id'));

        return view('auth/otp_verify', [
            'role'    => $role,
            'email'   => $user['email'] ?? '',
            'error'   => session()->getFlashdata('error'),
            'notice'  => session()->getFlashdata('notice'),
        ]);
    }

    public function verifyOtp($role)
    {
        if (! $this->hasPendingReset($role)) {
            return redirect()->to("/login/{$role}/forgot");
        }

        $code = trim((string) $this->request->getPost('otp'));
        $user = $this->userModel->find(session()->get('pwd_reset_user_id'));

        if (! $user || empty($user['reset_otp_hash']) || empty($user['reset_otp_expires'])) {
            return redirect()->to("/login/{$role}/otp")->with('error', 'Please request a new code.');
        }

        if (strtotime($user['reset_otp_expires']) < time()) {
            return redirect()->to("/login/{$role}/otp")->with('error', 'This code has expired. Please request a new one.');
        }

        if (! password_verify($code, $user['reset_otp_hash'])) {
            return redirect()->to("/login/{$role}/otp")->with('error', "Incorrect code, please try again.");
        }

        session()->set('pwd_reset_verified', true);

        return redirect()->to("/login/{$role}/reset-password");
    }

    public function resendOtp($role)
    {
        if (! $this->hasPendingReset($role)) {
            return redirect()->to("/login/{$role}/forgot");
        }

        $user = $this->userModel->find(session()->get('pwd_reset_user_id'));
        $otp  = (string) random_int(100000, 999999);

        $this->userModel->update($user['user_id'], [
            'reset_otp_hash'    => password_hash($otp, PASSWORD_DEFAULT),
            'reset_otp_expires' => date('Y-m-d H:i:s', strtotime('+10 minutes')),
        ]);

        $emailed = $this->deliverOtpEmail($user, $otp);
        $redirect = redirect()->to("/login/{$role}/otp");

        return $emailed ? $redirect->with('notice', 'A new code has been sent.') : $redirect;
    }

    public function showResetPassword($role)
    {
        if (! $this->hasPendingReset($role) || ! session()->get('pwd_reset_verified')) {
            return redirect()->to("/login/{$role}/forgot");
        }

        return view('auth/reset_password', [
            'role'  => $role,
            'error' => session()->getFlashdata('error'),
        ]);
    }

    public function resetPassword($role)
    {
        if (! $this->hasPendingReset($role) || ! session()->get('pwd_reset_verified')) {
            return redirect()->to("/login/{$role}/forgot");
        }

        $newPass = (string) $this->request->getPost('new_password');
        $confirm = (string) $this->request->getPost('confirm_password');

        if (strlen($newPass) < 8) {
            return redirect()->to("/login/{$role}/reset-password")->with('error', 'Password must be at least 8 characters.');
        }
        if ($newPass !== $confirm) {
            return redirect()->to("/login/{$role}/reset-password")->with('error', 'Passwords do not match.');
        }

        $userId = session()->get('pwd_reset_user_id');

        $this->userModel->update($userId, [
            'password_hash'     => password_hash($newPass, PASSWORD_DEFAULT),
            'reset_otp_hash'    => null,
            'reset_otp_expires' => null,
        ]);

        session()->remove(['pwd_reset_user_id', 'pwd_reset_role', 'pwd_reset_verified']);

        return redirect()->to("/login/{$role}")->with('success', 'Password reset. Please log in with your new password.');
    }

    // --- STAFF "Can't access your account?" (no self-service reset — notifies the Admin) ---

    public function showStaffForgot()
    {
        return view('auth/forgot_password_staff', [
            'sent'  => session()->getFlashdata('sent'),
            'error' => session()->getFlashdata('error'),
        ]);
    }

    public function notifyStaffForgot()
    {
        $staffName = trim((string) $this->request->getPost('staff_name'));
        $reason    = trim((string) $this->request->getPost('reason'));

        if ($staffName === '') {
            return redirect()->to('/login/staff/forgot')->with('error', 'Please enter your name.');
        }

        (new NotificationModel())->push(
            'Admin',
            'Access Request',
            "Password reset requested by {$staffName}",
            $reason !== '' ? $reason : 'No reason provided.',
            null,
            'access_request'
        );

        return redirect()->to('/login/staff/forgot')->with('sent', true);
    }

    private function hasPendingReset(string $role): bool
    {
        return session()->get('pwd_reset_role') === $role && session()->get('pwd_reset_user_id');
    }

    /**
     * Sends the OTP by email. Returns true if actually emailed; false if it fell
     * back to surfacing the code on-screen (no email on file, or SMTP not yet
     * configured in .env for this environment).
     */
    private function deliverOtpEmail(array $user, string $otp): bool
    {
        if (empty($user['email'])) {
            session()->setFlashdata('notice', "No email on file for this account. Your code is: {$otp}");
            return false;
        }

        $email = service('email');
        $email->setTo($user['email']);
        $email->setSubject('RfourL Military Supply — Your verification code');
        $email->setMessage(
            "Hi {$user['full_name']},\n\nYour verification code is: {$otp}\n\n" .
            "This code expires in 10 minutes. If you didn't request this, you can ignore this email."
        );

        $sent = false;
        try {
            $sent = $email->send();
        } catch (\Throwable $e) {
            log_message('error', 'OTP email failed: ' . $e->getMessage());
        }

        if (! $sent) {
            // SMTP isn't configured yet in this environment — surface the code so the
            // flow stays fully testable. Remove this fallback once real SMTP is set in .env.
            session()->setFlashdata('notice', "Email delivery isn't configured yet in this environment. Your code is: {$otp}");
        }

        return $sent;
    }
}