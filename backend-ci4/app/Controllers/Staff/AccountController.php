<?php

namespace App\Controllers\Staff;

use App\Controllers\BaseController;
use App\Libraries\AttemptStore;
use App\Libraries\DemoGuard;
use App\Libraries\EmailNotifier;
use App\Libraries\PasswordHistory;
use App\Libraries\PasswordPolicy;
use App\Libraries\StaffPasswordAlert;
use App\Libraries\Toast;
use App\Models\SecurityLogModel;
use App\Models\UserModel;

/**
 * Staff "My Account": their details, changing their own password, and editing their own profile
 * (name, contact number, email). The username and role stay with the owner. A new email only takes
 * effect after a code sent to that address is entered, so it is known to be a real inbox.
 */
class AccountController extends BaseController
{
    /** Password-change attempts allowed per account in the window below. */
    private const MAX_ATTEMPTS   = 5;
    private const WINDOW_SECONDS = 600;

    /** The email-change code: valid 10 minutes, 5 wrong tries (same as the forgot-password code). */
    private const CODE_MINUTES = 10;
    private const CODE_TRIES   = 5;
    private const PENDING_KEY  = 'pending_email_change';

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
            'notice'   => session()->getFlashdata('notice'),
            'pendingEmail' => $this->pending()['email'] ?? null,
        ]);
    }

    /** Name, contact number and email. Only the email needs the current password and a code. */
    public function updateProfile()
    {
        $back   = '/staff/account';
        $userId = (int) session()->get('user_id');
        $user   = $this->userModel->activeStaff()->find($userId);

        if (! $user) {
            return redirect()->to($back)->with('error', 'Account not found.');
        }
        // Demo mode: the demo staff account's name and email stay as they are.
        if (DemoGuard::locksUser($user)) {
            return DemoGuard::refuse($back, 'error');
        }

        // Only these fields are read: the username and role can't be changed from here.
        if (! $this->validate([
            'full_name' => 'required|max_length[100]',
            'phone'     => 'permit_empty|max_length[20]',
            'email'     => "required|valid_email|max_length[100]|is_unique[users.email,user_id,{$userId}]",
        ], [
            'full_name' => ['required' => 'Enter your full name.', 'max_length' => 'Your name can be up to 100 characters.'],
            'phone'     => ['max_length' => 'The contact number can be up to 20 characters.'],
            'email'     => [
                'required'    => 'Enter your email address.',
                'valid_email' => 'Enter a real email address.',
                'max_length'  => 'The email can be up to 100 characters.',
                'is_unique'   => 'That email is already used by another account.',
            ],
        ])) {
            return redirect()->to($back)->with('error', implode(' ', $this->validator->getErrors()));
        }

        $name         = trim((string) $this->request->getPost('full_name'));
        $phone        = trim((string) $this->request->getPost('phone'));
        $email        = trim((string) $this->request->getPost('email'));
        $emailChanged = strcasecmp($email, (string) ($user['email'] ?? '')) !== 0;

        if ($emailChanged) {
            // Counted before the password is checked, so an open session can't be used to guess it.
            if (AttemptStore::hit('staff_email_change_' . $userId, self::WINDOW_SECONDS) > self::CODE_TRIES) {
                return redirect()->to($back)->with('error', 'Too many attempts. Please wait a few minutes and try again.');
            }
            if (! password_verify((string) $this->request->getPost('current_password'), $user['password_hash'])) {
                SecurityLogModel::log('email_change_failed', $user, 'wrong current password');

                return redirect()->to($back)->with('error', 'Current password is incorrect.');
            }

            $code = (string) random_int(100000, 999999);
            if (! $this->sendCode($email, $user['full_name'], $code)) {
                return redirect()->to($back)->with('error', "We couldn't send the code to that address. Check it and try again.");
            }
            DemoGuard::reveal('Demo code', $code);
            session()->set(self::PENDING_KEY, [
                'email'   => $email,
                'hash'    => password_hash($code, PASSWORD_DEFAULT),
                'expires' => time() + self::CODE_MINUTES * 60,
                'tries'   => 0,
            ]);
        }

        // Name and number are saved now; the email waits for its code.
        $changes = [];
        if ($name !== (string) $user['full_name']) {
            $changes['full_name'] = $name;
        }
        if ($phone !== (string) ($user['phone'] ?? '')) {
            $changes['phone'] = $phone !== '' ? $phone : null;
        }
        if ($changes !== []) {
            $this->userModel->update($userId, $changes);
            if (isset($changes['full_name'])) {
                session()->set('full_name', $name);
            }
            SecurityLogModel::log('profile_updated', $user, 'staff updated own ' . implode(' and ', array_map(
                static fn ($c) => $c === 'full_name' ? 'name' : 'contact number',
                array_keys($changes)
            )));
        }

        if ($emailChanged) {
            return redirect()->to($back)->with('notice', "We sent a 6-digit code to {$email}. Enter it below to finish changing your email. It works for " . self::CODE_MINUTES . ' minutes.');
        }

        return redirect()->to($back)->with('toast', $changes === []
            ? Toast::info('Nothing to save', 'Your details are already up to date.')
            : Toast::success('Profile updated.', 'Your changes were saved.'));
    }

    /** The code from the new address: the email changes only if it is right. */
    public function confirmEmail()
    {
        $back   = '/staff/account';
        $userId = (int) session()->get('user_id');
        $user   = $this->userModel->activeStaff()->find($userId);
        $p      = $this->pending();

        if (! $user) {
            return redirect()->to($back)->with('error', 'Account not found.');
        }
        if (DemoGuard::locksUser($user)) {
            return DemoGuard::refuse($back, 'error');
        }
        if ($p === null) {
            session()->remove(self::PENDING_KEY);

            return redirect()->to($back)->with('error', 'That code has expired. Enter your new email again to get a new one.');
        }

        $code = trim((string) $this->request->getPost('code'));

        if (! password_verify($code, $p['hash'])) {
            $p['tries']++;
            if ($p['tries'] >= self::CODE_TRIES) {
                session()->remove(self::PENDING_KEY);
                SecurityLogModel::log('email_change_failed', $user, 'too many wrong codes');

                return redirect()->to($back)->with('error', 'Too many incorrect codes. Enter your new email again to get a new one.');
            }
            session()->set(self::PENDING_KEY, $p);

            return redirect()->to($back)->with('error', 'Incorrect code, please try again.');
        }

        // Someone else may have taken the address while the code was out.
        if ($this->userModel->where('email', $p['email'])->where('user_id !=', $userId)->countAllResults() > 0) {
            session()->remove(self::PENDING_KEY);

            return redirect()->to($back)->with('error', 'That email is already used by another account.');
        }

        $old = $user['email'] ?? null;
        $this->userModel->update($userId, ['email' => $p['email']]);
        session()->remove(self::PENDING_KEY);
        AttemptStore::forget('staff_email_change_' . $userId);
        SecurityLogModel::log('email_changed', $user, 'staff changed own email (code confirmed)');

        // The owner is told (with a Lock action), and the old address is warned.
        $fresh = $this->userModel->find($userId);
        StaffPasswordAlert::emailChanged($fresh, $old, $p['email']);
        if ($old !== null && $old !== '' && strcasecmp($old, $p['email']) !== 0) {
            (new EmailNotifier())->toUser(
                ['email' => $old],
                'Your RfourL account email was changed',
                "Your RfourL account email was changed. If this wasn't you, contact the owner."
            );
        }

        return redirect()->to($back)->with('toast', Toast::success('Email updated.', 'Your new email is ' . $p['email'] . '.'));
    }

    public function cancelEmail()
    {
        session()->remove(self::PENDING_KEY);

        return redirect()->to('/staff/account');
    }

    /** The email change waiting for its code, or null if there is none or it has run out. */
    private function pending(): ?array
    {
        $p = session()->get(self::PENDING_KEY);

        return is_array($p) && ! empty($p['email']) && ($p['expires'] ?? 0) >= time() ? $p : null;
    }

    private function sendCode(string $email, string $name, string $code): bool
    {
        $safeName = esc($name, 'html');
        $html     = "<div style=\"font-family:'Poppins',Arial,sans-serif;\"><p>Hi {$safeName},</p>"
            . '<p>Your RfourL Military Supply verification code is:</p>'
            . '<p style="font-size:22px;font-weight:bold;letter-spacing:2px;">' . $code . '</p>'
            . '<p>It works for ' . self::CODE_MINUTES . " minutes. If you didn't ask to use this email, ignore this message.</p></div>";
        $plain = "Hi {$name},\n\nYour RfourL Military Supply verification code is: {$code}\n\n"
            . 'It works for ' . self::CODE_MINUTES . " minutes. If you didn't ask to use this email, ignore this message.";

        return (new EmailNotifier())->sendHtml(['email' => $email], 'Your RfourL verification code', $html, $plain);
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
        // Not the current password, nor one of the last three they had before.
        if (PasswordHistory::reuses($user, $newPass)) {
            SecurityLogModel::log('password_reuse_blocked', $user, 'tried an earlier password');
            $suggestion = PasswordHistory::suggest($userId);

            return redirect()->to($back)
                ->with('error', "You can't use your old password. Try something new, like: {$suggestion}")
                ->with('password_suggestion', $suggestion);
        }
        // Staff sign in by password alone, so it must be unique (never say whose it is).
        if ($this->userModel->staffPasswordInUse($newPass, $userId)) {
            return redirect()->to($back)->with('error', "This password can't be used. Please choose a different one.");
        }

        PasswordHistory::record($userId, $user['password_hash']);
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
