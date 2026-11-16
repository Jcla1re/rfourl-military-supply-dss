<?php
// app/Views/auth/forgot_done.php: Forgot password, the end.
?>
<?= $this->extend('auth/_layout') ?>

<?= $this->section('title') ?>Password Changed<?= $this->endSection() ?>

<?= $this->section('content') ?>
                <div class="split-done">
                    <span class="split-done-icon"><i class="bi bi-check-lg" aria-hidden="true"></i></span>
                    <h2 class="split-title">Password changed</h2>
                    <p class="split-sub">You can now sign in with your new password.</p>
                    <a href="<?= site_url('login') ?>" class="btn-login">
                        <span>Back to sign in</span> <i class="bi bi-arrow-right btn-login-arrow" aria-hidden="true"></i>
                    </a>
                </div>
<?= $this->endSection() ?>
