<?php
// app/Views/notification_action/confirm.php
$notification = $notification ?? [];
$action       = $action ?? 'approve';
$isLock       = $action === 'lock';
$error        = $error ?? null;
$isApprove    = $action === 'approve';
?>
<!DOCTYPE html>
<html>
<head>
    <title><?= $isLock ? 'Lock Account' : ($isApprove ? 'Approve Reset' : 'Decline Request') ?> - RfourL</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
<?= view('partials/demo_head') ?>
</head>
<body class="auth-body">
<?= view('partials/demo_banner') ?>
    <div class="auth-card">
        <h2><?= $isLock ? 'Lock Account' : ($isApprove ? 'Approve Reset' : 'Decline Request') ?></h2>
        <p class="auth-hint"><?= esc((string) ($notification['title'] ?? '')) ?></p>

        <?php if (! empty($notification['message'])): ?>
            <div class="auth-notice"><?= esc($notification['message']) ?></div>
        <?php endif; ?>

        <?php if ($error): ?><div class="auth-error"><?= esc($error) ?></div><?php endif; ?>

        <form action="<?= current_url() ?>" method="post">
            <?= csrf_field() ?>
            <?php if ($isApprove): ?>
                <p class="auth-hint">Check with the staff member, by call or chat, that they really sent this request. Approving emails them a temporary password. You won't see it.</p>
                <label style="display:flex;gap:8px;align-items:flex-start;margin:12px 0;text-align:left;">
                    <input type="checkbox" name="confirmed" value="1" id="confirmedBox" style="width:auto;margin-top:3px;">
                    <span>I confirmed with them</span>
                </label>
            <?php endif; ?>
            <button type="submit" class="btn-login" style="background:<?= $isApprove ? '#2f6431' : '#7a2020' ?>;">
                <?= $isLock ? 'Lock account' : ($isApprove ? 'Approve reset' : 'Yes, Decline This Request') ?>
            </button>
        </form>
        <?php if ($isApprove): ?>
            <script>
                // The button stays off until the box is ticked.
                (function () {
                    var box = document.getElementById('confirmedBox');
                    var btn = box.form.querySelector('button[type=submit]');
                    btn.disabled = !box.checked;
                    box.addEventListener('change', function () { btn.disabled = !box.checked; });
                })();
            </script>
        <?php endif; ?>
    </div>
</body>
</html>
