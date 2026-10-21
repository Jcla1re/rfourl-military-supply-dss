<?php
// app/Views/auth/forgot_password_staff.php
$error   = $error ?? null;
$sent    = $sent ?? false;
$errorMsg = session()->getFlashdata('error') ?? $error;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Can't access your account? - RfourL</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
    <style>
        .notify-box {
            background: #fff; border-radius: var(--radius-control); padding: 0.9rem 1rem;
            font-size: 0.85rem; text-align: center; margin-bottom: 1.25rem; color: #333;
        }
        .notify-sent-pill {
            display: flex; align-items: center; justify-content: center; gap: 0.5rem;
            background: var(--green-bg, #ddf1df); color: var(--green-text, #2f6431);
            border-radius: var(--radius-pill); padding: 0.6rem 1rem; font-weight: 700; margin-top: 0.75rem;
        }
    </style>
</head>
<body class="auth-body">
    <div class="auth-card">
        <h2>Can't access your account?</h2>
        <p class="auth-hint">The owner resets staff passwords. Send a request.</p>

        <div class="notify-box">After the owner approves, you'll get a temporary password by email. It works for 2 hours.</div>

        <?php if ($errorMsg): ?>
            <div class="auth-error"><?= esc((string) $errorMsg) ?></div>
        <?php endif; ?>

        <form action="<?= site_url('login/staff/forgot') ?>" method="post">
            <?= csrf_field() ?>
            <label>Staff name:</label>
            <input type="text" name="staff_name" required>

            <label>Reason</label>
            <input type="text" name="reason" placeholder="e.g. forgot password">

            <button type="submit" class="btn-login">Send</button>
        </form>

        <?php if (!empty($sent)): ?>
            <div class="notify-sent-pill"><i>&check;</i> Request sent to the owner</div>
            <p class="auth-hint" style="margin-top:0.75rem;">Once the owner approves, check your email for a temporary password. It works for 2 hours.</p>
        <?php endif; ?>

        <div class="auth-back"><a href="<?= site_url('login/staff') ?>">&larr; Back to login</a></div>
    </div>
</body>
</html>
