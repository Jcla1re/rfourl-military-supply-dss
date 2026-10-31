<?php
// Shown after signing in with a temporary password: the only page a staff member can open until they set their own.
$errorMsg = session()->getFlashdata('error');
$invite   = ($kind ?? '') === 'invite';
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="icon" type="image/png" href="/assets/img/favicon/favicon-96x96.png?v=2" sizes="96x96" />
    <link rel="shortcut icon" href="/assets/img/favicon/favicon.ico?v=2" />
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/img/favicon/apple-touch-icon.png?v=2" />
    <meta name="apple-mobile-web-app-title" content="RfourL MS" />
    <link rel="manifest" href="/assets/img/favicon/site.webmanifest?v=2" />
    <title>Set your password - RfourL</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
<?= view('partials/demo_head') ?>
</head>
<body class="auth-body">
<?= view('partials/demo_banner') ?>
    <div class="auth-card">
        <h2>Set your password</h2>
        <p class="auth-hint"><?= $invite ? 'Welcome! Choose your own password to continue.' : 'Choose your own password to continue.' ?></p>

        <?php if ($errorMsg): ?>
            <div class="auth-error"><?= esc((string) $errorMsg) ?></div>
        <?php endif; ?>

        <form action="<?= site_url('staff/set-password') ?>" method="post">
            <?= csrf_field() ?>
            <label>New password:</label>
            <input type="password" name="new_password" minlength="8" placeholder="<?= esc(\App\Libraries\PasswordPolicy::HINT) ?>" pattern="(?=.*[A-Za-z])(?=.*\d).{8,}" title="<?= esc(\App\Libraries\PasswordPolicy::HINT) ?>" required autofocus autocomplete="new-password">
            <label>Confirm new password:</label>
            <input type="password" name="confirm_password" minlength="8" required autocomplete="new-password">
            <button type="submit" class="btn-login">Save password</button>
        </form>

        <form method="post" action="<?= site_url('logout') ?>" class="auth-back">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-link p-0">&larr; Sign out</button>
        </form>
    </div>
</body>
</html>
