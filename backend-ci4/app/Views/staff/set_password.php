<?php
// Shown after signing in with a temporary password: the only page a staff member can open until they set their own.
$errorMsg   = session()->getFlashdata('error');
$suggestion = session()->getFlashdata('password_suggestion');
$invite   = ($kind ?? '') === 'invite';
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="icon" href="/favicon.ico?v=4" sizes="32x32">
    <link rel="icon" href="/favicon.svg?v=4" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/assets/img/favicon/apple-touch-icon.png?v=4">
    <meta name="apple-mobile-web-app-title" content="RfourL MS" />
    <link rel="manifest" href="/assets/img/favicon/site.webmanifest?v=4">
    <title>Set Your Password | RfourL</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" integrity="sha384-tViUnnbYAV00FLIhhi3v/dWt3Jxw4gZQcNoSCxCIFNJVCx7/D55/wXsrNIRANwdD" crossorigin="anonymous" rel="stylesheet">
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
<?= view('partials/demo_head') ?>
</head>
<body class="auth-body">
<?= view('partials/demo_banner') ?>
    <div class="auth-card">
        <h2>Set your password</h2>
        <?php if (! empty($fullName)): ?>
            <p class="auth-signed-in">Signed in as <strong><?= esc($fullName) ?></strong> (@<?= esc($username ?? '') ?>)</p>
        <?php endif; ?>
        <p class="auth-hint"><?= $invite ? 'Welcome! Choose your own password to continue.' : 'Choose your own password to continue.' ?></p>

        <?php if ($errorMsg): ?>
            <div class="auth-error">
                <?= esc((string) $errorMsg) ?>
                <?php if (! empty($suggestion)): ?>
                    <button type="button" class="btn btn-sm btn-outline-dark" id="useSuggestion" data-value="<?= esc($suggestion, 'attr') ?>" style="margin-left:8px;">Use this</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form action="<?= site_url('staff/set-password') ?>" method="post">
            <?= csrf_field() ?>
            <label>New password:</label>
            <div class="pw-field">
                <input type="password" name="new_password" minlength="8" placeholder="<?= esc(\App\Libraries\PasswordPolicy::HINT) ?>" pattern="(?=.*[A-Za-z])(?=.*\d).{8,}" title="<?= esc(\App\Libraries\PasswordPolicy::HINT) ?>" required autofocus autocomplete="new-password">
                <button type="button" class="pw-toggle" aria-label="Show password" aria-pressed="false" tabindex="-1"><i class="bi bi-eye"></i></button>
            </div>
            <label>Confirm new password:</label>
            <div class="pw-field">
                <input type="password" name="confirm_password" minlength="8" required autocomplete="new-password">
                <button type="button" class="pw-toggle" aria-label="Show password" aria-pressed="false" tabindex="-1"><i class="bi bi-eye"></i></button>
            </div>
            <button type="submit" class="btn-login">Save password</button>
        </form>

        <form method="post" action="<?= site_url('logout') ?>" class="auth-back">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-link p-0">&larr; Sign out</button>
        </form>
    </div>
    <script src="/assets/js/pw-toggle.js"></script>
    <script>
    // "Use this": fills the suggested password into both fields (no reload).
    (function () {
        var btn = document.getElementById('useSuggestion');
        if (!btn) return;
        btn.addEventListener('click', function () {
            document.querySelectorAll('input[name=new_password], input[name=confirm_password]').forEach(function (i) { i.value = btn.dataset.value; });
        });
    })();
    </script>
</body>
</html>
