<?php
// app/Controllers/AuthController.php

namespace App\Controllers;

use App\Libraries\AttemptStore;
use App\Libraries\DemoGuard;
use App\Libraries\EmailNotifier;
use App\Libraries\PasswordPolicy;
use App\Libraries\LoginLockout;
use App\Models\NotificationModel;
use App\Models\SecurityLogModel;
use App\Models\UserModel;

class AuthController extends BaseController
{
    protected $userModel;
    protected LoginLockout $lockout;

    public function __construct()
    {
        $this->userModel = new UserModel();
        $this->lockout   = new LoginLockout();
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
        $locked = $this->lockout->secondsRemaining('admin');
        if ($locked > 0) {
            return redirect()->back()->with('error', "Too many tries. Wait {$locked} seconds.")->with('attempted_role', 'admin');
        }

        $username = $this->request->getPost('username');
        $password = $this->request->getPost('password');

        if (trim((string) $username) === '' || (string) $password === '') {
            return redirect()->back()->with('error', 'Please fill in all fields')->with('attempted_role', 'admin');
        }

        $user = $this->userModel->findByUsername($username);

        if (!$user || $user['role'] !== 'Admin') {
            return $this->failLogin('admin');
        }
        if (!password_verify($password, $user['password_hash'])) {
            return $this->failLogin('admin');
        }
        // Only revealed after the password is proven correct, so a stranger
        // can't use this message to discover which usernames exist.
        if (!$user['is_active']) {
            SecurityLogModel::log('login_blocked_deactivated', $user);
            return redirect()->back()->with('error', 'This account has been deactivated')->with('attempted_role', 'admin');
        }

        $this->lockout->reset('admin');
        $this->logUserIn($user);
        return redirect()->to('/admin/dashboard');
    }

    // --- STAFF (password only; each staff member has their own unique password, which identifies them) ---
    public function showStaffLogin()
    {
        return view('auth/login_staff');
    }

    public function attemptStaffLogin()
    {
        $locked = $this->lockout->secondsRemaining('staff');
        if ($locked > 0) {
            return redirect()->back()->with('error', "Too many tries. Wait {$locked} seconds.")->with('attempted_role', 'staff');
        }

        $password = $this->request->getPost('password');

        if ((string) $password === '') {
            return redirect()->back()->with('error', 'Please fill in all fields')->with('attempted_role', 'staff');
        }

        // No username: the password identifies the staff member. Only active,
        // non-removed Staff accounts are considered.
        $matches = $this->userModel->staffMatchingPassword((string) $password, true);

        if (count($matches) > 1) {
            // Old data can leave two accounts on the same password. Letting
            // either in could attribute sales to the wrong cashier, so nobody
            // signs in until the owner resets one of them.
            SecurityLogModel::log('login_blocked_shared_password', ['role' => 'Staff', 'username' => null], count($matches) . ' active staff accounts share this password');

            return redirect()->back()
                ->with('error', "This password can't be used. Ask the owner to reset it.")
                ->with('attempted_role', 'staff');
        }

        if (count($matches) === 0) {
            return $this->failLogin('staff');
        }

        $user = $matches[0];

        // A temporary password stops working after its time is up (2 hours for a reset, 48 for a new account).
        if (\App\Libraries\StaffPasswordReset::isExpired($user)) {
            SecurityLogModel::log('login_blocked_temp_expired', $user);

            return redirect()->back()
                ->with('error', 'This temporary password has expired. Ask the owner for a new one.')
                ->with('attempted_role', 'staff');
        }

        $this->lockout->reset('staff');
        $this->logUserIn($user);

        // Temporary password: they must pick their own before anything else.
        if (\App\Libraries\StaffPasswordReset::isPending($user)) {
            return redirect()->to('/staff/set-password');
        }

        return redirect()->to('/staff/dashboard');
    }

    // --- SUPPLIER ---
    public function showSupplierLogin()
    {
        return view('auth/login_supplier');
    }

