<?php
// app/Views/auth/_layout.php
// The split layout shared by the sign-in page, the Forgot password steps and the staff "Set your
// password" page: the form on the left, the store photo with the brand on the right.
// Sections: title, content, scripts (optional).
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" href="/favicon.ico?v=4" sizes="32x32">
    <link rel="icon" href="/favicon.svg?v=4" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/assets/img/favicon/apple-touch-icon.png?v=4">
    <meta name="apple-mobile-web-app-title" content="RfourL MS" />
    <link rel="manifest" href="/assets/img/favicon/site.webmanifest?v=4">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->renderSection('title') ?> | RfourL Military Supply</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" integrity="sha384-tViUnnbYAV00FLIhhi3v/dWt3Jxw4gZQcNoSCxCIFNJVCx7/D55/wXsrNIRANwdD" crossorigin="anonymous" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
<?= view('partials/demo_head') ?>
</head>
<body class="split-body">
<?= view('partials/demo_banner') ?>
    <main class="split-shell">
        <section class="split-form">
            <div class="split-form-inner">
                <div class="split-mobile-brand">
                    <img src="/assets/img/RfourL_Logo.jpg" alt="">
                    <span>RfourL Military Supply</span>
                </div>
<?= $this->renderSection('content') ?>
            </div>
        </section>

        <aside class="split-photo">
            <div class="split-photo-brand">
                <div class="landing-logo-box">
                    <img src="/assets/img/RfourL_Logo.jpg" alt="">
                </div>
                <div class="landing-brand">
                    <h1>RfourL<br>Military Supply</h1>
                    <p class="landing-tagline">Inventory, sales, and reorder decisions in one place.</p>
                </div>
            </div>
            <div class="landing-footer-line">
                &copy; 2026 RfourL Military Supply &middot; 7C 2nd Ave, Brgy. Bagong Lipunan ng Crame, Quezon City
            </div>
        </aside>
    </main>
    <script src="/assets/js/pw-toggle.js"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
