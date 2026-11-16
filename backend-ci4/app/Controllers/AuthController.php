<?php
// app/Controllers/AuthController.php

namespace App\Controllers;

use App\Libraries\AfterResponse;
use App\Libraries\AttemptStore;
use App\Libraries\DemoGuard;
use App\Libraries\EmailNotifier;
use App\Libraries\LoginLockout;
use App\Libraries\PasswordPolicy;
use App\Libraries\ReturnTo;
use App\Libraries\SavedCart;
use App\Libraries\StaffPasswordAlert;
use App\Libraries\StaffPasswordReset;
use App\Models\SecurityLogModel;
use App\Models\SupplierModel;
use App\Models\UserModel;
use Config\Auth;

/**
 * One sign-in page and one Forgot password flow for everyone (Owner, Staff, Supplier).
 *
 * Sign in: username + password; the account decides the portal. Nothing tells a wrong username
 * from a wrong password, by message or by time (a missing account is checked against DUMMY_HASH),
 * and nothing about the role is shown before the password is proven.
 *
 * Forgot password: 1 username or email, 2 "Check your email" (a 6-digit code), 3 new password, done.
 * Behind it: an Owner or Supplier gets a code; a Staff member gets a temporary password (and just
 * signs in with it). Every answer is the same whether the account exists and whatever its role:
 * an unknown or Staff identifier gets a "decoy" reset that behaves like a real one (a code that
 * never matches, the same tries, expiry and resend countdown). Emails go after the response
 * (AfterResponse), or the answer is padded to a fixed time where PHP can't do that.
 */
class AuthController extends BaseController
{
    public const WRONG         = 'Incorrect username or password.';
    public const LOCKED        = 'That was 3 wrong tries, so sign-in is locked. Wait %d seconds, or reset your password with "Forgot password?".';
    public const NETWORK_LOCKED = 'Too many wrong tries from this network, so sign-in is locked. Wait %d seconds, or reset your password with "Forgot password?".';

    /**
     * A bcrypt hash (cost 10, as PASSWORD_DEFAULT makes them) of a random string nobody knows. A
     * username with no account, or a code that doesn't exist, is checked against it so it takes as
     * long as a real check.
     */
    private const DUMMY_HASH = '$2y$10$/gqoD08NcC2Q5d4EhwtT6eYudryNmdXqW7ttYSYQwUZp5MVAGry5u';

    protected UserModel $userModel;

    public function __construct()
    {
        $this->userModel = new UserModel();
    }

    // ================================================================== sign in

    /** "/" and "/login": the sign-in form (with the idle notice after an idle sign-out). */
    public function showLogin()
    {
        $this->keepReturnTo();

        return view('auth/login');
    }

    public function attemptLogin()
    {
        $username = (string) $this->request->getPost('username');
        $password = (string) $this->request->getPost('password');
        $lockout  = new LoginLockout();

        // The username stays in the field after a wrong try (never the password).
        $back = redirect()->to('/login')->with('login_username', mb_substr(trim($username), 0, 50));

        if (($seconds = $lockout->secondsRemaining($username)) > 0) {
            return $this->lockedAnswer($back, $lockout, $username, $seconds);
        }
        if (trim($username) === '' || $password === '') {
            return $back->with('error', 'Please fill in all fields');
        }

        $user  = $this->userModel->findForLogin($username);
        $match = $this->passwordMatch($user, $password);

        if ($match === null) {
            return $this->failLogin($back, $lockout, $username, $user);
        }

        // From here on the password is proven, so the account's own situation may be shown.
        if (! $this->isAccountActive($user)) {
            return $back->with('error', $this->inactiveMessage($user));
        }

        if ($user['role'] === 'Staff') {
            // The temporary password emailed by Forgot password: its first use replaces the account's
            // password (the old one stops working) and leads to "Set your password".
            if ($match === 'temp') {
                if (StaffPasswordReset::waitingTempExpired($user)) {
                    SecurityLogModel::log('login_blocked_temp_expired', $user);

                    return $back->with('error', 'This temporary password has expired. Use "Forgot password?" to get a new one.');
                }
                $user = (new StaffPasswordReset())->promoteTemp($user);
            }

            // A temporary password stops working after its time is up (2 hours for Forgot password, 24 for an owner reset, 48 for a new account).
            if (StaffPasswordReset::isExpired($user)) {
                SecurityLogModel::log('login_blocked_temp_expired', $user);

                return $back->with('error', 'This temporary password has expired. Ask the owner for a new one.');
            }
        }

        $lockout->reset($username);
        $this->logUserIn($user);

        // Temporary password: they must pick their own before anything else.
        if ($user['role'] === 'Staff' && StaffPasswordReset::isPending($user)) {
            $this->afterSignIn('Staff'); // the page to return to is dropped

            return redirect()->to('/staff/set-password');
        }

        return redirect()->to($this->afterSignIn($user['role']));
    }

