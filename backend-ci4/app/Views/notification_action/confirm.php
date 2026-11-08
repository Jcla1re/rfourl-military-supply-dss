<?php
// app/Views/notification_action/confirm.php
$notification = $notification ?? [];
$action       = $action ?? 'accept';
$isLock       = $action === 'lock';
$isAccept     = $action === 'accept';
$error        = $error ?? null;
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="icon" href="/favicon.ico?v=4" sizes="32x32">
    <link rel="icon" href="/favicon.svg?v=4" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/assets/img/favicon/apple-touch-icon.png?v=4">
    <meta name="apple-mobile-web-app-title" content="RfourL MS" />
    <link rel="manifest" href="/assets/img/favicon/site.webmanifest?v=4">
    <title><?= $isLock ? 'Lock Account' : 'Confirm Password Reset' ?> | RfourL</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
<?= view('partials/demo_head') ?>
</head>
<body class="auth-body">
<?= view('partials/demo_banner') ?>
    <div class="auth-card">
        <h2><?= $isLock ? 'Lock Account' : 'Confirm Password Reset' ?></h2>
        <p class="auth-hint"><?= esc((string) ($notification['title'] ?? '')) ?></p>

        <?php if (! empty($notification['message'])): ?>
            <div class="auth-notice"><?= esc($notification['message']) ?></div>
        <?php endif; ?>

        <?php if ($error): ?><div class="auth-error"><?= esc($error) ?></div><?php endif; ?>

        <form action="<?= current_url() ?>" method="post">
            <?= csrf_field() ?>
            <?php if ($isAccept): ?>
                <p class="auth-hint">Accept only if it was really them. Once you accept, this request is closed and the account can no longer be locked from here.</p>
            <?php else: ?>
                <p class="auth-hint">They won't be able to sign in until you reset their password in Settings &gt; Manage Accounts.</p>
            <?php endif; ?>
            <button type="submit" class="btn-login" style="background:<?= $isAccept ? '#2f6431' : '#A1261B' ?>;">
                <?= $isLock ? 'Lock account' : 'Accept' ?>
            </button>
        </form>
    </div>
</body>
</html>
