<?php
$role     = $role ?? 'admin';
$email    = $email ?? '';
$noticeMsg = session()->getFlashdata('notice');
$errorMsg  = session()->getFlashdata('error');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Verification Code - RfourL</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
</head>
<body class="auth-body">
    <div class="auth-card">
        <h2>Forgot Password</h2>
        <p class="auth-hint">Code sent<?= !empty($email) ? ' to ' . esc($email) : '' ?>.</p>

        <?php if ($noticeMsg): ?>
            <div class="auth-notice"><?= esc((string) $noticeMsg) ?></div>
        <?php endif; ?>

        <form action="<?= site_url('login/' . $role . '/otp') ?>" method="post">
            <?= csrf_field() ?>
            <label>Verification Code:</label>
            <input type="text" name="otp" inputmode="numeric" maxlength="6" placeholder="Enter verification code" required autofocus>
            <button type="submit" class="btn-login">Confirm</button>
        </form>

        <?php if ($errorMsg): ?>
            <div class="auth-error"><?= esc((string) $errorMsg) ?></div>
        <?php endif; ?>

        <div class="auth-resend">
            Can't receive verification code?
            <form action="<?= site_url('login/' . $role . '/otp/resend') ?>" method="post" style="display:inline;">
                <?= csrf_field() ?>
                <button type="submit">Resend Code</button>
            </form>
        </div>

        <div class="auth-back"><a href="<?= site_url('login/' . $role . '/forgot') ?>">&larr; Use a different email</a></div>
    </div>
</body>
</html>