    /**
     * Which of the account's passwords this is: 'password', 'temp' (a Staff member's waiting
     * Forgot-password one) or null. Always two bcrypt checks per candidate, real or DUMMY_HASH, so
     * the time doesn't depend on whether the account exists, its role, or a waiting temporary
     * password. Tried as typed, then (when different) without spaces at the ends (copied from the
     * temporary-password email); a password that really has them still works as typed.
     */
    private function passwordMatch(?array $user, string $password): ?string
    {
        $candidates = [$password];
        $trimmed    = trim($password);
        if ($trimmed !== $password && $trimmed !== '') {
            $candidates[] = $trimmed;
        }

        $mainHash = $user['password_hash'] ?? '';
        $mainHash = $mainHash !== '' ? $mainHash : self::DUMMY_HASH;
        $tempHash = ($user !== null && $user['role'] === 'Staff' && ! empty($user['temp_password_hash']))
            ? $user['temp_password_hash'] : self::DUMMY_HASH;

        foreach ($candidates as $candidate) {
            $main = password_verify($candidate, $mainHash);
            $temp = password_verify($candidate, $tempHash);

            if ($user !== null && $main && $mainHash !== self::DUMMY_HASH) {
                return 'password';
            }
            if ($user !== null && $temp && $tempHash !== self::DUMMY_HASH) {
                return 'temp';
            }
        }

        return null;
    }

    /** A wrong username or password: counted, then the same message whatever was wrong. */
    private function failLogin($back, LoginLockout $lockout, string $username, ?array $user)
    {
        $lockout->registerFailure($username);
        $seconds = $lockout->secondsRemaining($username);

        SecurityLogModel::log(
            $seconds > 0 ? 'login_locked' : 'login_failed',
            ['user_id' => $user['user_id'] ?? null, 'role' => $user['role'] ?? null, 'username' => mb_substr(UserModel::normalise($username), 0, 100)],
            $user === null ? 'sign-in: no such username' : 'sign-in: wrong password'
        );

        if ($seconds > 0) {
            return $this->lockedAnswer($back, $lockout, $username, $seconds);
        }

        // The text stays the same; the number of tries left is shown under it.
        return $back->with('error', self::WRONG)->with('tries_left', $lockout->triesLeft($username));
    }

    /** Locked: the message, and the seconds for the button's "Try again in Ns". */
    private function lockedAnswer($back, LoginLockout $lockout, string $username, int $seconds)
    {
        $message = $lockout->accountLocked($username) ? self::LOCKED : self::NETWORK_LOCKED;

        return $back->with('error', sprintf($message, $seconds))->with('lock_seconds', $seconds);
    }

    /** A user is active only if their own flag is on and, for suppliers, the supplier record is too. */
    private function isAccountActive(array $user): bool
    {
        if (! $user['is_active'] || ! empty($user['deleted_at'])) {
            return false;
        }
        if ($user['role'] === 'Supplier') {
            $supplier = (new SupplierModel())->find($user['supplier_id']);

            return $supplier && $supplier['is_active'];
        }

        return true;
    }

    /** Only after the password is proven: why this account can't sign in. */
    private function inactiveMessage(array $user): string
    {
        if ($user['role'] === 'Staff') {
            // Locked by the owner (removed accounts are never found). Not a failed try.
            SecurityLogModel::log('login_blocked_locked', $user);

            return 'Sorry, this account was locked by the Owner. Please contact the Owner.';
        }

        SecurityLogModel::log('login_blocked_deactivated', $user);

        if ($user['role'] === 'Supplier' && $user['is_active']) {
            return 'This supplier account is archived. Please contact the owner.';
        }

        return 'This account has been deactivated';
    }

