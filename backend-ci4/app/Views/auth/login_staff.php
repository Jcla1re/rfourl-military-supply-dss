<?php
// app/Views/auth/login_staff.php
$errorMsg = session()->getFlashdata('error');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Staff Login - RfourL</title>
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
</head>
<body class="auth-body">
    <div class="auth-card">
        <h2>Welcome, Staff!</h2>
        <form action="/login/staff" method="post">
            <?= csrf_field() ?>
            <label>Password:</label>
            <input type="password" name="password" required>
            <div class="forgot-link"><a href="<?= site_url('login/staff/forgot') ?>">forgot password?</a></div>
            <button type="submit" class="btn-login">Login</button>
        </form>
        <?php if ($errorMsg): ?>
            <div class="auth-error"><?= esc((string) $errorMsg) ?></div>
        <?php endif; ?>
    </div>
</body>
</html>