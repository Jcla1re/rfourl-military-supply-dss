<?php // app/Views/auth/landing.php ?>
<!DOCTYPE html>
<html>
<head>
    <title>RfourL Military Supply</title>
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
</head>
<body class="auth-body">
    <div class="auth-frame d-flex" style="display:flex; gap:2rem; align-items:center; justify-content:center;">
        <div style="text-align:center;">
            <h2>Hello, Welcome!</h2>
            <img src="/assets/img/rfourl-logo.png" alt="RfourL" style="max-width:220px;">
        </div>
        <div class="role-select-card">
            <a href="/login/admin">Admin</a>
            <a href="/login/staff">Staff</a>
            <a href="/login/supplier">Supplier</a>
        </div>
    </div>
</body>
</html>