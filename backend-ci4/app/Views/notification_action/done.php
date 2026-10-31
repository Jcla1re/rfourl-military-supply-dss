<?php
// app/Views/notification_action/done.php
$action    = $action ?? 'approve';
$isApprove = $action === 'approve';
$isLock    = $action === 'lock';
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="icon" type="image/png" href="/assets/img/favicon/favicon-96x96.png?v=2" sizes="96x96" />
    <link rel="shortcut icon" href="/assets/img/favicon/favicon.ico?v=2" />
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/img/favicon/apple-touch-icon.png?v=2" />
    <meta name="apple-mobile-web-app-title" content="RfourL MS" />
    <link rel="manifest" href="/assets/img/favicon/site.webmanifest?v=2" />
    <title><?= $isLock ? 'Account Locked' : 'Request ' . ($isApprove ? 'Approved' : 'Declined') ?> - RfourL</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
<?= view('partials/demo_head') ?>
</head>
<body class="auth-body">
<?= view('partials/demo_banner') ?>
    <div class="auth-card">
        <h2><?= $isLock ? 'Account Locked' : 'Request ' . ($isApprove ? 'Approved' : 'Declined') ?></h2>
        <?php if ($isLock): ?>
            <p class="auth-hint"><?= esc($staffName ?? 'The staff member') ?>'s account is locked. Reset their password from Manage Accounts to unlock it.</p>
        <?php elseif ($isApprove): ?>
            <p class="auth-hint">Approved. A temporary password was emailed to <?= esc($staffName ?? 'the staff member') ?>. You won't see it. They will set their own, and you'll get an email when they do.</p>
        <?php else: ?>
            <p class="auth-hint">Declined. The staff member has been told.</p>
        <?php endif; ?>
        <div class="auth-back"><a href="<?= site_url('login/admin') ?>">Go to owner login</a></div>
    </div>
</body>
</html>
