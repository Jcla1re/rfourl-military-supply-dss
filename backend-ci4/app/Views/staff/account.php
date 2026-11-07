<?= $this->extend('layouts/staff_layout') ?>
<?= $this->section('content') ?>

<?php
$account      = $account ?? [];
$error        = $error ?? null;
$notice       = $notice ?? null;
$pendingEmail = $pendingEmail ?? null;
$suggestion   = session()->getFlashdata('password_suggestion');
$dash    = '—';
?>

<style>
.acct-card { background: #fff; border-radius: var(--radius-card); padding: 22px 26px; box-shadow: 0 1px 3px rgba(0,0,0,.06); height: 100%; }
.acct-title { font-size: 19px; font-weight: 600; color: #1F2617; margin: 0 0 16px; }
.acct-detail { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 11px 0; border-bottom: 1px solid #f2f2f2; font-size: 14px; }
.acct-detail span { color: #5C6152; }
.acct-detail strong { text-align: right; word-break: break-word; font-weight: 500; color: #1F2617; }
.acct-note { margin: 14px 0 0; font-size: 13px; color: #5C6152; }
.acct-field { margin-bottom: 18px; }
.acct-field label { display: block; margin: 0 0 7px; font-size: 14px; font-weight: 600; color: #1F2617; }
.acct-field input { width: 100%; padding: 11px; border: 1px solid #ccc; border-radius: var(--radius-control); }
.acct-field input:focus { outline: none; border-color: #71AA60; box-shadow: 0 0 0 3px rgba(113, 170, 96, .25); }
.acct-actions { display: flex; justify-content: flex-end; margin-top: 20px; }
.acct-save { background: #71AA60; border-color: #71AA60; }
.acct-save:hover, .acct-save:focus { background: #5F9850; border-color: #5F9850; }
.acct-stack { display: flex; flex-direction: column; gap: 16px; }
.acct-hint { margin: 4px 0 0; font-size: 12px; color: #5C6152; }
.acct-use { margin-left: 8px; }
</style>

<div class="page-wrap">
    <?php if (! empty($error)): ?>
        <div class="alert alert-danger">
            <?= esc($error) ?>
            <?php if (! empty($suggestion)): ?>
                <button type="button" class="btn btn-sm btn-outline-dark acct-use" id="useSuggestion" data-value="<?= esc($suggestion, 'attr') ?>">Use this</button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if (! empty($notice)): ?><div class="alert alert-info"><?= esc($notice) ?></div><?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-6">
          <div class="acct-stack">
            <div class="acct-card">
                <h5 class="acct-title">Account Details</h5>
                <div class="acct-detail"><span>Full Name</span><strong><?= esc($account['full_name'] ?? $dash) ?></strong></div>
                <div class="acct-detail"><span>Username</span><strong><?= esc($account['username'] ?? $dash) ?></strong></div>
                <div class="acct-detail"><span>Email</span><strong><?= esc($account['email'] ?? '') !== '' ? esc($account['email']) : $dash ?></strong></div>
                <div class="acct-detail"><span>Contact Number</span><strong><?= esc($account['phone'] ?? '') !== '' ? esc($account['phone']) : $dash ?></strong></div>
                <div class="acct-detail"><span>Role</span><strong><?= esc($account['role'] ?? 'Staff') ?></strong></div>
                <div class="acct-detail"><span>Last Login</span><strong><?= ! empty($account['last_login']) ? esc(date('M j, Y g:i A', strtotime($account['last_login']))) : $dash ?></strong></div>
                <p class="acct-note">Your username and role are set by the owner.</p>
            </div>

            <?php if ($pendingEmail): ?>
            <div class="acct-card">
                <h5 class="acct-title">Confirm Your New Email</h5>
                <p class="acct-note" style="margin:-8px 0 16px;">Enter the 6-digit code we sent to <?= esc($pendingEmail) ?>. Your email changes only after this step.</p>
                <form method="post" action="<?= site_url('staff/account/email/confirm') ?>">
                    <?= csrf_field() ?>
                    <div class="acct-field">
                        <label for="acctCode">Verification Code</label>
                        <input id="acctCode" name="code" inputmode="numeric" maxlength="6" pattern="\d{6}" required autocomplete="one-time-code">
                    </div>
                    <div class="acct-actions" style="gap:8px;">
                        <button type="submit" class="btn btn-success acct-save" form="cancelEmailForm" formnovalidate style="background:#fff;color:#1F2617;border-color:#ccc;">Cancel</button>
                        <button type="submit" class="btn btn-success acct-save"<?= demo_lock_attr($account ?? null) ?>>Confirm email</button>
                    </div>
                </form>
                <form id="cancelEmailForm" method="post" action="<?= site_url('staff/account/email/cancel') ?>"><?= csrf_field() ?></form>
            </div>
            <?php endif; ?>

            <div class="acct-card">
                <h5 class="acct-title">Edit Profile</h5>
                <form method="post" action="<?= site_url('staff/account/profile') ?>">
                    <?= csrf_field() ?>
                    <div class="acct-field">
                        <label for="acctName">Full Name</label>
                        <input id="acctName" name="full_name" maxlength="100" value="<?= esc($account['full_name'] ?? '', 'attr') ?>" required>
                    </div>
                    <div class="acct-field">
                        <label for="acctEmail">Email</label>
                        <input id="acctEmail" name="email" type="email" maxlength="100" value="<?= esc($account['email'] ?? '', 'attr') ?>" required autocomplete="email">
                        <p class="acct-hint">Temporary passwords are sent here. A new address gets a code to confirm it.</p>
                    </div>
                    <div class="acct-field">
                        <label for="acctPhone">Contact Number</label>
                        <input id="acctPhone" name="phone" maxlength="20" value="<?= esc($account['phone'] ?? '', 'attr') ?>">
                    </div>
                    <div class="acct-field">
                        <label for="acctProfilePw">Current Password</label>
                        <input id="acctProfilePw" name="current_password" type="password" autocomplete="current-password">
                        <p class="acct-hint">Needed only when you change your email.</p>
                    </div>
                    <div class="acct-actions"><button type="submit" class="btn btn-success acct-save"<?= demo_lock_attr($account ?? null) ?>>Save Profile</button></div>
                </form>
            </div>
          </div>
        </div>

        <div class="col-lg-6">
            <div class="acct-card">
                <h5 class="acct-title">Change Password</h5>
                <p class="acct-note" style="margin:-8px 0 16px;">For security, the owner is notified whenever you change your password.</p>
                <form method="post" action="<?= site_url('staff/account/password') ?>">
                    <?= csrf_field() ?>
                    <div class="acct-field">
                        <label for="acctCurrent">Current Password</label>
                        <input id="acctCurrent" name="current_password" type="password" required autocomplete="current-password">
                    </div>
                    <div class="acct-field">
                        <label for="acctNew">New Password</label>
                        <input id="acctNew" name="new_password" type="password" placeholder="<?= esc(\App\Libraries\PasswordPolicy::HINT) ?>" required autocomplete="new-password">
                    </div>
                    <div class="acct-field">
                        <label for="acctConfirm">Confirm New Password</label>
                        <input id="acctConfirm" name="confirm_password" type="password" required autocomplete="new-password">
                    </div>
                    <div class="acct-actions"><button type="submit" class="btn btn-success acct-save"<?= demo_lock_attr($account ?? null) ?>>Change Password</button></div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// "Use this": fills the suggested password into both password fields (no reload).
(function () {
    var btn = document.getElementById('useSuggestion');
    if (!btn) return;
    btn.addEventListener('click', function () {
        ['acctNew', 'acctConfirm'].forEach(function (id) { document.getElementById(id).value = btn.dataset.value; });
        document.getElementById('acctNew').focus();
    });
})();
</script>

<?= $this->endSection() ?>