    public function attemptSupplierLogin()
    {
        $locked = $this->lockout->secondsRemaining('supplier');
        if ($locked > 0) {
            return redirect()->back()->with('error', "Too many tries. Wait {$locked} seconds.")->with('attempted_role', 'supplier');
        }

        $username = $this->request->getPost('username');
        $password = $this->request->getPost('password');

        if (trim((string) $username) === '' || (string) $password === '') {
            return redirect()->back()->with('error', 'Please fill in all fields')->with('attempted_role', 'supplier');
        }

        $user = $this->userModel->findByUsername($username);

        if (!$user || $user['role'] !== 'Supplier') {
            return $this->failLogin('supplier');
        }
        if (!password_verify($password, $user['password_hash'])) {
            return $this->failLogin('supplier');
        }
        if (! $this->isAccountActive($user)) {
            SecurityLogModel::log('login_blocked_deactivated', $user);
            $supplier = (new \App\Models\SupplierModel())->find($user['supplier_id']);
            $message  = ($supplier && ! $supplier['is_active'])
                ? 'This supplier account is archived. Please contact the owner.'
                : 'This account has been deactivated';
            return redirect()->back()->with('error', $message)->with('attempted_role', 'supplier');
        }

        $this->lockout->reset('supplier');
        $this->logUserIn($user);
        return redirect()->to('/supplier/dashboard');
    }

    /**
     * Records a failed login attempt for this role and returns the
     * appropriate redirect: the usual generic message, or the lockout
     * countdown once MAX_ATTEMPTS is reached.
     */
    private function failLogin(string $role)
    {
        $this->lockout->registerFailure($role);
        $seconds = $this->lockout->secondsRemaining($role);

        SecurityLogModel::log(
            $seconds > 0 ? 'login_locked' : 'login_failed',
            ['role' => ucfirst($role), 'username' => $this->request->getPost('username')],
            "{$role} login"
        );

        if ($seconds > 0) {
            return redirect()->to("/login/{$role}/forgot")
                ->with('error', "Too many tries. Wait {$seconds} seconds or reset your password.");
        }

        $message = 'Incorrect password. Please try again';

        return redirect()->back()->with('error', $message)->with('attempted_role', $role);
    }

    /** A user is active only if their own flag is on and, for suppliers, the supplier record is too. */
    private function isAccountActive(array $user): bool
    {
        if (! $user['is_active']) {
            return false;
        }
        if ($user['role'] === 'Supplier') {
            $supplier = (new \App\Models\SupplierModel())->find($user['supplier_id']);
            return $supplier && $supplier['is_active'];
        }

        return true;
    }

    private function logUserIn(array $user): void
    {
        // New session ID on login so a pre-login session ID can't be reused
        // by an attacker (session fixation).
        session()->regenerate(true);
        session()->set([
            'user_id'     => $user['user_id'],
            'username'    => $user['username'],
            'full_name'   => $user['full_name'],
            'role'        => $user['role'],
            'supplier_id' => $user['supplier_id'] ?? null,
            'isLoggedIn'  => true,
            'last_activity' => time(),
        ]);
        $this->userModel->update($user['user_id'], ['last_login' => date('Y-m-d H:i:s')]);
        SecurityLogModel::log('login_success', $user);
    }

    public function logout()
    {
        SecurityLogModel::log('logout');
        session()->destroy();
        return redirect()->to('/');
    }

    // --- FORGOT PASSWORD (Admin & Supplier only — the Owner resets a staff member's password from Settings > User Accounts) ---

    private const RESET_ROLES = ['admin' => 'Admin', 'supplier' => 'Supplier'];

    // A 6-digit code only has 1,000,000 possibilities, so it is burned after
    // a handful of wrong guesses and a new one must be requested.
    private const OTP_MAX_ATTEMPTS = 5;

    /** True if this client is still within its allowance for the given action. */
    private function withinLimit(string $name, string $subject, int $capacity, int $seconds): bool
    {
        return AttemptStore::hit($name . '_' . md5(strtolower($subject)), $seconds) <= $capacity;
    }

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

        // Keyed on what was typed (not on whether the account exists), so the
        // limit reveals nothing and stops anyone flooding an inbox with codes.
        if (! $this->withinLimit('otp_ip', $this->request->getIPAddress(), 5, 600)
            || ! $this->withinLimit('otp_mail', $email, 3, 600)) {
            return redirect()->to("/login/{$role}/forgot")->with('error', 'Too many requests. Please wait a few minutes and try again.');
        }

        $user  = $this->userModel->where('role', self::RESET_ROLES[$role])
            ->where('email', $email)
            ->where('is_active', 1)
            ->first();

        // Always show the same message so we don't reveal whether an email is registered.
        $genericMessage = 'If that email is registered, a verification code has been sent to it.';

        if (! $user) {
            return redirect()->to("/login/{$role}/forgot")->with('error', $genericMessage);
        }

        // Demo mode: the demo accounts' passwords can't be reset.
        if (DemoGuard::locksUser($user)) {
            return DemoGuard::refuse("/login/{$role}/forgot", 'error');
        }

