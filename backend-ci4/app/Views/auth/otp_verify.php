<!DOCTYPE html>
<html>
<head>
    <title>Verification Code - RfourL</title>
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
</head>
<body class="auth-body">
    <div class="auth-card">
        <h2>Forgot Password</h2>
        <p class="auth-hint">Verification code has been sent to your email<?= !empty($email) ? ' (' . esc($email) . ')' : '' ?>. Please check it</p>

        <?php if (session()->getFlashdata('notice')): ?>
            <div class="auth-notice"><?= esc(session()->getFlashdata('notice')) ?></div>
        <?php endif; ?>

        <form action="<?= site_url('login/' . $role . '/otp') ?>" method="post">
            <?= csrf_field() ?>
            <label>Verification Code:</label>
            <input type="text" name="otp" inputmode="numeric" maxlength="6" placeholder="Enter verification code" required autofocus>
            <button type="submit" class="btn-login">Confirm</button>
        </form>

        <?php if (session()->getFlashdata('error')): ?>
            <div class="auth-error"><?= esc(session()->getFlashdata('error')) ?></div>
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
