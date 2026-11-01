<?php
$role     = $role ?? 'admin';
$errorMsg = session()->getFlashdata('error');
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="icon" href="/favicon.ico?v=4" sizes="32x32">
    <link rel="icon" href="/favicon.svg?v=4" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/assets/img/favicon/apple-touch-icon.png?v=4">
    <meta name="apple-mobile-web-app-title" content="RfourL MS" />
    <link rel="manifest" href="/assets/img/favicon/site.webmanifest?v=4">
    <title>Reset Password - RfourL</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" integrity="sha384-tViUnnbYAV00FLIhhi3v/dWt3Jxw4gZQcNoSCxCIFNJVCx7/D55/wXsrNIRANwdD" crossorigin="anonymous" rel="stylesheet">
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
<?= view('partials/demo_head') ?>
</head>
<body class="auth-body">
<?= view('partials/demo_banner') ?>
    <div class="auth-card">
        <h2>Forgot Password</h2>
        <p class="auth-hint">Set a New Password</p>

        <form action="<?= site_url('login/' . $role . '/reset-password') ?>" method="post">
            <?= csrf_field() ?>
            <label>Enter New Password:</label>
            <div class="pw-field">
                <input type="password" name="new_password" minlength="8" pattern="(?=.*[A-Za-z])(?=.*\d).{8,}" title="At least 8 characters, with a letter and a number" required autofocus>
                <button type="button" class="pw-toggle" aria-label="Show password" aria-pressed="false" tabindex="-1"><i class="bi bi-eye"></i></button>
            </div>
            <label>Confirm New Password:</label>
            <div class="pw-field">
                <input type="password" name="confirm_password" minlength="8" required>
                <button type="button" class="pw-toggle" aria-label="Show password" aria-pressed="false" tabindex="-1"><i class="bi bi-eye"></i></button>
            </div>
            <button type="submit" class="btn-login">Reset Password</button>
        </form>

        <?php if ($errorMsg): ?>
            <div class="auth-error"><?= esc((string) $errorMsg) ?></div>
        <?php endif; ?>
    </div>
    <script src="/assets/js/pw-toggle.js"></script>
</body>
</html>
