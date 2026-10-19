
<?= $this->extend('layouts/admin_layout') ?>
<?= $this->section('content') ?>

<?php
$dss = $dss ?? [];
$admin = $admin ?? [];
$staffAccounts = $staffAccounts ?? [];
$tab = $tab ?? 'profile';
$tabs = [
    'profile'  => 'My Profile',
    'accounts' => 'Manage Accounts',
    'notif'    => 'Notifications',
    'dss'      => 'DSS Parameter',
    'records'  => 'Data & Records',
];
?>

<style>

/* Tabs row (bottom of header) */
.set-tabs {
    display: flex;
    gap: 2px;
    background: #4B6B42;
    padding: 0;
    margin: 0;
}
.set-tabs a {
    flex: 1;
    height: 44px;
    box-sizing: border-box;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    text-decoration: none;
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
    font-size: 15px;
    font-weight: 400;
    color: #fff;
    background: #4B6B42;
    border: 1px solid rgba(255, 255, 255, 0.45);
    border-bottom: none;
    border-radius: var(--radius-control) var(--radius-control) 0 0;
    transition: background-color 0.15s ease;
}
.set-tabs a:hover:not(.active) {
    background: rgba(255, 255, 255, 0.08);
}
.set-tabs a.active {
    background: #F3F1EB;
    color: #1E2616;
    font-weight: 600;
    border: none;
}

