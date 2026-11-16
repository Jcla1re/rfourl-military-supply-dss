<?php
// app/Views/auth/login.php: "/" and "/login", one sign-in form for every role.
$errorMsg    = session()->getFlashdata('error');
$successMsg  = session()->getFlashdata('success');
$triesLeft   = (int) session()->getFlashdata('tries_left');
$lockSeconds = (int) session()->getFlashdata('lock_seconds');
$username    = (string) (session()->getFlashdata('login_username') ?? old('username') ?? '');
?>
<?= $this->extend('auth/_layout') ?>

<?= $this->section('title') ?>Sign In<?= $this->endSection() ?>

<?= $this->section('content') ?>
                <h2 class="split-title">Welcome</h2>
                <p class="split-sub">Sign in with your RfourL account.</p>

                <?= view('partials/_auth_session_notice') ?>
                <?php if ($errorMsg): ?>
                    <div class="auth-alert is-error" role="alert"><?= esc((string) $errorMsg) ?>
                        <?php if ($triesLeft > 0): ?><div class="auth-tries"><?= $triesLeft ?> <?= $triesLeft === 1 ? 'try' : 'tries' ?> left before sign-in is locked for <?= \App\Libraries\LoginLockout::LOCKOUT_SECONDS ?> seconds.</div><?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($successMsg): ?>
                    <div class="auth-alert is-success" role="status"><?= esc((string) $successMsg) ?></div>
                <?php endif; ?>

                <form action="<?= site_url('login') ?>" method="post" class="split-fields">
                    <?= csrf_field() ?>
                    <div class="field-group">
                        <label for="loginUsername">Username</label>
                        <div class="input-icon-wrap">
                            <i class="bi bi-person" aria-hidden="true"></i>
                            <input type="text" id="loginUsername" name="username" value="<?= esc($username, 'attr') ?>" placeholder="Enter your username" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="50" required<?= $username === '' ? ' autofocus' : '' ?>>
                        </div>
                    </div>
                    <div class="field-group">
                        <label for="loginPassword">Password</label>
                        <div class="input-icon-wrap">
                            <i class="bi bi-lock" aria-hidden="true"></i>
                            <input type="password" id="loginPassword" name="password" placeholder="Enter your password" autocomplete="current-password" required<?= $username !== '' ? ' autofocus' : '' ?>>
                            <button type="button" class="pw-toggle" aria-label="Show password" aria-pressed="false" tabindex="-1"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <div class="form-row-between">
                        <a href="<?= site_url('login/forgot') ?>" class="forgot-link-inline">Forgot password?</a>
                    </div>
                    <button type="submit" class="btn-login" id="loginSubmitBtn"<?= $lockSeconds > 0 ? ' disabled data-lock-seconds="' . $lockSeconds . '"' : '' ?>>
                        <span>Sign in</span> <i class="bi bi-arrow-right btn-login-arrow" aria-hidden="true"></i>
                    </button>
                </form>
                <?= view('partials/demo_credentials') ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?php if ($lockSeconds > 0): ?>
    <script>
        // Locked after a wrong try: the button counts down from the seconds the server gave, then works again.
        (function () {
            var btn = document.getElementById('loginSubmitBtn');
            var seconds = parseInt(btn.getAttribute('data-lock-seconds'), 10) || 0;
            var label = btn.innerHTML;
            (function tick() {
                if (seconds <= 0) {
                    btn.disabled = false;
                    btn.innerHTML = label;
                    return;
                }
                btn.textContent = 'Try again in ' + seconds + 's';
                seconds--;
                setTimeout(tick, 1000);
            })();
        })();
    </script>
<?php endif; ?>
<?= $this->endSection() ?>
