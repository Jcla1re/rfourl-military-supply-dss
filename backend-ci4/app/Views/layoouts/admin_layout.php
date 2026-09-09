<?php // app/Views/layouts/admin_layout.php ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $title ?? 'RfourL Military Supply' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/admin-theme.css" rel="stylesheet">
</head>
<body>
<div class="d-flex">
    <!-- SIDEBAR -->
    <div class="sidebar" style="width: 260px;">
        <div>
            <div class="brand">RfourL<br><small style="font-weight:400;">MILITARY SUPPLY</small></div>

            <div class="nav-section-label">MAIN</div>
            <a href="/admin/dashboard" class="nav-link <?= $active === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
            <a href="/admin/inventory" class="nav-link <?= $active === 'inventory' ? 'active' : '' ?>">Inventory</a>
            <a href="/admin/reorder-alerts" class="nav-link <?= $active === 'reorder-alerts' ? 'active' : '' ?>">Reorder Alerts</a>
            <a href="/admin/trend-analysis" class="nav-link <?= $active === 'trend-analysis' ? 'active' : '' ?>">Trend Analysis</a>

            <div class="nav-section-label">SALES</div>
            <a href="/admin/pos" class="nav-link <?= $active === 'pos' ? 'active' : '' ?>">POS & Sales</a>

            <div class="nav-section-label">PROCUREMENT</div>
            <a href="/admin/orders" class="nav-link <?= $active === 'orders' ? 'active' : '' ?>">Orders</a>
            <a href="/admin/suppliers" class="nav-link <?= $active === 'suppliers' ? 'active' : '' ?>">Suppliers</a>

            <div class="nav-section-label">SYSTEM</div>
            <a href="/admin/settings" class="nav-link <?= $active === 'settings' ? 'active' : '' ?>">Settings</a>
            <a href="/admin/notifications" class="nav-link <?= $active === 'notifications' ? 'active' : '' ?>">Notifications</a>
        </div>

        <div class="user-footer">
            <div>
                <div style="font-weight:600; color:#fff;"><?= esc(session()->get('full_name')) ?></div>
                <small>Admin</small>
            </div>
            <a href="/logout" style="color:#d9dcd1;" title="Logout">&#8594;</a>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="flex-grow-1">
        <?= $this->renderSection('content') ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</body>
</html>