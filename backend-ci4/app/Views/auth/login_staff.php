<?php
// app/Views/auth/login_staff.php
$errorMsg      = session()->getFlashdata('error');
$lockedSeconds = (new \App\Libraries\LoginLockout())->secondsRemaining('staff');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff Login - RfourL</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" integrity="sha384-tViUnnbYAV00FLIhhi3v/dWt3Jxw4gZQcNoSCxCIFNJVCx7/D55/wXsrNIRANwdD" crossorigin="anonymous" rel="stylesheet">
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
</head>
<body class="landing-body">
    <div class="landing-shell">
        <div class="dark-panel slid">
            <div class="dark-view is-active">
                <div class="landing-logo-box landing-logo-box-sm">
                    <img src="/assets/img/RfourL_Logo.jpg" alt="RfourL Military Supply">
                </div>

                <div class="signing-in-label">SIGNING IN AS</div>
                <h1 class="signing-in-role">Staff</h1>
                <p class="signing-in-desc">Process sales and print receipts at the point of sale.</p>

                <div class="dark-divider"></div>

                <div class="switch-role-label">Not you? Switch role</div>
                <div class="switch-role-buttons">
                    <a href="<?= site_url('login/admin') ?>" class="switch-role-btn"><span>Sign in as Admin</span> <i class="bi bi-arrow-right"></i></a>
                    <a href="<?= site_url('login/supplier') ?>" class="switch-role-btn"><span>Sign in as Supplier</span> <i class="bi bi-arrow-right"></i></a>
                </div>
            </div>

            <div class="landing-footer-line">
                &copy; 2026 RfourL Military Supply &middot; 7C 2nd Ave, Brgy. Bagong Lipunan ng Crame, Quezon City
            </div>
        </div>

        <div class="content-panel slid">
            <div class="panel-content login-form-view is-active">
                <a href="<?= site_url('/') ?>" class="back-to-roles"><i class="bi bi-chevron-left"></i> Change role</a>
                <div class="portal-badge"><i class="bi bi-receipt"></i> STAFF PORTAL</div>
                <h2>Welcome back</h2>
                <p class="form-subtitle">Sign in with your staff account.</p>
                <form action="/login/staff" method="post">
                    <?= csrf_field() ?>
                    <div class="field-group">
                        <label>Password</label>
                        <div class="input-icon-wrap">
                            <i class="bi bi-lock"></i>
                            <input type="password" name="password" placeholder="Enter your password" required>
                            <button type="button" class="pw-toggle" aria-label="Show password"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <div class="form-row-between">
                        <a href="<?= site_url('login/staff/forgot') ?>" class="forgot-link-inline">Forgot password?</a>
                    </div>
                    <button type="submit" class="btn-login" id="loginSubmitBtn"<?= $lockedSeconds > 0 ? ' disabled' : '' ?>>
                        <span>Sign in</span> <i class="bi bi-arrow-right"></i>
                    </button>
                </form>
                <?php if ($errorMsg): ?>
                    <div class="auth-error"><?= esc((string) $errorMsg) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        document.querySelectorAll('.pw-toggle').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = btn.previousElementSibling;
                const icon = btn.querySelector('i');
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.className = 'bi bi-eye-slash';
                } else {
                    input.type = 'password';
                    icon.className = 'bi bi-eye';
                }
            });
        });
    </script>
    <?php if ($lockedSeconds > 0): ?>
    <script>
        (function () {
            let seconds = <?= (int) $lockedSeconds ?>;
            const btn = document.getElementById('loginSubmitBtn');
            const label = btn.innerHTML;
            (function tick() {
                if (seconds <= 0) {
                    btn.disabled = false;
                    btn.innerHTML = label;
                    return;
                }
                btn.textContent = `Try again in ${seconds}s`;
                seconds--;
                setTimeout(tick, 1000);
            })();
        })();
    </script>
    <?php endif; ?>
</body>
</html>
