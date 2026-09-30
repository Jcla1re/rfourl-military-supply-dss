<?php
// app/Views/notification_action/confirm.php
$notification = $notification ?? [];
$action       = $action ?? 'approve';
$isApprove    = $action === 'approve';
?>
<!DOCTYPE html>
<html>
<head>
    <title><?= $isApprove ? 'Approve' : 'Decline' ?> Request - RfourL</title>
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
</head>
<body class="auth-body">
    <div class="auth-card">
        <h2><?= $isApprove ? 'Approve' : 'Decline' ?> Request</h2>
        <p class="auth-hint"><?= esc((string) ($notification['title'] ?? '')) ?></p>

        <?php if (! empty($notification['message'])): ?>
            <div class="auth-notice"><?= esc($notification['message']) ?></div>
        <?php endif; ?>

        <form action="<?= current_url() ?>" method="post">
            <?= csrf_field() ?>
            <button type="submit" class="btn-login" style="background:<?= $isApprove ? '#2f6431' : '#7a2020' ?>;">
                Yes, <?= $isApprove ? 'Approve' : 'Decline' ?> This Request
            </button>
        </form>
    </div>
</body>
</html>
