<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\DssRunner;
use App\Libraries\SalesArchive;
use App\Libraries\AttemptStore;
use App\Libraries\DemoGuard;
use App\Libraries\EmailNotifier;
use App\Libraries\OnceToken;
use App\Libraries\PasswordHistory;
use App\Libraries\PasswordPolicy;
use App\Libraries\StaffPasswordReset;
use App\Libraries\Toast;
use App\Models\SecurityLogModel;
use App\Models\DssParameterModel;
use App\Models\NotificationPreferenceModel;
use App\Models\UserModel;

class SettingsController extends BaseController
{
    protected $dssParameterModel;
    protected $userModel;
    protected $notificationPreferenceModel;

    public function __construct()
    {
        $this->dssParameterModel          = new DssParameterModel();
        $this->userModel                  = new UserModel();
        $this->notificationPreferenceModel = new NotificationPreferenceModel();
    }

    public function index()
    {
        $tab = $this->request->getGet('tab') ?: 'profile';

        // The old Security tab now lives inside Manage Accounts; keep old links and bookmarks working.
        if ($tab === 'security') {
            $tab = 'accounts';
        }

        $data = [
            'title'         => 'Settings',
            'active'        => 'settings',
            'tab'           => $tab,
            'dss'           => $this->dssParameterModel->current(),
            'dssCalculated' => (new DssRunner())->lastCalculatedLabel(),
            'dssRunning'    => (new DssRunner())->isRunning(),
            'dssStarted'    => (bool) session()->getFlashdata('dss_started'),
            'notifPrefs'    => $this->notificationPreferenceModel->current(),
            'admin'         => $this->userModel->find(session()->get('user_id')),
            'staffAccounts' => $this->userModel->activeStaff()->findAll(),
            'success'       => session()->getFlashdata('success'),
            'error'         => session()->getFlashdata('error'),
            // A refused My Profile form: ['form' => 'personal'|'signin', 'fields' => [name => message]].
            'fieldErrors'   => session()->getFlashdata('field_errors'),
        ];

        if ($tab === 'records') {
            SalesArchive::syncNotification();
            $data['retentionYears']   = SalesArchive::years();
            $data['retentionOptions'] = SalesArchive::YEAR_OPTIONS;
            $data['retentionDefault'] = SalesArchive::DEFAULT_YEARS;
            $data['oldRecords']       = SalesArchive::count();
            $data['oldRange']         = SalesArchive::range();
        }

        return view('admin/settings', $data);
    }

    public function updateNotificationPreference()
    {
        $key = (string) $this->request->getPost('pref_key');

        if (! in_array($key, NotificationPreferenceModel::KEYS, true)) {
            return redirect()->to('/admin/settings?tab=notif')->with('error', 'Unknown notification preference.');
        }

        $enabled = (bool) $this->request->getPost('enabled');
        $this->notificationPreferenceModel->setEnabled($key, $enabled, (int) session()->get('user_id'));

        return redirect()->to('/admin/settings?tab=notif')->with('success', 'Notification preferences updated.');
    }

    /**
     * "Recalculate now": starts the same engine as `php spark dss:run` (EOQ,
     * Safety Stock, ROP, K-Means classes, reorder alerts) in the background
     * and returns at once, so the admin can keep working. Every admin page
     * polls the run status and shows a toast when it finishes. One run at a
     * time, shared with the weekly automatic run.
     */
    public function runEngine()
    {
        $session = session();
        $state   = (new DssRunner())->startManual((int) $session->get('user_id'));

        if ($state === 'running') {
            // Stays until the run finishes, then dss-watch.js replaces it in
            // place with the result toast (same 'dss' key).
            $session->setFlashdata('toast', Toast::info(
                'A recalculation is already running',
                "You'll get a notice when it finishes.",
                ['key' => 'dss', 'busy' => true]
            ));
        } elseif ($state === 'failed') {
            $session->setFlashdata('toast', Toast::error("Couldn't recalculate", 'The background process could not be started. Please try again.'));
        } else {
            $session->setFlashdata('dss_started', true);
        }

        // Release the session lock right away so other requests (other
        // tabs, the status poll) never queue behind this one.
        $session->close();

        return redirect()->to('/admin/settings?tab=dss');
    }

