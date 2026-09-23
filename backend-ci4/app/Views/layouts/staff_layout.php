<?php // app/Views/layouts/staff_layout.php
$__unreadNotifications = (new \App\Models\NotificationModel())->unreadCount('Staff', session()->get('user_id'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $title ?? 'RfourL Military Supply' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="/assets/css/admin-theme.css" rel="stylesheet">
</head>
<body>
<div class="d-flex app-shell">
    <!-- SIDEBAR -->
    <div class="sidebar" id="adminSidebar">
        <script>
            try {
                if (localStorage.getItem('sidebarCollapsed') === 'true') {
                    document.getElementById('adminSidebar').classList.add('collapsed');
                }
            } catch (e) {}
        </script>
        <div>
            <div class="brand d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <img src="/assets/img/RfourL_Logo.jpg" alt="" class="brand-logo" style="width:36px; height:36px; border-radius:6px;">
                    <div class="brand-text">
                        <div style="font-weight:700; line-height:1;">RfourL</div>
                        <small style="font-weight:400; font-size:0.65rem; letter-spacing:0.05em;">MILITARY SUPPLY</small>
                    </div>
                </div>
                <button id="sidebarToggle" class="btn btn-sm text-white border-0" style="background:none;" data-tooltip="Hide sidebar">
                    <i class="bi bi-layout-sidebar fs-5"></i>
                </button>
            </div>

            <div class="nav-section-label">MAIN</div>
            <nav class="sidebar-nav">
                <a href="<?= site_url('staff/dashboard') ?>" class="nav-link <?= ($active ?? '') === 'dashboard' ? 'active' : '' ?>" data-tooltip="Dashboard">
                    <span class="nav-icon">
                        <i class="bi bi-grid icon-outline"></i>
                        <i class="bi bi-grid-fill icon-fill"></i>
                    </span>
                    <span class="nav-label">Dashboard</span>
                </a>
                <a href="<?= site_url('staff/inventory') ?>" class="nav-link <?= ($active ?? '') === 'inventory' ? 'active' : '' ?>" data-tooltip="Inventory">
                    <span class="nav-icon">
                        <i class="bi bi-box-seam icon-outline"></i>
                        <i class="bi bi-box-seam-fill icon-fill"></i>
                    </span>
                    <span class="nav-label">Inventory</span>
                </a>
                <a href="<?= site_url('staff/log-transaction') ?>" class="nav-link <?= ($active ?? '') === 'log_transaction' ? 'active' : '' ?>" data-tooltip="Log Transaction">
                    <span class="nav-icon">
                        <i class="bi bi-clipboard-data icon-outline"></i>
                        <i class="bi bi-clipboard-data-fill icon-fill"></i>
                    </span>
                    <span class="nav-label">Log Transaction</span>
                </a>
                <a href="<?= site_url('staff/reorder-alerts') ?>" class="nav-link <?= ($active ?? '') === 'reorder_alerts' ? 'active' : '' ?>" data-tooltip="Reorder Alerts">
                    <span class="nav-icon">
                        <svg class="icon-outline" viewBox="0 0 640 640" xmlns="http://www.w3.org/2000/svg">
                            <g fill="currentColor">
                                <path d="M124 245.5V427l-30 51.1-30 51.2 13.6 23.3L91.2 576h241.6l12.4-21.2c6.9-11.7 13-22.3 13.7-23.5.9-1.9.6-3.1-2.4-8.2-1.9-3.2-3.5-6.2-3.5-6.5s50.2-.6 111.5-.6H576V64H124zm161-90.6v60.9l13.2 9.8c7.2 5.3 13.7 9.7 14.2 9.8.6.1 5.4-2.1 10.6-4.8l9.5-4.9 8.1 4.6c4.5 2.6 8.7 4.7 9.4 4.7s4.8-2 9.1-4.5 8.2-4.5 8.7-4.5 5.1 2.3 10.1 5c5.1 2.8 9.8 4.7 10.4 4.3.7-.3 6.9-4.9 14-10.1l12.7-9.4V94h131v392H440.3l-105.8-.1-47.5-81.2-47.5-81.2h-54.9L170.3 348c-7.9 13.5-14.8 25.2-15.3 26-.6.9-1-54-1-139.3V94h131zm100-7.9c0 29.1-.3 53-.7 53-.3 0-4.3-2-8.7-4.4l-8.1-4.3-8.8 4.9-8.7 4.9-8.6-4.9-8.7-5-8.1 4.4c-4.4 2.4-8.4 4.4-8.8 4.4-.5 0-.8-23.9-.8-53V94h70zM272.7 439.5c27.8 47.3 50.9 86.9 51.5 88.1.8 1.7.1 3.6-3.7 10.3l-4.8 8.1H108.4l-4.5-7.7c-2.4-4.3-4.5-8.1-4.7-8.5-.3-.5 20.8-36.8 102.5-176.1.3-.4 5-.6 10.5-.5l10.1.3z"/>
                                <path d="M371 405v50h145V355H371zm115 0v20h-85v-40h85zm-289 33v35h30v-70h-30zm10 56.1c-3.2 1.3-6.8 4.7-8.6 8.1-4.5 8.6 3.6 20.8 13.7 20.8 4.7 0 10.8-3.8 13-8 6.3-11.7-6-25.7-18.1-20.9"/>
                            </g>
                        </svg>
                        <svg class="icon-fill" viewBox="0 0 640 640" xmlns="http://www.w3.org/2000/svg">
                            <g fill="currentColor" stroke="currentColor" stroke-width="14" stroke-linejoin="round">
                                <path d="M124 245.5V427l-30 51.1-30 51.2 13.6 23.3L91.2 576h241.6l12.4-21.2c6.9-11.7 13-22.3 13.7-23.5.9-1.9.6-3.1-2.4-8.2-1.9-3.2-3.5-6.2-3.5-6.5s50.2-.6 111.5-.6H576V64H124zm161-90.6v60.9l13.2 9.8c7.2 5.3 13.7 9.7 14.2 9.8.6.1 5.4-2.1 10.6-4.8l9.5-4.9 8.1 4.6c4.5 2.6 8.7 4.7 9.4 4.7s4.8-2 9.1-4.5 8.2-4.5 8.7-4.5 5.1 2.3 10.1 5c5.1 2.8 9.8 4.7 10.4 4.3.7-.3 6.9-4.9 14-10.1l12.7-9.4V94h131v392H440.3l-105.8-.1-47.5-81.2-47.5-81.2h-54.9L170.3 348c-7.9 13.5-14.8 25.2-15.3 26-.6.9-1-54-1-139.3V94h131zm100-7.9c0 29.1-.3 53-.7 53-.3 0-4.3-2-8.7-4.4l-8.1-4.3-8.8 4.9-8.7 4.9-8.6-4.9-8.7-5-8.1 4.4c-4.4 2.4-8.4 4.4-8.8 4.4-.5 0-.8-23.9-.8-53V94h70zM272.7 439.5c27.8 47.3 50.9 86.9 51.5 88.1.8 1.7.1 3.6-3.7 10.3l-4.8 8.1H108.4l-4.5-7.7c-2.4-4.3-4.5-8.1-4.7-8.5-.3-.5 20.8-36.8 102.5-176.1.3-.4 5-.6 10.5-.5l10.1.3z"/>
                                <path d="M371 405v50h145V355H371zm115 0v20h-85v-40h85zm-289 33v35h30v-70h-30zm10 56.1c-3.2 1.3-6.8 4.7-8.6 8.1-4.5 8.6 3.6 20.8 13.7 20.8 4.7 0 10.8-3.8 13-8 6.3-11.7-6-25.7-18.1-20.9"/>
                            </g>
                        </svg>
                    </span>
                    <span class="nav-label">Reorder Alerts</span>
                </a>
            </nav>

            <div class="nav-section-label">SALES</div>
            <a href="<?= site_url('staff/sales') ?>" class="nav-link <?= ($active ?? '') === 'sales' ? 'active' : '' ?>" data-tooltip="POS & Sales">
                <span class="nav-icon">
                    <i class="bi bi-cart icon-outline"></i>
                    <i class="bi bi-cart-fill icon-fill"></i>
                </span>
                <span class="nav-label">POS &amp; Sales</span>
            </a>
        </div>

        <div class="user-footer">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-person-circle fs-4"></i>
                <div class="user-text">
                    <div style="font-weight:600; color:#fff; font-size:0.9rem;"><?= esc(session()->get('full_name')) ?></div>
                    <small><?= esc(session()->get('role')) ?></small>
                </div>
            </div>
            <a href="/logout" class="logout-link" title="Logout"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="flex-grow-1 main-panel">
        <div class="topbar d-flex justify-content-between align-items-center">
            <h1><?= esc($title ?? 'Dashboard') ?></h1>

            <div class="d-flex align-items-center gap-3">
                <?= $this->renderSection('header_actions') ?>

                <a href="<?= site_url('staff/notifications') ?>" class="notification-button position-relative" style="text-decoration:none;">
                    <i class="bi bi-bell"></i>
                    <?php if ($__unreadNotifications > 0): ?>
                        <span class="notif-badge"><?= esc($__unreadNotifications) ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </div>
        <?= $this->renderSection('content') ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const sidebar = document.getElementById('adminSidebar');
    const toggleBtn = document.getElementById('sidebarToggle');

    toggleBtn.setAttribute('data-tooltip', sidebar.classList.contains('collapsed') ? 'Show sidebar' : 'Hide sidebar');

    toggleBtn.addEventListener('click', () => {
        sidebar.classList.toggle('collapsed');
        const isCollapsed = sidebar.classList.contains('collapsed');
        toggleBtn.setAttribute('data-tooltip', isCollapsed ? 'Show sidebar' : 'Hide sidebar');
        localStorage.setItem('sidebarCollapsed', isCollapsed);
    });
</script>
</body>
</html>
