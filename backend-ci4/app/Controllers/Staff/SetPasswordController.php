<?php

namespace App\Controllers\Staff;

use App\Controllers\BaseController;
use App\Libraries\AttemptStore;
use App\Libraries\PasswordPolicy;
use App\Libraries\StaffPasswordReset;
use App\Libraries\Toast;
use App\Models\SecurityLogModel;
use App\Models\UserModel;

/**
 * "Set your own password": the only page a staff member on a temporary
 * password can reach (RoleAuthFilter sends everything else here).
 */
class SetPasswordController extends BaseController
{
    private const MAX_ATTEMPTS   = 5;
    private const WINDOW_SECONDS = 600;

    private UserModel $users;

    public function __construct()
    {
        $this->users = new UserModel();
    }

    public function index()
    {
        $user = $this->pendingUser();
        if ($user === null) {
            return redirect()->to('/staff/dashboard');
        }
        if (StaffPasswordReset::isExpired($user)) {
            return $this->expired($user);
        }

        return view('staff/set_password', ['kind' => $user['temp_password_kind']]);
    }

    public function save()
    {
        $user = $this->pendingUser();
        if ($user === null) {
            return redirect()->to('/staff/dashboard');
        }
        if (StaffPasswordReset::isExpired($user)) {
            return $this->expired($user);
        }

        $back = '/staff/set-password';

        if (AttemptStore::hit('staff_set_pw_' . $user['user_id'], self::WINDOW_SECONDS) > self::MAX_ATTEMPTS) {
            return redirect()->to($back)->with('error', 'Too many attempts. Please wait a few minutes and try again.');
        }

        $newPass = (string) $this->request->getPost('new_password');

        if ($error = PasswordPolicy::check($newPass)) {
            return redirect()->to($back)->with('error', $error);
        }
        if ($newPass !== (string) $this->request->getPost('confirm_password')) {
            return redirect()->to($back)->with('error', 'Passwords do not match.');
        }
        if (password_verify($newPass, $user['password_hash'])) {
            return redirect()->to($back)->with('error', 'Choose a different password from the temporary one.');
        }
        // Staff sign in by password alone, so it must be unique (never say whose it is).
        if ($this->users->staffPasswordInUse($newPass, (int) $user['user_id'])) {
            return redirect()->to($back)->with('error', "This password can't be used. Please choose a different one.");
        }

        (new StaffPasswordReset())->complete($user, $newPass);
        AttemptStore::forget('staff_set_pw_' . $user['user_id']);
        session()->regenerate(true);

        return redirect()->to('/staff/dashboard')->with('toast', Toast::success('Password saved.', "You're all set. Use it next time you sign in."));
    }

    /** The signed-in staff member, only while they are still on a temporary password. */
    private function pendingUser(): ?array
    {
        $user = $this->users->activeStaff()->find((int) session()->get('user_id'));

        return $user && StaffPasswordReset::isPending($user) ? $user : null;
    }

    private function expired(array $user)
    {
        SecurityLogModel::log('login_blocked_temp_expired', $user);
        session()->remove(['isLoggedIn', 'user_id', 'role', 'username', 'full_name', 'last_activity']);

        return redirect()->to('/login/staff')->with('error', 'This temporary password has expired. Ask the owner for a new one.');
    }
}