    /**
     * The sign-in page opened with ?return=/staff/sales (the page's script after an idle sign-out):
     * kept as the page to return to, if ReturnTo accepts it. Kept in the session, so it survives a
     * wrong password or a lockout wait.
     */
    private function keepReturnTo(): void
    {
        $return = $this->request->getGet('return');
        if (is_string($return) && ($clean = ReturnTo::clean($return)) !== null) {
            session()->set('return_to', $clean);
        }
    }

    /**
     * Where to go after signing in: the page to return to, when it is a GET page of this role's own
     * area (ReturnTo::clean), else the role's dashboard. The idle notice and the page are cleared.
     */
    private function afterSignIn(string $role): string
    {
        $target = ReturnTo::clean(session()->get('return_to'), $role) ?? ReturnTo::dashboard($role);
        // After an idle sign-out only: the POS page may bring back the cart the browser saved.
        SavedCart::markSignIn(session()->get('signed_out_reason'));
        session()->remove(['return_to', 'signed_out_reason']);

        return $target;
    }

    private function logUserIn(array $user): void
    {
        // New session ID on login so a pre-login session ID can't be reused
        // by an attacker (session fixation).
        session()->regenerate(true);
        session()->remove(self::RESET_KEY);
        session()->set([
            'user_id'     => $user['user_id'],
            'username'    => $user['username'],
            'full_name'   => $user['full_name'],
            'role'        => $user['role'],
            'supplier_id' => $user['supplier_id'] ?? null,
            'isLoggedIn'  => true,
            'last_activity' => time(),
            // users.last_login is overwritten just below, so the login before this one is
            // kept here (Reorder Alerts: "New since {previous login}").
            'previous_login' => $user['last_login'] ?? null,
        ]);
        $this->userModel->update($user['user_id'], ['last_login' => date('Y-m-d H:i:s')]);
        SecurityLogModel::log('login_success', $user);
    }

    public function logout()
    {
        SecurityLogModel::log('logout');
        // Everything goes, including the idle notice and the page to return to (never shown after this).
        session()->remove(array_keys($_SESSION ?? []));
        session()->destroy();

        return redirect()->to('/');
    }

    // ================================================================== old URLs

    /** /login/admin, /login/staff, /login/supplier (GET or a form posted from an old page): 302 to /login. */
    public function oldLogin()
    {
        return redirect()->to($this->keepingQuery('/login'), 302);
    }

    /** The old per-role forgot / otp / reset-password pages: 302 to the start of the new flow. */
    public function oldForgot()
    {
        return redirect()->to($this->keepingQuery('/login/forgot'), 302);
    }

    /** $path with the ?reason= and ?return= of this request, when there are any. */
    private function keepingQuery(string $path): string
    {
        $keep = [];
        foreach (['reason', 'return'] as $name) {
            $value = $this->request->getGet($name);
            if (is_string($value) && $value !== '') {
                $keep[$name] = $value;
            }
        }

        return $keep === [] ? $path : $path . '?' . http_build_query($keep);
    }

    // ================================================================== forgot password

    /** The started reset in the session (see startReset()). */
    private const RESET_KEY = 'pwd_reset';

    /** A started reset ends after 30 minutes without a step in it. */
    private const RESET_IDLE_SECONDS = 1800;

    /** The code works 10 minutes. */
    private const CODE_SECONDS = 600;

    /** "Didn't get it? Resend in 0:60". */
    public const RESEND_COOLDOWN = 60;

    // A 6-digit code only has 1,000,000 possibilities, so it is burned after
    // a handful of wrong guesses and a new one must be requested.
    private const OTP_MAX_ATTEMPTS = 5;

    /** True if this client is still within its allowance for the given action. */
    private function withinLimit(string $name, string $subject, int $capacity, int $seconds): bool
    {
        return AttemptStore::hit($name . '_' . md5(strtolower($subject)), $seconds) <= $capacity;
    }

    /** Step 1: "Forgot password?" (username or email). */
    public function showForgot()
    {
        return view('auth/forgot');
    }

