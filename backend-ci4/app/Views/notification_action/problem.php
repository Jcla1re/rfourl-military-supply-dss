<?php
// app/Views/notification_action/problem.php
$message = $message ?? 'This link is invalid or has expired.';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Link Unavailable - RfourL</title>
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
</head>
<body class="auth-body">
    <div class="auth-card">
        <h2>Link Unavailable</h2>
        <p class="auth-hint"><?= esc($message) ?></p>
        <div class="auth-back"><a href="<?= site_url('login/admin') ?>">Go to admin login</a></div>
    </div>
</body>
</html>