    /** Settings > Data & Records: how many years sales records are kept (3 / 5 / 7 / 10). */
    public function updateRetention()
    {
        $years = (int) $this->request->getPost('retention_years');
        if (! in_array($years, SalesArchive::YEAR_OPTIONS, true)) {
            return redirect()->to('/admin/settings?tab=records')->with('error', 'Choose 3, 5, 7 or 10 years.');
        }

        $before = SalesArchive::years();
        SalesArchive::setYears($years, (int) session()->get('user_id'));
        SalesArchive::syncNotification();
        if ($before !== $years) {
            SecurityLogModel::log('retention_changed', null, "sales retention {$before} -> {$years} years");
        }

        return redirect()->to('/admin/settings?tab=records')->with('toast', Toast::success('Retention period saved', "Sales records are kept for {$years} years."));
    }

    public function updateDssParameters()
    {
        $rules = [
            'ordering_cost'         => 'required|numeric|greater_than_equal_to[0]',
            'holding_cost_per_unit' => 'required|numeric|greater_than[0]',
            'service_level_target'  => 'required|numeric|greater_than[0]|less_than[100]',
            'z_score'               => 'required|numeric',
            'demand_lookback_days'  => 'required|integer|greater_than[0]',
            'minimum_order_qty'     => 'required|integer|greater_than[0]',
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
            'minimum_order_qty'     => (int) $this->request->getPost('minimum_order_qty'),
            'updated_by'            => session()->get('user_id'),
        ];

        $current = $this->dssParameterModel->current();

        if (! empty($current['id'])) {
            $this->dssParameterModel->update($current['id'], $payload);
        } else {
            $this->dssParameterModel->insert($payload);
        }

        return redirect()->to('/admin/settings?tab=dss')->with('success', 'Order settings saved.');
    }

    private const PROFILE_BACK = '/admin/settings?tab=profile';

    /** Sign-in form: saves that reach the current-password check, per window (format errors don't count). */
    private const SIGNIN_TRIES  = 5;
    private const SIGNIN_WINDOW = 600;

    /**
     * Personal information: full name and contact number. Neither opens anything, so no password
     * is asked for. The email is part of the sign-in form (updateSignIn()).
     */
    public function updateAccount()
    {
        $userId = (int) session()->get('user_id');
        $user   = $this->userModel->find($userId);

        if (! $user) {
            return redirect()->to('/admin/settings')->with('error', 'Account not found.');
        }
        if (DemoGuard::locksUser($user)) {
            return DemoGuard::refuse(self::PROFILE_BACK);
        }

        // Trimmed, inner runs of spaces collapsed to one.
        $name   = trim((string) preg_replace('/\s+/u', ' ', (string) $this->request->getPost('full_name')));
        $phone  = self::normalisePhone($this->request->getPost('phone'));
        $errors = [];

        // Only a new name is checked, so an older name that breaks today's rules (e.g. over 60
        // characters) never stops the contact number from being saved.
        $nameChanged = $name !== (string) $user['full_name'];
        if ($name === '') {
            $errors['full_name'] = 'Enter your full name.';
        } elseif ($nameChanged && mb_strlen($name) > 60) {
            $errors['full_name'] = 'Full name can be up to 60 characters.';
        } elseif ($nameChanged && mb_strlen($name) < 2) {
            $errors['full_name'] = 'Full name must be at least 2 characters.';
        } elseif ($nameChanged && (! preg_match("/^[\\p{L}\\p{M} .'\\-]+$/u", $name) || ! preg_match('/\p{L}/u', $name))) {
            $errors['full_name'] = "Use only letters, spaces and . - ' in your name.";
        }
        if ($phone === false) {
            $errors['phone'] = 'Use an 11-digit mobile number like 0917 123 4567.';
        }
        if ($errors !== []) {
            return $this->refuseForm('personal', $errors);
        }

        $changes = [];
        if ($name !== (string) $user['full_name']) {
            $changes['full_name'] = $name;
        }
        if ($phone !== ($user['phone'] ?? null)) {
            $changes['phone'] = $phone;
        }
        if ($changes === []) {
            return redirect()->to(self::PROFILE_BACK)->with('toast', Toast::info('Nothing changed yet.', 'Edit your name or contact number, then click Save.'));
        }

        // Only a real save may report success or change the name shown in the sidebar.
        if (! $this->userModel->update($userId, $changes)) {
            return $this->refuseForm('personal', ['form' => "Your changes couldn't be saved. Please try again."]);
        }
        if (isset($changes['full_name'])) {
            session()->set('full_name', $name);
        }
        SecurityLogModel::log('profile_updated', null, 'owner updated own ' . implode(' and ', array_map(
            static fn ($c) => $c === 'full_name' ? 'name' : 'contact number',
            array_keys($changes)
        )));

        return redirect()->to(self::PROFILE_BACK)->with('success', 'Profile updated.');
    }

