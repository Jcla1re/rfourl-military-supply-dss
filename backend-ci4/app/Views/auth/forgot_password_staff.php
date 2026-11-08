<?php
// app/Views/auth/forgot_password_staff.php
$error   = $error ?? null;
$sent    = $sent ?? false;
$errorMsg = session()->getFlashdata('error') ?? $error;
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="icon" href="/favicon.ico?v=4" sizes="32x32">
    <link rel="icon" href="/favicon.svg?v=4" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/assets/img/favicon/apple-touch-icon.png?v=4">
    <meta name="apple-mobile-web-app-title" content="RfourL MS" />
    <link rel="manifest" href="/assets/img/favicon/site.webmanifest?v=4">
    <title>Account Help | RfourL</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
    <style>
        .notify-box {
            background: #fff; border-radius: var(--radius-control); padding: 0.9rem 1rem;
            font-size: 0.85rem; text-align: center; margin-bottom: 1.25rem; color: #333;
        }
        .notify-sent-pill {
            display: flex; align-items: center; justify-content: center; gap: 0.5rem;
            background: var(--green-bg, #ddf1df); color: var(--green-text, #2f6431);
            border-radius: var(--radius-pill); padding: 0.6rem 1rem; font-weight: 700; margin-top: 0.75rem;
        }
    </style>
<?= view('partials/demo_head') ?>
</head>
<body class="auth-body">
<?= view('partials/demo_banner') ?>
    <div class="auth-card">
        <h2>Forgot password?</h2>
        <p class="auth-hint">Type your username or name. We email you a temporary password.</p>

        <div class="notify-box">The temporary password works for 2 hours. Your current password keeps working until you use it.</div>

        <?php if ($errorMsg): ?>
            <div class="auth-error"><?= esc((string) $errorMsg) ?></div>
        <?php endif; ?>

        <form action="<?= site_url('login/staff/forgot') ?>" method="post">
            <?= csrf_field() ?>
            <label>Username or full name:</label>
            <input type="text" name="staff_name" required autofocus autocomplete="username">

            <button type="submit" class="btn-login">Send temporary password</button>
        </form>

        <?php if (!empty($sent)): ?>
            <div class="notify-sent-pill"><i>&check;</i> Request received</div>
            <p class="auth-hint" style="margin-top:0.75rem;">If the account exists and has an email on file, a temporary password has been sent.</p>
        <?php endif; ?>

        <div class="auth-back"><a href="<?= site_url('login/staff') ?>">&larr; Back to login</a></div>
    </div>
</body>
</html>