    public function requestReset()
    {
        $started = microtime(true);
        $typed   = trim((string) $this->request->getPost('identifier'));
        $back    = redirect()->to('/login/forgot')->with('forgot_identifier', mb_substr($typed, 0, 100));

        if ($typed === '') {
            return $back->with('error', 'Please enter your username or email.');
        }

        // Keyed on the client and on what was typed (never on whether it exists), so the limit
        // reveals nothing and stops anyone flooding an inbox.
        if (! $this->withinLimit('forgot_ip', $this->request->getIPAddress(), 5, 600)
            || ! $this->withinLimit('forgot_ident', UserModel::normalise($typed), 3, 600)) {
            return $back->with('error', 'Too many requests. Please wait a few minutes and try again.');
        }

        if (($refused = $this->startReset($typed)) !== null) {
            return $refused;
        }

        AfterResponse::padTo($started, (float) config(Auth::class)->forgotMinSeconds);

        return redirect()->to('/login/forgot/check');
    }

    /**
     * Finds the account and sends what fits its role, then starts the reset in the session. Every
     * outcome stores the same kind of reset; only the server knows which it is. Returns a response
     * only for a demo account (demo mode), whose password can't be reset.
     */
    private function startReset(string $typed): ?\CodeIgniter\HTTP\RedirectResponse
    {
        $user = $this->userModel->findForReset($typed);
        if ($user !== null && ! $this->isAccountActive($user)) {
            $user = null;
        }

        // Demo mode: the demo accounts' passwords can't be reset (their logins are shown on the page anyway).
        if ($user !== null && DemoGuard::locksUser($user)) {
            return DemoGuard::refuse('/login/forgot', 'error');
        }

        $kind = $user === null ? 'none' : ($user['role'] === 'Staff' ? 'temp' : 'code');

        match ($kind) {
            'code'  => $this->sendCode($user),
            'temp'  => $this->sendTemporaryPassword($user),
            default => $this->noAccount(),
        };

        $now = time();
        session()->set(self::RESET_KEY, [
            'token'    => bin2hex(random_bytes(8)),
            'kind'     => $kind,
            'user_id'  => $kind === 'code' ? (int) $user['user_id'] : null,
            'ident'    => UserModel::normalise($typed),
            'verified' => false,
            'burned'   => false,
            'sent_at'  => $now,
            'expires'  => $now + self::CODE_SECONDS,
            'touched'  => $now,
        ]);

        return null;
    }

    /** Owner or Supplier: a new 6-digit code (10 minutes); the email goes after the response. */
    private function sendCode(array $user): void
    {
        $otp = (string) random_int(100000, 999999);

        $this->userModel->update($user['user_id'], [
            'reset_otp_hash'    => password_hash($otp, PASSWORD_DEFAULT),
            'reset_otp_expires' => date('Y-m-d H:i:s', time() + self::CODE_SECONDS),
        ]);
        AttemptStore::forget('otp_attempts_' . $user['user_id']);

        // Demo mode: testers see the code on the next page, so they don't need the inbox.
        DemoGuard::reveal('Demo code', $otp);

        AfterResponse::defer(fn () => $this->deliverOtpEmail($user, $otp));
    }

    /**
     * Staff: a temporary password emailed to them (2 hours); their current password keeps working
     * until they use it. No owner approval. The email goes after the response.
     */
    private function sendTemporaryPassword(array $staff): void
    {
        // At most 3 requests per account per hour; extra ones are logged and sent nothing.
        if (AttemptStore::hit('staff_forgot_acct_' . $staff['user_id'], 3600) > StaffPasswordReset::FORGOT_PER_HOUR) {
            SecurityLogModel::log('password_forgot_rate_limited', $staff, 'more than ' . StaffPasswordReset::FORGOT_PER_HOUR . ' requests in an hour');

            return;
        }

        // A staff email that is also an Owner's email never gets a temporary password (the Owner
        // would receive it). Nothing is sent; the Owner is told in the app to fix the address.
        if ($this->userModel->isAdminEmail($staff['email'] ?? null)) {
            SecurityLogModel::log('password_forgot_owner_email', $staff, "the staff email is the owner's; nothing sent");
            StaffPasswordAlert::ownerNotice(
                "{$staff['full_name']} tried to reset their password",
                "{$staff['full_name']}'s email is the same as the owner's, so no temporary password was sent. Give them their own email in Manage Accounts, or reset the password there."
            );

            return;
        }

        // No usable email: nothing to send, so the owner is told in the app.
        if (! StaffPasswordReset::usableEmail($staff['email'] ?? null)) {
            SecurityLogModel::log('password_forgot_no_email', $staff, 'no usable email on file');
            StaffPasswordAlert::ownerNotice(
                "{$staff['full_name']} tried to reset their password",
                "{$staff['full_name']} tried to reset their password but has no email on file. Reset it from Manage Accounts."
            );

            return;
        }

        $reset = new StaffPasswordReset();
        [$password, $hash, $before] = $reset->prepareForgot($staff);
        DemoGuard::reveal("Temporary password for {$staff['full_name']}", $password);

        AfterResponse::defer(static function () use ($reset, $staff, $password, $hash, $before): void {
            if ($reset->sendForgot($staff, $password, $hash, $before)) {
                SecurityLogModel::log('password_forgot_requested', $staff, 'temporary password emailed');

                return;
            }
            log_message('error', "Forgot-password email could not be sent for user {$staff['user_id']}");
            SecurityLogModel::log('password_forgot_failed', $staff, 'the email could not be sent');
        });
    }