    /**
     * "0917 123 4567", "0917-123-4567" or "+63 917 123 4567" -> "09171234567". Null when blank;
     * false when it is not an 11-digit mobile number starting with 09 (after removing spaces and hyphens).
     */
    private static function normalisePhone($typed): string|false|null
    {
        $typed = trim((string) $typed);
        if ($typed === '') {
            return null;
        }
        if (! preg_match('/^[0-9 +\-]+$/', $typed)) {
            return false;
        }

        $digits = str_replace([' ', '-'], '', $typed);
        if (str_starts_with($digits, '+63')) {
            $digits = '0' . substr($digits, 3);
        }

        return preg_match('/^09\d{9}$/', $digits) ? $digits : false;
    }

    /** One rule set for every username change: 4-50 letters, digits, "_" or "-", not used by anyone else. */
    private function usernameRules(int $exceptUserId): array
    {
        return [
            ['username' => "required|min_length[4]|max_length[50]|alpha_dash|is_unique[users.username,user_id,{$exceptUserId}]"],
            ['username' => ['is_unique' => 'That username is already taken.']],
        ];
    }

    /**
     * Sign-in & security: username, email and password in one form, with the current password asked
     * for once. Order: nothing changed -> field checks -> attempt limit -> current password -> new
     * password not the current one -> save. Format errors never count as an attempt.
     */
    public function updateSignIn()
    {
        $id   = (int) session()->get('user_id');
        $user = $this->userModel->find($id);

        if (! $user) {
            return redirect()->to('/admin/settings')->with('error', 'Account not found.');
        }
        if (DemoGuard::locksUser($user)) {
            return DemoGuard::refuse(self::PROFILE_BACK);
        }

        $post     = fn (string $key) => (string) $this->request->getPost($key);
        $username = trim($post('username'));
        $email    = mb_strtolower(trim($post('email')));
        $confirm  = mb_strtolower(trim($post('email_confirm')));
        $newPass  = $post('new_password');
        $newPass2 = $post('new_password_confirm');
        $current  = $post('current_password');

        $oldEmail        = $user['email'] ?? null;
        $usernameChanged = $username !== (string) $user['username'];
        $emailChanged    = $email !== mb_strtolower(trim((string) $oldEmail));
        $passwordChanged = $newPass !== '' || $newPass2 !== '';

        // a) Nothing to do: no attempt counted.
        if (! $usernameChanged && ! $emailChanged && ! $passwordChanged) {
            return redirect()->to(self::PROFILE_BACK)->with('toast', Toast::info('Nothing changed yet.', 'Change your username, email or password, then click Save.'));
        }

        // b) Field checks (never counted as an attempt).
        $errors = [];
        if ($usernameChanged) {
            [$rules, $messages] = $this->usernameRules($id);
            if (! $this->validateData(['username' => $username], $rules, $messages)) {
                $errors['username'] = $this->validator->getError('username');
            }
        }
        if ($emailChanged || $email === '') {
            if ($error = $this->emailError($email, $id)) {
                $errors['email'] = $error;
            } elseif ($confirm !== $email) {
                $errors['email_confirm'] = "The two emails don't match.";
            }
        }
        if ($passwordChanged) {
            if ($error = PasswordPolicy::check($newPass)) {
                $errors['new_password'] = $error;
            } elseif ($newPass !== $newPass2) {
                $errors['new_password_confirm'] = 'Passwords do not match.';
            }
        }
        if ($current === '') {
            $errors['current_password'] = 'Enter your current password to save these changes.';
        }
        if ($errors !== []) {
            return $this->refuseForm('signin', $errors);
        }

        // c) One attempt counter for the whole form, counted before the password is checked.
        $limitKey = 'owner_signin_change_' . $id;
        if (AttemptStore::hit($limitKey, self::SIGNIN_WINDOW) > self::SIGNIN_TRIES) {
            return $this->refuseForm('signin', ['form' => 'Too many attempts. Please wait a few minutes and try again.']);
        }

        // d) The current password.
        if (! password_verify($current, $user['password_hash'])) {
            SecurityLogModel::log('signin_change_failed', null, 'owner sign-in details: wrong current password');

            return $this->refuseForm('signin', ['current_password' => 'Current password is incorrect.']);
        }
        AttemptStore::forget($limitKey);

        // e) A new password must really be new.
        if ($passwordChanged && password_verify($newPass, $user['password_hash'])) {
            return $this->refuseForm('signin', ['new_password' => 'Your new password must be different from your current one.']);
        }

        // f) Save what changed. Checked above; the model's own username rule can't tell "this account"
        //    from another one on update.
        $changes = [];
        if ($usernameChanged) {
            $changes['username'] = $username;
        }
        if ($emailChanged) {
            $changes['email'] = $email;
        }
        if ($passwordChanged) {
            $changes['password_hash'] = password_hash($newPass, PASSWORD_DEFAULT);
        }
        $saved = $this->userModel->skipValidation(true)->update($id, $changes);
        $this->userModel->skipValidation(false);
        if (! $saved) {
            return $this->refuseForm('signin', ['form' => "Your changes couldn't be saved. Please try again."]);
        }

        if ($usernameChanged) {
            session()->set('username', $username);
            SecurityLogModel::log('username_changed', $user, "{$user['username']} -> {$username}");
        }
        if ($emailChanged) {
            SecurityLogModel::log('email_changed', null, 'owner sign-in email');
            // The old address is told. No links, no codes.
            if (! empty($oldEmail)) {
                (new EmailNotifier())->toUser(
                    ['email' => $oldEmail],
                    'Your RfourL account email was changed',
                    'The email on your RfourL owner account was changed on ' . date('M j, Y, g:i A') . ". If this wasn't you, contact support right away."
                );
            }
        }
        if ($passwordChanged) {
            session()->regenerate(true);
            SecurityLogModel::log('password_changed', null, 'owner changed own password');
        }

        // g) Say what changed.
        $what    = array_keys(array_filter(['Username' => $usernameChanged, 'Email' => $emailChanged, 'Password' => $passwordChanged]));
        $message = count($what) === 1 ? "{$what[0]} changed." : 'Sign-in details updated.';
        if ($usernameChanged) {
            $message .= ' Use your new username the next time you sign in.';
        }

        return redirect()->to(self::PROFILE_BACK)->with('success', $message);
    }

