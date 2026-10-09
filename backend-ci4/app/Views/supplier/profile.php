
<?= $this->extend('layouts/supplier_layout') ?>
<?= $this->section('content') ?>

<?php $supplier = $supplier ?? []; $stats = $stats ?? []; ?>

<style>
.pf-head { background: #fff; border-radius: 14px; padding: 22px 26px; display: flex; align-items: center; gap: 18px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin-bottom: 20px; }
.pf-avatar { width: 64px; height: 64px; border-radius: 50%; background: var(--green-bg); color: var(--green-text); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 21px; flex-shrink: 0; }
.pf-badge { background: #e9e7e1; border-radius: 999px; padding: 4px 12px; font-size: 12px; font-weight: 700; margin-right: 6px; display: inline-block; }
.pf-badge.is-active { background: var(--green-bg); color: var(--green-text); }
.pf-badge.is-inactive { background: var(--red-bg); color: var(--red-text); }
.pf-meta { margin-left: auto; display: flex; gap: 28px; text-align: right; }
.pf-meta .v { font-size: 22px; font-weight: 800; line-height: 1.1; }
.pf-meta .l { font-size: 12px; color: #777; }

/* Cards sit in equal-height pairs (row + height 100%), so they never stretch past their content */
.pf-card { background: #fff; border-radius: 14px; padding: 20px 24px 14px; box-shadow: 0 1px 3px rgba(0,0,0,.06); height: 100%; display: flex; flex-direction: column; }
.pf-card-head { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
.pf-card-head .ic { width: 32px; height: 32px; border-radius: 8px; background: var(--green-bg); color: var(--green-text); display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
.pf-card-head h6 { margin: 0; font-weight: 800; font-size: 15px; }
.pf-card-head .btn { margin-left: auto; }
.pf-row { display: flex; justify-content: space-between; gap: 16px; padding: 11px 0; border-bottom: 1px solid #f2f2f2; font-size: 14px; }
.pf-row:last-child { border-bottom: none; }
.pf-row span { color: #777; }
.pf-row strong { text-align: right; word-break: break-word; }
.pf-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: auto; padding-top: 14px; padding-bottom: 6px; }
.pf-perf-label { display: flex; justify-content: space-between; font-size: 14px; }
.pf-perf-track { height: 8px; background: #e4e2dc; border-radius: 4px; overflow: hidden; margin: 6px 0 14px; }
.pf-perf-track > span { display: block; height: 100%; background: var(--green-text); border-radius: 4px; }
.pf-tiles { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: auto; padding-top: 6px; padding-bottom: 8px; }
.pf-tile { background: var(--content-bg); border-radius: 10px; padding: 12px 14px; }
.pf-tile .v { font-size: 20px; font-weight: 800; line-height: 1.15; }
.pf-tile .l { font-size: 12px; color: #777; margin-top: 2px; }
@media (max-width: 767px) { .pf-head { flex-wrap: wrap; } .pf-meta { margin-left: 0; text-align: left; width: 100%; } }
</style>

<?php $isActive = !empty($supplier['is_active']); ?>

<div class="page-wrap">
    <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>

    <div class="pf-head">
        <div class="pf-avatar"><?= esc(strtoupper(substr($supplier['company_name'] ?? '?', 0, 2))) ?></div>
        <div>
            <h4 class="mb-1"><?= esc($supplier['company_name'] ?? '') ?></h4>
            <div class="text-muted mb-2">Primary Supplier &middot; <?= esc($supplier['category'] ?? 'General supplier') ?></div>
            <span class="pf-badge <?= $isActive ? 'is-active' : 'is-inactive' ?>">&bull; <?= $isActive ? 'Active' : 'Inactive' ?></span>
            <span class="pf-badge">Verified Supplier</span>
            <span class="pf-badge">RfourL Military</span>
        </div>
        <div class="pf-meta">
            <div><div class="v"><?= esc($stats['on_time_rate'] ?? 0) ?>%</div><div class="l">On-time delivery</div></div>
            <div><div class="v"><?= esc($stats['total_fulfilled'] ?? '0 / 0') ?></div><div class="l">Orders fulfilled</div></div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="pf-card">
                <div class="pf-card-head">
                    <span class="ic"><i class="bi bi-building"></i></span>
                    <h6>Business Information</h6>
                    <button type="button" class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#editProfileModal">Edit</button>
                </div>
                <div class="pf-row"><span>Company Name</span><strong><?= esc($supplier['company_name'] ?? '—') ?></strong></div>
                <div class="pf-row"><span>Supplier ID</span><strong><?= esc($supplier['supplier_id'] ?? '—') ?></strong></div>
                <div class="pf-row"><span>Product Category</span><strong><?= esc($supplier['category'] ?? '—') ?></strong></div>
                <div class="pf-row"><span>Account Status</span><strong><?= $isActive ? 'Active' : 'Inactive' ?></strong></div>
                <div class="pf-row"><span>Joined</span><strong><?= !empty($supplier['created_at']) ? esc(date('F Y', strtotime($supplier['created_at']))) : '—' ?></strong></div>
                <div class="pf-row"><span>Primary Contact</span><strong><?= esc($supplier['contact_email'] ?? '—') ?></strong></div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="pf-card">
                <div class="pf-card-head">
                    <span class="ic"><i class="bi bi-graph-up-arrow"></i></span>
                    <h6>Performance Summary</h6>
                </div>
                <div class="pf-perf-label"><span>On-Time Delivery Rate</span><strong><?= esc($stats['on_time_rate'] ?? 0) ?>%</strong></div>
                <div class="pf-perf-track"><span style="width: <?= esc($stats['on_time_rate'] ?? 0) ?>%"></span></div>

                <div class="pf-perf-label"><span>Orders Completed (Month)</span><strong><?= esc($stats['orders_completed'] ?? '0 / 0') ?></strong></div>
                <div class="pf-perf-track"><span style="width: 100%"></span></div>

                <div class="pf-tiles">
                    <div class="pf-tile"><div class="v"><?= number_format($stats['items_month'] ?? 0) ?></div><div class="l">Items supplied (month)</div></div>
                    <div class="pf-tile"><div class="v"><?= esc($supplier['lead_time_days'] ?? '—') ?> days</div><div class="l">Avg. lead time</div></div>
                    <div class="pf-tile"><div class="v"><?= esc($stats['total_fulfilled'] ?? '0 / 0') ?></div><div class="l">Total orders fulfilled</div></div>
                    <div class="pf-tile"><div class="v"><?= number_format($stats['items_total'] ?? 0) ?></div><div class="l">Items supplied (all time)</div></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="pf-card">
                <div class="pf-card-head">
                    <span class="ic"><i class="bi bi-truck"></i></span>
                    <h6>Delivery Preferences</h6>
                    <button type="button" class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#editProfileModal">Edit</button>
                </div>
                <div class="pf-row"><span>Preferred Courier</span><strong><?= esc($supplier['preferred_courier'] ?? '') ?: '—' ?></strong></div>
                <div class="pf-row"><span>Default Lead Time</span><strong><?= esc($supplier['lead_time_days'] ?? '—') ?> business days</strong></div>
                <div class="pf-row"><span>Contact Person</span><strong><?= esc($supplier['contact_person'] ?? '') ?: '—' ?></strong></div>
                <div class="pf-row"><span>Contact Number</span><strong><?= esc($supplier['contact_number'] ?? '') ?: '—' ?></strong></div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="pf-card">
                <div class="pf-card-head">
                    <span class="ic"><i class="bi bi-shield-lock"></i></span>
                    <h6>Login &amp; Security</h6>
                </div>
                <div class="pf-row"><span>Username</span><strong><?= esc($loginAccount['username'] ?? '—') ?></strong></div>
                <div class="pf-row"><span>Login Email</span><strong><?= esc($loginAccount['email'] ?? '—') ?></strong></div>
                <div class="pf-row"><span>Last Login</span><strong><?= !empty($loginAccount['last_login']) ? esc(date('M j, Y g:i A', strtotime($loginAccount['last_login']))) : '—' ?></strong></div>
                <div class="pf-actions">
                    <button type="button" class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#loginEmailModal">Change Login Email</button>
                    <button type="button" class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#passwordModal">Change Password</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editProfileModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="<?= site_url('supplier/profile/update') ?>">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title">Edit Contact &amp; Delivery Info</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3"><label class="fw-bold mb-1 d-block">Contact Person</label><input class="form-control" name="contact_person" value="<?= esc($supplier['contact_person'] ?? '') ?>"></div>
                    <div class="mb-3"><label class="fw-bold mb-1 d-block">Contact Email</label><input class="form-control" type="email" name="contact_email" value="<?= esc($supplier['contact_email'] ?? '') ?>"></div>
                    <div class="mb-3"><label class="fw-bold mb-1 d-block">Contact Number</label><input class="form-control" name="contact_number" value="<?= esc($supplier['contact_number'] ?? '') ?>"></div>
                    <div><label class="fw-bold mb-1 d-block">Preferred Courier</label><input class="form-control" name="preferred_courier" value="<?= esc($supplier['preferred_courier'] ?? '') ?>"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="loginEmailModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="<?= site_url('supplier/profile/login-email') ?>">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title">Change Login Email</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">This is the email that receives your password-reset verification code.</p>
                    <div class="mb-3"><label class="fw-bold mb-1 d-block">New Login Email</label><input class="form-control" type="email" name="login_email" required></div>
                    <div><label class="fw-bold mb-1 d-block">Current Password</label><input class="form-control" type="password" name="current_password" required autocomplete="current-password"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="passwordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="<?= site_url('supplier/profile/password') ?>">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title">Change Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3"><label class="fw-bold mb-1 d-block">Current Password</label><input class="form-control" type="password" name="current_password" required autocomplete="current-password"></div>
                    <div class="mb-3"><label class="fw-bold mb-1 d-block">New Password</label><input class="form-control" type="password" name="new_password" minlength="8" pattern="(?=.*[A-Za-z])(?=.*\d).{8,}" title="At least 8 characters, with a letter and a number" required autocomplete="new-password"><div class="form-text">At least 8 characters, with a letter and a number.</div></div>
                    <div><label class="fw-bold mb-1 d-block">Confirm New Password</label><input class="form-control" type="password" name="confirm_password" required autocomplete="new-password"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
