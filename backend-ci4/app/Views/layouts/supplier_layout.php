<?php // app/Views/layouts/supplier_layout.php
$__unreadNotifications = (new \App\Models\NotificationModel())->unreadCount('Supplier', session()->get('user_id'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $title ?? 'RfourL Military Supply' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" integrity="sha384-tViUnnbYAV00FLIhhi3v/dWt3Jxw4gZQcNoSCxCIFNJVCx7/D55/wXsrNIRANwdD" crossorigin="anonymous" rel="stylesheet">
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
                <a href="<?= site_url('supplier/dashboard') ?>" class="nav-link <?= ($active ?? '') === 'dashboard' ? 'active' : '' ?>" data-tooltip="Dashboard">
                    <span class="nav-icon">
                        <i class="bi bi-grid icon-outline"></i>
                        <i class="bi bi-grid-fill icon-fill"></i>
                    </span>
                    <span class="nav-label">Dashboard</span>
                </a>
                <a href="<?= site_url('supplier/new-orders') ?>" class="nav-link <?= ($active ?? '') === 'new_orders' ? 'active' : '' ?>" data-tooltip="New Orders">
                    <span class="nav-icon">
                        <i class="bi bi-clipboard icon-outline"></i>
                        <i class="bi bi-clipboard-fill icon-fill"></i>
                    </span>
                    <span class="nav-label">New Orders</span>
                </a>
                <a href="<?= site_url('supplier/deliveries') ?>" class="nav-link <?= ($active ?? '') === 'deliveries' ? 'active' : '' ?>" data-tooltip="Deliveries">
                    <span class="nav-icon">
                        <i class="bi bi-truck-front icon-outline"></i>
                        <i class="bi bi-truck-front-fill icon-fill"></i>
                    </span>
                    <span class="nav-label">Deliveries</span>
                </a>
                <a href="<?= site_url('supplier/completed') ?>" class="nav-link <?= ($active ?? '') === 'completed' ? 'active' : '' ?>" data-tooltip="Completed">
                    <span class="nav-icon">
                        <i class="bi bi-clipboard-check icon-outline"></i>
                        <i class="bi bi-clipboard-check-fill icon-fill"></i>
                    </span>
                    <span class="nav-label">Completed</span>
                </a>
            </nav>

            <div class="nav-section-label">UPDATES</div>
            <a href="<?= site_url('supplier/notifications') ?>" class="nav-link <?= ($active ?? '') === 'notifications' ? 'active' : '' ?>" data-tooltip="Notifications">
                <span class="nav-icon">
                    <i class="bi bi-bell icon-outline"></i>
                    <i class="bi bi-bell-fill icon-fill"></i>
                </span>
                <span class="nav-label">Notifications</span>
            </a>
        </div>

        <div class="user-footer">
            <a href="<?= site_url('supplier/profile') ?>" class="d-flex align-items-center gap-2" style="color:inherit; text-decoration:none;">
                <i class="bi bi-person-circle fs-4"></i>
                <div class="user-text">
                    <div style="font-weight:600; color:#fff; font-size:0.9rem;"><?= esc(session()->get('full_name')) ?></div>
                    <small><?= esc(session()->get('role')) ?></small>
                </div>
            </a>
            <form method="post" action="<?= site_url('logout') ?>" class="m-0 d-inline"><?= csrf_field() ?><button type="submit" class="logout-link" title="Logout"><i class="bi bi-box-arrow-right"></i></button></form>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="flex-grow-1 main-panel">
        <div class="topbar d-flex justify-content-between align-items-center">
            <div>
                <h1><?= esc($title ?? 'Dashboard') ?></h1>
                <?php if (!empty($subtitle)): ?><div class="text-white-50 small mt-1"><?= esc($subtitle) ?></div><?php endif; ?>
            </div>

            <div class="d-flex align-items-center gap-3">
                <?= $this->renderSection('header_actions') ?>

                <a href="<?= site_url('supplier/notifications') ?>" class="notification-button position-relative" style="text-decoration:none;">
                    <i class="bi bi-bell"></i>
                    <?php if ($__unreadNotifications > 0): ?>
                        <span class="notif-badge"><?= esc($__unreadNotifications) ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </div>
        <div class="main-scroll">
            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
            <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>
            <?= $this->renderSection('content') ?>
        </div>
    </div>
</div>

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
<script>
    // Flash messages (.alert-success/.alert-danger/.toast-success) render as
    // an icon + title + message toast card near the header, auto-dismissing
    // after 3s instead of sitting as a boxed banner in the page flow.
    function toastEscapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s ?? '';
        return d.innerHTML;
    }

    document.querySelectorAll('.alert-success, .toast-success').forEach(el => {
        const msg = el.textContent.trim();
        el.innerHTML = `<div class="toast-icon success"><i class="bi bi-check-circle-fill"></i></div>
            <div><div class="toast-title">Success!</div><div class="toast-msg">${toastEscapeHtml(msg)}</div></div>`;
    });
    document.querySelectorAll('.alert-danger').forEach(el => {
        const msg = el.textContent.trim();
        el.innerHTML = `<div class="toast-icon error"><i class="bi bi-x-circle-fill"></i></div>
            <div><div class="toast-title">Error</div><div class="toast-msg">${toastEscapeHtml(msg)}</div></div>`;
    });

    document.querySelectorAll('.alert-success, .alert-danger, .toast-success').forEach(el => {
        setTimeout(() => {
            el.classList.add('toast-hide');
            setTimeout(() => el.remove(), 400);
        }, 1000);
    });
</script>
</body>
</html>