    /** Null when $email (already trimmed and lowercased) can be the owner's sign-in email. */
    private function emailError(string $email, int $exceptUserId): ?string
    {
        if ($email === '') {
            return 'Enter your email address.';
        }
        if (mb_strlen($email) > 100) {
            return 'The email can be up to 100 characters.';
        }
        // valid_email, and the part after the last dot is 2-24 letters (".com", ".ph", not ".c0m").
        if (! $this->validateData(['email' => $email], ['email' => 'valid_email']) || ! preg_match('/\.[a-z]{2,24}$/', $email)) {
            return 'Enter a real email address, like name@example.com.';
        }
        // Any letter case or spaces, the way Forgot password compares them.
        if ($this->userModel->where('LOWER(TRIM(email))', $email)->where('user_id !=', $exceptUserId)->countAllResults() > 0) {
            return 'That email is already used by another account.';
        }

        return null;
    }

    /**
     * Back to My Profile with one message per field ('form' = a message for the whole form), the
     * form reopened, and what was typed kept, except the token, the one-time key and every field
     * whose name contains "pass".
     */
    private function refuseForm(string $form, array $errors)
    {
        $post = (array) $this->request->getPost();
        foreach (array_keys($post) as $key) {
            if ($key === config('Security')->tokenName || $key === OnceToken::FIELD || $key === \App\Filters\CsrfFilter::LABEL_FIELD
                || stripos((string) $key, 'pass') !== false) {
                unset($post[$key]);
            }
        }
        session()->setFlashdata('_ci_old_input', ['get' => [], 'post' => $post]);

        return redirect()->to(self::PROFILE_BACK)
            ->with('error', implode(' ', $errors))
            ->with('field_errors', ['form' => $form, 'fields' => $errors]);
    }

