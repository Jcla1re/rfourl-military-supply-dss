<?php // app/Views/layouts/admin_layout.php
$__unreadNotifications = (new \App\Models\NotificationModel())->unreadCount('Admin');
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
        <div>
            <div class="brand d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <img src="/assets/img/RfourL_Logo.jpg" alt="" class="brand-logo" style="width:36px; height:36px; border-radius:6px;">
                    <div class="brand-text">
                        <div style="font-weight:700; line-height:1;">RfourL</div>
                        <small style="font-weight:400; font-size:0.65rem; letter-spacing:0.05em;">MILITARY SUPPLY</small>
                    </div>
                </div>
                <button id="sidebarToggle" class="btn btn-sm text-white border-0" style="background:none;">
                    <i class="bi bi-layout-sidebar fs-5"></i>
                </button>
            </div>

            <div class="nav-section-label">MAIN</div>
            <nav class="sidebar-nav">
                <a href="<?= site_url('admin/dashboard') ?>" class="nav-link <?= ($active ?? '') === 'dashboard' ? 'active' : '' ?>">
                    <i class="bi bi-grid"></i>
                    <span class="nav-label">Dashboard</span>
                </a>

                <a href="<?= site_url('admin/inventory') ?>" class="nav-link <?= ($active ?? '') === 'inventory' ? 'active' : '' ?>">
                    <i class="bi bi-box-seam"></i>
                    <span class="nav-label">Inventory</span>
                </a>

                <a href="<?= site_url('admin/reorder-alerts') ?>" class="nav-link <?= ($active ?? '') === 'reorder_alerts' ? 'active' : '' ?>">
                    <i class="bi bi-bell"></i>
                    <span class="nav-label">Reorder Alerts</span>
                </a>

                <a href="<?= site_url('admin/trend-analysis') ?>" class="nav-link <?= ($active ?? '') === 'trend_analysis' ? 'active' : '' ?>">
                    <i class="bi bi-graph-up"></i>
                    <span class="nav-label">Trend Analysis</span>
                </a>
            </nav>

            <div class="nav-section-label">SALES</div>
            <a href="<?= site_url('admin/sales') ?>" class="nav-link <?= ($active ?? '') === 'sales' ? 'active' : '' ?>">
                <i class="bi bi-receipt"></i> <span class="nav-label">POS &amp; Sales</span>
            </a>

            <div class="nav-section-label">PROCUREMENT</div>
            <a href="<?= site_url('admin/orders') ?>" class="nav-link <?= ($active ?? '') === 'orders' ? 'active' : '' ?>">
                <i class="bi bi-box2"></i> <span class="nav-label">Orders</span>
            </a>
            <a href="<?= site_url('admin/suppliers') ?>" class="nav-link <?= ($active ?? '') === 'suppliers' ? 'active' : '' ?>">
                <i class="bi bi-truck"></i> <span class="nav-label">Suppliers</span>
            </a>

            <div class="nav-section-label">SYSTEM</div>
            <a href="<?= site_url('admin/settings') ?>" class="nav-link <?= ($active ?? '') === 'settings' ? 'active' : '' ?>">
                <i class="bi bi-gear"></i> <span class="nav-label">Settings</span>
            </a>
            <a href="<?= site_url('admin/notifications') ?>" class="nav-link <?= ($active ?? '') === 'notifications' ? 'active' : '' ?>">
                <i class="bi bi-bell-fill"></i> <span class="nav-label">Notifications</span>
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
            <a href="/logout" class="logout-link" style="color:#d9dcd1;" title="Logout"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="flex-grow-1">
        <div class="topbar d-flex justify-content-between align-items-center">
            <h1><?= esc($title ?? 'Dashboard') ?></h1>

            <div class="d-flex align-items-center gap-3">
                <?= $this->renderSection('header_actions') ?>

                <a href="<?= site_url('admin/notifications') ?>" class="notification-button position-relative" style="text-decoration:none;">
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
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const sidebar = document.getElementById('adminSidebar');
    const toggleBtn = document.getElementById('sidebarToggle');

    localStorage.removeItem('sidebarCollapsed');

    toggleBtn.addEventListener('click', () => {
        sidebar.classList.toggle('collapsed');
        localStorage.setItem(
            'sidebarCollapsed',
            sidebar.classList.contains('collapsed')
        );
    });
</script>
</body>
</html>