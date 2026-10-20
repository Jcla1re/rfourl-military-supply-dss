<?php
// app/Views/notification_action/done.php
$action    = $action ?? 'approve';
$isApprove = $action === 'approve';
$isLock    = $action === 'lock';
?>
<!DOCTYPE html>
<html>
<head>
    <title><?= $isLock ? 'Account Locked' : 'Request ' . ($isApprove ? 'Approved' : 'Declined') ?> - RfourL</title>
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
</head>
<body class="auth-body">
    <div class="auth-card">
        <h2><?= $isLock ? 'Account Locked' : 'Request ' . ($isApprove ? 'Approved' : 'Declined') ?></h2>
        <?php if ($isLock): ?>
            <p class="auth-hint"><?= esc($staffName ?? 'The staff member') ?>'s account is locked. Reset their password from Manage Accounts to unlock it.</p>
        <?php elseif ($isApprove): ?>
            <p class="auth-hint">The staff member has been notified. Log in to the admin portal &rarr; Settings &rarr; Security to set their new password.</p>
        <?php else: ?>
            <p class="auth-hint">The staff member has been notified that their request was declined.</p>
        <?php endif; ?>
        <div class="auth-back"><a href="<?= site_url('login/admin') ?>">Go to admin login</a></div>
    </div>
</body>
</html>
