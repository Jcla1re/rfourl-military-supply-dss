<?php // app/Views/auth/forgot_password_staff.php ?>
<!DOCTYPE html>
<html>
<head>
    <title>Can't access your account? - RfourL</title>
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
    <style>
        .notify-box {
            background: #fff; border-radius: 10px; padding: 0.9rem 1rem;
            font-size: 0.85rem; text-align: center; margin-bottom: 1.25rem; color: #333;
        }
        .notify-sent-pill {
            display: flex; align-items: center; justify-content: center; gap: 0.5rem;
            background: var(--green-bg, #ddf1df); color: var(--green-text, #2f6431);
            border-radius: 999px; padding: 0.6rem 1rem; font-weight: 700; margin-top: 0.75rem;
        }
    </style>
</head>
<body class="auth-body">
    <div class="auth-card">
        <h2>Can't access your account?</h2>
        <p class="auth-hint">Staff password are managed by the owner. Submitting this request will notify the admin immediately.</p>

        <div class="notify-box">No self-service reset - The owner will send you the password</div>

        <?php if (session()->getFlashdata('error') || !empty($error)): ?>
            <div class="auth-error"><?= esc(session()->getFlashdata('error') ?? $error) ?></div>
        <?php endif; ?>

        <form action="<?= site_url('login/staff/forgot') ?>" method="post">
            <?= csrf_field() ?>
            <label>Staff name:</label>
            <input type="text" name="staff_name" required>

            <label>Reason</label>
            <input type="text" name="reason" placeholder="e.g. forgot password">

            <button type="submit" class="btn-login">Notify Admin</button>
        </form>

        <?php if (!empty($sent)): ?>
            <div class="notify-sent-pill"><i>&check;</i> Notify admin sent</div>
        <?php endif; ?>

        <div class="auth-back"><a href="<?= site_url('login/staff') ?>">&larr; Back to login</a></div>
    </div>
</body>
</html>