    /**
     * The owner changes a staff member's username (not a removed account). No owner password: the
     * owner is already signed in, and the username alone opens nothing (it is shown to every
     * colleague). The staff member must be told the new one before they next sign in.
     */
    public function updateStaffUsername($userId)
    {
        $back  = '/admin/settings?tab=accounts';
        $staff = $this->userModel->activeStaff()->find((int) $userId);

        if (! $staff) {
            return redirect()->to($back)->with('error', 'Staff account not found.');
        }
        if (DemoGuard::locksUser($staff)) {
            return DemoGuard::refuse($back);
        }

        [$rules, $messages] = $this->usernameRules((int) $staff['user_id']);
        if (! $this->validate($rules, $messages)) {
            return redirect()->to($back)->with('error', implode(' ', $this->validator->getErrors()));
        }

        $new = trim((string) $this->request->getPost('username'));
        $this->userModel->skipValidation(true)->update((int) $staff['user_id'], ['username' => $new]);
        $this->userModel->skipValidation(false);
        SecurityLogModel::log('username_changed', null, "staff: {$staff['username']} -> {$new} (by owner)");

        return redirect()->to($back)->with('success', "{$staff['full_name']}'s username is now {$new}.");
    }

    /**
     * The Owner sets a new password for a staff member (this also unlocks a locked account). The owner
     * proves who they are with their own password and ticks that they told the staff member. The password
     * follows the same rules as any staff password. The staff member gets an email WITHOUT the password
     * (they must ask the owner for it) and, at their next sign-in, has to create their own, so the owner
     * doesn't keep knowing it.
     */
    public function resetStaffPassword($userId)
    {
        $back  = '/admin/settings?tab=accounts';
        $staff = $this->userModel->activeStaff()->find((int) $userId);
        $owner = $this->userModel->find((int) session()->get('user_id'));

        if (! $staff) {
            return redirect()->to($back)->with('error', 'Staff account not found.');
        }
        if (DemoGuard::locksUser($staff)) {
            return DemoGuard::refuse($back);
        }
        // The same form again (double click, resubmit): the reset was done the first time, so
        // show that result instead of "one of their recent passwords". Nothing is sent again.
        $onceKey = OnceToken::key($this->request);
        $done    = fn () => redirect()->to($back)->with('toast', Toast::success(
            'Password reset',
            "{$staff['full_name']} must create their own password when they sign in with it."
        ));
        if (OnceToken::used($onceKey)) {
            return $done();
        }
        if (AttemptStore::hit('owner_reset_' . (int) session()->get('user_id'), 600) > 5) {
            return redirect()->to($back)->with('error', 'Too many attempts. Please wait a few minutes and try again.');
        }
        if (! $owner || ! password_verify((string) $this->request->getPost('owner_password'), $owner['password_hash'])) {
            SecurityLogModel::log('password_reset_failed', null, 'wrong owner password for staff: ' . $staff['username']);

            return redirect()->to($back)->with('error', 'Your password is incorrect.');
        }
        if (! $this->request->getPost('informed')) {
            return redirect()->to($back)->with('error', "Tick \"I have informed {$staff['full_name']} about this reset\" first.");
        }

        $newPass = (string) $this->request->getPost('new_password');
        if ($error = PasswordPolicy::check($newPass)) {
            return redirect()->to($back)->with('error', $error);
        }
        if ($newPass !== (string) $this->request->getPost('confirm_password')) {
            return redirect()->to($back)->with('error', 'Passwords do not match.');
        }
        // Not one of the staff member's recent passwords (nor the one they have now).
        if (PasswordHistory::reuses($staff, $newPass)) {
            return redirect()->to($back)->with('error', "That is one of {$staff['full_name']}'s recent passwords. Choose a different one.");
        }
        $wasLocked = ! $staff['is_active'];
        $reset     = new StaffPasswordReset();
        $db        = db_connect();
        $db->transStart();
        if (OnceToken::claim($onceKey, 'staff_password_reset', (int) session()->get('user_id'))) {
            return $done();
        }
        $reset->ownerReset($staff, $newPass);
        OnceToken::complete($onceKey, 'user', (int) $staff['user_id']);
        $db->transComplete();
        $reset->emailOwnerReset($staff);

        AttemptStore::forget('owner_reset_' . (int) session()->get('user_id'));
        SecurityLogModel::log('password_reset', null, 'staff: ' . $staff['username'] . ' reset by owner' . ($wasLocked ? ' (account unlocked)' : '') . '; they must create a new password at next sign-in');

        return redirect()->to($back)->with('toast', Toast::success(
            'Password reset',
            "{$staff['full_name']} must create their own password when they sign in with it." . ($wasLocked ? ' The account is unlocked.' : '')
        ));
    }