    /** No account: nothing is sent. One hash, so this takes about as long as making a code. */
    private function noAccount(): void
    {
        password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
        SecurityLogModel::log('password_forgot_no_match', ['user_id' => null, 'role' => null, 'username' => null], 'no single active account matched');
    }

    /** Step 2: "Check your email" (the same page for everyone). */
    public function showCheckEmail()
    {
        $reset = $this->pendingReset();
        if ($reset === null) {
            return redirect()->to('/login/forgot');
        }

        return view('auth/forgot_check', [
            'resendIn' => max(0, self::RESEND_COOLDOWN - (time() - (int) $reset['sent_at'])),
        ]);
    }

    public function verifyCode()
    {
        $reset = $this->pendingReset();
        if ($reset === null) {
            return redirect()->to('/login/forgot');
        }
        $back = redirect()->to('/login/forgot/check');

        $code = $this->request->getPost('code');
        $code = preg_replace('/\D/', '', is_array($code) ? implode('', array_map('strval', $code)) : (string) $code);

        if (strlen($code) !== 6) {
            return $back->with('error', 'Enter the 6-digit code from the email.');
        }
        if ($reset['burned']) {
            return $back->with('error', 'Please request a new code.');
        }
        if (time() > (int) $reset['expires']) {
            return $back->with('error', 'This code has expired. Please request a new one.');
        }

        // A real code, or DUMMY_HASH (no account, a Staff member, or a code that is gone): the same check either way.
        $user = $reset['kind'] === 'code' ? $this->userModel->find((int) $reset['user_id']) : null;
        $hash = ($user && ! empty($user['reset_otp_hash']) && ! empty($user['reset_otp_expires']) && strtotime($user['reset_otp_expires']) >= time())
            ? $user['reset_otp_hash'] : self::DUMMY_HASH;
        $right = password_verify($code, $hash) && $hash !== self::DUMMY_HASH;

        $attemptsKey = 'otp_attempts_' . ($user ? $user['user_id'] : 'reset_' . $reset['token']);

        if (! $right) {
            if (AttemptStore::hit($attemptsKey, self::CODE_SECONDS) >= self::OTP_MAX_ATTEMPTS) {
                if ($user) {
                    $this->userModel->update($user['user_id'], ['reset_otp_hash' => null, 'reset_otp_expires' => null]);
                    SecurityLogModel::log('otp_locked', $user, 'too many wrong codes');
                }
                AttemptStore::forget($attemptsKey);
                $this->updateReset(['burned' => true]);

                return $back->with('error', 'Too many incorrect attempts. Please request a new code.');
            }

            return $back->with('error', 'Incorrect code, please try again.');
        }

        AttemptStore::forget($attemptsKey);
        $this->updateReset(['verified' => true]);

        return redirect()->to('/login/forgot/new-password');
    }

    /**
     * "Resend": the same request again for the same username or email (a new code for an Owner or
     * Supplier, a new temporary password for Staff, nothing otherwise), within the same limits,
     * at most once a minute. The same answer always.
     */
    public function resend()
    {
        $started = microtime(true);
        $reset   = $this->pendingReset();
        if ($reset === null) {
            return redirect()->to('/login/forgot');
        }
        $back = redirect()->to('/login/forgot/check');

        $wait = self::RESEND_COOLDOWN - (time() - (int) $reset['sent_at']);
        if ($wait > 0) {
            return $back->with('error', "Please wait {$wait} seconds before asking again.");
        }
        if (! $this->withinLimit('forgot_resend', $reset['ident'], 3, 600)) {
            return $back->with('error', 'Too many requests. Please wait a few minutes and try again.');
        }

        if (($refused = $this->startReset($reset['ident'])) !== null) {
            return $refused;
        }

        AfterResponse::padTo($started, (float) config(Auth::class)->forgotMinSeconds);

        return $back->with('notice', "If an account matches, we've sent the next step again. It can take a minute.");
    }