        $otp = (string) random_int(100000, 999999);

        $this->userModel->update($user['user_id'], [
            'reset_otp_hash'    => password_hash($otp, PASSWORD_DEFAULT),
            'reset_otp_expires' => date('Y-m-d H:i:s', strtotime('+10 minutes')),
        ]);
        AttemptStore::forget('otp_attempts_' . $user['user_id']);

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
            'role'   => $role,
            'email'  => $user['email'] ?? '',
            'error'  => session()->getFlashdata('error'),
            'notice' => session()->getFlashdata('notice'),
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
            $attemptsKey = 'otp_attempts_' . $user['user_id'];

            if (AttemptStore::hit($attemptsKey, 600) >= self::OTP_MAX_ATTEMPTS) {
                $this->userModel->update($user['user_id'], ['reset_otp_hash' => null, 'reset_otp_expires' => null]);
                AttemptStore::forget($attemptsKey);
                SecurityLogModel::log('otp_locked', $user, 'too many wrong codes');

                return redirect()->to("/login/{$role}/otp")->with('error', 'Too many incorrect attempts. Please request a new code.');
            }

            return redirect()->to("/login/{$role}/otp")->with('error', "Incorrect code, please try again.");
        }

        AttemptStore::forget('otp_attempts_' . $user['user_id']);
        session()->set('pwd_reset_verified', true);

        return redirect()->to("/login/{$role}/reset-password");
    }

    public function resendOtp($role)
    {
        if (! $this->hasPendingReset($role)) {
            return redirect()->to("/login/{$role}/forgot");
        }

        $user = $this->userModel->find(session()->get('pwd_reset_user_id'));

        if (DemoGuard::locksUser($user)) {
            return DemoGuard::refuse("/login/{$role}/forgot", 'error');
        }

        if (! $this->withinLimit('otp_resend', (string) $user['user_id'], 3, 600)) {
            return redirect()->to("/login/{$role}/otp")->with('error', 'Too many requests. Try again in a few minutes.');
        }

        $otp = (string) random_int(100000, 999999);

        $this->userModel->update($user['user_id'], [
            'reset_otp_hash'    => password_hash($otp, PASSWORD_DEFAULT),
            'reset_otp_expires' => date('Y-m-d H:i:s', strtotime('+10 minutes')),
        ]);
        AttemptStore::forget('otp_attempts_' . $user['user_id']);

        $delivered = $this->deliverOtpEmail($user, $otp);
        $redirect  = redirect()->to("/login/{$role}/otp");

        if ($delivered) {
            return $redirect->with('notice', 'A new code has been sent.');
        }

        // The owner has nobody above them to contact; a supplier contacts the shop.
        return $redirect->with('error', $role === 'supplier'
            ? "We couldn't send the code to your email right now. Try again in a few minutes. If it keeps happening, contact RfourL Military Supply."
            : "We couldn't send the code to your email right now. Check your internet connection and try again in a few minutes.");
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

        if ($error = PasswordPolicy::check($newPass)) {
            return redirect()->to("/login/{$role}/reset-password")->with('error', $error);
        }
        if ($newPass !== $confirm) {
            return redirect()->to("/login/{$role}/reset-password")->with('error', 'Passwords do not match.');
        }

        $userId = session()->get('pwd_reset_user_id');

        if (DemoGuard::locksUser($this->userModel->find($userId))) {
            return DemoGuard::refuse("/login/{$role}/forgot", 'error');
        }

        $this->userModel->update($userId, [
            'password_hash'     => password_hash($newPass, PASSWORD_DEFAULT),
            'reset_otp_hash'    => null,
            'reset_otp_expires' => null,
        ]);

        $resetUser = $this->userModel->find($userId);
        SecurityLogModel::log('password_reset', $resetUser, 'via emailed code');

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
        // Each request emails the Owner, so cap how often one client can send it.
        if (! $this->withinLimit('staff_forgot', $this->request->getIPAddress(), 3, 600)) {
            return redirect()->to('/login/staff/forgot')->with('error', 'Too many requests. Please wait a few minutes and try again.');
        }

        $staffName = trim((string) $this->request->getPost('staff_name'));
        $reason    = trim((string) $this->request->getPost('reason'));

        if ($staffName === '') {
            return redirect()->to('/login/staff/forgot')->with('error', 'Please enter your name.');
        }

        $reasonText = $reason !== '' ? $reason : 'No reason provided.';

        // Random per-notification token so the email's Approve/Decline
        // buttons can act without an active admin session — validated like
        // a one-time "magic link" rather than tied to who's logged in.
        $actionToken = bin2hex(random_bytes(24));

        // Match the typed name (or username) to ONE active staff account. The answer is never
        // shown to the person asking, so this can't be used to find out who has an account.
        $needle  = mb_strtolower($staffName);
        $matches = $this->userModel->activeStaff()->where('is_active', 1)
            ->groupStart()->where('LOWER(full_name)', $needle)->orWhere('LOWER(username)', $needle)->groupEnd()
            ->findAll();
        $matched = count($matches) === 1 ? $matches[0] : null;

        // Demo mode: the demo staff account's password can't be reset.
        if (DemoGuard::locksUser($matched)) {
            return DemoGuard::refuse('/login/staff/forgot', 'error');
        }

        $notifications  = new NotificationModel();
        $notificationId = $notifications->push(
            'Admin',
            'Access Request',
            "Password reset requested by {$staffName}",
            $reasonText,
            null,
            'access_request',
            null,
            $actionToken
        );
        if ($matched) {
            try {
                $notifications->update($notificationId, ['action_user_id' => (int) $matched['user_id']]);
            } catch (\Throwable $e) {
                log_message('error', 'Reset request not linked to an account (run php spark migrate): ' . $e->getMessage());
            }
        }
        $matchLine = $matched
            ? "Matches staff account: {$matched['full_name']} (@{$matched['username']})."
            : "No single staff account matches this name. Use Reset Password in Settings > Manage Accounts instead.";

        $approveUrl = site_url("notification-action/{$notificationId}/{$actionToken}/approve");
        $declineUrl = site_url("notification-action/{$notificationId}/{$actionToken}/decline");

        // A locked-out Staff member needs a fast response — email the
        // Owner/Admin directly instead of waiting for them to notice the
        // in-app notification bell. HTML with real buttons; the link itself
        // only opens a confirm page (see NotificationActionController) so an
        // email security scanner pre-fetching the link can't silently act on
        // it — only an explicit click on that page's confirm button can.
        $staffNameHtml = esc($staffName, 'html');
        $reasonHtml    = esc($reasonText, 'html');

        $matchHtml   = esc($matchLine, 'html');
        $htmlMessage = "<div style=\"font-family:'Poppins',Arial,sans-serif;\"><p>{$staffNameHtml} can't log in and is requesting a password reset.</p>"
            . "<p><strong>Reason given:</strong> {$reasonHtml}</p>"
            . "<p>{$matchHtml}</p>"
            . '<p>Check with them, by call or chat, that they really sent this request. Approving emails them a temporary password.</p>'
            . '<p style="margin:24px 0;">'
            . "<a href=\"{$approveUrl}\" style=\"background:#2f6431;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;margin-right:12px;display:inline-block;\">Approve reset</a>"
            . "<a href=\"{$declineUrl}\" style=\"background:#7a2020;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block;\">Decline</a>"
            . '</p>'
            . '<p style="color:#888;font-size:12px;">Or sign in and open Notifications.</p></div>';

        $plainText = "{$staffName} can't log in and is requesting a password reset.\n\n"
            . "Reason given: {$reasonText}\n\n{$matchLine}\n\n"
            . "Check with them, by call or chat, that they really sent this request. Approving emails them a temporary password.\n\n"
            . "Approve reset: {$approveUrl}\n"
            . "Decline: {$declineUrl}\n\n"
            . "Or sign in and open Notifications to approve or decline this request.";

        (new EmailNotifier())->toRoleHtml(
            'Admin',
            "Staff password reset requested by {$staffName}",
            $htmlMessage,
            $plainText
        );

        return redirect()->to('/login/staff/forgot')->with('sent', true);
    }

    private function hasPendingReset(string $role): bool
    {
        return session()->get('pwd_reset_role') === $role && session()->get('pwd_reset_user_id');
    }

    /**
     * Sends the OTP by email. Returns true if actually emailed. The code is
     * never shown on-screen (except in demo mode); on failure it is only logged (without the code).
     */
    private function deliverOtpEmail(array $user, string $otp): bool
    {
        // Demo mode: testers see the code on the next page, so they don't need the inbox.
        DemoGuard::reveal('Demo code', $otp);

        if (empty($user['email'])) {
            log_message('error', "OTP not sent: no email on file for user {$user['user_id']}");
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
            log_message('error', "OTP email could not be delivered for user {$user['user_id']}");
        }

        return $sent;
    }
}