    /**
     * New staff account: name, username and email. The system creates the account, then
     * emails a temporary password (48 hours). If the email can't be sent the account is not created.
     */
    public function addStaffAccount()
    {
        $back = '/admin/settings?tab=accounts';

        // The same form again (double click, resubmit): the account was made the first time, so
        // show that result instead of "username already taken". Nothing is sent again.
        $onceKey = OnceToken::key($this->request);
        $created = function (array $earlier) use ($back) {
            $staff = ! empty($earlier['result_id']) ? $this->userModel->find((int) $earlier['result_id']) : null;

            return redirect()->to($back)->with('toast', Toast::success('Account created', $staff
                ? 'A temporary password was emailed to ' . StaffPasswordReset::maskEmail((string) $staff['email']) . '.'
                : 'A temporary password was emailed.'));
        };
        if ($earlier = OnceToken::used($onceKey)) {
            return $created($earlier);
        }

        // The username is what they sign in with: same rules as renaming it (trimmed, 4-50 letters, digits, _ or -).
        $data = (array) $this->request->getPost();
        $data['username'] = trim((string) ($data['username'] ?? ''));
        [$usernameRules, $usernameMessages] = $this->usernameRules(0);
        if (! $this->validateData($data, [
            'full_name'     => 'required',
            'username'      => $usernameRules['username'],
            'contact_email' => 'required|valid_email|is_unique[users.email]',
        ], $usernameMessages)) {
            return redirect()->to($back)->with('error', implode(' ', $this->validator->getErrors()));
        }

        $email = trim((string) $this->request->getPost('contact_email'));
        // A staff email gets temporary passwords, so it can never be an Owner's.
        if ($this->userModel->isAdminEmail($email)) {
            return redirect()->to($back)->with('error', "That is the owner's email. Use the staff member's own email.");
        }
        $reset = new StaffPasswordReset();
        $db    = db_connect();

        $db->transStart();
        if ($earlier = OnceToken::claim($onceKey, 'staff_account', (int) session()->get('user_id'))) {
            return $created($earlier);
        }
        try {
            // The account needs a hash to exist; issue() replaces it with the real temporary password.
            $id = $this->userModel->insert([
                'username'      => trim((string) $this->request->getPost('username')),
                'full_name'     => trim((string) $this->request->getPost('full_name')),
                'email'         => $email,
                'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                'role'          => 'Staff',
                'is_active'     => 1,
            ]);
            $staff    = $this->userModel->find($id);
            $password = $reset->issue($staff, StaffPasswordReset::KIND_INVITE);
            $sent     = $reset->emailPassword($staff, $password, StaffPasswordReset::KIND_INVITE);
        } catch (\Throwable $e) {
            log_message('error', 'Staff account creation failed: ' . $e->getMessage());
            $sent = false;
        }

        if (! $sent) {
            $db->transRollback();

            return redirect()->to($back)->with('error', "We couldn't send the email, so the account was not created. Check the email address and try again.");
        }
        OnceToken::complete($onceKey, 'user', $id);
        $db->transComplete();

        DemoGuard::reveal("Temporary password for {$staff['full_name']}", $password);
        SecurityLogModel::log('account_created', null, 'staff: ' . $staff['username'] . ' (temporary password emailed)');

        return redirect()->to($back)->with('toast', Toast::success('Account created', 'A temporary password was emailed to ' . StaffPasswordReset::maskEmail($email) . '.'));
    }

