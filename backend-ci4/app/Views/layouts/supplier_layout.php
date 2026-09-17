<?php // app/Views/layouts/supplier_layout.php
$__unreadNotifications = (new \App\Models\NotificationModel())->unreadCount('Supplier', session()->get('user_id'));
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
                    <img src="/assets/img/RfourL_Logo.jpg" alt="" style="width:36px; height:36px; border-radius:6px;">
                    <div class="brand-text">
                        <div style="font-weight:700; line-height:1;">RfourL</div>
                        <small style="font-weight:400; font-size:0.65rem; letter-spacing:0.05em;">MILITARY SUPPLY</small>
                    </div>
                </div>
                <button id="sidebarToggle" class="btn btn-sm text-white border-0" style="background:none;">
                    <i class="bi bi-list fs-5"></i>
                </button>
            </div>

            <div class="nav-section-label">MAIN</div>
            <nav class="sidebar-nav">
                <a href="<?= site_url('supplier/dashboard') ?>" class="nav-link <?= ($active ?? '') === 'dashboard' ? 'active' : '' ?>">
                    <i class="bi bi-grid"></i>
                    <span class="nav-label">Dashboard</span>
                </a>
                <a href="<?= site_url('supplier/new-orders') ?>" class="nav-link <?= ($active ?? '') === 'new_orders' ? 'active' : '' ?>">
                    <i class="bi bi-clipboard"></i>
                    <span class="nav-label">New Orders</span>
                </a>
                <a href="<?= site_url('supplier/deliveries') ?>" class="nav-link <?= ($active ?? '') === 'deliveries' ? 'active' : '' ?>">
                    <i class="bi bi-truck"></i>
                    <span class="nav-label">Deliveries</span>
                </a>
                <a href="<?= site_url('supplier/completed') ?>" class="nav-link <?= ($active ?? '') === 'completed' ? 'active' : '' ?>">
                    <i class="bi bi-clipboard-check"></i>
                    <span class="nav-label">Completed</span>
                </a>
            </nav>

            <div class="nav-section-label">UPDATES</div>
            <a href="<?= site_url('supplier/notifications') ?>" class="nav-link <?= ($active ?? '') === 'notifications' ? 'active' : '' ?>">
                <i class="bi bi-bell"></i> <span class="nav-label">Notifications</span>
            </a>
        </div>

        <div class="user-footer">
            <a href="<?= site_url('supplier/profile') ?>" class="d-flex align-items-center gap-2" style="color:inherit; text-decoration:none;">
                <i class="bi bi-person-circle fs-4"></i>
                <div>
                    <div style="font-weight:600; color:#fff; font-size:0.9rem;"><?= esc(session()->get('full_name')) ?></div>
                    <small><?= esc(session()->get('role')) ?></small>
                </div>
            </a>
            <a href="/logout" style="color:#d9dcd1;" title="Logout"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="flex-grow-1">
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
