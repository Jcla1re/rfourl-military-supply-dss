<?php
// app/Views/auth/forgot_new_password.php: Forgot password, step 3 (after the right code).
?>
<?= $this->extend('auth/_layout') ?>

<?= $this->section('title') ?>Set a New Password<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?= view('auth/_forgot_steps', ['step' => 3]) ?>
                <h2 class="split-title">Set a new password</h2>
                <p class="split-sub">Use at least 8 characters with a letter and a number.</p>

<?= view('auth/_messages') ?>

                <form action="<?= site_url('login/forgot/new-password') ?>" method="post" class="split-fields">
                    <?= csrf_field() ?>
<?= view('auth/_password_fields', ['serverRule' => 'Different from your current password (checked when you save)']) ?>
                    <button type="submit" class="btn-login">
                        <span>Save new password</span> <i class="bi bi-arrow-right btn-login-arrow" aria-hidden="true"></i>
                    </button>
                </form>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
    <script src="/assets/js/auth-forms.js"></script>
<?= $this->endSection() ?>
