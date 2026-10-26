<?php
// app/Views/notification_action/problem.php
$message = $message ?? 'This link is invalid or has expired.';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Link Unavailable - RfourL</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
</head>
<body class="auth-body">
    <div class="auth-card">
        <h2>Link Unavailable</h2>
        <p class="auth-hint"><?= esc($message) ?></p>
        <div class="auth-back"><a href="<?= site_url('login/admin') ?>">Go to owner login</a></div>
    </div>
</body>
</html>
