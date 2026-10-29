<?php

namespace App\Controllers\Staff;

use App\Controllers\BaseController;
use App\Libraries\AttemptStore;
use App\Libraries\DemoGuard;
use App\Libraries\PasswordPolicy;
use App\Libraries\StaffPasswordAlert;
use App\Libraries\Toast;
use App\Models\SecurityLogModel;
use App\Models\UserModel;

/** Staff "My Account": read-only details, plus changing their own password. */
class AccountController extends BaseController
{
    /** Password-change attempts allowed per account in the window below. */
    private const MAX_ATTEMPTS   = 5;
    private const WINDOW_SECONDS = 600;

    protected UserModel $userModel;

    public function __construct()
    {
        $this->userModel = new UserModel();
    }

    public function index()
    {
        return view('staff/account', [
            'title'    => 'My Account',
            'subtitle' => 'Your account',
            'active'   => 'account',
            'account'  => $this->userModel->find((int) session()->get('user_id')),
            'error'    => session()->getFlashdata('error'),
        ]);
    }

    public function changePassword()
    {
        $back   = '/staff/account';
        $userId = (int) session()->get('user_id');

        // Counted before the current password is checked, so an open session
        // can't be used to guess it.
        if (AttemptStore::hit('staff_pw_change_' . $userId, self::WINDOW_SECONDS) > self::MAX_ATTEMPTS) {
            return redirect()->to($back)->with('error', 'Too many attempts. Please wait a few minutes and try again.');
        }

        $user = $this->userModel->activeStaff()->find($userId);

        if (DemoGuard::locksUser($user)) {
            return DemoGuard::refuse($back, 'error');
        }

        if (! $user || ! password_verify((string) $this->request->getPost('current_password'), $user['password_hash'])) {
            SecurityLogModel::log('password_change_failed', $user ?: null, 'wrong current password');
            return redirect()->to($back)->with('error', 'Current password is incorrect.');
        }

        $current = (string) $this->request->getPost('current_password');
        $newPass = (string) $this->request->getPost('new_password');

        if ($error = PasswordPolicy::check($newPass)) {
            return redirect()->to($back)->with('error', $error);
        }
        if ($newPass !== (string) $this->request->getPost('confirm_password')) {
            return redirect()->to($back)->with('error', 'Passwords do not match.');
        }
        if ($newPass === $current) {
            return redirect()->to($back)->with('error', 'New password must be different from your current password.');
        }
        // Staff sign in by password alone, so it must be unique (never say whose it is).
        if ($this->userModel->staffPasswordInUse($newPass, $userId)) {
            return redirect()->to($back)->with('error', "This password can't be used. Please choose a different one.");
        }

        $this->userModel->update($userId, ['password_hash' => password_hash($newPass, PASSWORD_DEFAULT)]);
        session()->regenerate(true);
        AttemptStore::forget('staff_pw_change_' . $userId);
        SecurityLogModel::log('password_changed', $user, 'staff changed own password');
        // Always sent: the owner can't opt out of this one.
        StaffPasswordAlert::send($user, StaffPasswordAlert::deviceName());

        return redirect()->to($back)->with('toast', Toast::success(
            'Password updated.',
            'Use your new password next time you sign in.'
        ));
    }
}
