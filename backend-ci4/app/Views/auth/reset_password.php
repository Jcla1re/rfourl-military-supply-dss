<!DOCTYPE html>
<html>
<head>
    <title>Reset Password - RfourL</title>
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
</head>
<body class="auth-body">
    <div class="auth-card">
        <h2>Forgot Password</h2>
        <p class="auth-hint">Set a New Password</p>

        <form action="<?= site_url('login/' . $role . '/reset-password') ?>" method="post">
            <?= csrf_field() ?>
            <label>Enter New Password:</label>
            <input type="password" name="new_password" minlength="8" required autofocus>
            <label>Confirm New Password:</label>
            <input type="password" name="confirm_password" minlength="8" required>
            <button type="submit" class="btn-login">Reset Password</button>
        </form>

        <?php if (session()->getFlashdata('error')): ?>
            <div class="auth-error"><?= esc(session()->getFlashdata('error')) ?></div>
        <?php endif; ?>
    </div>
</body>
</html>