.set-body { padding: 24px; }
.set-detail { display: flex; justify-content: space-between; gap: 16px; padding: 11px 0; border-bottom: 1px solid #f2f2f2; font-size: 14px; }
.set-detail:last-child { border-bottom: none; }
.set-detail span { color: #777; }
.set-detail strong { text-align: right; word-break: break-word; }
.set-quick { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
.set-quick a { display: flex; align-items: center; gap: 12px; background: #fff; border-radius: var(--radius-card); padding: 16px 18px; box-shadow: 0 1px 3px rgba(0,0,0,.06); text-decoration: none; color: inherit; transition: box-shadow .15s ease, transform .15s ease; }
.set-quick a:hover { box-shadow: 0 4px 10px rgba(0,0,0,.1); transform: translateY(-1px); }
.set-quick .ic { width: 40px; height: 40px; border-radius: var(--radius-icon); background: var(--green-bg); color: var(--green-text); display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.set-quick strong { display: block; font-size: 14px; }
.set-quick small { color: #777; font-size: 12px; }
.set-body h4 { font-weight: 800; }
.set-hint { color: #666; margin-bottom: 20px; }
/* Data & Records tab (exact design spec). Poppins like the rest of the app. */
.rec-tab { font-family: 'Poppins', sans-serif; }
.rec-tab h4, .rec-tab h5, .rec-tab label { font-family: inherit; }
.set-body .rec-page-title { font-size: 30px; font-weight: 700; color: #1F2617; margin: 0; }
.rec-page-sub { font-size: 14px; color: #5C6152; margin: 4px 0 20px; }
.rec-card { background: #FFFFFF; border: 1px solid #E8E5DC; border-radius: 14px; padding: 24px 28px; margin-bottom: 20px; box-shadow: none; }
.rec-title { font-size: 19px; font-weight: 600; color: #1F2617; margin: 0; line-height: 1.3; }
.rec-desc { font-size: 14px; color: #5C6152; line-height: 1.55; margin: 4px 0 0; }
.rec-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px 32px; }
.rec-text { flex: 1 1 320px; min-width: 0; max-width: 560px; }
.rec-controls { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
.rec-select {
    width: 290px; height: 46px; padding: 0 40px 0 14px; font-size: 15px; /* fits "Keep 10 years (recommended)" */
    border: 1px solid #D6D2C6; border-radius: 10px; color: #1F2617;
    background: #fff url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'><path d='M1 1l5 5 5-5' stroke='%235C6152' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>") no-repeat right 14px center;
    font-family: 'Poppins', sans-serif; appearance: none; -webkit-appearance: none; -moz-appearance: none; cursor: pointer;
}
.rec-select:focus-visible { outline: 3px solid var(--focus-ring); outline-offset: 2px; }
.rec-btn {
    display: inline-flex; align-items: center; justify-content: center; height: 44px; padding: 0 18px;
    border: 0; border-radius: 10px; background: #71AA60; color: #fff; font-family: 'Poppins', sans-serif;
    font-size: 15px; font-weight: 600; text-decoration: none; white-space: nowrap; cursor: pointer;
    transition: background-color .15s ease;
}
.rec-btn:hover { background: #5F9850; color: #fff; }
.rec-btn:active { background: #4F8542; }
.rec-btn:focus-visible { outline: 3px solid var(--focus-ring); outline-offset: 2px; }
.rec-btn:disabled { background: #E8E5DC; color: #8A8D7E; cursor: not-allowed; }
/* Old sales records: yellow box when some are due, neutral box when none. */
.rec-box { display: flex; flex-wrap: wrap; align-items: center; gap: 14px 16px; margin-top: 16px; padding: 18px 20px; border-radius: 12px; }
.rec-box.is-due { background: #FFF8E6; border: 1px solid #F3E2B3; }
.rec-box.is-clear { background: #F5F3EE; }
.rec-box-ic { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.rec-box.is-due .rec-box-ic { background: #FFF0C7; color: #7A5300; }
.rec-box.is-clear .rec-box-ic { background: #EAF1E3; color: #3B5434; }
.rec-box-ic i { line-height: 1; }
.rec-box-ic i::before { vertical-align: 0; }
.rec-box-text { flex: 1 1 260px; min-width: 0; }
.rec-box-title { font-size: 16px; font-weight: 600; color: #1F2617; margin: 0; }
.rec-box-sub { font-size: 14px; color: #5C6152; margin: 2px 0 0; line-height: 1.5; }

.set-card { background: #fff; border-radius: var(--radius-card); padding: 22px 26px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin-bottom: 20px; }
.set-profile-head { display: flex; align-items: center; gap: 18px; }
.set-avatar { width: 64px; height: 64px; border-radius: 50%; background: #eee; display: flex; align-items: center; justify-content: center; font-size: 30px; color: #999; }
.set-role-pill { display: inline-flex; align-items: center; gap: 6px; background: #e9e7e1; border-radius: var(--radius-pill); padding: 4px 14px; font-size: 13px; font-weight: 600; margin: 4px 0; }
.set-field label { font-weight: 600; margin-bottom: 6px; display: block; }
.set-field input, .set-field select { width: 100%; padding: 11px; border: 1px solid #ccc; border-radius: var(--radius-control); }
.set-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
@media (max-width: 800px) { .set-grid { grid-template-columns: 1fr; } }

.acct-row { display: flex; align-items: center; gap: 14px; padding: 14px 0; border-bottom: 1px solid #eee; }
.acct-row:last-child { border-bottom: none; }
.acct-avatar { width: 42px; height: 42px; border-radius: 50%; background: #f0efe9; display: flex; align-items: center; justify-content: center; font-weight: 700; }
.acct-name { font-weight: 700; }
.acct-email { color: #777; font-size: 13px; }
.acct-role-pill { background: #e9e7e1; border-radius: var(--radius-pill); padding: 4px 12px; font-size: 12px; font-weight: 700; }

.notif-pref-row { display: flex; justify-content: space-between; align-items: center; padding: 16px 0; border-bottom: 1px solid #eee; }
.notif-pref-row:last-child { border-bottom: none; }
.notif-pref-row strong { display: block; }
.notif-pref-row span.desc { color: #777; font-size: 13px; }

.dss-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
@media (max-width: 900px) { .dss-cards { grid-template-columns: 1fr; } }
.dss-formula { background: var(--content-bg); border-radius: var(--radius-control); padding: 14px 16px; font-size: 13px; text-align: center; margin-top: 14px; }
.dss-run { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 6px; }
.dss-run-help { margin: 0 0 18px; font-size: 13px; color: #5C6152; }
.dss-run-status { margin: 0 0 6px; font-size: 13px; font-weight: 500; color: #2C3A1E; }
.dss-last-run { font-size: 14px; color: #5C6152; }
.dss-last-run strong { color: #1F2617; font-weight: 600; }
.dss-spin { display: inline-block; animation: dssSpin 1s linear infinite; }
@keyframes dssSpin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .dss-spin { animation: none; } }
.dss-warning { background: #eee; border-radius: var(--radius-control); padding: 16px 20px; display: flex; gap: 14px; margin-bottom: 20px; }
</style>

<div class="page-wrap" style="padding:0;">
    <div class="set-tabs">
        <?php foreach ($tabs as $key => $label): ?>
            <a href="?tab=<?= $key ?>" class="<?= $tab === $key ? 'active' : '' ?>"><?= esc($label) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="set-body">
        <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>

        <?php if ($tab === 'profile'): ?>
            <h4>My Profile</h4>
            <div class="set-hint">Manage your personal information and login credentials</div>

            <div class="set-card">
                <div class="set-profile-head">
                    <div class="set-avatar"><i class="bi bi-person-fill"></i></div>
                    <div>
                        <div class="fw-bold fs-5"><?= esc($admin['full_name'] ?? '') ?></div>
                        <div class="set-role-pill">★ Owner - Administrator</div>
                        <div class="text-muted small">Last login: <?= !empty($admin['last_login']) ? esc(date('M j, Y g:i A', strtotime($admin['last_login']))) : '—' ?></div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-lg-7">
                    <div class="set-card h-100 mb-0">
                        <h5 class="fw-bold mb-3">Personal Information</h5>
                <form method="post" action="<?= site_url('admin/settings/account') ?>">
                    <?= csrf_field() ?>
                    <div class="set-grid mb-3">
                        <div class="set-field">
                            <label>Full Name</label>
                            <input name="full_name" value="<?= esc($admin['full_name'] ?? '') ?>" required>
                        </div>
                        <div class="set-field">
                            <label>Role</label>
                            <input value="Owner / Administrator" readonly style="color:#888;">
                        </div>
                    </div>
                    <div class="set-grid mb-3">
                        <div class="set-field">
                            <label>Email Address</label>
                            <input name="email" type="email" value="<?= esc($admin['email'] ?? '') ?>">
                        </div>
                        <div class="set-field">
                            <label>Contact number</label>
                            <input name="phone" value="<?= esc($admin['phone'] ?? '') ?>" placeholder="e.g. 0917 123 4567">
                        </div>
                    </div>
                    <div class="text-end"><button type="submit" class="btn btn-success">Save</button></div>
                </form>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="set-card h-100 mb-0">
                        <h5 class="fw-bold mb-2">Account Details</h5>
                        <div class="set-detail"><span>Username</span><strong><?= esc($admin['username'] ?? '—') ?></strong></div>
                        <div class="set-detail"><span>Role</span><strong>Owner / Administrator</strong></div>
                        <div class="set-detail"><span>Account Status</span><strong><?= !empty($admin['is_active']) ? 'Active' : 'Inactive' ?></strong></div>
                        <div class="set-detail"><span>Member Since</span><strong><?= !empty($admin['created_at']) ? esc(date('F j, Y', strtotime($admin['created_at']))) : '—' ?></strong></div>
                        <div class="set-detail"><span>Last Login</span><strong><?= !empty($admin['last_login']) ? esc(date('M j, Y g:i A', strtotime($admin['last_login']))) : '—' ?></strong></div>
                    </div>
                </div>
            </div>

            <?php $activeStaff = count(array_filter($staffAccounts, fn ($u) => ! empty($u['is_active']) && ($u['role'] ?? '') === 'Staff')); ?>
            <div class="set-quick">
                <a href="<?= site_url('admin/settings?tab=accounts') ?>"><span class="ic"><i class="bi bi-people"></i></span><div><strong>Manage Accounts</strong><small><?= $activeStaff ?> active staff &middot; passwords</small></div></a>
                <a href="<?= site_url('admin/settings?tab=notif') ?>"><span class="ic"><i class="bi bi-bell"></i></span><div><strong>Notifications</strong><small>Choose which alerts you receive</small></div></a>
                <a href="<?= site_url('admin/settings?tab=dss') ?>"><span class="ic"><i class="bi bi-sliders"></i></span><div><strong>DSS Parameter</strong><small>EOQ and ROP computation inputs</small></div></a>
                <a href="<?= site_url('admin/settings?tab=records') ?>"><span class="ic"><i class="bi bi-archive"></i></span><div><strong>Data &amp; Records</strong><small>Old sales records &middot; export &amp; clean-up</small></div></a>
            </div>

        <?php elseif ($tab === 'accounts'): ?>
            <h4>Manage Accounts</h4>
            <div class="set-hint">Manage staff access and account passwords. Only the owner can add, edit, or deactivate accounts.</div>

            <div class="set-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0">Active Accounts (<?= count($staffAccounts) + 1 ?>)</h5>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addStaffModal"><i class="bi bi-plus-circle"></i> Add Staff Account</button>
                </div>

                <div class="acct-row">
                    <div class="acct-avatar"><?= esc(strtoupper(substr($admin['full_name'] ?? 'A', 0, 1))) ?></div>
                    <div class="flex-grow-1">
                        <div class="acct-name"><?= esc($admin['full_name'] ?? '') ?></div>
                        <div class="acct-email"><?= esc($admin['email'] ?? '') ?></div>
                    </div>
                    <span class="acct-role-pill" style="background:#fdf1c0;">Owner</span>
                    <span class="status-pill green">Active</span>
                    <span class="text-muted small">(You)</span>
                </div>

                <?php foreach ($staffAccounts as $s): ?>
                    <div class="acct-row">
                        <div class="acct-avatar"><?= esc(strtoupper(substr($s['full_name'], 0, 1))) ?></div>
                        <div class="flex-grow-1">
                            <div class="acct-name"><?= esc($s['full_name']) ?></div>
                            <div class="acct-email">@<?= esc($s['username']) ?><?= ! empty($s['email']) ? ' &middot; ' . esc($s['email']) : '' ?></div>
                        </div>
                        <span class="acct-role-pill">Staff</span>
                        <span class="status-pill <?= $s['is_active'] ? 'green' : 'red' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span>
                        <button type="button" class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#resetStaffPwModal"
                                data-action="<?= site_url('admin/settings/staff-accounts/password/' . $s['user_id']) ?>"
                                data-name="<?= esc($s['full_name'], 'attr') ?>">Reset Password</button>
                        <form method="post" action="<?= site_url('admin/settings/staff-accounts/toggle/' . $s['user_id']) ?>"
                              data-confirm="<?= $s['is_active'] ? "They won't be able to sign in until you reactivate the account." : 'They will be able to sign in again with their password.' ?>"
                              data-confirm-title="<?= ($s['is_active'] ? 'Deactivate ' : 'Reactivate ') . esc($s['full_name'], 'attr') ?>?"
                              data-confirm-ok="<?= $s['is_active'] ? 'Deactivate' : 'Reactivate' ?>"
                              data-confirm-variant="<?= $s['is_active'] ? 'danger' : 'success' ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm <?= $s['is_active'] ? 'btn-maroon' : 'btn-success' ?>"><?= $s['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
                        </form>
                        <form method="post" action="<?= site_url('admin/settings/staff-accounts/delete/' . $s['user_id']) ?>" data-confirm="They will no longer be able to sign in. Their past sales and records will be kept."
                              data-confirm-title="Remove <?= esc($s['full_name'], 'attr') ?>?"
                              data-confirm-ok="Remove" data-confirm-variant="danger">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="modal fade" id="resetStaffPwModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="post" id="resetStaffPwForm" action="">
                            <?= csrf_field() ?>
                            <div class="modal-header">
                                <h5 class="modal-title">Reset Password &mdash; <span id="resetStaffPwName"></span></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="set-field mb-3"><label>New Password</label><input name="new_password" type="password" placeholder="8+ characters, letter and number" required autocomplete="new-password"></div>
                                <div class="set-field"><label>Confirm Password</label><input name="confirm_password" type="password" placeholder="Re-enter password" required autocomplete="new-password"></div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-success">Update password</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <script>
                document.getElementById('resetStaffPwModal').addEventListener('show.bs.modal', function (e) {
                    var btn = e.relatedTarget;
                    document.getElementById('resetStaffPwForm').action = btn.dataset.action;
                    document.getElementById('resetStaffPwName').textContent = btn.dataset.name;
                    this.querySelectorAll('input[type=password]').forEach(function (i) { i.value = ''; });
                });
            </script>

            <div class="modal fade" id="addStaffModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="post" action="<?= site_url('admin/settings/staff-accounts') ?>">
                            <?= csrf_field() ?>
                            <div class="modal-header">
                                <h5 class="modal-title">Add Staff Account</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="set-field mb-3"><label>Full Name</label><input name="full_name" required></div>
                                <div class="set-field mb-3"><label>Username</label><input name="username" required></div>
                                <div class="set-field mb-3"><label>Contact email</label><input name="contact_email" type="email" placeholder="Personal email"></div>
                                <div class="set-field mb-3"><label>Password</label><input name="password" type="password" placeholder="8+ characters, letter and number" required></div>
                                <div class="set-field"><label>Confirm Password</label><input name="confirm_password" type="password" placeholder="Re-enter password" required></div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-success">Save &amp; create account</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <h5 class="fw-bold mt-4 mb-3">Passwords</h5>
            <div class="row g-3">
                <div class="col-lg-6">
                    <div class="set-card h-100 mb-0">
                        <h5 class="fw-bold mb-3">Owner Password</h5>
                        <form method="post" action="<?= site_url('admin/settings/owner-password') ?>">
                            <?= csrf_field() ?>
                            <div class="d-flex flex-column gap-3 mb-3">
                                <div class="set-field"><label>Current Password</label><input name="current_password" type="password" required autocomplete="current-password"></div>
                                <div class="set-field"><label>New Password</label><input name="new_password" type="password" placeholder="8+ characters, letter and number" required autocomplete="new-password"></div>
                                <div class="set-field"><label>Confirm New Password</label><input name="confirm_password" type="password" required autocomplete="new-password"></div>
                            </div>
                            <div class="text-end"><button type="submit" class="btn btn-outline-dark">Change Password</button></div>
                        </form>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="set-card h-100 mb-0">
                        <h5 class="fw-bold mb-2">Staff Passwords</h5>
                        <p class="text-muted small mb-0">Each staff member signs in with their own unique password. The system recognizes who is signing in by their password. Use <strong>Reset Password</strong> on their row if they forget it.</p>
                    </div>
                </div>
            </div>


        <?php elseif ($tab === 'notif'): ?>
            <h4>Notification Preferences</h4>
            <div class="set-hint">Choose how and when the system alerts you about inventory events.</div>

            <div class="set-card">
                <?php
                $notifPrefs = $notifPrefs ?? [];
                $prefs = [
                    ['rop_alerts', 'Reorder Point (ROP) Alerts', 'Notify when an item reaches its calculated ROP threshold'],
                    ['low_stock_warnings', 'Low Stock Warnings (Approaching ROP)', 'Early warning before stock reaches the ROP. ' . \App\Models\ProductModel::LOW_STOCK_HELP],
                    ['daily_sales_summary', 'Daily Sales Summary', "Receive a summary of the day's transactions every night at 9 PM"],
                    ['procurement_reminders', 'Procurement Schedule Reminders', 'Alert 2 days before a scheduled restock date'],
                    ['weekly_trend_report', 'Weekly Trend Report', 'Auto-generate and display a demand summary every Monday'],
                ];
                ?>
                <?php foreach ($prefs as [$key, $label, $desc]): ?>
                    <?php $isOn = !empty($notifPrefs[$key]); ?>
                    <div class="notif-pref-row">
                        <div><strong><?= esc($label) ?></strong><span class="desc"><?= esc($desc) ?></span></div>
                        <form method="post" action="<?= site_url('admin/settings/notification-preference') ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="pref_key" value="<?= esc($key) ?>">
                            <input type="hidden" name="enabled" value="0">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" style="width:2.5em;height:1.4em;"
                                       name="enabled" value="1" <?= $isOn ? 'checked' : '' ?> onchange="this.form.submit()">
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php elseif ($tab === 'dss'): ?>
            <h4>DSS Parameter</h4>
            <div class="set-hint">Configure the core EOQ and ROP computation inputs. Changes take effect on the next calculation.</div>

            <div class="dss-warning">
                <i class="bi bi-exclamation-triangle fs-4"></i>
                <div>Changing these values will affect all procurement recommendations system-wide. Only the owner can modify DSS parameters. The system does not use AI &mdash; all calculations are based on historical transaction data and the formulas below.</div>
            </div>

            <?php // Recalculate ROP / EOQ / safety stock / classes now (same engine as `php spark dss:run`), in the background. ?>
            <form method="post" action="<?= site_url('admin/settings/run-engine') ?>" class="dss-run" id="dssRunForm">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-success" id="dssRunBtn"<?= $dssRunning ? ' disabled aria-busy="true"' : '' ?>>
                    <i class="bi bi-arrow-repeat<?= $dssRunning ? ' dss-spin' : '' ?>" aria-hidden="true"></i> <span class="dss-run-label"><?= $dssRunning ? 'Recalculating…' : 'Recalculate now' ?></span>
                </button>
                <span class="dss-last-run">
                    <?php if ($dssCalculated): ?>
                        Last calculated: <strong><?= esc($dssCalculated) ?></strong>
                    <?php else: ?>
                        Not calculated yet
                    <?php endif; ?>
                </span>
            </form>
            <p class="dss-run-status" id="dssRunStatus" role="status"<?= $dssRunning ? '' : ' hidden' ?>>
                <?= $dssStarted
                    ? 'Recalculating in the background. You can leave this page; you&rsquo;ll get a notice when it&rsquo;s done.'
                    : 'A recalculation is already running. You&rsquo;ll get a notice when it&rsquo;s done.' ?>
            </p>
            <p class="dss-run-help">Runs automatically every 7 days. Recalculate now after changing DSS settings or supplier lead times.</p>
            <script>
            (function () {
                const form = document.getElementById('dssRunForm');
                const btn = document.getElementById('dssRunBtn');
                const label = btn.querySelector('.dss-run-label');
                const icon = btn.querySelector('.bi');
                const busy = on => {
                    btn.disabled = on;
                    btn.toggleAttribute('aria-busy', on);
                    icon.classList.toggle('dss-spin', on);
                    label.textContent = on ? 'Recalculating…' : 'Recalculate now';
                };
                // The request only starts the run, so it returns quickly.
                form.addEventListener('submit', () => setTimeout(() => busy(true), 0));
                // Run finished (assets/js/dss-watch.js): enable the button again and refresh "Last calculated".
                window.addEventListener('dss:finished', e => {
                    busy(false);
                    document.getElementById('dssRunStatus').hidden = true;
                    if (e.detail && e.detail.calculated) {
                        const strong = document.createElement('strong');
                        strong.textContent = e.detail.calculated;
                        form.querySelector('.dss-last-run').replaceChildren('Last calculated: ', strong);
                    }
                });
            })();
            </script>

            <form method="post" action="<?= site_url('admin/settings/dss-parameters') ?>">
                <?= csrf_field() ?>
                <div class="dss-cards">
                    <div class="set-card">
                        <h5 class="fw-bold mb-3">EOQ Parameters</h5>
                        <div class="set-field mb-3">
                            <label>Ordering Cost per Order (S) - ₱</label>
                            <input name="ordering_cost" type="number" step="0.01" min="0" value="<?= esc($dss['ordering_cost'] ?? 0) ?>" required>
                            <small class="text-muted">Fixed cost incurred each time an order is placed (transport, admin)</small>
                        </div>
                        <div class="set-field mb-3">
                            <label>Holding Cost per Unit/Year (H) - ₱</label>
                            <input name="holding_cost_per_unit" type="number" step="0.01" min="0" value="<?= esc($dss['holding_cost_per_unit'] ?? 0) ?>" required>
                            <small class="text-muted">Cost to store one item for one year (space, insurance)</small>
                        </div>
                        <div class="set-field mb-3">
                            <label>Minimum Order Quantity</label>
                            <input name="minimum_order_qty" type="number" min="1" value="<?= esc($dss['minimum_order_qty'] ?? 5) ?>" required>
                            <small class="text-muted">EOQ floor. An item with no recorded demand (e.g. out of stock all period, or newly added) computes EOQ = 0 by formula alone — this ensures a reorder is still recommended.</small>
                        </div>
                        <div class="dss-formula">EOQ = √( 2 × D<sub>annual</sub> × S / H ) &middot; D<sub>annual</sub> = d × 365</div>
                    </div>
                    <div class="set-card">
                        <h5 class="fw-bold mb-3">ROP Parameters</h5>
                        <div class="set-field mb-3">
                            <label>Default Service Level Target (%)</label>
                            <input name="service_level_target" type="number" step="0.01" min="0" max="100" value="<?= esc($dss['service_level_target'] ?? 95) ?>" required>
                            <small class="text-muted">Probability of not stocking out (Z = <?= esc($dss['z_score'] ?? 1.645) ?> at <?= esc($dss['service_level_target'] ?? 95) ?>%). Used only as a fallback — each item normally gets its own service level (95% / 90% / 85%) from its K-Means Class A/B/C.</small>
                        </div>
                        <div class="set-field mb-3">
                            <label>Default Z-Score</label>
                            <input name="z_score" type="number" step="0.001" value="<?= esc($dss['z_score'] ?? 1.645) ?>" required>
                            <small class="text-muted">Applied when an item has no K-Means classification yet (e.g. clustering service unreachable and no cached assignment).</small>
                        </div>
                        <div class="set-field mb-3">
                            <label>Demand Lookback Period</label>
                            <input name="demand_lookback_days" type="number" min="1" value="<?= esc($dss['demand_lookback_days'] ?? 90) ?>" required>
                            <small class="text-muted">How many days of past sales are used to compute avg demand.</small>
                        </div>
                        <div class="dss-formula">ROP = d × L + Safety stock &middot; SS = Z × &sigma;d × √L</div>
                    </div>
                </div>
                <div class="text-end mt-3"><button type="submit" class="btn btn-success">Save Parameters</button></div>
            </form>

        <?php elseif ($tab === 'records'): ?>
            <div class="rec-tab">
            <h4 class="rec-page-title">Data &amp; Records</h4>
            <p class="rec-page-sub">Keep sales history tidy. Old records can be saved as PDF before they are removed.</p>

            <div class="rec-card">
                <form method="post" action="<?= site_url('admin/settings/retention') ?>" class="rec-row" id="retentionForm">
                    <?= csrf_field() ?>
                    <div class="rec-text">
                        <h5 class="rec-title"><label for="retentionYears" class="m-0">Retention period</label></h5>
                        <p class="rec-desc" id="retentionHelp">Sales records older than this can be saved as PDF and removed.<br>Check BIR record-keeping requirements before lowering this.</p>
                    </div>
                    <div class="rec-controls">
                        <select id="retentionYears" name="retention_years" class="rec-select" aria-describedby="retentionHelp" data-saved="<?= $retentionYears ?>">
                            <?php foreach ($retentionOptions as $y): ?>
                                <option value="<?= $y ?>" <?= $y === $retentionYears ? 'selected' : '' ?>>Keep <?= $y ?> years<?= $y === $retentionDefault ? ' (recommended)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="rec-btn" id="retentionSave" disabled>Save</button>
                    </div>
                </form>
                <script>
                (function () {
                    // Save turns on only when the choice differs from the saved one.
                    const sel = document.getElementById('retentionYears');
                    const btn = document.getElementById('retentionSave');
                    const sync = () => { btn.disabled = sel.value === sel.dataset.saved; };
                    sel.addEventListener('change', sync);
                    window.addEventListener('pageshow', () => { sel.value = sel.dataset.saved; sync(); });
                })();
                </script>
            </div>

            <div class="rec-card">
                <h5 class="rec-title">Old sales records</h5>
                <?php if ($oldRecords === 0 || empty($oldRange)): ?>
                    <div class="rec-box is-clear">
                        <span class="rec-box-ic" aria-hidden="true"><i class="bi bi-check-lg"></i></span>
                        <div class="rec-box-text">
                            <p class="rec-box-title">No sales records are older than <?= $retentionYears ?> years</p>
                            <p class="rec-box-sub">All sales history is kept. Nothing to do.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="rec-box is-due">
                        <span class="rec-box-ic" aria-hidden="true"><i class="bi bi-database"></i></span>
                        <div class="rec-box-text">
                            <p class="rec-box-title"><?= number_format($oldRecords) ?> sales records are older than <?= $retentionYears ?> years</p>
                            <p class="rec-box-sub">Sales from <?= esc(date('Y', strtotime($oldRange['first']))) ?> to <?= esc(date('M j, Y', strtotime($oldRange['last']))) ?>. Review them to save a copy or remove them.</p>
                        </div>
                        <a href="<?= site_url('admin/archive') ?>" class="rec-btn">Review records</a>
                    </div>
                <?php endif; ?>
            </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?= $this->endSection() ?>