    /** Step 3: "Set a new password" (only after the right code). */
    public function showNewPassword()
    {
        if ($this->verifiedReset() === null) {
            return redirect()->to('/login/forgot');
        }

        return view('auth/forgot_new_password');
    }

    public function saveNewPassword()
    {
        $reset = $this->verifiedReset();
        if ($reset === null) {
            return redirect()->to('/login/forgot');
        }
        $back = redirect()->to('/login/forgot/new-password');

        $newPass = (string) $this->request->getPost('new_password');
        $confirm = (string) $this->request->getPost('confirm_password');

        if ($error = PasswordPolicy::check($newPass)) {
            return $back->with('error', $error);
        }
        if ($newPass !== $confirm) {
            return $back->with('error', 'Passwords do not match.');
        }

        $user = $this->userModel->find((int) $reset['user_id']);
        if (! $user) {
            session()->remove(self::RESET_KEY);

            return redirect()->to('/login/forgot');
        }
        if (DemoGuard::locksUser($user)) {
            return DemoGuard::refuse('/login/forgot', 'error');
        }
        if (password_verify($newPass, $user['password_hash'])) {
            return $back->with('error', 'Choose a different password from your current one.');
        }

        $this->userModel->update($user['user_id'], [
            'password_hash'     => password_hash($newPass, PASSWORD_DEFAULT),
            'reset_otp_hash'    => null,
            'reset_otp_expires' => null,
        ]);

        $resetUser = $this->userModel->find($user['user_id']);
        SecurityLogModel::log('password_reset', $resetUser, 'via emailed code');
        (new LoginLockout())->reset($user['username']);
        session()->remove(self::RESET_KEY);

        // One email to this account's own address. No links, no codes. (After the password is set,
        // so saying who to contact gives nothing away.)
        $contact = $user['role'] === 'Admin' ? 'support' : 'the owner';
        $when    = date('M j, Y, g:i A');
        AfterResponse::defer(static fn () => (new EmailNotifier())->toUser(
            $resetUser,
            'Your RfourL password was changed',
            "Your RfourL password was changed on {$when}. If this wasn't you, contact {$contact} right away."
        ));

        return redirect()->to('/login/forgot/done')->with('reset_done', true);
    }

    /** "Password changed". */
    public function showDone()
    {
        if (! session()->getFlashdata('reset_done')) {
            return redirect()->to('/login');
        }

        return view('auth/forgot_done');
    }

    /** The started reset, if it is still within RESET_IDLE_SECONDS of its last step (which this counts as). */
    private function pendingReset(): ?array
    {
        $reset = session()->get(self::RESET_KEY);
        if (! is_array($reset) || empty($reset['token'])) {
            return null;
        }
        if (time() - (int) $reset['touched'] > self::RESET_IDLE_SECONDS) {
            session()->remove(self::RESET_KEY);

            return null;
        }

        return $this->updateReset(['touched' => time()]);
    }

    /** A started reset whose code was right (only ever an Owner or Supplier). */
    private function verifiedReset(): ?array
    {
        $reset = $this->pendingReset();

        return ($reset !== null && $reset['kind'] === 'code' && $reset['verified']) ? $reset : null;
    }

    private function updateReset(array $changes): array
    {
        $reset = array_merge((array) session()->get(self::RESET_KEY), $changes);
        session()->set(self::RESET_KEY, $reset);

        return $reset;
    }

    /**
     * Sends the code by email (after the response, see AfterResponse). Returns true if emailed.
     * The code is never shown on screen (except in demo mode) and never logged.
     */
    private function deliverOtpEmail(array $user, string $otp): bool
    {
        if (empty($user['email'])) {
            log_message('error', "OTP not sent: no email on file for user {$user['user_id']}");

            return false;
        }

        $email = EmailNotifier::fresh();
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
