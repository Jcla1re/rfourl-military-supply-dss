<?php // app/Views/auth/login_admin.php ?>
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
            <div class="forgot-link"><a href="#">forgot password?</a></div>
            <button type="submit" class="btn-login">Login</button>
        </form>
        <?php if (session()->getFlashdata('error')): ?>
            <div class="auth-error"><?= esc(session()->getFlashdata('error')) ?></div>
        <?php endif; ?>
    </div>
</body>
</html>