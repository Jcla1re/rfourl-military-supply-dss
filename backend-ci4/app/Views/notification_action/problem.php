<?php
// app/Views/notification_action/problem.php
$message = $message ?? 'This link is invalid or has expired.';
$heading = $heading ?? 'Link Unavailable';
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="icon" href="/favicon.ico?v=4" sizes="32x32">
    <link rel="icon" href="/favicon.svg?v=4" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/assets/img/favicon/apple-touch-icon.png?v=4">
    <meta name="apple-mobile-web-app-title" content="RfourL MS" />
    <link rel="manifest" href="/assets/img/favicon/site.webmanifest?v=4">
    <title>Link Unavailable | RfourL</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
<?= view('partials/demo_head') ?>
</head>
<body class="auth-body">
<?= view('partials/demo_banner') ?>
    <div class="auth-card">
        <h2><?= esc($heading) ?></h2>
        <p class="auth-hint"><?= esc($message) ?></p>
        <div class="auth-back"><a href="<?= site_url('login/admin') ?>">Go to owner login</a></div>
    </div>
</body>
</html>
