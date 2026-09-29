<?php
// app/Views/auth/login_admin.php
$errorMsg   = session()->getFlashdata('error');
$successMsg = session()->getFlashdata('success');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Login - RfourL</title>
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
</head>
<body class="auth-body">
    <div class="auth-card">
        <h2>Welcome, Admin!</h2>
        <form action="/login/admin" method="post">
            <?= csrf_field() ?>
            <label>Username:</label>
            <input type="text" name="username" required>
            <label>Password:</label>
            <input type="password" name="password" required>
            <div class="forgot-link"><a href="<?= site_url('login/admin/forgot') ?>">forgot password?</a></div>
            <button type="submit" class="btn-login">Login</button>
        </form>
        <?php if ($errorMsg): ?>
            <div class="auth-error"><?= esc((string) $errorMsg) ?></div>
        <?php endif; ?>
        <?php if ($successMsg): ?>
            <div class="auth-success"><?= esc((string) $successMsg) ?></div>
        <?php endif; ?>
    </div>
</body>
</html>