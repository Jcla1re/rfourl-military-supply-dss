<?php
$role      = $role ?? 'admin';
$roleLabel = $role === 'supplier' ? 'Supplier' : 'Owner';
$errorMsg  = session()->getFlashdata('error');
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="icon" href="/favicon.ico?v=4" sizes="32x32">
    <link rel="icon" href="/favicon.svg?v=4" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/assets/img/favicon/apple-touch-icon.png?v=4">
    <meta name="apple-mobile-web-app-title" content="RfourL MS" />
    <link rel="manifest" href="/assets/img/favicon/site.webmanifest?v=4">
    <title>Forgot Password | RfourL</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
<?= view('partials/demo_head') ?>
</head>
<body class="auth-body">
<?= view('partials/demo_banner') ?>
    <div class="auth-card">
        <h2>Forgot Password</h2>
        <p class="auth-hint">We'll email you a code.</p>

        <form action="<?= site_url('login/' . $role . '/forgot') ?>" method="post">
            <?= csrf_field() ?>
            <label>Email:</label>
            <input type="email" name="email" required autofocus>

            <button type="submit" class="btn-login">Send Code</button>
        </form>

        <?php if ($errorMsg): ?>
            <div class="auth-error"><?= esc((string) $errorMsg) ?></div>
        <?php endif; ?>

        <div class="auth-back"><a href="<?= site_url('login/' . $role) ?>">&larr; Back to login</a></div>
    </div>
</body>
</html>
