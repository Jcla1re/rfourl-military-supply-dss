<?php $roleLabel = $role === 'supplier' ? 'Supplier' : 'Admin'; ?>
<!DOCTYPE html>
<html>
<head>
    <title>Forgot Password - RfourL</title>
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
</head>
<body class="auth-body">
    <div class="auth-card">
        <h2>Forgot Password</h2>
        <p class="auth-hint">Enter the email linked to your <?= esc($roleLabel) ?> account and we'll send you a verification code.</p>

        <form action="<?= site_url('login/' . $role . '/forgot') ?>" method="post">
            <?= csrf_field() ?>
            <label>Email:</label>
            <input type="email" name="email" required autofocus>
            <button type="submit" class="btn-login">Send Code</button>
        </form>

        <?php if (session()->getFlashdata('error')): ?>
            <div class="auth-error"><?= esc(session()->getFlashdata('error')) ?></div>
        <?php endif; ?>

        <div class="auth-back"><a href="<?= site_url('login/' . $role) ?>">&larr; Back to login</a></div>
    </div>
</body>
</html>