    /** "Invite expired" -> "Resend": a new temporary password (48 hours) goes to the staff member's email. */
    public function resendStaffInvite($userId)
    {
        $back  = '/admin/settings?tab=accounts';
        $staff = $this->userModel->activeStaff()->find((int) $userId);

        if (! $staff || ! StaffPasswordReset::isPending($staff) || ($staff['temp_password_kind'] ?? '') !== StaffPasswordReset::KIND_INVITE || empty($staff['email'])) {
            return redirect()->to($back)->with('error', "This invite can't be resent.");
        }
        $password = (new StaffPasswordReset())->issueAndEmail($staff, StaffPasswordReset::KIND_INVITE);
        if ($password === null) {
            return redirect()->to($back)->with('error', "We couldn't send the email. Please try again.");
        }
        DemoGuard::reveal("Temporary password for {$staff['full_name']}", $password);

        SecurityLogModel::log('invite_resent', null, 'staff: ' . $staff['username']);

        return redirect()->to($back)->with('toast', Toast::success('Invite resent', 'A new temporary password was emailed to ' . StaffPasswordReset::maskEmail($staff['email']) . '.'));
    }

    public function deactivateStaff($userId)
    {
        $user = $this->userModel->activeStaff()->find((int) $userId);

        if (! $user) {
            return redirect()->to('/admin/settings?tab=accounts')->with('error', 'Staff account not found.');
        }
        if (DemoGuard::locksUser($user)) {
            return DemoGuard::refuse('/admin/settings?tab=accounts');
        }

        $this->userModel->update((int) $userId, ['is_active' => $user['is_active'] ? 0 : 1]);
        SecurityLogModel::log($user['is_active'] ? 'account_deactivated' : 'account_reactivated', null, 'staff: ' . $user['username']);

        return redirect()->to('/admin/settings?tab=accounts')->with('success', $user['is_active'] ? 'Staff account deactivated.' : 'Staff account reactivated.');
    }

    /**
     * Removes a staff account. With no sales, order, inventory, or
     * notification history it is a real delete. Every FK referencing
     * users.user_id is ON DELETE RESTRICT, so an account WITH history is
     * instead marked removed (deleted_at + inactive): it vanishes from the
     * account list and can't sign in, but the row stays so old receipts,
     * orders and logs keep the cashier's name.
     */
    public function deleteStaff($userId)
    {
        $userId = (int) $userId;
        $user   = $this->userModel->activeStaff()->find($userId);

        if (! $user) {
            return redirect()->to('/admin/settings?tab=accounts')->with('error', 'Staff account not found.');
        }
        if (DemoGuard::locksUser($user)) {
            return DemoGuard::refuse('/admin/settings?tab=accounts');
        }

        $db = db_connect();

        $hasHistory = $db->table('sales_transaction')->where('user_id', $userId)->countAllResults() > 0
            || $db->table('stock_order')->where('user_id', $userId)->countAllResults() > 0
            || $db->table('inventory_log')->where('user_id', $userId)->countAllResults() > 0
            || $db->table('notifications')->where('recipient_id', $userId)->countAllResults() > 0;

        if ($hasHistory) {
            $this->userModel->update($userId, ['deleted_at' => date('Y-m-d H:i:s'), 'is_active' => 0]);
        } else {
            $this->userModel->delete($userId);
        }

        SecurityLogModel::log('account_removed', null, 'staff: ' . $user['username'] . ($hasHistory ? ' (kept, has history)' : ' (deleted)'));

        return redirect()->to('/admin/settings?tab=accounts')->with('success', "{$user['full_name']} was removed. Their past sales and records are kept.");
    }
}
