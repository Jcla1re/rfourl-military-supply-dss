<?= $this->extend('layouts/staff_layout') ?>
<?= $this->section('content') ?>

<?php
$account = $account ?? [];
$error   = $error ?? null;
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
</style>

<div class="page-wrap">
    <?php if (! empty($error)): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="acct-card">
                <h5 class="acct-title">Account Details</h5>
                <div class="acct-detail"><span>Full Name</span><strong><?= esc($account['full_name'] ?? $dash) ?></strong></div>
                <div class="acct-detail"><span>Username</span><strong><?= esc($account['username'] ?? $dash) ?></strong></div>
                <div class="acct-detail"><span>Email</span><strong><?= esc($account['email'] ?? '') !== '' ? esc($account['email']) : $dash ?></strong></div>
                <div class="acct-detail"><span>Role</span><strong><?= esc($account['role'] ?? 'Staff') ?></strong></div>
                <div class="acct-detail"><span>Last Login</span><strong><?= ! empty($account['last_login']) ? esc(date('M j, Y g:i A', strtotime($account['last_login']))) : $dash ?></strong></div>
                <p class="acct-note">To change your name or email, ask the owner.</p>
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
                    <div class="acct-actions"><button type="submit" class="btn btn-success acct-save">Change Password</button></div>
                </form>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
