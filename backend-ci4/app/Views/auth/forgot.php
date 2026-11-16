<?php
// app/Views/auth/forgot.php: Forgot password, step 1 (the same for every role).
$identifier = (string) (session()->getFlashdata('forgot_identifier') ?? '');
?>
<?= $this->extend('auth/_layout') ?>

<?= $this->section('title') ?>Forgot Password<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?= view('auth/_forgot_steps', ['step' => 1]) ?>
                <h2 class="split-title">Forgot password?</h2>
                <p class="split-sub">Enter your username or email. We'll email you the next step.</p>

<?= view('auth/_messages') ?>

                <form action="<?= site_url('login/forgot') ?>" method="post" class="split-fields" data-busy-label="Sending&hellip;">
                    <?= csrf_field() ?>
                    <div class="field-group">
                        <label for="forgotIdentifier">Username or email</label>
                        <div class="input-icon-wrap">
                            <i class="bi bi-person" aria-hidden="true"></i>
                            <input type="text" id="forgotIdentifier" name="identifier" value="<?= esc($identifier, 'attr') ?>" placeholder="Enter your username or email" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="100" required autofocus>
                        </div>
                    </div>
                    <button type="submit" class="btn-login">
                        <span>Continue</span> <i class="bi bi-arrow-right btn-login-arrow" aria-hidden="true"></i>
                    </button>
                </form>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
    <script src="/assets/js/auth-forms.js"></script>
<?= $this->endSection() ?>
