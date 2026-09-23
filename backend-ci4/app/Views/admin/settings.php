
<?= $this->extend('layouts/admin_layout') ?>
<?= $this->section('content') ?>

<?php
$dss = $dss ?? [];
$admin = $admin ?? [];
$staffAccounts = $staffAccounts ?? [];
$tab = $tab ?? 'profile';
$tabs = [
    'profile'  => 'My Profile',
    'accounts' => 'User Account',
    'security' => 'Security',
    'notif'    => 'Notifications',
    'dss'      => 'DSS Parameter',
];
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap');

/* Header (title row) — height matched to the sidebar's own (untouched)
   brand block so the two horizontal lines land at the same height:
   sidebar has 16px top padding + ~60px brand block = ~76px. */
.topbar {
    background: #4B6B42;
    padding: 0 32px 0 48px;
    height: 76px;
    min-height: 76px;
    box-sizing: border-box;
}
.topbar h1 {
    font-family: 'Poppins', sans-serif;
    font-size: 26px;
    font-weight: 600;
    letter-spacing: normal;
    line-height: 1;
    color: #fff;
    margin: 0;
}
.notification-button {
    width: 44px;
    height: 44px;
    border-radius: 8px;
    background: #6AAB5A;
    color: #fff;
    font-size: 22px;
}
.notification-button:hover {
    background: #6AAB5A;
    filter: brightness(1.08);
    color: #fff;
}

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
    border-radius: 6px 6px 0 0;
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
.set-body h4 { font-weight: 800; }
.set-hint { color: #666; margin-bottom: 20px; }

.set-card { background: #fff; border-radius: 14px; padding: 22px 26px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin-bottom: 20px; }
.set-profile-head { display: flex; align-items: center; gap: 18px; }
.set-avatar { width: 64px; height: 64px; border-radius: 50%; background: #eee; display: flex; align-items: center; justify-content: center; font-size: 30px; color: #999; }
.set-role-pill { display: inline-flex; align-items: center; gap: 6px; background: #e9e7e1; border-radius: 999px; padding: 4px 14px; font-size: 13px; font-weight: 600; margin: 4px 0; }
.set-field label { font-weight: 600; margin-bottom: 6px; display: block; }
.set-field input, .set-field select { width: 100%; padding: 11px; border: 1px solid #ccc; border-radius: 8px; }
.set-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
@media (max-width: 800px) { .set-grid { grid-template-columns: 1fr; } }

.acct-row { display: flex; align-items: center; gap: 14px; padding: 14px 0; border-bottom: 1px solid #eee; }
.acct-row:last-child { border-bottom: none; }
.acct-avatar { width: 42px; height: 42px; border-radius: 50%; background: #f0efe9; display: flex; align-items: center; justify-content: center; font-weight: 700; }
.acct-name { font-weight: 700; }
.acct-email { color: #777; font-size: 13px; }
.acct-role-pill { background: #e9e7e1; border-radius: 999px; padding: 4px 12px; font-size: 12px; font-weight: 700; }

.notif-pref-row { display: flex; justify-content: space-between; align-items: center; padding: 16px 0; border-bottom: 1px solid #eee; }
.notif-pref-row:last-child { border-bottom: none; }
.notif-pref-row strong { display: block; }
.notif-pref-row span.desc { color: #777; font-size: 13px; }

.dss-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
@media (max-width: 900px) { .dss-cards { grid-template-columns: 1fr; } }
.dss-formula { background: var(--content-bg); border-radius: 10px; padding: 14px 16px; font-size: 13px; text-align: center; margin-top: 14px; }
.dss-warning { background: #eee; border-radius: 10px; padding: 16px 20px; display: flex; gap: 14px; margin-bottom: 20px; }
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

            <div class="set-card">
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
                            <input value="—" readonly style="color:#888;">
                        </div>
                    </div>
                    <div class="text-end"><button type="submit" class="btn btn-maroon">Save</button></div>
                </form>
            </div>

        <?php elseif ($tab === 'accounts'): ?>
            <h4>User Accounts</h4>
            <div class="set-hint">Manage staff access. Only the owner can add, edit, or deactivate account.</div>

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
                            <div class="acct-email"><?= esc($s['email'] ?? $s['username']) ?></div>
                        </div>
                        <span class="acct-role-pill">Staff</span>
                        <span class="status-pill <?= $s['is_active'] ? 'green' : 'red' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span>
                        <form method="post" action="<?= site_url('admin/settings/staff-accounts/toggle/' . $s['user_id']) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm <?= $s['is_active'] ? 'btn-maroon' : 'btn-success' ?>"><?= $s['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>

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
                                <div class="set-field mb-3"><label>Password</label><input name="password" type="password" placeholder="Minimum 8 characters" required></div>
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

        <?php elseif ($tab === 'security'): ?>
            <h4>Security</h4>
            <div class="set-hint">Manage your password and session access</div>

            <div class="set-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0">Owner Password</h5>
                </div>
                <form method="post" action="<?= site_url('admin/settings/owner-password') ?>">
                    <?= csrf_field() ?>
                    <div class="set-grid mb-3">
                        <div class="set-field"><label>New Password</label><input name="new_password" type="password" placeholder="Minimum 8 characters"></div>
                        <div class="set-field"><label>Confirm New Password</label><input name="confirm_password" type="password"></div>
                    </div>
                    <div class="text-end"><button type="submit" class="btn btn-outline-dark">Change Password</button></div>
                </form>
            </div>

            <div class="set-card">
                <h5 class="fw-bold mb-3">Staff Password</h5>
                <p class="text-muted small">Staff share a single password to sign in to the POS terminal.</p>
                <form method="post" action="<?= site_url('admin/settings/staff-password') ?>">
                    <?= csrf_field() ?>
                    <div class="set-grid mb-3">
                        <div class="set-field"><label>New Password</label><input name="new_password" type="password" placeholder="Minimum 8 characters"></div>
                        <div class="set-field"><label>Confirm New Password</label><input name="confirm_password" type="password"></div>
                    </div>
                    <div class="text-end"><button type="submit" class="btn btn-outline-dark">Change Password</button></div>
                </form>
            </div>

        <?php elseif ($tab === 'notif'): ?>
            <h4>Notification Preferences</h4>
            <div class="set-hint">Choose how and when the system alerts you about inventory events.</div>

            <div class="set-card">
                <?php
                $prefs = [
                    ['Reorder Point (ROP) Alerts', "Notify when an item reaches its calculated ROP threshold", true],
                    ['Low Stock Warnings (Approaching ROP)', 'Early warning when stock drops to 120% of the ROP value', true],
                    ['Daily Sales Summary', "Receive a summary of the day's transactions every night at 9 PM", true],
                    ['Procurement Schedule Reminders', 'Alert 2 days before a scheduled restock date', true],
                    ['Weekly Trend Report', 'Auto-generate and display a demand summary every Monday', false],
                ];
                ?>
                <?php foreach ($prefs as $p): ?>
                    <div class="notif-pref-row">
                        <div><strong><?= esc($p[0]) ?></strong><span class="desc"><?= esc($p[1]) ?></span></div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" style="width:2.5em;height:1.4em;" <?= $p[2] ? 'checked' : '' ?> disabled>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php elseif ($tab === 'dss'): ?>
            <h4>DSS Parameter</h4>
            <div class="set-hint">Configure the core EOQ and ROP computation inputs. Changes take effect on the next system circulation.</div>

            <div class="dss-warning">
                <i class="bi bi-exclamation-triangle fs-4"></i>
                <div>Changing these values will affect all procurement recommendations system-wide. Only the owner can modify DSS parameters. The system does not use AI &mdash; all calculations are based on historical transaction data and the formulas below.</div>
            </div>

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
                        <div class="set-field">
                            <label>Holding Cost per Unit/Year (H) - ₱</label>
                            <input name="holding_cost_per_unit" type="number" step="0.01" min="0" value="<?= esc($dss['holding_cost_per_unit'] ?? 0) ?>" required>
                            <small class="text-muted">Cost to store one item for one year (space, insurance)</small>
                        </div>
                        <div class="dss-formula">EOQ = √( 2 × D<sub>annual</sub> × S / H ) &middot; D<sub>annual</sub> = d × 365</div>
                    </div>
                    <div class="set-card">
                        <h5 class="fw-bold mb-3">ROP Parameters</h5>
                        <div class="set-field mb-3">
                            <label>Service Level Target (%)</label>
                            <input name="service_level_target" type="number" step="0.01" min="0" max="100" value="<?= esc($dss['service_level_target'] ?? 95) ?>" required>
                            <small class="text-muted">Probability of not stocking out (Z = <?= esc($dss['z_score'] ?? 1.645) ?> at <?= esc($dss['service_level_target'] ?? 95) ?>%)</small>
                        </div>
                        <div class="set-field mb-2">
                            <label>Z-Score</label>
                            <input name="z_score" type="number" step="0.001" value="<?= esc($dss['z_score'] ?? 1.645) ?>" required>
                        </div>
                        <div class="set-field">
                            <label>Demand Lookback Period</label>
                            <input name="demand_lookback_days" type="number" min="1" value="<?= esc($dss['demand_lookback_days'] ?? 90) ?>" required>
                            <small class="text-muted">How many days of past sales are used to compute avg demand.</small>
                        </div>
                        <div class="dss-formula">ROP = d × L + Safety stock &middot; SS = Z × &sigma;d × √L</div>
                    </div>
                </div>
                <div class="text-end mt-3"><button type="submit" class="btn btn-maroon">Save Parameters</button></div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?= $this->endSection() ?>
