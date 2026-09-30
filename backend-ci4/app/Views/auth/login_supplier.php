<?php
// app/Views/auth/login_supplier.php
$errorMsg      = session()->getFlashdata('error');
$successMsg    = session()->getFlashdata('success');
$lockedSeconds = (new \App\Libraries\LoginLockout())->secondsRemaining('supplier');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Supplier Login - RfourL</title>
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
</head>
<body class="auth-body">
    <div class="auth-card">
        <h2>Welcome, Supplier!</h2>
        <form action="/login/supplier" method="post">
            <?= csrf_field() ?>
            <label>Username:</label>
            <input type="text" name="username" required>
            <label>Password:</label>
            <input type="password" name="password" required>
            <div class="forgot-link"><a href="<?= site_url('login/supplier/forgot') ?>">forgot password?</a></div>
            <button type="submit" class="btn-login" id="loginSubmitBtn"<?= $lockedSeconds > 0 ? ' disabled' : '' ?>>Login</button>
        </form>
        <?php if ($errorMsg): ?>
            <div class="auth-error"><?= esc((string) $errorMsg) ?></div>
        <?php endif; ?>
        <?php if ($successMsg): ?>
            <div class="auth-success"><?= esc((string) $successMsg) ?></div>
        <?php endif; ?>
        <div class="auth-back"><a href="<?= site_url('/') ?>">&larr; Back to role selection</a></div>
    </div>
    <?php if ($lockedSeconds > 0): ?>
    <script>
        (function () {
            let seconds = <?= (int) $lockedSeconds ?>;
            const btn = document.getElementById('loginSubmitBtn');
            const label = btn.textContent;
            (function tick() {
                if (seconds <= 0) {
                    btn.disabled = false;
                    btn.textContent = label;
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