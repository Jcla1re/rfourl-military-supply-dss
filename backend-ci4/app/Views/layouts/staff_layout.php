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
<div class="d-flex">
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
                        <i class="bi bi-box2 icon-outline"></i>
                        <i class="bi bi-box2-fill icon-fill"></i>
                        <i class="bi bi-exclamation-triangle-fill nav-icon-badge"></i>
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
    <div class="flex-grow-1">
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
