<?php
// app/Views/auth/forgot_check.php: Forgot password, step 2. Exactly the same page whether the account
// exists and whatever its role: it explains both cases (a code, or a temporary password). Nothing on
// it comes from the account. Expects $resendIn (seconds before Resend works).
$resendIn = (int) ($resendIn ?? 0);
?>
<?= $this->extend('auth/_layout') ?>

<?= $this->section('title') ?>Check Your Email<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?= view('auth/_forgot_steps', ['step' => 2]) ?>
                <h2 class="split-title">Check your email</h2>
                <p class="split-sub">If an account matches, we've sent you the next step. It can take a minute.</p>

<?= view('auth/_messages') ?>

                <div class="split-cards">
                    <div class="split-card">
                        <i class="bi bi-123" aria-hidden="true"></i>
                        <p><strong>Got a 6-digit code?</strong> Enter it below to set a new password. It expires in 10 minutes.</p>
                    </div>
                    <div class="split-card">
                        <i class="bi bi-key" aria-hidden="true"></i>
                        <p><strong>Got a temporary password?</strong> Use it to <a href="<?= site_url('login') ?>">sign in</a>. It works for 2 hours, and your current password keeps working until you use it.</p>
                    </div>
                </div>

                <form action="<?= site_url('login/forgot/check') ?>" method="post" class="split-fields" id="codeForm">
                    <?= csrf_field() ?>
                    <fieldset class="otp-boxes" data-otp-boxes>
                        <legend>6-digit code</legend>
                        <?php for ($i = 1; $i <= 6; $i++): ?>
                            <input type="text" name="code[]" inputmode="numeric" pattern="[0-9]" maxlength="1" aria-label="Digit <?= $i ?> of 6"<?= $i === 1 ? ' autocomplete="one-time-code" autofocus' : ' autocomplete="off"' ?> required>
                        <?php endfor; ?>
                    </fieldset>
                    <button type="submit" class="btn-login">
                        <span>Verify code</span> <i class="bi bi-arrow-right btn-login-arrow" aria-hidden="true"></i>
                    </button>
                </form>

                <form action="<?= site_url('login/forgot/resend') ?>" method="post" class="split-resend" data-resend data-seconds="<?= $resendIn ?>">
                    <?= csrf_field() ?>
                    <span data-resend-wait<?= $resendIn > 0 ? '' : ' hidden' ?>>Didn't get it? Resend in <span data-resend-clock>0:<?= str_pad((string) $resendIn, 2, '0', STR_PAD_LEFT) ?></span></span>
                    <span data-resend-ready<?= $resendIn > 0 ? ' hidden' : '' ?>>Didn't get it? <button type="submit">Resend</button></span>
                </form>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
    <script src="/assets/js/auth-forms.js"></script>
<?= $this->endSection() ?>
