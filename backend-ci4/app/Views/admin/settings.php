
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
];
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap');

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
    font-weight: 500;
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
        <?php endif; ?>
    </div>
</div>

<?= $this->endSection() ?>
