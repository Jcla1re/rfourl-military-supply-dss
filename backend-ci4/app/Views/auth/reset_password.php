<?php
$role     = $role ?? 'admin';
$errorMsg = session()->getFlashdata('error');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Reset Password - RfourL</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
</head>
<body class="auth-body">
    <div class="auth-card">
        <h2>Forgot Password</h2>
        <p class="auth-hint">Set a New Password</p>

        <form action="<?= site_url('login/' . $role . '/reset-password') ?>" method="post">
            <?= csrf_field() ?>
            <label>Enter New Password:</label>
            <input type="password" name="new_password" minlength="8" pattern="(?=.*[A-Za-z])(?=.*\d).{8,}" title="At least 8 characters, with a letter and a number" required autofocus>
            <label>Confirm New Password:</label>
            <input type="password" name="confirm_password" minlength="8" required>
            <button type="submit" class="btn-login">Reset Password</button>
        </form>

        <?php if ($errorMsg): ?>
            <div class="auth-error"><?= esc((string) $errorMsg) ?></div>
        <?php endif; ?>
    </div>
</body>
</html>
