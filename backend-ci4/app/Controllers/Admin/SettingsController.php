<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\DssRunner;
use App\Libraries\SalesArchive;
use App\Libraries\AttemptStore;
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
            'tempOnce'      => session()->getFlashdata('temp_password_once'),
            'success'       => session()->getFlashdata('success'),
            'error'         => session()->getFlashdata('error'),
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

    public function updateAccount()
    {
        $userId = session()->get('user_id');
        $user   = $this->userModel->find($userId);

        if (! $user) {
            return redirect()->to('/admin/settings')->with('error', 'Account not found.');
        }

        if (! $this->validate([
            'email' => "permit_empty|valid_email|is_unique[users.email,user_id,{$userId}]",
        ])) {
            return redirect()->to('/admin/settings?tab=profile')->with('error', implode(' ', $this->validator->getErrors()));
        }

        $update = [
            'full_name' => $this->request->getPost('full_name'),
            'email'     => $this->request->getPost('email') ?: null,
            'phone'     => $this->request->getPost('phone') ?: null,
        ];

        $this->userModel->update($userId, $update);
        session()->set('full_name', $update['full_name']);
        if (($user['email'] ?? null) !== $update['email']) {
            SecurityLogModel::log('email_changed', null, 'owner profile email');
        }

        return redirect()->to('/admin/settings?tab=profile')->with('success', 'Profile updated.');
    }

    public function changeOwnerPassword()
    {
        $user = $this->userModel->find((int) session()->get('user_id'));

        // The Owner must prove they know the current password, so a stolen
        // or left-open session cannot be used to lock the Owner out.
        if (! $user || ! password_verify((string) $this->request->getPost('current_password'), $user['password_hash'])) {
            SecurityLogModel::log('password_change_failed', null, 'wrong current password');
            return redirect()->to('/admin/settings?tab=profile')->with('error', 'Current password is incorrect.');
        }

        return $this->changePasswordFor((int) $user['user_id'], 'owner');
    }

    /**
     * The Owner resets a staff member's password. The owner proves who they are with their own
     * password and says they checked with the staff member. The staff member gets a temporary
     * password by email (2 hours) and must set their own. If the account has no email, the
     * temporary password is shown to the owner once, to pass on privately.
     */
    public function resetStaffPassword($userId)
    {
        $back  = '/admin/settings?tab=accounts';
        $staff = $this->userModel->activeStaff()->find((int) $userId);
        $owner = $this->userModel->find((int) session()->get('user_id'));

        if (! $staff) {
            return redirect()->to($back)->with('error', 'Staff account not found.');
        }
        if (AttemptStore::hit('owner_reset_' . (int) session()->get('user_id'), 600) > 5) {
            return redirect()->to($back)->with('error', 'Too many attempts. Please wait a few minutes and try again.');
        }
        if (! $owner || ! password_verify((string) $this->request->getPost('owner_password'), $owner['password_hash'])) {
            SecurityLogModel::log('password_reset_failed', null, 'wrong owner password for staff: ' . $staff['username']);

            return redirect()->to($back)->with('error', 'Your password is incorrect.');
        }
        if (! $this->request->getPost('confirmed')) {
            return redirect()->to($back)->with('error', 'Tick "I confirmed with them" first.');
        }

        $reset = new StaffPasswordReset();

        if (! empty($staff['email'])) {
            if ($reset->issueAndEmail($staff, StaffPasswordReset::KIND_RESET) === null) {
                return redirect()->to($back)->with('error', "We couldn't send the email, so nothing was changed. Please try again.");
            }
            $toast = Toast::success('Temporary password sent', 'Emailed to ' . StaffPasswordReset::maskEmail($staff['email']) . '. They set their own when they sign in.');
        } else {
            $password = $reset->issue($staff, StaffPasswordReset::KIND_RESET);
            session()->setFlashdata('temp_password_once', ['name' => $staff['full_name'], 'password' => $password, 'hours' => 2]);
            $toast = Toast::info('Temporary password ready', 'No email on file, so it is shown below once.');
        }

        AttemptStore::forget('owner_reset_' . (int) session()->get('user_id'));
        SecurityLogModel::log('password_reset', null, 'staff: ' . $staff['username'] . ' reset by owner (temporary password)');

        return redirect()->to($back)->with('toast', $toast);
    }

    private function changePasswordFor(int $userId, string $label)
    {
        $newPass = $this->request->getPost('new_password');
        $confirm = $this->request->getPost('confirm_password');

        if ($error = PasswordPolicy::check($newPass)) {
            return redirect()->to('/admin/settings?tab=profile')->with('error', $error);
        }
        if ($newPass !== $confirm) {
            return redirect()->to('/admin/settings?tab=profile')->with('error', 'Passwords do not match.');
        }

        $this->userModel->update($userId, ['password_hash' => password_hash($newPass, PASSWORD_DEFAULT)]);
        SecurityLogModel::log('password_changed', null, "{$label} password changed by admin session");

        return redirect()->to('/admin/settings?tab=profile')->with('success', ucfirst($label) . ' password updated.');
    }

    /**
     * New staff account: name, username and email. The system creates the account, then
     * emails a temporary password (48 hours). If the email can't be sent the account is not created.
     */
    public function addStaffAccount()
    {
        $back = '/admin/settings?tab=accounts';

        if (! $this->validate([
            'full_name'     => 'required',
            'username'      => 'required|is_unique[users.username]',
            'contact_email' => 'required|valid_email|is_unique[users.email]',
        ])) {
            return redirect()->to($back)->with('error', implode(' ', $this->validator->getErrors()));
        }

        $email = trim((string) $this->request->getPost('contact_email'));
        $reset = new StaffPasswordReset();
        $db    = db_connect();

        $db->transStart();
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
        $db->transComplete();

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
        if ((new StaffPasswordReset())->issueAndEmail($staff, StaffPasswordReset::KIND_INVITE) === null) {
            return redirect()->to($back)->with('error', "We couldn't send the email. Please try again.");
        }

        SecurityLogModel::log('invite_resent', null, 'staff: ' . $staff['username']);

        return redirect()->to($back)->with('toast', Toast::success('Invite resent', 'A new temporary password was emailed to ' . StaffPasswordReset::maskEmail($staff['email']) . '.'));
    }

    public function deactivateStaff($userId)
    {
        $user = $this->userModel->activeStaff()->find((int) $userId);

        if (! $user) {
            return redirect()->to('/admin/settings?tab=accounts')->with('error', 'Staff account not found.');
        }

        // Reactivating must not recreate a shared password. Hashes can't be
        // compared to each other, so this can only catch the known seeded
        // default (see staff:check-passwords); the login-time check covers the rest.
        if (! $user['is_active'] && $this->sharesDefaultPasswordWithActiveStaff($user)) {
            return redirect()->to('/admin/settings?tab=accounts')->with(
                'error',
                "{$user['full_name']} can't be reactivated because their password matches another active staff member's. Reset their password first."
            );
        }

        $this->userModel->update((int) $userId, ['is_active' => $user['is_active'] ? 0 : 1]);
        SecurityLogModel::log($user['is_active'] ? 'account_deactivated' : 'account_reactivated', null, 'staff: ' . $user['username']);

        return redirect()->to('/admin/settings?tab=accounts')->with('success', $user['is_active'] ? 'Staff account deactivated.' : 'Staff account reactivated.');
    }

    private function sharesDefaultPasswordWithActiveStaff(array $user): bool
    {
        $default = \App\Database\Seeds\LegacyDataSeeder::DEFAULT_PASSWORD;

        if (! password_verify($default, $user['password_hash'])) {
            return false;
        }

        return $this->userModel->staffMatchingPassword($default, true, (int) $user['user_id']) !== [];
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
